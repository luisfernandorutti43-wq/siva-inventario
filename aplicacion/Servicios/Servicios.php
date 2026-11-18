<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LectorExcelVentasHistoricas.php';

final class DailyForecastAiCache
{
    private const FORMAT = 'siva-daily-forecast-ai-cache';
    private const VERSION = 1;

    public function __construct(private readonly string $directory)
    {
    }

    public function remember(string $key, callable $calculate, callable $isValid): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            throw new InvalidArgumentException('La clave de caché del pronóstico no es válida.');
        }
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException('No se pudo preparar la caché del pronóstico diario.');
        }

        $cachePath = $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . 'forecast.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('No se pudo bloquear la caché del pronóstico diario.');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('No se pudo bloquear la caché del pronóstico diario.');
        }

        try {
            $cachedResult = $this->load($cachePath, $key, $isValid);
            if ($cachedResult !== null) {
                return ['result' => $cachedResult, 'cache_hit' => true];
            }

            $result = $calculate();
            if (!is_array($result) || !$isValid($result)) {
                throw new RuntimeException('El cálculo devolvió un resultado de pronóstico inválido.');
            }
            $this->store($cachePath, $key, $result);

            return ['result' => $result, 'cache_hit' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(string $path, string $key, callable $isValid): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('No se pudo leer la caché del pronóstico diario.');
        }

        try {
            $cache = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log('La caché del pronóstico estaba dañada y se volverá a calcular: ' . $exception->getMessage());
            $this->remove($path);

            return null;
        }
        if (!is_array($cache)
            || ($cache['format'] ?? '') !== self::FORMAT
            || ($cache['version'] ?? null) !== self::VERSION
            || ($cache['key'] ?? '') !== $key
            || !is_array($cache['result'] ?? null)
            || !$isValid($cache['result'])
        ) {
            error_log('La caché del pronóstico no coincide con su formato y se volverá a calcular.');
            $this->remove($path);

            return null;
        }

        return $cache['result'];
    }

    private function store(string $path, string $key, array $result): void
    {
        try {
            $contents = json_encode([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'key' => $key,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudo preparar la caché del pronóstico diario.', 0, $exception);
        }

        $temporaryPath = tempnam($this->directory, 'forecast-');
        if ($temporaryPath === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de la caché.');
        }
        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)
                || !rename($temporaryPath, $path)
            ) {
                throw new RuntimeException('No se pudo guardar la caché del pronóstico diario.');
            }
        } finally {
            if (is_file($temporaryPath) && !unlink($temporaryPath)) {
                error_log('No se pudo retirar el archivo temporal de caché: ' . $temporaryPath);
            }
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('No se pudo retirar una caché inválida del pronóstico diario.');
        }
    }
}

final class BrandSalesForecastService
{
    private const WAREHOUSES = ['A0101', 'A0102'];
    private const HISTORY_START = '2025-08-01';
    private const WARMUP_WEEKS = 8;
    private const VALIDATION_WEEKS = 8;
    private const HOLDOUT_WEEKS = 8;
    private const WAPE_TARGET = 30.0;
    private const MINIMUM_PASSING_WEEK_SHARE = 0.70;
    private const BRAND_ACTIVITY_THRESHOLDS = [0, 25, 50, 100, 200, 300, 500];
    private const METHODS = ['median_4', 'croston_sba', 'tsb'];
    private const AI_CACHE_VERSION = 'daily-forecast-v1';

    public function __construct(private PDO $database)
    {
    }

    public function generate(): array
    {
        $salesRows = $this->loadDailyBrandSales();
        if ($salesRows === []) {
            throw new RuntimeException('No hay ventas historicas de A0101/A0102 desde agosto de 2025.');
        }

        $lastSaleDate = new DateTimeImmutable((string) $salesRows[array_key_last($salesRows)]['fecha_venta']);
        $calendar = [];
        $dateIndexes = [];
        $startDate = new DateTimeImmutable(self::HISTORY_START);
        for ($date = $startDate; $date <= $lastSaleDate; $date = $date->modify('+1 day')) {
            $dateText = $date->format('Y-m-d');
            $dateIndexes[$dateText] = count($calendar);
            $calendar[] = $dateText;
        }

        $dailyBrandSeries = [];
        foreach ($salesRows as $row) {
            $brand = trim((string) $row['marca']);
            $index = $dateIndexes[(string) $row['fecha_venta']] ?? null;
            if ($brand === '' || $index === null) {
                continue;
            }
            $dailyBrandSeries[$brand] ??= array_fill(0, count($calendar), 0.0);
            $dailyBrandSeries[$brand][$index] = max(0.0, (float) $row['cantidad']);
        }
        ksort($dailyBrandSeries, SORT_NATURAL | SORT_FLAG_CASE);
        if ($dailyBrandSeries === []) {
            throw new RuntimeException('No se encontraron marcas con ventas validas.');
        }

        $businessWeeks = $this->buildBusinessWeeks($dateIndexes, $lastSaleDate);
        $weeklyBrandSeries = $this->buildWeeklyBrandSeries($dailyBrandSeries, $businessWeeks);
        $backtestIndexes = $this->buildBacktestWeekIndexes(count($businessWeeks));
        $validationIndexes = array_slice($backtestIndexes, self::WARMUP_WEEKS, self::VALIDATION_WEEKS);
        $holdoutIndexes = array_slice(
            $backtestIndexes,
            self::WARMUP_WEEKS + self::VALIDATION_WEEKS,
            self::HOLDOUT_WEEKS
        );

        $holdoutBrandMethods = $this->selectBrandMethods($weeklyBrandSeries, $validationIndexes);
        $currentValidationIndexes = range(
            count($businessWeeks) - self::VALIDATION_WEEKS,
            count($businessWeeks) - 1
        );
        $brandMethods = $this->selectBrandMethods($weeklyBrandSeries, $currentValidationIndexes);
        $validationUnits = $this->sumUnitsByBrand($weeklyBrandSeries, $validationIndexes);
        $thresholdOptions = $this->evaluateBrandThresholds(
            $weeklyBrandSeries,
            $validationIndexes,
            $businessWeeks,
            $holdoutBrandMethods,
            $validationUnits
        );
        $selectedThreshold = $this->selectBrandThreshold($thresholdOptions);
        $frequentBrands = $selectedThreshold['included_brands'];

        $allBrandsHoldout = $this->evaluateWeeklyForecast(
            $weeklyBrandSeries,
            $holdoutIndexes,
            $businessWeeks,
            $holdoutBrandMethods
        );
        $frequentBrandsHoldout = $this->evaluateWeeklyForecast(
            $weeklyBrandSeries,
            $holdoutIndexes,
            $businessWeeks,
            $holdoutBrandMethods,
            $frequentBrands
        );
        $networkHoldout = $this->evaluateWeeklyForecast(
            $weeklyBrandSeries,
            $holdoutIndexes,
            $businessWeeks,
            $holdoutBrandMethods,
            null,
            true
        );

        $today = new DateTimeImmutable('today');
        $currentWeekStart = $today->format('N') === '7'
            ? $today->modify('+1 day')
            : $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        $lastObservedWeekIndex = array_key_last($businessWeeks);
        $currentForecastWeek = $this->buildForecastWeek(
            $currentWeekStart,
            count($businessWeeks),
            $weeklyBrandSeries,
            $dailyBrandSeries,
            $businessWeeks,
            $brandMethods,
            $frequentBrands
        );
        $historicalForecastWeeks = $this->buildHistoricalForecastWeeks(
            $weeklyBrandSeries,
            $dailyBrandSeries,
            $businessWeeks,
            $frequentBrands,
            $currentWeekStart
        );

        $methodCounts = array_count_values($brandMethods);
        $methodLabels = [];
        foreach (self::METHODS as $method) {
            if (($methodCounts[$method] ?? 0) > 0) {
                $methodLabels[] = $this->methodLabel($method) . ': ' . $methodCounts[$method];
            }
        }

        return [
            'last_sale_date' => $lastSaleDate,
            'last_complete_week_end' => $businessWeeks[$lastObservedWeekIndex]['end'],
            'brand_series' => $dailyBrandSeries,
            'frequent_brands' => $frequentBrands,
            'brand_activity_threshold' => $selectedThreshold['units_threshold'],
            'brand_activity_options' => $thresholdOptions,
            'selected_brand_method_label' => implode(' · ', $methodLabels),
            'current_forecast_week' => $currentForecastWeek,
            'historical_forecast_weeks' => $historicalForecastWeeks,
            'current_week_start' => $currentWeekStart->format('Y-m-d'),
            'holdout_weeks' => self::HOLDOUT_WEEKS,
            'wape_target' => self::WAPE_TARGET,
            'minimum_passing_week_share' => self::MINIMUM_PASSING_WEEK_SHARE,
            'all_brands_holdout' => $allBrandsHoldout,
            'frequent_brands_holdout' => $frequentBrandsHoldout,
            'network_holdout' => $networkHoldout,
        ];
    }

    public function generateDaily(): array
    {
        $latestImportedForecast = $this->buildLatestImportedDailyForecast();
        $forecast = $latestImportedForecast['forecast'];
        if (is_array($forecast)) {
            $cacheHit = ($forecast['ai_cache_hit'] ?? false) === true;
            unset($forecast['ai_cache_hit']);
            if (!$cacheHit) {
                $this->saveDailyProductForecast($forecast);
                $this->saveDailyBrandForecast($forecast);
            }
        }

        return [
            'current_daily_forecast' => $forecast,
            'daily_forecast_message' => $latestImportedForecast['message'],
        ];
    }

