<?php
declare(strict_types=1);

final class DailyForecastEvaluator
{
    private const MAX_EVALUATION_DAYS = 7;
    private const MINIMUM_EVALUATION_DAYS = 3;
    private const SUNDAY = '7';

    /**
     * Evaluates the product-level weekday-median forecast with rolling origins.
     * Each target date is predicted using only calendar days strictly before it.
     */
    public static function evaluate(array $calendar, array $dailySeriesByProduct): array
    {
        $weekdays = array_map(
            static fn (string $dateText): string => (new DateTimeImmutable($dateText))->format('N'),
            $calendar
        );
        $targetIndexes = [];
        foreach ($calendar as $index => $dateText) {
            if ($index > 0 && $weekdays[$index] !== self::SUNDAY) {
                $targetIndexes[] = $index;
            }
        }
        $targetIndexes = array_slice($targetIndexes, -self::MAX_EVALUATION_DAYS);

        $modelAbsoluteError = 0.0;
        $baselineAbsoluteError = 0.0;
        $actualUnits = 0.0;
        $forecastUnits = 0.0;
        $baselineUnits = 0.0;
        $evaluatedDays = [];
        $evaluatedProducts = [];
        $scoredProductDays = 0;

        foreach ($targetIndexes as $targetIndex) {
            $targetDate = (string) $calendar[$targetIndex];
            $weekday = $weekdays[$targetIndex];
            $dayHadKnownProducts = false;

            foreach ($dailySeriesByProduct as $productKey => $dailySeries) {
                $unitsByDay = $dailySeries['units'] ?? [];
                $hasPriorSales = false;
                for ($index = 0; $index < $targetIndex; $index++) {
                    if ((float) ($unitsByDay[$index] ?? 0.0) > 0.0) {
                        $hasPriorSales = true;
                        break;
                    }
                }
                if (!$hasPriorSales) {
                    continue;
                }

                $weekdayHistory = [];
                for ($index = 0; $index < $targetIndex; $index++) {
                    if ($weekdays[$index] === $weekday) {
                        $weekdayHistory[] = max(0.0, (float) ($unitsByDay[$index] ?? 0.0));
                    }
                }
                $weekdayHistory = array_slice($weekdayHistory, -4);
                $prediction = (float) self::medianForecastUnits($weekdayHistory);
                $baseline = $weekdayHistory === []
                    ? 0.0
                    : (float) round(array_sum($weekdayHistory) / count($weekdayHistory));
                $actual = max(0.0, (float) ($unitsByDay[$targetIndex] ?? 0.0));

                $modelAbsoluteError += abs($actual - $prediction);
                $baselineAbsoluteError += abs($actual - $baseline);
                $actualUnits += $actual;
                $forecastUnits += $prediction;
                $baselineUnits += $baseline;
                $scoredProductDays++;
                $evaluatedProducts[(string) $productKey] = true;
                $dayHadKnownProducts = true;
            }

            if ($dayHadKnownProducts) {
                $evaluatedDays[] = $targetDate;
            }
        }

        $periodCount = count($evaluatedDays);
        $status = $periodCount < self::MINIMUM_EVALUATION_DAYS
            ? 'insufficient_history'
            : ($actualUnits <= 0.0 ? 'zero_actual_sales' : 'available');

        return [
            'status' => $status,
            'method' => 'weekday_median_last_four',
            'baseline_method' => 'weekday_mean_last_four',
            'max_evaluation_days' => self::MAX_EVALUATION_DAYS,
            'evaluated_days' => $periodCount,
            'start_date' => $evaluatedDays[0] ?? null,
            'end_date' => $evaluatedDays === [] ? null : $evaluatedDays[array_key_last($evaluatedDays)],
            'evaluated_products' => count($evaluatedProducts),
            'scored_product_days' => $scoredProductDays,
            'actual_units' => $actualUnits,
            'forecast_units' => $forecastUnits,
            'baseline_units' => $baselineUnits,
            'wape_percent' => $status === 'available' ? 100 * $modelAbsoluteError / $actualUnits : null,
            'baseline_wape_percent' => $status === 'available'
                ? 100 * $baselineAbsoluteError / $actualUnits
                : null,
        ];
    }

    public static function medianForecastUnits(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        $median = count($values) % 2 === 0
            ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
            : (float) $values[$middle];

        return (int) round($median);
    }
}
