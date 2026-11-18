<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';

function expectPreviewSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

$preview = new InventoryScanExcelExporter();
$scans = [
    ['scan_id' => 1, 'barcode' => 'TIRE-1', 'warehouse' => 'A0101'],
    ['scan_id' => 2, 'barcode' => 'TIRE-1', 'warehouse' => 'A0101'],
    ['scan_id' => 3, 'barcode' => 'TIRE-2', 'warehouse' => 'A0101'],
];
$products = [
    "TIRE-1\0A0101" => [
        'sku' => 'SKU-1',
        'name' => 'Llanta de prueba',
        'brand' => 'Prueba',
        'measure' => '205/55 R16',
        'current_stock' => 4,
        'minimum_stock' => 5,
    ],
    "TIRE-2\0A0101" => [
        'sku' => 'SKU-2',
        'name' => 'Otra llanta',
        'brand' => 'Prueba',
        'measure' => '215/60 R16',
        'current_stock' => 0,
        'minimum_stock' => 2,
    ],
];

$summary = $preview->summarizePreview($scans, $products);
expectPreviewSame('A0101', $summary['warehouse'], 'Preview must identify its one warehouse');
expectPreviewSame(3, $summary['units'], 'Preview must count scanned units');
expectPreviewSame(2, count($summary['products']), 'Preview must group scans by product');
expectPreviewSame(2, $summary['products'][0]['units'], 'Repeated scans must add units to one product');
expectPreviewSame(6, $summary['products'][0]['stock_after'], 'Preview must calculate resulting stock');
expectPreviewSame(true, $summary['products'][1]['low_stock'], 'Preview must identify stock at or under minimum');
expectPreviewSame(
    $summary['stock_snapshot_hash'],
    $preview->stockSnapshotHash($products),
    'Preview stock hash must be stable'
);
$changedProducts = $products;
$changedProducts["TIRE-1\0A0101"]['current_stock']++;
if (hash_equals($summary['stock_snapshot_hash'], $preview->stockSnapshotHash($changedProducts))) {
    throw new RuntimeException('Preview stock hash must detect changed inventory.');
}

$mixedWarehouseScans = [
    ['scan_id' => 1, 'barcode' => 'TIRE-1', 'warehouse' => 'A0101'],
    ['scan_id' => 2, 'barcode' => 'TIRE-1', 'warehouse' => 'A0102'],
];
try {
    $preview->summarizePreview($mixedWarehouseScans, []);
    throw new RuntimeException('Mixed-warehouse previews must be rejected.');
} catch (RuntimeException $exception) {
    expectPreviewSame(
        'El Excel debe contener escaneos de un solo almacén.',
        $exception->getMessage(),
        'Mixed-warehouse error must explain the rule'
    );
}

fwrite(STDOUT, "InventoryScanPreview tests passed.\n");