    private function runAiDailyForecast(
        array $historicalSales,
        array $products,
        array $forecastDates,
        DateTimeImmutable $cutoffDate
    ): array {
        try {
            $payload = json_encode([
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'forecast_dates' => array_map(
                    static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'),
                    $forecastDates
                ),
                'products' => $products,
                'sales' => $historicalSales,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudieron preparar los datos para el modelo de IA.', 0, $exception);
        }

        $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'pronostico_diario_ventas_ia.py';
        $scriptHash = hash_file('sha256', $scriptPath);
        if (!is_string($scriptHash)) {
            throw new RuntimeException('No se pudo identificar la versión del modelo de pronóstico.');
        }
        $cacheKey = hash('sha256', self::AI_CACHE_VERSION . "\0" . $scriptHash . "\0" . $payload);
        $cache = new DailyForecastAiCache(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siva-daily-forecast-ai'
        );
        $cached = $cache->remember(
            $cacheKey,
            fn (): array => $this->calculateAiDailyForecast($payload),
            fn (array $result): bool => $this->isValidAiDailyForecastResult($result)
        );

        return $cached['result'] + ['_cache_hit' => $cached['cache_hit']];
    }

    private function calculateAiDailyForecast(string $payload): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('PHP no permite ejecutar el modelo de IA (proc_open está deshabilitado).');
        }

        $projectRoot = dirname(__DIR__, 2);
        $pythonExecutable = getenv('SIVA_PYTHON_EXECUTABLE');
        if (!is_string($pythonExecutable) || trim($pythonExecutable) === '') {
            $projectPython = $projectRoot . DIRECTORY_SEPARATOR . 'analisis'
                . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts'
                . DIRECTORY_SEPARATOR . 'python.exe';
            $pythonExecutable = is_file($projectPython) ? $projectPython : 'python';
        }
        $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'pronostico_diario_ventas_ia.py';
        $command = [$pythonExecutable, $scriptPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $projectRoot, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el modelo de IA. Revisa la configuración de Python.');
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_string($output) || $output === '') {
            $detail = trim((string) $errorOutput);
            throw new RuntimeException(
                $detail !== ''
                    ? 'No se pudo generar el pronóstico de IA: ' . $detail
                    : 'No se pudo generar el pronóstico de IA. Revisa Python y sus dependencias.'
            );
        }

        try {
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El modelo de IA devolvió una respuesta inválida.', 0, $exception);
        }
        if (!is_array($result) || !$this->isValidAiDailyForecastResult($result)) {
            throw new RuntimeException('El modelo de IA devolvió un resultado incompleto.');
        }

        return $result;
    }

    private function isValidAiDailyForecastResult(array $result): bool
    {
        return ($result['model'] ?? '') === 'Random Forest (75%) + promedio de 8 semanas (25%)'
            && is_array($result['forecast_by_product'] ?? null);
    }

    public function forecastPeriodForSalesDate(
        DateTimeImmutable $lastSaleDate,
        ?DateTimeImmutable $today = null
    ): array
    {
        return $this->forecastPeriod($lastSaleDate, $today);
    }

    private function buildDailyForecast(
        DateTimeImmutable $lastSaleDate,
        array $dailySeriesByProduct,
        DateTimeImmutable $forecastStartDate,
        DateTimeImmutable $forecastEndDate,
        array $historicalSales
    ): array {
        $forecastDates = [];
        for ($date = $forecastStartDate; $date <= $forecastEndDate; $date = $date->modify('+1 day')) {
            $forecastDates[] = $date;
        }

        $productsForModel = [];
        foreach ($dailySeriesByProduct as $productKey => $dailySeries) {
            $productsForModel[] = [
                'product_id' => hash('sha256', (string) $productKey),
                'codigo' => $dailySeries['product']['codigo'],
                'marca' => $dailySeries['product']['marca'],
                'medida' => $dailySeries['product']['medida'],
            ];
        }
        $aiForecast = $this->runAiDailyForecast(
            $historicalSales,
            $productsForModel,
            $forecastDates,
            $lastSaleDate
        );

        $productRows = [];
        $totalUnits = 0;
        $brandRowsByName = [];
        foreach ($dailySeriesByProduct as $productKey => $dailySeries) {
            $product = $dailySeries['product'];
            $productId = hash('sha256', (string) $productKey);
            $dayUnits = $aiForecast['forecast_by_product'][$productId] ?? null;
            if (!is_array($dayUnits) || count($dayUnits) !== count($forecastDates)) {
                throw new RuntimeException('La IA no generó el pronóstico de una llanta del archivo.');
            }
            $dayUnits = array_map(static fn ($units): int => max(0, (int) $units), $dayUnits);
            $units = array_sum($dayUnits);
            $totalUnits += $units;
            $productRows[] = [
                'producto_hash' => $productId,
                'codigo' => $product['codigo'],
                'marca' => $product['marca'],
                'medida' => $product['medida'],
                'modelo' => $product['modelo'],
                'recent_units' => array_sum($dailySeries['units']),
                'frequent' => false,
                'method' => 'random_forest',
                'method_label' => 'IA combinada',
                'units' => $units,
                'actual_units' => null,
                'difference_units' => null,
                'daily_units' => $dayUnits,
            ];

            $brand = $product['marca'];
            $brandRowsByName[$brand] ??= [
                'marca' => $brand,
                'recent_units' => 0,
                'units' => 0,
                'daily_units' => array_fill(0, count($forecastDates), 0),
            ];
            $brandRowsByName[$brand]['recent_units'] += (int) array_sum($dailySeries['units']);
            $brandRowsByName[$brand]['units'] += $units;
            foreach ($dayUnits as $index => $dayUnit) {
                $brandRowsByName[$brand]['daily_units'][$index] += $dayUnit;
            }
        }

        usort(
            $productRows,
            static fn (array $left, array $right): int => $right['units'] <=> $left['units']
        );

        $brandDailyTotals = array_fill(0, count($forecastDates), 0);
        $brandTotalUnits = 0;
        $brandRows = array_values($brandRowsByName);
        foreach ($brandRows as $brandRow) {
            $brandTotalUnits += (int) $brandRow['units'];
            foreach ($brandRow['daily_units'] as $index => $dayUnit) {
                $brandDailyTotals[$index] += $dayUnit;
            }
        }
        usort(
            $brandRows,
            static fn (array $left, array $right): int => $right['units'] <=> $left['units']
        );

        return [
            'start_date' => $forecastDates[0]->format('Y-m-d'),
            'end_date' => $forecastDates[array_key_last($forecastDates)]->format('Y-m-d'),
            'source_cutoff_date' => $lastSaleDate->format('Y-m-d'),
            'forecast_dates' => array_map(
                static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'),
                $forecastDates
            ),
            'label' => $forecastDates[0]->format('d/m') . ' al '
                . $forecastDates[array_key_last($forecastDates)]->format('d/m'),
            'total_units' => $totalUnits,
            'brand_total_units' => $brandTotalUnits,
            'brand_daily_totals' => $brandDailyTotals,
            'actual_units' => null,
            'difference_units' => null,
            'error_percent' => null,
            'ai_model' => $aiForecast['model'],
            'ai_training_samples' => $aiForecast['training_samples'],
            'ai_history_days' => $aiForecast['history_days'],
            'ai_trained_through' => $aiForecast['trained_through'],
            'ai_cache_hit' => (bool) ($aiForecast['_cache_hit'] ?? false),
            'brand_forecast_rows' => $brandRows,
            'product_forecast_rows' => $productRows,
        ];
    }

