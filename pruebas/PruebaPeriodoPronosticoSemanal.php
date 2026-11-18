<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';

$service = (new ReflectionClass(BrandSalesForecastService::class))->newInstanceWithoutConstructor();
$cases = [
    [
        'today' => '2026-11-13',
        'latest_sale' => '2026-11-12',
        'start' => '2026-11-09',
        'end' => '2026-11-15',
    ],
    [
        'today' => '2026-11-15',
        'latest_sale' => '2026-11-15',
        'start' => '2026-11-09',
        'end' => '2026-11-15',
    ],
    [
        'today' => '2026-11-16',
        'latest_sale' => '2026-11-15',
        'start' => '2026-11-16',
        'end' => '2026-11-22',
    ],
];

foreach ($cases as $case) {
    [$start, $end] = $service->forecastPeriodForSalesDate(
        new DateTimeImmutable($case['latest_sale']),
        new DateTimeImmutable($case['today'])
    );
    if ($start->format('Y-m-d') !== $case['start'] || $end->format('Y-m-d') !== $case['end']) {
        throw new RuntimeException(
            'Unexpected forecast period: ' . $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d')
        );
    }
}

fwrite(STDOUT, "WeeklyForecastPeriod tests passed.\n");
