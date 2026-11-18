<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

$cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siva-forecast-cache-test-'
    . bin2hex(random_bytes(6));
$cache = new DailyForecastAiCache($cacheDirectory);
$isValid = static fn (array $result): bool => ($result['model'] ?? '') === 'test'
    && is_array($result['forecast_by_product'] ?? null);
$calculations = 0;
$key = hash('sha256', 'forecast-input');

try {
    $first = $cache->remember(
        $key,
        static function () use (&$calculations): array {
            $calculations++;

            return ['model' => 'test', 'forecast_by_product' => ['product-1' => [1, 2]]];
        },
        $isValid
    );
    expectSame(false, $first['cache_hit'], 'The first request should calculate the forecast');

    $second = $cache->remember(
        $key,
        static function () use (&$calculations): array {
            $calculations++;

            return [];
        },
        $isValid
    );
    expectSame(true, $second['cache_hit'], 'The repeated request should reuse the forecast');
    expectSame($first['result'], $second['result'], 'A cache hit should preserve the forecast');

    $changedInput = $cache->remember(
        hash('sha256', 'changed-forecast-input'),
        static function () use (&$calculations): array {
            $calculations++;

            return ['model' => 'test', 'forecast_by_product' => []];
        },
        $isValid
    );
    expectSame(false, $changedInput['cache_hit'], 'Changed inputs should calculate a new forecast');
    expectSame(2, $calculations, 'Only cache misses should calculate the forecast');
} finally {
    foreach (glob($cacheDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove forecast cache test file.');
        }
    }
    if (is_dir($cacheDirectory) && !rmdir($cacheDirectory)) {
        throw new RuntimeException('Could not remove forecast cache test directory.');
    }
}

echo "Daily forecast AI cache tests passed.\n";