    private function buildLatestImportedDailyForecast(): array
    {
        $latestImport = $this->database->query(
            "SELECT archivo_hash, MAX(fecha_venta) AS last_sale_date,
                    MAX(importado_en) AS latest_imported_at, MAX(id) AS latest_row_id
             FROM ventas_excel_importadas
             WHERE almacen_codigo IN ('A0101', 'A0102', 'REPORTE')
             GROUP BY archivo_hash
             ORDER BY latest_imported_at DESC, latest_row_id DESC
             LIMIT 1"
        )->fetch();

        if (!is_array($latestImport)) {
            return [
                'forecast' => null,
                'message' => 'Para usar el pronóstico diario, primero importa el Excel de ventas del mes que quieres completar. Diario usará ese archivo; no toma las ventas registradas fuera de él.',
            ];
        }

        $latestMonthEnd = (new DateTimeImmutable((string) $latestImport['last_sale_date']))
            ->modify('first day of next month');
        $latestMonthStart = (new DateTimeImmutable((string) $latestImport['last_sale_date']))
            ->modify('first day of this month');
        $monthCutoff = $this->database->prepare(
            "SELECT MAX(fecha_venta)
             FROM ventas_excel_importadas
             WHERE almacen_codigo IN ('A0101', 'A0102', 'REPORTE')
               AND fecha_venta >= :month_start AND fecha_venta < :next_month_start"
        );
        $monthCutoff->execute([
            'month_start' => $latestMonthStart->format('Y-m-d'),
            'next_month_start' => $latestMonthEnd->format('Y-m-d'),
        ]);
        $cutoffText = $monthCutoff->fetchColumn();
        if (!is_string($cutoffText) || $cutoffText === '') {
            throw new RuntimeException('No se pudo determinar la fecha de corte de las ventas importadas.');
        }
        $lastSaleDate = new DateTimeImmutable($cutoffText);
        $monthStart = $lastSaleDate->modify('first day of this month');
        $nextMonthStart = $monthStart->modify('first day of next month');
        $statement = $this->database->prepare(
            "SELECT
                CASE WHEN UPPER(TRIM(codigo)) LIKE 'SIN-SKU-%' THEN '' ELSE TRIM(codigo) END AS codigo,
                TRIM(marca) AS marca,
                TRIM(medida) AS medida,
                TRIM(modelo) AS modelo,
                fecha_venta,
                SUM(cantidad) AS cantidad
             FROM ventas_excel_importadas
             WHERE almacen_codigo IN (:warehouse_one, :warehouse_two, :warehouse_report)
               AND TRIM(marca) <> ''
               AND TRIM(medida) <> ''
               AND fecha_venta >= :month_start
               AND fecha_venta < :next_month_start
             GROUP BY
                CASE WHEN UPPER(TRIM(codigo)) LIKE 'SIN-SKU-%' THEN '' ELSE TRIM(codigo) END,
                TRIM(marca), TRIM(medida), TRIM(modelo), fecha_venta
             ORDER BY fecha_venta, TRIM(marca), TRIM(medida), TRIM(modelo)"
        );
        $statement->execute([
            'warehouse_one' => self::WAREHOUSES[0],
            'warehouse_two' => self::WAREHOUSES[1],
            'warehouse_report' => 'REPORTE',
            'month_start' => $monthStart->format('Y-m-d'),
            'next_month_start' => $nextMonthStart->format('Y-m-d'),
        ]);
        $salesRows = $statement->fetchAll();

        $calendar = [];
        $dateIndexes = [];
        for ($date = $monthStart; $date <= $lastSaleDate; $date = $date->modify('+1 day')) {
            $dateText = $date->format('Y-m-d');
            $dateIndexes[$dateText] = count($calendar);
            $calendar[] = $dateText;
        }

        $dailySeriesByProduct = [];
        foreach ($salesRows as $row) {
            $brand = trim((string) $row['marca']);
            $measure = trim((string) $row['medida']);
            $model = trim((string) $row['modelo']);
            $code = trim((string) $row['codigo']);
            $index = $dateIndexes[(string) $row['fecha_venta']] ?? null;
            if ($brand === '' || $measure === '' || $index === null) {
                continue;
            }
            $productIdentity = json_encode(
                [$code, $brand, $measure, $model],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $dailySeriesByProduct[$productIdentity] ??= [
                'product' => [
                    'codigo' => $code,
                    'marca' => $brand,
                    'medida' => $measure,
                    'modelo' => $model,
                ],
                'units' => array_fill(0, count($calendar), 0.0),
            ];
            $dailySeriesByProduct[$productIdentity]['units'][$index] += max(0.0, (float) $row['cantidad']);
        }
        uasort(
            $dailySeriesByProduct,
            static fn (array $left, array $right): int => strnatcasecmp(
                implode(' ', array_values($left['product'])),
                implode(' ', array_values($right['product']))
            )
        );

        $today = new DateTimeImmutable('today');
        [$forecastStartDate, $forecastEndDate] = $this->forecastPeriod($lastSaleDate, $today);
        $modelCutoffDate = $forecastStartDate->modify('-1 day');
        $historicalSales = $this->loadHistoricalProductSales($modelCutoffDate);
        $forecastDates = [];
        for ($date = $forecastStartDate; $date <= $forecastEndDate; $date = $date->modify('+1 day')) {
            $forecastDates[] = $date;
        }
        if ($forecastDates === [] || $dailySeriesByProduct === []) {
            return [
                'forecast' => null,
                'message' => 'No quedan días por pronosticar después de la fecha de corte del Excel más reciente, o ese archivo no contiene llantas válidas con marca y medida.',
            ];
        }

        $forecast = $this->buildDailyForecast(
            $modelCutoffDate,
            $dailySeriesByProduct,
            $forecastStartDate,
            $forecastEndDate,
            $historicalSales
        );
        $forecast['source_file_hash'] = (string) $latestImport['archivo_hash'];
        $forecast['import_cutoff_date'] = $lastSaleDate->format('Y-m-d');

        return [
            'forecast' => $forecast,
            'message' => '',
        ];
    }

    private function loadHistoricalProductSales(DateTimeImmutable $cutoffDate): array
    {
        $statement = $this->database->prepare(
            "SELECT sales.codigo, TRIM(sales.marca) AS marca, TRIM(sales.medida) AS medida,
                    sales.fecha_venta AS fecha, SUM(sales.cantidad) AS cantidad
             FROM (
                SELECT h.archivo_hash, h.hoja, h.fila_origen, h.codigo, h.marca, h.medida,
                       h.fecha_venta, h.cantidad
                FROM historico_ventas_excel h
                WHERE h.almacen_codigo IN (:history_warehouse_one, :history_warehouse_two)
                  AND h.fecha_venta >= :history_start
                  AND h.fecha_venta <= :history_cutoff
                  AND h.requiere_revision = 0
                UNION ALL
                SELECT v.archivo_hash, v.hoja, v.fila_origen, v.codigo, v.marca, v.medida,
                       v.fecha_venta, v.cantidad
                FROM ventas_excel_importadas v
                WHERE v.almacen_codigo IN (
                    :import_warehouse_one, :import_warehouse_two, :import_warehouse_report
                )
                  AND v.fecha_venta >= :import_start
                  AND v.fecha_venta <= :import_cutoff
                  AND (v.requiere_revision = 0 OR v.almacen_codigo = :report_review_warehouse)
                  AND NOT EXISTS (
                    SELECT 1
                    FROM historico_ventas_excel existing
                    WHERE existing.archivo_hash = v.archivo_hash
                      AND existing.hoja = v.hoja
                      AND existing.fila_origen = v.fila_origen
                  )
             ) sales
             WHERE TRIM(sales.marca) <> '' AND TRIM(sales.medida) <> ''
             GROUP BY sales.codigo, TRIM(sales.marca), TRIM(sales.medida), sales.fecha_venta
             ORDER BY sales.fecha_venta, sales.codigo, sales.marca, sales.medida"
        );
        $statement->execute([
            'history_warehouse_one' => self::WAREHOUSES[0],
            'history_warehouse_two' => self::WAREHOUSES[1],
            'history_start' => self::HISTORY_START,
            'history_cutoff' => $cutoffDate->format('Y-m-d'),
            'import_warehouse_one' => self::WAREHOUSES[0],
            'import_warehouse_two' => self::WAREHOUSES[1],
            'import_warehouse_report' => 'REPORTE',
            'import_start' => self::HISTORY_START,
            'import_cutoff' => $cutoffDate->format('Y-m-d'),
            'report_review_warehouse' => 'REPORTE',
        ]);

        return $statement->fetchAll();
    }

    private function forecastPeriod(
        DateTimeImmutable $lastSaleDate,
        ?DateTimeImmutable $today = null
    ): array
    {
        $today ??= new DateTimeImmutable('today');
        $cutoffDate = $lastSaleDate > $today ? $lastSaleDate : $today;
        $daysSinceMonday = (int) $cutoffDate->format('N') - 1;
        $weekStart = $cutoffDate->modify('-' . $daysSinceMonday . ' days');
        $weekEnd = $weekStart->modify('+6 days');

        return [$weekStart, $weekEnd];
    }

    private function saveDailyProductForecast(array $forecast): void
    {
        $this->database->beginTransaction();
        try {
            $delete = $this->database->prepare(
                'DELETE FROM predicciones_ventas_productos_diarias WHERE archivo_hash = :file_hash'
            );
            $delete->execute(['file_hash' => $forecast['source_file_hash']]);
            $insert = $this->database->prepare(
                'INSERT INTO predicciones_ventas_productos_diarias
                 (archivo_hash, producto_hash, codigo, marca, medida, modelo, fecha_corte, fecha_objetivo, unidades_estimadas)
                 VALUES (:file_hash, :product_hash, :code, :brand, :measure, :model, :cutoff_date, :target_date, :units)
                 ON DUPLICATE KEY UPDATE
                   fecha_corte = VALUES(fecha_corte),
                   codigo = VALUES(codigo),
                   marca = VALUES(marca),
                   medida = VALUES(medida),
                   modelo = VALUES(modelo),
                   unidades_estimadas = VALUES(unidades_estimadas),
                   calculado_en = CURRENT_TIMESTAMP'
            );

            foreach ($forecast['product_forecast_rows'] as $productRow) {
                foreach ($forecast['forecast_dates'] as $index => $targetDate) {
                    $insert->execute([
                        'file_hash' => $forecast['source_file_hash'],
                        'product_hash' => $productRow['producto_hash'],
                        'code' => $productRow['codigo'],
                        'brand' => $productRow['marca'],
                        'measure' => $productRow['medida'],
                        'model' => $productRow['modelo'],
                        'cutoff_date' => $forecast['source_cutoff_date'],
                        'target_date' => $targetDate,
                        'units' => (int) ($productRow['daily_units'][$index] ?? 0),
                    ]);
                }
            }

            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function saveDailyBrandForecast(array $forecast): void
    {
        $this->database->beginTransaction();
        try {
            $delete = $this->database->prepare(
                'DELETE FROM predicciones_ventas_marcas_diarias WHERE archivo_hash = :file_hash'
            );
            $delete->execute(['file_hash' => $forecast['source_file_hash']]);
            $insert = $this->database->prepare(
                'INSERT INTO predicciones_ventas_marcas_diarias
                 (archivo_hash, marca, fecha_corte, fecha_objetivo, unidades_estimadas)
                 VALUES (:file_hash, :brand, :cutoff_date, :target_date, :units)
                 ON DUPLICATE KEY UPDATE
                   fecha_corte = VALUES(fecha_corte),
                   unidades_estimadas = VALUES(unidades_estimadas),
                   calculado_en = CURRENT_TIMESTAMP'
            );

            foreach ($forecast['brand_forecast_rows'] as $brandRow) {
                foreach ($forecast['forecast_dates'] as $index => $targetDate) {
                    $insert->execute([
                        'file_hash' => $forecast['source_file_hash'],
                        'brand' => $brandRow['marca'],
                        'cutoff_date' => $forecast['source_cutoff_date'],
                        'target_date' => $targetDate,
                        'units' => (int) ($brandRow['daily_units'][$index] ?? 0),
                    ]);
                }
            }

            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function loadDailyBrandSales(): array
    {
        $statement = $this->database->prepare(
            'SELECT TRIM(marca) AS marca, fecha_venta, SUM(cantidad) AS cantidad
             FROM historico_ventas_excel
             WHERE almacen_codigo IN (:warehouse_one, :warehouse_two)
               AND TRIM(marca) <> \'\'
               AND fecha_venta >= :start_date
               AND fecha_venta <= :as_of_date
             GROUP BY TRIM(marca), fecha_venta
             ORDER BY fecha_venta, TRIM(marca)'
        );
        $statement->execute([
            'warehouse_one' => self::WAREHOUSES[0],
            'warehouse_two' => self::WAREHOUSES[1],
            'start_date' => self::HISTORY_START,
            'as_of_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        ]);

        return $statement->fetchAll();
    }

    private function buildBusinessWeeks(array $dateIndexes, DateTimeImmutable $lastSaleDate): array
    {
        $weekStart = new DateTimeImmutable(self::HISTORY_START);
        $daysUntilMonday = ((int) $weekStart->format('N') - 1 + 7) % 7;
        if ($daysUntilMonday > 0) {
            $weekStart = $weekStart->modify('+' . (7 - $daysUntilMonday) . ' days');
        }

        $weeks = [];
        while ($weekStart->modify('+5 days') <= $lastSaleDate) {
            $startText = $weekStart->format('Y-m-d');
            $endText = $weekStart->modify('+5 days')->format('Y-m-d');
            $startIndex = $dateIndexes[$startText] ?? null;
            if ($startIndex !== null && isset($dateIndexes[$endText])) {
                $weeks[] = [
                    'start' => $startText,
                    'end' => $endText,
                    'daily_start_index' => $startIndex,
                ];
            }
            $weekStart = $weekStart->modify('+7 days');
        }

        return $weeks;
    }

    private function buildWeeklyBrandSeries(array $dailyBrandSeries, array $businessWeeks): array
    {
        $weeklySeries = [];
        foreach ($dailyBrandSeries as $brand => $dailySeries) {
            $weeklySeries[$brand] = [];
            foreach ($businessWeeks as $week) {
                $units = 0.0;
                for ($day = 0; $day < 6; $day++) {
                    $units += (float) ($dailySeries[$week['daily_start_index'] + $day] ?? 0.0);
                }
                $weeklySeries[$brand][] = $units;
            }
        }

        return $weeklySeries;
    }

    private function buildBacktestWeekIndexes(int $weekCount): array
    {
        $totalWeeks = self::WARMUP_WEEKS + self::VALIDATION_WEEKS + self::HOLDOUT_WEEKS;
        $firstWeekIndex = $weekCount - $totalWeeks;
        if ($firstWeekIndex < 0) {
            throw new RuntimeException('Se necesita mas historial para ejecutar la prueba temporal semanal.');
        }

        return range($firstWeekIndex, $weekCount - 1);
    }

    private function selectBrandMethods(array $weeklySeriesByBrand, array $validationIndexes): array
    {
        $validationActual = 0.0;
        foreach ($weeklySeriesByBrand as $series) {
            foreach ($validationIndexes as $weekIndex) {
                $validationActual += (float) ($series[$weekIndex] ?? 0.0);
            }
        }

        $bestMethod = self::METHODS[0];
        $bestWape = INF;
        if ($validationActual > 0.0) {
            foreach (self::METHODS as $method) {
                $absoluteError = 0.0;
                foreach ($weeklySeriesByBrand as $series) {
                    foreach ($validationIndexes as $weekIndex) {
                        $history = array_slice($series, 0, $weekIndex);
                        $predicted = $this->predictWeeklyDemand($history, $method);
                        $absoluteError += abs((float) ($series[$weekIndex] ?? 0.0) - $predicted);
                    }
                }
                $wape = $absoluteError / $validationActual;
                if ($wape < $bestWape) {
                    $bestWape = $wape;
                    $bestMethod = $method;
                }
            }
        }

        return array_fill_keys(array_keys($weeklySeriesByBrand), $bestMethod);
    }

    private function sumUnitsByBrand(array $weeklySeriesByBrand, array $weekIndexes): array
    {
        $totals = array_fill_keys(array_keys($weeklySeriesByBrand), 0.0);
        foreach ($weeklySeriesByBrand as $brand => $series) {
            foreach ($weekIndexes as $weekIndex) {
                $totals[$brand] += (float) ($series[$weekIndex] ?? 0.0);
            }
        }

        return $totals;
    }

    private function evaluateBrandThresholds(
        array $weeklySeriesByBrand,
        array $weekIndexes,
        array $businessWeeks,
        array $brandMethods,
        array $validationUnits
    ): array {
        $options = [];
        foreach (self::BRAND_ACTIVITY_THRESHOLDS as $threshold) {
            $includedBrands = array_fill_keys(array_keys(array_filter(
                $validationUnits,
                static fn (float $units): bool => $units >= $threshold
            )), true);
            if ($includedBrands === []) {
                continue;
            }
            $validation = $this->evaluateWeeklyForecast(
                $weeklySeriesByBrand,
                $weekIndexes,
                $businessWeeks,
                $brandMethods,
                $includedBrands
            );
            $weeksBelowTarget = count(array_filter(
                $validation['weekly_wapes'],
                static fn (?float $wape): bool => $wape !== null && $wape <= self::WAPE_TARGET
            ));
            $options[] = [
                'units_threshold' => $threshold,
                'brands_included' => count($includedBrands),
                'validation_wape' => $validation['wape'],
                'weeks_below_target' => $weeksBelowTarget,
                'meets_validation_target' => $weeksBelowTarget / count($validation['weekly_wapes'])
                    >= self::MINIMUM_PASSING_WEEK_SHARE,
                'included_brands' => $includedBrands,
            ];
        }

        return $options;
    }

    private function selectBrandThreshold(array $options): array
    {
        if ($options === []) {
            throw new RuntimeException('No se pudo calcular un umbral de actividad para las marcas.');
        }
        $passingOptions = array_values(array_filter(
            $options,
            static fn (array $option): bool => $option['meets_validation_target']
        ));
        $candidates = $passingOptions !== [] ? $passingOptions : $options;
        usort($candidates, static function (array $left, array $right) use ($passingOptions): int {
            if ($passingOptions !== []) {
                return ($right['brands_included'] <=> $left['brands_included'])
                    ?: ($left['validation_wape'] <=> $right['validation_wape']);
            }
            return $left['validation_wape'] <=> $right['validation_wape'];
        });

        return $candidates[0];
    }

    private function evaluateWeeklyForecast(
        array $weeklySeriesByBrand,
        array $weekIndexes,
        array $businessWeeks,
        array $brandMethods,
        ?array $includedBrands = null,
        bool $aggregateNetwork = false
    ): array {
        $totalAbsoluteError = 0.0;
        $totalActual = 0.0;
        $weeklyWapes = [];
        $weeklyDetails = [];
        foreach ($weekIndexes as $weekNumber => $weekIndex) {
            $weekError = 0.0;
            $weekActual = 0.0;
            $weekPredicted = 0.0;
            foreach ($weeklySeriesByBrand as $brand => $series) {
                if ($includedBrands !== null && !isset($includedBrands[$brand])) {
                    continue;
                }
                $actual = (float) ($series[$weekIndex] ?? 0.0);
                $history = array_slice($series, 0, $weekIndex);
                $predicted = $this->predictWeeklyDemand($history, $brandMethods[$brand]);
                $weekActual += $actual;
                $weekPredicted += $predicted;
                if (!$aggregateNetwork) {
                    $weekError += abs($actual - $predicted);
                }
            }
            if ($aggregateNetwork) {
                $weekError = abs($weekActual - $weekPredicted);
            }
            $wape = $weekActual > 0.0 ? ($weekError / $weekActual) * 100 : null;
            $totalAbsoluteError += $weekError;
            $totalActual += $weekActual;
            $weeklyWapes[] = $wape;
            $weeklyDetails[] = [
                'week' => $weekNumber + 1,
                'start' => $businessWeeks[$weekIndex]['start'],
                'end' => $businessWeeks[$weekIndex]['end'],
                'actual' => $weekActual,
                'estimated' => $weekPredicted,
                'wape' => $wape,
            ];
        }

        return [
            'wape' => $totalActual > 0.0 ? ($totalAbsoluteError / $totalActual) * 100 : null,
            'weekly_wapes' => $weeklyWapes,
            'weekly_details' => $weeklyDetails,
        ];
    }

    private function buildForecastWeek(
        DateTimeImmutable $weekStart,
        int $targetWeekIndex,
        array $weeklySeriesByBrand,
        array $dailySeriesByBrand,
        array $businessWeeks,
        array $brandMethods,
        array $frequentBrands,
        ?int $actualWeekIndex = null
    ): array {
        $brandRows = [];
        $weekTotal = 0.0;
        $actualTotal = 0.0;
        $absoluteErrorTotal = 0.0;
        foreach ($weeklySeriesByBrand as $brand => $series) {
            $history = array_slice($series, 0, $targetWeekIndex);
            $units = (float) round($this->predictWeeklyDemand($history, $brandMethods[$brand]));
            $actualUnits = $actualWeekIndex === null
                ? null
                : (float) ($series[$actualWeekIndex] ?? 0.0);
            $differenceUnits = $actualUnits === null ? null : $units - $actualUnits;
            $weekTotal += $units;
            if ($actualUnits !== null) {
                $actualTotal += $actualUnits;
                $absoluteErrorTotal += abs($differenceUnits);
            }
            $brandRows[] = [
                'marca' => $brand,
                'recent_units' => array_sum(array_slice($history, -4)),
                'frequent' => isset($frequentBrands[$brand]),
                'method' => $brandMethods[$brand],
                'method_label' => $this->methodLabel($brandMethods[$brand]),
                'units' => $units,
                'actual_units' => $actualUnits,
                'difference_units' => $differenceUnits,
                'daily_units' => $this->distributeWeeklyForecast(
                    $units,
                    $brand,
                    $dailySeriesByBrand,
                    $businessWeeks,
                    $targetWeekIndex
                ),
            ];
        }
        usort(
            $brandRows,
            static fn (array $left, array $right): int => $right['units'] <=> $left['units']
        );
        $weekEnd = $weekStart->modify('+5 days');

        return [
            'start_date' => $weekStart->format('Y-m-d'),
            'end_date' => $weekEnd->format('Y-m-d'),
            'label' => $weekStart->format('d/m') . ' al ' . $weekEnd->format('d/m'),
            'total_units' => $weekTotal,
            'actual_units' => $actualWeekIndex === null ? null : $actualTotal,
            'difference_units' => $actualWeekIndex === null ? null : $weekTotal - $actualTotal,
            'absolute_error_units' => $actualWeekIndex === null ? null : $absoluteErrorTotal,
            'error_percent' => $actualWeekIndex === null || $actualTotal <= 0.0
                ? null
                : ($absoluteErrorTotal / $actualTotal) * 100,
            'brand_forecast_rows' => $brandRows,
        ];
    }

    private function buildHistoricalForecastWeeks(
        array $weeklySeriesByBrand,
        array $dailySeriesByBrand,
        array $businessWeeks,
        array $frequentBrands,
        DateTimeImmutable $currentWeekStart
    ): array {
        $historicalWeeks = [];
        $firstForecastIndex = self::WARMUP_WEEKS + self::VALIDATION_WEEKS;
        for ($targetWeekIndex = $firstForecastIndex, $weekCount = count($businessWeeks);
            $targetWeekIndex < $weekCount;
            $targetWeekIndex++
        ) {
            $targetWeek = $businessWeeks[$targetWeekIndex];
            $weekStart = new DateTimeImmutable($targetWeek['start']);
            if ($weekStart >= $currentWeekStart) {
                continue;
            }

            $validationIndexes = range(
                $targetWeekIndex - self::VALIDATION_WEEKS,
                $targetWeekIndex - 1
            );
            $brandMethods = $this->selectBrandMethods($weeklySeriesByBrand, $validationIndexes);
            $historicalWeeks[] = $this->buildForecastWeek(
                $weekStart,
                $targetWeekIndex,
                $weeklySeriesByBrand,
                $dailySeriesByBrand,
                $businessWeeks,
                $brandMethods,
                $frequentBrands,
                $targetWeekIndex
            );
        }

        return array_reverse($historicalWeeks);
    }

    private function distributeWeeklyForecast(
        float $weeklyUnits,
        string $brand,
        array $dailySeriesByBrand,
        array $businessWeeks,
        int $targetWeekIndex
    ): array {
        $weekdayUnits = array_fill(0, 6, 0.0);
        $firstHistoryWeek = max(0, $targetWeekIndex - 4);
        for ($weekIndex = $firstHistoryWeek; $weekIndex < $targetWeekIndex; $weekIndex++) {
            $dailyStartIndex = $businessWeeks[$weekIndex]['daily_start_index'];
            for ($weekday = 0; $weekday < 6; $weekday++) {
                $weekdayUnits[$weekday] += (float) (
                    $dailySeriesByBrand[$brand][$dailyStartIndex + $weekday] ?? 0.0
                );
            }
        }

        $weekdayTotal = array_sum($weekdayUnits);
        if ($weekdayTotal <= 0.0) {
            $shares = array_fill(0, 6, 1 / 6);
        } else {
            $shares = array_map(
                static fn (float $units): float => $units / $weekdayTotal,
                $weekdayUnits
            );
        }

        $wholeWeekUnits = (int) round($weeklyUnits);
        $dailyForecast = [];
        $remainders = [];
        $allocated = 0;
        foreach ($shares as $weekday => $share) {
            $exactUnits = $wholeWeekUnits * $share;
            $dailyForecast[$weekday] = (int) floor($exactUnits);
            $allocated += $dailyForecast[$weekday];
            $remainders[$weekday] = $exactUnits - $dailyForecast[$weekday];
        }
        arsort($remainders, SORT_NUMERIC);
        $leftoverUnits = $wholeWeekUnits - $allocated;
        foreach (array_keys($remainders) as $weekday) {
            if ($leftoverUnits <= 0) {
                break;
            }
            $dailyForecast[$weekday]++;
            $leftoverUnits--;
        }

        return array_values($dailyForecast);
    }

    private function predictWeeklyDemand(array $history, string $method): float
    {
        if ($method === 'median_4') {
            $recent = array_slice($history, -4);
            if ($recent === []) {
                return 0.0;
            }
            sort($recent, SORT_NUMERIC);
            $middle = intdiv(count($recent), 2);
            return count($recent) % 2 === 0
                ? ((float) $recent[$middle - 1] + (float) $recent[$middle]) / 2
                : (float) $recent[$middle];
        }
        if ($method === 'croston_sba') {
            return $this->crostonSbaForecast($history);
        }
        if ($method === 'tsb') {
            $state = $this->tsbState($history);
            return $state === null ? 0.0 : max(0.0, $state[0] * $state[1]);
        }

        throw new RuntimeException('Metodo de pronostico semanal no valido.');
    }

    private function crostonSbaForecast(array $history): float
    {
        $firstDemandIndex = null;
        foreach ($history as $index => $units) {
            if ((float) $units > 0.0) {
                $firstDemandIndex = $index;
                break;
            }
        }
        if ($firstDemandIndex === null) {
            return 0.0;
        }

        $alpha = 0.1;
        $demandSize = (float) $history[$firstDemandIndex];
        $interval = (float) ($firstDemandIndex + 1);
        $periodsSinceDemand = 1;
        for ($index = $firstDemandIndex + 1, $length = count($history); $index < $length; $index++) {
            $units = (float) $history[$index];
            if ($units > 0.0) {
                $demandSize += $alpha * ($units - $demandSize);
                $interval += $alpha * ($periodsSinceDemand - $interval);
                $periodsSinceDemand = 1;
            } else {
                $periodsSinceDemand++;
            }
        }

        return max(0.0, (1 - ($alpha / 2)) * $demandSize / $interval);
    }

    private function tsbState(array $history): ?array
    {
        $firstDemandIndex = null;
        foreach ($history as $index => $units) {
            if ((float) $units > 0.0) {
                $firstDemandIndex = $index;
                break;
            }
        }
        if ($firstDemandIndex === null) {
            return null;
        }

        $demandSize = (float) $history[$firstDemandIndex];
        $probability = 1 / ($firstDemandIndex + 1);
        for ($index = $firstDemandIndex + 1, $length = count($history); $index < $length; $index++) {
            $units = (float) $history[$index];
            $occurred = $units > 0.0 ? 1.0 : 0.0;
            $probability += 0.1 * ($occurred - $probability);
            if ($units > 0.0) {
                $demandSize += 0.1 * ($units - $demandSize);
            }
        }

        return [max(0.0, $demandSize), max(0.0, $probability)];
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'median_4' => 'Mediana 4 semanas',
            'croston_sba' => 'Croston SBA',
            'tsb' => 'TSB',
            default => throw new RuntimeException('Metodo de pronostico no valido.'),
        };
    }
}

final class Code39Barcode
{
    private const PATTERNS = [
        '0' => 0x034, '1' => 0x121, '2' => 0x061, '3' => 0x160, '4' => 0x031,
        '5' => 0x130, '6' => 0x070, '7' => 0x025, '8' => 0x124, '9' => 0x064,
        'A' => 0x109, 'B' => 0x049, 'C' => 0x148, 'D' => 0x019, 'E' => 0x118,
        'F' => 0x058, 'G' => 0x00D, 'H' => 0x10C, 'I' => 0x04C, 'J' => 0x01C,
        'K' => 0x103, 'L' => 0x043, 'M' => 0x142, 'N' => 0x013, 'O' => 0x112,
        'P' => 0x052, 'Q' => 0x007, 'R' => 0x106, 'S' => 0x046, 'T' => 0x016,
        'U' => 0x181, 'V' => 0x0C1, 'W' => 0x1C0, 'X' => 0x091, 'Y' => 0x190,
        'Z' => 0x0D0, '-' => 0x0A8, '.' => 0x168, ' ' => 0x0C8, '$' => 0x0A2,
        '/' => 0x0A4, '+' => 0x08A, '%' => 0x02A, '*' => 0x094,
    ];

    public static function svg(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Z0-9 .\\-$\\/+%]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('El código contiene caracteres no admitidos por Code 39.');
        }

        $characters = '*' . $value . '*';
        $narrow = 1;
        $quietZone = 10;
        $width = $quietZone * 2 + strlen($characters);
        foreach (str_split($characters) as $character) {
            $pattern = self::PATTERNS[$character];
            for ($position = 0; $position < 9; $position++) {
                $width += (($pattern >> (8 - $position)) & 1) === 1 ? 3 : 1;
            }
        }

        $x = $quietZone;
        $rectangles = [];
        foreach (str_split($characters) as $character) {
            $pattern = self::PATTERNS[$character];
            for ($position = 0; $position < 9; $position++) {
                $elementWidth = (($pattern >> (8 - $position)) & 1) === 1 ? 3 : 1;
                if ($position % 2 === 0) {
                    $rectangles[] = '<rect x="' . $x . '" y="0" width="' . $elementWidth . '" height="50"/>';
                }
                $x += $elementWidth;
            }
            $x += $narrow;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" class="barcode-svg" viewBox="0 0 '
            . $width . ' 50" role="img" aria-label="Código de barras '
            . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<g fill="#111">' . implode('', $rectangles) . '</g></svg>';
    }
}

final class InventoryMobileUrl
{
    public static function forCurrentApp(array $parameters): ?string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (preg_match('/\A(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]{1,5})?\z/iD', $host) !== 1) {
            $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        }
        if (preg_match('/\A(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]{1,5})?\z/iD', $host) !== 1) {
            return null;
        }

        $nombreHost = strtolower((string) parse_url('http://' . $host, PHP_URL_HOST));
        if (in_array($nombreHost, ['localhost', '127.0.0.1', '[::1]'], true)) {
            $direccionIp = (string) ($_SERVER['SERVER_ADDR'] ?? '');
            if (filter_var($direccionIp, FILTER_VALIDATE_IP) === false
                || in_array($direccionIp, ['127.0.0.1', '::1', '0.0.0.0'], true)
            ) {
                $direccionIp = gethostbyname(gethostname());
            }
            if (filter_var($direccionIp, FILTER_VALIDATE_IP) === false
                || in_array($direccionIp, ['127.0.0.1', '::1', '0.0.0.0'], true)
            ) {
                return null;
            }

            $puerto = parse_url('http://' . $host, PHP_URL_PORT);
            $host = filter_var($direccionIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . $direccionIp . ']'
                : $direccionIp;
            if ($puerto !== null) {
                $host .= ':' . $puerto;
            }
            $nombreHost = strtolower((string) parse_url('http://' . $host, PHP_URL_HOST));
        }

        if ($nombreHost === '26.109.34.132') {
            $direccionWifi = getenv('SIVA_QR_WIFI_HOST') ?: '192.168.18.54';
            if (filter_var($direccionWifi, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return null;
            }
            $puerto = parse_url('http://' . $host, PHP_URL_PORT);
            $host = $direccionWifi . ($puerto === null ? '' : ':' . $puerto);
            $nombreHost = $direccionWifi;
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        if (!str_starts_with($script, '/') || str_contains($script, "\r") || str_contains($script, "\n")) {
            return null;
        }

        $protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';

        return $protocol . '://' . $host . $script . '?' . http_build_query($parameters);
    }
}

final class InventoryQrCode
{
    private static function codigoBarrasValido(string $value, int $longitudMaxima): bool
    {
        return strlen($value) <= $longitudMaxima
            && preg_match('/\A[\x20-\x7E]+\z/D', $value) === 1;
    }

    public static function svg(string $value): string
    {
        $codigoBarrasValido = self::codigoBarrasValido($value, 80);
        $componentesUrl = parse_url($value);
        $parametrosUrl = [];
        $urlEscaneoValida = false;
        if (strlen($value) <= 250 && is_array($componentesUrl)
            && in_array($componentesUrl['scheme'] ?? '', ['http', 'https'], true)
            && isset($componentesUrl['host']) && $componentesUrl['host'] !== ''
            && str_ends_with((string) ($componentesUrl['path'] ?? ''), '/index.php')
            && !isset($componentesUrl['user']) && !isset($componentesUrl['pass'])
            && !isset($componentesUrl['fragment'])
        ) {
            parse_str((string) ($componentesUrl['query'] ?? ''), $parametrosUrl);
            $urlConteoFisicoValida = ($parametrosUrl['pagina'] ?? '') === 'inventario'
                && ($parametrosUrl['view'] ?? '') === 'count'
                && ($parametrosUrl['count_scan'] ?? '') === '1'
                && in_array($parametrosUrl['warehouse'] ?? '', ['A0101', 'A0102', 'A0103'], true)
                && self::codigoBarrasValido((string) ($parametrosUrl['barcode'] ?? ''), 80);
            $urlEscaneoAnteriorValida = ($parametrosUrl['pagina'] ?? '') === 'inventario'
                && ($parametrosUrl['scan'] ?? '') === '1'
                && in_array($parametrosUrl['warehouse'] ?? '', ['A0101', 'A0102', 'A0103'], true)
                && self::codigoBarrasValido((string) ($parametrosUrl['barcode'] ?? ''), 80);
            $urlVentaProductoValida = ($parametrosUrl['pagina'] ?? '') === 'ventas'
                && ($parametrosUrl['view'] ?? '') === 'new'
                && self::codigoBarrasValido((string) ($parametrosUrl['scan_sku'] ?? ''), 40);
            $urlEscaneoValida = $urlConteoFisicoValida || $urlEscaneoAnteriorValida || $urlVentaProductoValida;
        }
        if (!$codigoBarrasValido && !$urlEscaneoValida) {
            throw new InvalidArgumentException('El código contiene caracteres no válidos para generar el QR.');
        }

        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('No se encontró la biblioteca QR. Ejecuta Composer install antes de publicar.');
        }
        require_once $autoload;
        $options = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
            'imageBase64' => false,
            'scale' => 8,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M,
        ]);
        $svg = (new \chillerlan\QRCode\QRCode($options))->render($value);
        if (!is_string($svg) || !str_contains($svg, '<svg')) {
            throw new RuntimeException('La biblioteca QR devolvió un SVG inválido.');
        }

        $svg = preg_replace_callback(
            '/<svg(?=\s|>)([^>]*)>/',
            static function (array $matches): string {
                $attributes = $matches[1];
                if (preg_match('/\bclass="([^"]*)"/', $attributes, $classMatch) === 1) {
                    $attributes = preg_replace(
                        '/\bclass="[^"]*"/',
                        'class="' . trim($classMatch[1] . ' qr-code-svg') . '"',
                        $attributes,
                        1
                    ) ?? $attributes;
                } else {
                    $attributes = ' class="qr-code-svg"' . $attributes;
                }
                $attributes .= ' shape-rendering="crispEdges"';

                return '<svg' . $attributes . '>';
            },
            $svg,
            1,
            $matches
        );
        if (!is_string($svg) || $matches !== 1) {
            throw new RuntimeException('El generador QR devolvió un SVG inválido.');
        }

        $svg = preg_replace('/<style>[^<]*<\/style>/', '', $svg, 1) ?? $svg;
        $svg = preg_replace('/\sstyle="[^"]*"/', '', $svg, 1) ?? $svg;

        return $svg;
    }
}

final class HistoricalSalesExcelReader
{
    public function read(string $path): array
    {
        return (new \NativeHistoricalSalesExcelReader())->read($path);
    }
}

final class DuplicateHistoricalSalesFileException extends RuntimeException
{
}

final class HistoricalSalesImportService
{
    private const MAX_FILE_BYTES = 15_000_000;
    private const DUPLICATE_FILE_MESSAGE = 'Este Excel ya se subió. Por favor, no lo vuelvas a subir.';

    public function __construct(private PDO $database)
    {
    }

    public function prepare(
        string $path,
        string $originalName,
        string $reportWarehouse
    ): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('El archivo no existe o supera el límite de 15 MB.');
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('El archivo debe ser un libro Excel .xlsx.');
        }

        $fileHash = hash_file('sha256', $path);
        if (!is_string($fileHash)) {
            throw new RuntimeException('No se pudo verificar el archivo Excel.');
        }

        if ($this->fileAlreadyImported($fileHash)) {
            throw new DuplicateHistoricalSalesFileException(self::DUPLICATE_FILE_MESSAGE);
        }

        $workbook = (new HistoricalSalesExcelReader())->read($path);
        if (count($workbook['rows']) > 50_000) {
            throw new RuntimeException('El archivo supera el límite de 50,000 filas.');
        }

        $reportFormat = ($workbook['format'] ?? 'standard') === 'brand_report';
        $reportWarehouse = strtoupper(trim($reportWarehouse));
        if ($reportFormat && $reportWarehouse !== '' && !in_array($reportWarehouse, ['A0101', 'A0102'], true)) {
            throw new RuntimeException('El almacén seleccionado para el reporte no es válido.');
        }

        $salesRows = [];
        $months = [];
        $saleDates = [];
        $excludedWarehouseRows = 0;
        foreach ($workbook['rows'] as $sourceRow) {
            $values = $sourceRow['values'] ?? [];
            if (count($values) !== 7) {
                throw new RuntimeException('Una fila no contiene las siete columnas esperadas.');
            }

            [$code, $description, $dateValue, $measure, $model, $warehouse, $quantityValue] = $values;
            $warehouse = $reportFormat
                ? ($reportWarehouse !== '' ? $reportWarehouse : 'REPORTE')
                : strtoupper(trim((string) $warehouse));
            if (!$reportFormat && !in_array($warehouse, ['A0101', 'A0102'], true)) {
                $excludedWarehouseRows++;
                continue;
            }

            $code = trim((string) $code);
            $description = trim((string) $description);
            $measure = trim((string) $measure);
            $model = trim((string) ($sourceRow['model'] ?? $model));
            $dateText = substr(trim((string) $dateValue), 0, 10);
            $saleDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                (!$reportFormat && $code === '')
                || text_length($code) > 191
                || $description === ''
                || $measure === ''
                || text_length($measure) > 120
                || text_length($model) > 120
                || !$saleDate
                || $saleDate->format('Y-m-d') !== $dateText
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            ) {
                throw new RuntimeException(
                    'El Excel contiene código, descripción, medida o fecha inválidos en la fila '
                    . (int) ($sourceRow['row'] ?? 0) . '.'
                );
            }

            $quantity = filter_var($quantityValue, FILTER_VALIDATE_INT);
            if ($quantity === false && is_numeric($quantityValue)) {
                $numericQuantity = (float) $quantityValue;
                if (is_finite($numericQuantity) && floor($numericQuantity) === $numericQuantity) {
                    $quantity = (int) $numericQuantity;
                }
            }

            if ($quantity === false || $quantity === 0) {
                throw new RuntimeException(
                    'El Excel contiene una cantidad vacía, cero o no entera en la fila '
                    . (int) ($sourceRow['row'] ?? 0) . '.'
                );
            }

            $brandSource = trim((string) ($sourceRow['brand'] ?? $description));
            $brandParts = preg_split('/\s+/u', $brandSource, 2);
            $brand = trim((string) ($brandParts[0] ?? ''));
            if ($brand === '' || text_length($brand) > 120) {
                throw new RuntimeException(
                    'No se pudo obtener la marca desde la descripción de la fila '
                    . (int) ($sourceRow['row'] ?? 0) . '.'
                );
            }

            $sheet = trim((string) ($sourceRow['sheet'] ?? ''));
            $sourceNumber = filter_var($sourceRow['row'] ?? null, FILTER_VALIDATE_INT);
            if ($sheet === '' || text_length($sheet) > 64 || $sourceNumber === false || $sourceNumber < 1) {
                throw new RuntimeException('El archivo contiene una hoja o fila de origen inválida.');
            }
            if ($reportFormat) {
                $code = 'SIN-SKU-' . substr($fileHash, 0, 12) . '-' . $sourceNumber;
            }

            $months[$saleDate->format('Y-m')] = true;
            $saleDates[] = $dateText;
            $salesRows[] = [
                'sheet' => $sheet,
                'source_row' => $sourceNumber,
                'code' => $code,
                'brand' => $brand,
                'measure' => $measure,
                'model' => $model,
                'warehouse' => $warehouse,
                'date' => $dateText,
                'quantity' => $quantity,
                'requires_review' => $reportFormat ? 1 : 0,
            ];
        }

        if ($salesRows === []) {
            throw new RuntimeException('No hay ventas válidas para generar el pronóstico en el archivo.');
        }
        if (count($months) !== 1) {
            throw new RuntimeException('Sube un archivo con las ventas de un solo mes para evitar mezclar periodos.');
        }

        $month = (string) array_key_first($months);
        $monthStart = $month . '-01';
        $nextMonthStart = (new DateTimeImmutable($monthStart))
            ->modify('first day of next month')
            ->format('Y-m-d');
        $monthRows = $this->database->prepare(
            "SELECT COUNT(*) FROM ventas s
             WHERE s.almacen_codigo IN (:sales_warehouse_one, :sales_warehouse_two)
               AND s.fecha_venta >= :sales_month_start AND s.fecha_venta < :sales_next_month_start
               AND EXISTS (
                 SELECT 1 FROM detalle_ventas d
                 WHERE d.venta_id = s.id AND d.estado = 'activa'
               )"
        );
        $monthRows->execute([
            'sales_warehouse_one' => 'A0101',
            'sales_warehouse_two' => 'A0102',
            'sales_month_start' => $monthStart,
            'sales_next_month_start' => $nextMonthStart,
        ]);
        if ((int) $monthRows->fetchColumn() > 0) {
            throw new RuntimeException(
                'Ya registraste ventas de ' . $month . ' en Salidas / Ventas. '
                . 'La IA ya las usa; no importes también el Excel.'
            );
        }

        sort($saleDates);
        $overlapDates = array_values(array_unique($saleDates));
        $dateMarkers = [];
        $dateParameters = [];
        foreach ($overlapDates as $index => $date) {
            $marker = 'overlap_date_' . $index;
            $dateMarkers[] = ':' . $marker;
            $dateParameters[$marker] = $date;
        }
        $warehouses = array_values(array_unique(array_column($salesRows, 'warehouse')));
        $warehouseMarkers = [];
        $warehouseParameters = [];
        foreach ($warehouses as $index => $warehouse) {
            $marker = 'overlap_warehouse_' . $index;
            $warehouseMarkers[] = ':' . $marker;
            $warehouseParameters[$marker] = $warehouse;
        }
        $overlapQuery = $this->database->prepare(
            'SELECT COUNT(*) AS row_count, COUNT(DISTINCT archivo_hash) AS file_count,
                    COUNT(DISTINCT fecha_venta) AS date_count,
                    MIN(fecha_venta) AS first_overlap_date, MAX(fecha_venta) AS last_overlap_date
             FROM ventas_excel_importadas
             WHERE fecha_venta IN (' . implode(', ', $dateMarkers) . ')
               AND almacen_codigo IN (' . implode(', ', $warehouseMarkers) . ')'
        );
        $overlapQuery->execute(array_merge($dateParameters, $warehouseParameters));
        $overlapRow = $overlapQuery->fetch();
        $overlap = [
            'row_count' => (int) ($overlapRow['row_count'] ?? 0),
            'file_count' => (int) ($overlapRow['file_count'] ?? 0),
            'date_count' => (int) ($overlapRow['date_count'] ?? 0),
            'first_date' => $overlapRow['first_overlap_date'] ?? null,
            'last_date' => $overlapRow['last_overlap_date'] ?? null,
        ];
        [$forecastStart, $forecastEnd] = (new BrandSalesForecastService($this->database))
            ->forecastPeriodForSalesDate(new DateTimeImmutable($saleDates[array_key_last($saleDates)]));

        return [
            'file_hash' => $fileHash,
            'original_name' => basename(str_replace('\\', '/', $originalName)),
            'sales_rows' => $salesRows,
            'month' => $month,
            'first_sale_date' => $saleDates[0],
            'last_sale_date' => $saleDates[array_key_last($saleDates)],
            'forecast_start_date' => $forecastStart->format('Y-m-d'),
            'forecast_end_date' => $forecastEnd->format('Y-m-d'),
            'report_format' => $reportFormat,
            'report_warehouse' => $reportFormat && $reportWarehouse !== '' ? $reportWarehouse : null,
            'excluded_warehouse_rows' => $excludedWarehouseRows,
            'overlap' => $overlap,
        ];
    }

    public function persist(string $path, array $prepared, int $userId): void
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('No se pudo volver a leer el Excel para guardarlo.');
        }
        if (!hash_equals((string) $prepared['file_hash'], hash('sha256', $content))) {
            throw new RuntimeException('El archivo temporal cambió. Vuelve a seleccionarlo antes de confirmar.');
        }

