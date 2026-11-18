<?php
declare(strict_types=1);

final class NativeHistoricalSalesExcelReader
{
    private const MAX_FILE_BYTES = 15_000_000;
    private const MAX_ROWS = 50_000;
    private const MAX_SHEETS = 20;
    private const STANDARD_HEADERS = [
        'llantacodigo',
        'descripcion',
        'fecha',
        'medida',
        'modelo',
        'almacen',
        'cantidad',
    ];
    private const REPORT_HEADERS = [
        'fecha',
        'hojaorigen',
        'ref',
        'cliente',
        'marca',
        'medida',
        'modelo',
        'cant',
    ];
    private const BUILTIN_DATE_FORMATS = [
        14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58,
    ];

    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('El archivo no existe o supera el límite de 15 MB.');
        }

        try {
            $workbook = new PharData($path, FilesystemIterator::SKIP_DOTS, null, Phar::ZIP);
            return $this->readWorkbook($workbook);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'No se pudo abrir el Excel. Verifica que sea un archivo .xlsx válido.',
                0,
                $exception
            );
        }
    }

    private function readWorkbook(PharData $archive): array
    {
        $workbook = $this->readXml($archive, 'xl/workbook.xml');
        $namespaces = $workbook->getNamespaces(true);
        $mainNamespace = (string) ($namespaces[''] ?? '');
        $relationshipNamespace = (string) ($namespaces['r'] ?? '');
        if ($mainNamespace === '' || $relationshipNamespace === '') {
            throw new RuntimeException('El Excel no contiene una hoja válida.');
        }

        $workbook->registerXPathNamespace('x', $mainNamespace);
        $allSheets = $workbook->xpath('/x:workbook/x:sheets/x:sheet') ?: [];
        if (count($allSheets) > self::MAX_SHEETS) {
            throw new RuntimeException('El archivo no puede tener más de 20 hojas.');
        }
        $workbookRelationships = $this->readXml($archive, 'xl/_rels/workbook.xml.rels');
        $relationshipRootNamespace = (string) ($workbookRelationships->getNamespaces(true)[''] ?? '');
        $workbookRelationships->registerXPathNamespace('x', $relationshipRootNamespace);
        $relationshipTargets = [];
        foreach ($workbookRelationships->xpath('/x:Relationships/x:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            $relationshipTargets[(string) ($attributes['Id'] ?? '')] = (string) ($attributes['Target'] ?? '');
        }

        $visibleSheets = [];
        foreach ($allSheets as $sheet) {
            $attributes = $sheet->attributes();
            if (in_array((string) ($attributes['state'] ?? 'visible'), ['hidden', 'veryHidden'], true)) {
                continue;
            }
            $relationshipId = (string) $sheet->attributes($relationshipNamespace)['id'];
            $visibleSheets[] = [
                'title' => (string) ($attributes['name'] ?? ''),
                'path' => $this->resolveSheetPath($relationshipTargets[$relationshipId] ?? ''),
            ];
        }
        if ($visibleSheets === [] || count($visibleSheets) > self::MAX_SHEETS) {
            throw new RuntimeException('El archivo debe tener entre 1 y 20 hojas visibles.');
        }

        $sharedStrings = $this->readSharedStrings($archive, $mainNamespace);
        $dateStyles = $this->readDateStyles($archive);
        $workbookProperties = $workbook->xpath('/x:workbook/x:workbookPr') ?: [];
        $date1904Value = strtolower((string) ($workbookProperties[0]->attributes()['date1904'] ?? ''));
        $date1904 = in_array($date1904Value, ['1', 'true'], true);
        $sheets = [];
        $standardSheets = [];
        $reportSheets = [];
        foreach ($visibleSheets as $sheet) {
            $parsedSheet = $this->readSheet(
                $archive,
                $sheet['path'],
                $sheet['title'],
                $mainNamespace,
                $sharedStrings,
                $dateStyles,
                $date1904
            );
            $sheets[] = $parsedSheet;
            $headers = [];
            foreach ($this->rowValues($parsedSheet['rows'][1] ?? [], 10) as $value) {
                if ($value !== null) {
                    $headers[] = $this->normalizeHeader((string) $value);
                }
            }
            if ($headers === self::STANDARD_HEADERS) {
                $standardSheets[] = $parsedSheet;
                continue;
            }

            for ($headerRow = 1; $headerRow <= min(5, $parsedSheet['max_row']); $headerRow++) {
                $header = [];
                for ($column = 0; $column < 10; $column++) {
                    $header[] = $this->normalizeHeader((string) ($parsedSheet['rows'][$headerRow][$column] ?? ''));
                }
                if (array_slice($header, 0, count(self::REPORT_HEADERS)) === self::REPORT_HEADERS) {
                    $reportSheets[] = ['sheet' => $parsedSheet, 'header_row' => $headerRow];
                    break;
                }
            }
        }

        if ($standardSheets !== [] && $reportSheets !== []) {
            throw new RuntimeException('El libro mezcla el formato histórico y el reporte de ventas.');
        }
        if ($reportSheets !== []) {
            return $this->readReport($reportSheets);
        }
        if ($standardSheets === [] || count($standardSheets) !== count($sheets)) {
            throw new RuntimeException(
                'No se reconoce el formato. Usa el histórico de siete columnas o el reporte '
                . 'con Fecha, Hoja origen, Ref., Cliente, Marca, Medida, Modelo y Cant.'
            );
        }

        return $this->readStandard($standardSheets);
    }

    private function readReport(array $reportSheets): array
    {
        if (count($reportSheets) !== 1) {
            throw new RuntimeException('El reporte de ventas debe tener una sola hoja de detalle.');
        }

        $sheet = $reportSheets[0]['sheet'];
        $headerRow = $reportSheets[0]['header_row'];
        if ($sheet['max_row'] > self::MAX_ROWS + $headerRow) {
            throw new RuntimeException("La hoja '{$sheet['title']}' supera el límite de 50,000 filas.");
        }

        $rows = [];
        foreach ($sheet['rows'] as $rowNumber => $values) {
            if ($rowNumber <= $headerRow || !$this->hasValues($values)) {
                continue;
            }
            if (in_array($this->normalizeHeader((string) ($values[0] ?? '')), ['total', 'totales'], true)) {
                continue;
            }

            $brand = trim((string) ($values[4] ?? ''));
            $model = trim((string) ($values[6] ?? ''));
            $rows[] = [
                'sheet' => $sheet['title'],
                'row' => $rowNumber,
                'format' => 'brand_report',
                'brand' => $brand,
                'reference' => trim((string) ($values[2] ?? '')),
                'values' => [
                    '',
                    implode(' ', array_filter([$brand, $model], static fn (string $part): bool => $part !== '')),
                    $values[0] ?? null,
                    trim((string) ($values[5] ?? '')),
                    $model,
                    '',
                    $values[7] ?? null,
                ],
            ];
            if (count($rows) > self::MAX_ROWS) {
                throw new RuntimeException('El archivo supera el límite de 50,000 filas.');
            }
        }
        if ($rows === []) {
            throw new RuntimeException('El reporte no contiene filas de ventas con detalle.');
        }

        return ['format' => 'brand_report', 'rows' => $rows];
    }

    private function readStandard(array $sheets): array
    {
        $rows = [];
        foreach ($sheets as $sheet) {
            if ($sheet['max_row'] > self::MAX_ROWS + 1) {
                throw new RuntimeException("La hoja '{$sheet['title']}' supera el límite de 50,000 filas.");
            }
            foreach ($sheet['rows'] as $rowNumber => $values) {
                if ($rowNumber < 2) {
                    continue;
                }
                $values = $this->rowValues($values, 7);
                if (!$this->hasValues($values)) {
                    continue;
                }
                $rows[] = [
                    'sheet' => $sheet['title'],
                    'row' => $rowNumber,
                    'format' => 'standard',
                    'values' => $values,
                ];
                if (count($rows) > self::MAX_ROWS) {
                    throw new RuntimeException('El archivo supera el límite de 50,000 filas.');
                }
            }
        }
        if ($rows === []) {
            throw new RuntimeException('El archivo Excel no contiene ventas.');
        }

        return ['format' => 'standard', 'rows' => $rows];
    }

    private function readSheet(
        PharData $archive,
        string $path,
        string $title,
        string $namespace,
        array $sharedStrings,
        array $dateStyles,
        bool $date1904
    ): array {
        $sheet = $this->readXml($archive, $path);
        $sheet->registerXPathNamespace('x', $namespace);
        $maxRow = 0;
        $dimension = (string) ($sheet->xpath('/x:worksheet/x:dimension')[0]->attributes()['ref'] ?? '');
        if (preg_match('/(?:^|:)[A-Z]+([0-9]+)$/i', $dimension, $matches) === 1) {
            $maxRow = (int) $matches[1];
        } elseif (preg_match('/^[A-Z]+([0-9]+)$/i', $dimension, $matches) === 1) {
            $maxRow = (int) $matches[1];
        }

        $rows = [];
        foreach ($sheet->xpath('/x:worksheet/x:sheetData/x:row') ?: [] as $rowNode) {
            $rowNumber = (int) ($rowNode->attributes()['r'] ?? 0);
            if ($rowNumber < 1) {
                throw new RuntimeException('El Excel contiene una fila con número inválido.');
            }
            $maxRow = max($maxRow, $rowNumber);
            $row = [];
            foreach ($rowNode->children($namespace) as $cell) {
                if ($cell->getName() !== 'c') {
                    continue;
                }
                $attributes = $cell->attributes();
                $column = $this->columnIndex((string) ($attributes['r'] ?? ''));
                $type = (string) ($attributes['t'] ?? '');
                $style = (int) ($attributes['s'] ?? 0);
                $children = $cell->children($namespace);
                if ($type === 'inlineStr') {
                    $value = isset($children->is) ? $this->textContent($children->is, $namespace) : '';
                } else {
                    $value = isset($children->v) ? (string) $children->v : null;
                    if ($type === 's' && $value !== null) {
                        $sharedIndex = filter_var($value, FILTER_VALIDATE_INT);
                        if ($sharedIndex === false || !array_key_exists($sharedIndex, $sharedStrings)) {
                            throw new RuntimeException('El Excel contiene una referencia de texto inválida.');
                        }
                        $value = $sharedStrings[$sharedIndex];
                    } elseif ($value !== null && isset($dateStyles[$style]) && is_numeric($value)) {
                        $value = $this->excelDate((float) $value, $date1904);
                    } elseif ($type === 'b' && $value !== null) {
                        $value = $value === '1' ? '1' : '0';
                    }
                }
                $row[$column] = $value;
            }
            $rows[$rowNumber] = $row;
        }
        ksort($rows);

        return ['title' => $title, 'rows' => $rows, 'max_row' => $maxRow];
    }

    private function readSharedStrings(PharData $archive, string $namespace): array
    {
        if (!$archive->offsetExists('xl/sharedStrings.xml')) {
            return [];
        }
        $sharedStrings = $this->readXml($archive, 'xl/sharedStrings.xml');
        $sharedStrings->registerXPathNamespace('x', $namespace);
        $values = [];
        foreach ($sharedStrings->xpath('/x:sst/x:si') ?: [] as $item) {
            $values[] = $this->textContent($item, $namespace);
        }

        return $values;
    }

    private function readDateStyles(PharData $archive): array
    {
        if (!$archive->offsetExists('xl/styles.xml')) {
            return [];
        }
        $styles = $this->readXml($archive, 'xl/styles.xml');
        $namespace = (string) ($styles->getNamespaces(true)[''] ?? '');
        $styles->registerXPathNamespace('x', $namespace);
        $dateFormats = array_fill_keys(self::BUILTIN_DATE_FORMATS, true);
        foreach ($styles->xpath('/x:styleSheet/x:numFmts/x:numFmt') ?: [] as $format) {
            $attributes = $format->attributes();
            $formatCode = (string) ($attributes['formatCode'] ?? '');
            $formatCode = preg_replace('/"[^"]*"|\\\\.|\\[[^]]*\\]/', '', $formatCode) ?? $formatCode;
            if (preg_match('/[dy]/i', $formatCode) === 1) {
                $dateFormats[(int) ($attributes['numFmtId'] ?? -1)] = true;
            }
        }

        $dateStyles = [];
        foreach ($styles->xpath('/x:styleSheet/x:cellXfs/x:xf') ?: [] as $index => $style) {
            if (isset($dateFormats[(int) ($style->attributes()['numFmtId'] ?? -1)])) {
                $dateStyles[$index] = true;
            }
        }

        return $dateStyles;
    }

    private function readXml(PharData $archive, string $path): SimpleXMLElement
    {
        if (!$archive->offsetExists($path)) {
            throw new RuntimeException('El Excel está incompleto; falta información de una hoja.');
        }
        $entry = $archive[$path];
        if (!$entry instanceof PharFileInfo || $entry->getSize() > self::MAX_FILE_BYTES) {
            throw new RuntimeException('El Excel contiene una sección demasiado grande para procesar.');
        }
        $xml = @simplexml_load_string(
            $entry->getContent(),
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_COMPACT
        );
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('El Excel contiene datos XML inválidos.');
        }

        return $xml;
    }

    private function resolveSheetPath(string $target): string
    {
        if ($target === '') {
            throw new RuntimeException('No se encontró una hoja visible en el Excel.');
        }
        $segments = [];
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $resolved = implode('/', $segments);
        if (!str_starts_with($resolved, 'xl/worksheets/') || !str_ends_with($resolved, '.xml')) {
            throw new RuntimeException('La hoja del Excel apunta a una ruta no permitida.');
        }

        return $resolved;
    }

    private function columnIndex(string $cellReference): int
    {
        if (preg_match('/^([A-Z]+)/i', $cellReference, $matches) !== 1) {
            throw new RuntimeException('El Excel contiene una celda con referencia inválida.');
        }
        $column = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $column = $column * 26 + ord($letter) - ord('A') + 1;
        }

        return $column - 1;
    }

    private function normalizeHeader(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = strtolower(is_string($ascii) ? $ascii : $value);

        return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
    }

    private function textContent(SimpleXMLElement $element, string $namespace): string
    {
        if ($element->getName() === 't') {
            return (string) $element;
        }
        $text = '';
        foreach ($element->children($namespace) as $child) {
            $text .= $this->textContent($child, $namespace);
        }

        return $text;
    }

    private function excelDate(float $serial, bool $date1904): string
    {
        $baseDate = $date1904 ? '1904-01-01' : '1899-12-30';

        return (new DateTimeImmutable($baseDate))
            ->modify('+' . (int) floor($serial) . ' days')
            ->format('Y-m-d');
    }

    private function hasValues(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function rowValues(array $row, int $columnCount): array
    {
        $values = [];
        for ($column = 0; $column < $columnCount; $column++) {
            $values[] = $row[$column] ?? null;
        }

        return $values;
    }
}
