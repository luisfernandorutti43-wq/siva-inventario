<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Servicios' . DIRECTORY_SEPARATOR . 'EvaluadorPronosticoDiario.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function expectNear(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 0.00001) {
        throw new RuntimeException($message . ': expected ' . $expected . ', got ' . $actual);
    }
}

function calendarFrom(string $startDate, int $days): array
{
    $calendar = [];
    $date = new DateTimeImmutable($startDate);
    for ($index = 0; $index < $days; $index++) {
        $calendar[] = $date->format('Y-m-d');
        $date = $date->modify('+1 day');
    }

    return $calendar;
}

function productSeries(array $units): array
{
    return [
        'product-1' => [
            'product' => ['marca' => 'TEST'],
            'units' => $units,
        ],
    ];
}

$calendar = calendarFrom('2026-10-01', 15);
$constantSeries = productSeries(array_fill(0, count($calendar), 2.0));
$constantResult = DailyForecastEvaluator::evaluate($calendar, $constantSeries);
expectSame('available', $constantResult['status'], 'Constant series should be evaluable');
expectSame(7, $constantResult['evaluated_days'], 'Evaluation should use at most seven weekdays');
expectSame(1, $constantResult['evaluated_products'], 'Known product count should be reported');
expectSame(0.0, $constantResult['wape_percent'], 'Constant demand should have zero WAPE');
expectSame(
    0.0,
    $constantResult['baseline_wape_percent'],
    'The weekday-mean baseline should match a constant series'
);
expectSame('weekday_median_last_four', $constantResult['method'], 'The evaluated method should be explicit');
expectSame(0, DailyForecastEvaluator::medianForecastUnits([]), 'An empty weekday history should forecast zero');
expectSame(2, DailyForecastEvaluator::medianForecastUnits([1.0, 2.0]), 'Even-sized medians should round to whole units');

$intermittentUnits = [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 2.0, 4.0, 0.0, 0.0, 6.0, 0.0, 2.0, 4.0];
$intermittentResult = DailyForecastEvaluator::evaluate($calendar, productSeries($intermittentUnits));
expectSame('available', $intermittentResult['status'], 'Intermittent demand should be evaluated');
expectSame('2026-10-08', $intermittentResult['start_date'], 'The evaluation should skip Sundays');
expectSame('2026-10-15', $intermittentResult['end_date'], 'The evaluation should stop at the cutoff');
expectSame(18.0, $intermittentResult['actual_units'], 'Intermittent holdout sales should be totaled');
expectSame(3.0, $intermittentResult['forecast_units'], 'Median forecasts should use prior weekdays only');
expectNear(
    100 * 15 / 18,
    (float) $intermittentResult['wape_percent'],
    'WAPE should sum absolute errors over actual holdout units'
);

$zeroSalesUnits = array_fill(0, count($calendar), 0.0);
for ($index = 0; $index < 7; $index++) {
    $zeroSalesUnits[$index] = 1.0;
}
$zeroSalesResult = DailyForecastEvaluator::evaluate($calendar, productSeries($zeroSalesUnits));
expectSame('zero_actual_sales', $zeroSalesResult['status'], 'Zero actual sales must be identified');
expectSame(null, $zeroSalesResult['wape_percent'], 'WAPE must be undefined when actual sales total zero');
expectSame(0.0, $zeroSalesResult['actual_units'], 'Zero-sales holdout should report zero actual units');

$shortCalendar = calendarFrom('2026-10-01', 4);
$shortResult = DailyForecastEvaluator::evaluate($shortCalendar, productSeries([3.0, 0.0, 0.0, 0.0]));
expectSame('insufficient_history', $shortResult['status'], 'Short holdout should not publish WAPE');
expectSame(null, $shortResult['wape_percent'], 'Insufficient evaluation must not publish WAPE');

$noKnownProductsCalendar = calendarFrom('2026-10-01', 15);
$noKnownProductsUnits = array_fill(0, count($noKnownProductsCalendar), 0.0);
$noKnownProductsUnits[14] = 5.0;
$noKnownProductsResult = DailyForecastEvaluator::evaluate(
    $noKnownProductsCalendar,
    productSeries($noKnownProductsUnits)
);
expectSame(
    'insufficient_history',
    $noKnownProductsResult['status'],
    'Products first observed during holdout must not count as known forecast targets'
);
expectSame(
    0,
    $noKnownProductsResult['scored_product_days'],
    'No target product should be scored before its first prior observation'
);

$emptyResult = DailyForecastEvaluator::evaluate($calendar, []);
expectSame('insufficient_history', $emptyResult['status'], 'Empty series should be insufficient');
expectSame(0, $emptyResult['evaluated_days'], 'Empty series should have no scored days');

$gappedUnits = array_fill(0, count($calendar), 0.0);
$gappedUnits[0] = 4.0;
$gappedUnits[8] = 4.0;
$gappedUnits[14] = 4.0;
$gappedSeries = productSeries($gappedUnits);
$beforeEvaluation = serialize([$calendar, $gappedSeries]);
DailyForecastEvaluator::evaluate($calendar, $gappedSeries);
expectSame(
    $beforeEvaluation,
    serialize([$calendar, $gappedSeries]),
    'Evaluation must not mutate historical inputs'
);

fwrite(STDOUT, "DailyForecastEvaluator tests passed.\n");