        $this->database->beginTransaction();
        try {
            $archive = $this->database->prepare(
                'INSERT INTO historico_ventas_archivos
                 (archivo_hash, nombre_archivo, contenido, mes_ventas, fecha_corte, formato, almacen_origen, filas_importadas, importado_por)
                 VALUES
                 (:file_hash, :file_name, :content, :sales_month, :cutoff_date, :format, :warehouse, :row_count, :user_id)'
            );
            $archive->bindValue(':file_hash', $prepared['file_hash']);
            $archive->bindValue(':file_name', $prepared['original_name']);
            $archive->bindValue(':content', $content, PDO::PARAM_LOB);
            $archive->bindValue(':sales_month', $prepared['month'] . '-01');
            $archive->bindValue(':cutoff_date', $prepared['last_sale_date']);
            $archive->bindValue(':format', $prepared['report_format'] ? 'brand_report' : 'standard');
            $archive->bindValue(
                ':warehouse',
                $prepared['report_warehouse'],
                $prepared['report_warehouse'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $archive->bindValue(':row_count', count($prepared['sales_rows']), PDO::PARAM_INT);
            $archive->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $archive->execute();

            $insert = $this->database->prepare(
                'INSERT INTO ventas_excel_importadas
                 (archivo_hash, hoja, fila_origen, codigo, marca, medida, modelo, almacen_codigo, fecha_venta, cantidad, requiere_revision)
                 VALUES
                 (:file_hash, :sheet, :source_row, :code, :brand, :measure, :model, :warehouse, :sale_date, :quantity, :requires_review)'
            );
            foreach ($prepared['sales_rows'] as $saleRow) {
                $insert->execute([
                    'file_hash' => $prepared['file_hash'],
                    'sheet' => $saleRow['sheet'],
                    'source_row' => $saleRow['source_row'],
                    'code' => $saleRow['code'],
                    'brand' => $saleRow['brand'],
                    'measure' => $saleRow['measure'],
                    'model' => $saleRow['model'],
                    'warehouse' => $saleRow['warehouse'],
                    'sale_date' => $saleRow['date'],
                    'quantity' => $saleRow['quantity'],
                    'requires_review' => $saleRow['requires_review'],
                ]);
            }

            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($exception instanceof PDOException) {
                try {
                    if ($this->fileAlreadyImported((string) $prepared['file_hash'])) {
                        throw new DuplicateHistoricalSalesFileException(self::DUPLICATE_FILE_MESSAGE, 0, $exception);
                    }
                } catch (PDOException $verificationException) {
                    error_log('No se pudo verificar si el Excel ya estaba importado: ' . $verificationException->getMessage());
                }
            }
            throw $exception;
        }
    }

    private function fileAlreadyImported(string $fileHash): bool
    {
        $existingFile = $this->database->prepare(
            'SELECT 1 FROM historico_ventas_excel WHERE archivo_hash = :history_hash
             UNION ALL
             SELECT 1 FROM historico_ventas_archivos WHERE archivo_hash = :archive_hash
             UNION ALL
             SELECT 1 FROM ventas_excel_importadas WHERE archivo_hash = :import_hash
             LIMIT 1'
        );
        $existingFile->execute([
            'history_hash' => $fileHash,
            'archive_hash' => $fileHash,
            'import_hash' => $fileHash,
        ]);

        return $existingFile->fetchColumn() !== false;
    }
}

final class InventoryScanExcelExporter
{
    public function summarizePreview(array $scans, array $products): array
    {
        $warehouses = array_values(array_unique(array_column($scans, 'warehouse')));
        if (count($warehouses) !== 1) {
            throw new RuntimeException('El Excel debe contener escaneos de un solo almacén.');
        }

        $groups = [];
        foreach ($scans as $scan) {
            $key = (string) $scan['barcode'] . "\0" . (string) $scan['warehouse'];
            if (!isset($products[$key])) {
                throw new RuntimeException('No se encontró un producto para uno de los escaneos.');
            }
            if (!isset($groups[$key])) {
                $groups[$key] = $products[$key] + [
                    'warehouse' => (string) $scan['warehouse'],
                    'units' => 0,
                ];
            }
            $groups[$key]['units']++;
        }

        foreach ($groups as &$product) {
            $product['stock_after'] = $product['current_stock'] + $product['units'];
            $product['low_stock'] = $product['stock_after'] <= $product['minimum_stock'];
        }
        unset($product);

        return [
            'warehouse' => $warehouses[0],
            'units' => count($scans),
            'products' => array_values($groups),
            'stock_snapshot_hash' => $this->stockSnapshotHash($products),
        ];
    }

    public function stockSnapshotHash(array $products): string
    {
        $stocks = [];
        foreach ($products as $key => $product) {
            $stocks[(string) $key] = (int) $product['current_stock'];
        }
        ksort($stocks, SORT_STRING);

        return hash('sha256', serialize($stocks));
    }

    public function generate(array $scans): string
    {
        $grupos = [];
        foreach ($scans as $scan) {
            $clave = (string) $scan['barcode'] . "\0" . (string) $scan['warehouse'];
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'scan_ids' => [],
                    'barcode' => $scan['barcode'],
                    'excel_code' => $scan['excel_code'],
                    'name' => $scan['name'],
                    'brand' => $scan['brand'],
                    'measure' => $scan['measure'],
                    'warehouse' => $scan['warehouse'],
                    'quantity' => 0,
                ];
            }
            $grupos[$clave]['scan_ids'][] = (string) $scan['scan_id'];
            $grupos[$clave]['quantity']++;
        }

        $rows = [[
            'IDs escaneo',
            'Código de barras',
            'Código B4X01',
            'Producto',
            'Marca',
            'Medida',
            'Almacén',
            'Cantidad',
        ]];
        foreach ($grupos as $grupo) {
            $rows[] = [
                implode(',', $grupo['scan_ids']),
                $grupo['barcode'],
                $grupo['excel_code'],
                $grupo['name'],
                $grupo['brand'],
                $grupo['measure'],
                $grupo['warehouse'],
                $grupo['quantity'],
            ];
        }

        $sheetRows = '';
        foreach ($rows as $rowNumber => $row) {
            $sheetRows .= '<row r="' . ($rowNumber + 1) . '">';
            foreach (array_values($row) as $columnNumber => $value) {
                $cell = chr(ord('A') + $columnNumber) . ($rowNumber + 1);
                $text = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $sheetRows .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $text . '</t></is></c>';
            }
            $sheetRows .= '</row>';
        }

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Escaneos" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<dimension ref="A1:H' . count($rows) . '"/><sheetData>'
                . $sheetRows . '</sheetData></worksheet>',
        ];

        $path = '';
        try {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'inventario-escaneos-' . bin2hex(random_bytes(12)) . '.xlsx';
            $workbook = new PharData($path, 0, null, Phar::ZIP);
            foreach ($parts as $partPath => $contents) {
                $workbook->addFromString($partPath, $contents);
            }
            unset($workbook);

            $file = file_get_contents($path);
            if (!is_string($file)) {
                throw new RuntimeException('No se pudo leer el archivo Excel generado.');
            }

            return $file;
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo generar el Excel de escaneos.', 0, $exception);
        } finally {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }
}

final class InventoryReportExcelExporter
{
    public function generate(array $report): string
    {
        $stockRows = [[
            'Almacén',
            'Nombre del almacén',
            'SKU',
            'Producto',
            'Marca',
            'Medida',
            'Existencia operativa',
            'Stock mínimo',
            'Stock de seguridad',
            'Unidades en tránsito',
            'Unidades para llegar al mínimo',
            'Estado',
        ]];
        foreach ($report['stock_rows'] as $row) {
            $stockRows[] = [
                $row['warehouse_code'],
                $row['warehouse_name'],
                $row['sku'],
                $row['name'],
                $row['brand'],
                $row['size'],
                $row['quantity'],
                $row['minimum_stock'],
                $row['safety_stock'],
                $row['stock_in_transit'],
                $row['shortage_quantity'],
                $this->stockStatusLabel((string) $row['stock_status']),
            ];
        }

        $movementRows = [[
            'Fecha y hora',
            'Almacén',
            'Nombre del almacén',
            'SKU',
            'Producto',
            'Marca',
            'Medida',
            'Tipo de movimiento',
            'Variación de unidades',
            'Detalle / motivo',
            'Usuario',
        ]];
        foreach ($report['movements'] as $movement) {
            $movementRows[] = [
                $movement['created_at'],
                $movement['warehouse_code'],
                $movement['warehouse_name'],
                $movement['product_sku'],
                $movement['product_name'],
                $movement['product_brand'],
                $movement['product_measure'],
                $this->movementTypeLabel(
                    (string) $movement['movement_type'],
                    (string) ($movement['reference_type'] ?? '')
                ),
                $movement['quantity_change'],
                $movement['notes'],
                $movement['user_name'],
            ];
        }

        return $this->buildWorkbook([
            'Existencias' => $stockRows,
            'Movimientos' => $movementRows,
        ], 'inventario-reporte', 'No se pudo generar el Excel del reporte de inventario.');
    }

    public function generatePhysicalCount(array $report): string
    {
        $rows = [[
            'Fecha de conteo',
            'Hora',
            'Almacén',
            'Nombre del almacén',
            'SKU',
            'Producto',
            'Marca',
            'Medida',
            'Saldo anterior',
            'Unidades contadas',
            'Diferencia',
            'Motivo',
            'Usuario',
        ]];
        foreach ($report as $count) {
            $countedAt = new DateTimeImmutable((string) $count['counted_at']);
            $rows[] = [
                $countedAt->format('d/m/Y'),
                $countedAt->format('H:i:s'),
                $count['warehouse_code'],
                $count['warehouse_name'],
                $count['sku'],
                $count['product_name'],
                $count['brand'],
                $count['measure'],
                $count['previous_quantity'],
                $count['physical_quantity'],
                $count['difference'],
                $count['reason'],
                $count['user_name'],
            ];
        }

        return $this->buildWorkbook(
            ['Conteo físico' => $rows],
            'conteo-fisico-reporte',
            'No se pudo generar el Excel del reporte de conteo físico.',
            ['Conteo físico' => [14, 10, 12, 24, 16, 32, 18, 18, 15, 17, 13, 36, 24]]
        );
    }

    private function buildWorkbook(
        array $rowsBySheet,
        string $temporaryPrefix,
        string $errorMessage,
        array $columnWidthsBySheet = []
    ): string
    {
        $sheetParts = [];
        $sheetOverrides = '';
        $sheetRelationships = '';
        $sheetDefinitions = '';
        foreach ($rowsBySheet as $sheetName => $rows) {
            $sheetNumber = count($sheetParts) + 1;
            $sheetParts['xl/worksheets/sheet' . $sheetNumber . '.xml'] = $this->buildWorksheet(
                $rows,
                $columnWidthsBySheet[$sheetName] ?? []
            );
            $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetRelationships .= '<Relationship Id="rId' . $sheetNumber
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'
                . $sheetNumber . '.xml"/>';
            $sheetDefinitions .= '<sheet name="' . htmlspecialchars((string) $sheetName, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '" sheetId="' . $sheetNumber
                . '" r:id="rId' . $sheetNumber . '"/>';
        }

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . $sheetOverrides . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets>' . $sheetDefinitions . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $sheetRelationships . '</Relationships>',
        ] + $sheetParts;

        $path = '';
        try {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . $temporaryPrefix . '-' . bin2hex(random_bytes(12)) . '.xlsx';
            $workbook = new PharData($path, 0, null, Phar::ZIP);
            foreach ($parts as $partPath => $contents) {
                $workbook->addFromString($partPath, $contents);
            }
            unset($workbook);

            $file = file_get_contents($path);
            if (!is_string($file)) {
                throw new RuntimeException('No se pudo leer el archivo Excel generado.');
            }

            return $file;
        } catch (Throwable $exception) {
            throw new RuntimeException($errorMessage, 0, $exception);
        } finally {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }

    }

    private function buildWorksheet(array $rows, array $columnWidths = []): string
    {
        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $sheetRows .= '<row r="' . $rowNumber . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $this->columnName($columnIndex) . $rowNumber;
                $text = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $sheetRows .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $text . '</t></is></c>';
            }
            $sheetRows .= '</row>';
        }

        $lastColumn = $this->columnName(max(0, count($rows[0] ?? []) - 1));
        $columnDefinitions = '';
        foreach ($columnWidths as $index => $width) {
            $column = (int) $index + 1;
            $columnWidth = max(1, min(80, (int) $width));
            $columnDefinitions .= '<col min="' . $column . '" max="' . $column
                . '" width="' . $columnWidth . '" customWidth="1"/>';
        }
        $columns = $columnDefinitions === '' ? '' : '<cols>' . $columnDefinitions . '</cols>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastColumn . count($rows) . '"/>' . $columns . '<sheetData>'
            . $sheetRows . '</sheetData></worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        do {
            $name = chr($index % 26 + 65) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function stockStatusLabel(string $status): string
    {
        return match ($status) {
            'critical' => 'Crítico',
            'warning' => 'Por reponer',
            default => 'Saludable',
        };
    }

    private function movementTypeLabel(string $type, string $referenceType): string
    {
        if ($type === 'adjustment') {
            return match ($referenceType) {
                'purchase_cancel' => 'Anulación de compra',
                'purchase_edit' => 'Corrección de compra',
                'physical_count' => 'Ajuste de conteo',
                default => 'Ajuste',
            };
        }

        return match ($type) {
            'opening' => 'Stock inicial',
            'outgoing' => 'Salida / venta',
            'purchase' => 'Compra',
            default => $type,
        };
    }
}

final class InventoryPhysicalCountPdfExporter
{
    public function generate(array $report): string
    {
        $pages = [];
        $content = '';
        $y = 0;
        $warehouseName = (string) ($report[0]['warehouse_name'] ?? '');
        $this->startPage($pages, $content, $y, $warehouseName);
        $currentDate = null;

        foreach ($report as $count) {
            $difference = (int) $count['difference'];
            $countedAt = new DateTimeImmutable((string) $count['counted_at']);
            $dateLabel = $countedAt->format('d/m/Y');
            if ($dateLabel !== $currentDate) {
                if ($y < 56) {
                    $this->startPage($pages, $content, $y, $warehouseName);
                }
                $this->dateHeading($content, $y, 'FECHA ' . $dateLabel);
                $currentDate = $dateLabel;
            }

            $values = [
                $countedAt->format('H:i:s'),
                (string) $count['sku'],
                (string) $count['product_name'] . ' | ' . (string) $count['brand'] . ' | ' . (string) $count['measure'],
                (string) (int) $count['previous_quantity'],
                (string) (int) $count['physical_quantity'],
                ($difference > 0 ? '+' : '') . $difference,
                (string) $count['reason'],
                (string) $count['user_name'],
            ];

            $columnX = [28, 72, 152, 398, 465, 532, 590, 742];
            $columnLimits = [10, 16, 50, 8, 8, 8, 32, 18];
            $wrappedColumns = [];
            foreach ($values as $index => $value) {
                $wrappedColumns[] = $this->wrapText($value, $columnLimits[$index]);
            }
            $lineCount = max(array_map('count', $wrappedColumns));
            $rowHeight = $lineCount * 10 + 5;
            if ($y - $rowHeight < 34) {
                $this->startPage($pages, $content, $y, $warehouseName);
                $this->dateHeading($content, $y, 'FECHA ' . $dateLabel . ' (continúa)');
            }
            for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
                foreach ($wrappedColumns as $columnIndex => $columnLines) {
                    $text = (string) ($columnLines[$lineIndex] ?? '');
                    if ($text !== '') {
                        $content .= $this->textCommand($text, $columnX[$columnIndex], $y, 8);
                    }
                }
                $y -= 10;
            }
            $content .= 'q 0.35 w 24 ' . ($y + 3) . ' m 818 ' . ($y + 3) . " l S Q\n";
            $y -= 5;
        }

        $pages[] = $content;

        return $this->buildPdf($pages);
    }

    private function startPage(array &$pages, string &$content, int &$y, string $warehouseName): void
    {
        if ($content !== '') {
            $pages[] = $content;
        }
        $content = $this->textCommand('Reporte de conteo físico', 28, 565, 18, true)
            . $this->textCommand('Almacén: ' . $warehouseName, 28, 544, 10)
            . $this->textCommand('Generado: ' . date('d/m/Y H:i'), 28, 529, 9);
        $headings = [
            ['Hora', 28], ['SKU', 72], ['Producto / marca / medida', 152],
            ['Saldo anterior', 398], ['Contado', 465], ['Diferencia', 532],
            ['Motivo', 590], ['Usuario', 742],
        ];
        foreach ($headings as [$heading, $x]) {
            $fontSize = $heading === 'Saldo anterior' ? 7 : 8;
            $content .= $this->textCommand($heading, $x, 504, $fontSize, true);
        }
        $content .= 'q 0.65 w 24 496 m 818 496 l S Q' . "\n";
        $y = 481;
    }

    private function dateHeading(string &$content, int &$y, string $label): void
    {
        $content .= 'q 0.93 g 24 ' . ($y - 5) . ' 794 16 re f Q' . "\n";
        $content .= $this->textCommand($label, 29, $y, 9, true);
        $y -= 20;
    }

    private function wrapText(string $text, int $maximumCharacters): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || $words === []) {
            return [''];
        }

        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            $lineLength = function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : strlen($line);
            if ($line !== '' && $lineLength + 1 + $length > $maximumCharacters) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function textCommand(string $text, int $x, int $y, int $fontSize, bool $bold = false): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo codificar el texto del reporte PDF.');
        }
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

        return 'BT /F' . ($bold ? '2' : '1') . ' ' . $fontSize . ' Tf 1 0 0 1 '
            . $x . ' ' . $y . ' Tm (' . $escaped . ") Tj ET\n";
    }

    private function buildPdf(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pageReferences = [];
        foreach ($pages as $index => $content) {
            $pageId = 5 + $index * 2;
            $contentId = $pageId + 1;
            $pageReferences[] = $pageId . ' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n"
                . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences)
            . '] /Count ' . count($pages) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1)
            . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }
}
