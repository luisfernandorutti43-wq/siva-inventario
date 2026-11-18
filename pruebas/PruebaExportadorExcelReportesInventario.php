<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';

$report = [
    'stock_rows' => [[
        'warehouse_code' => 'A0101',
        'warehouse_name' => 'Castilla',
        'sku' => 'SKU-1',
        'name' => 'Llanta & prueba',
        'brand' => 'Marca',
        'size' => '205/55 R16',
        'quantity' => 1,
        'minimum_stock' => 5,
        'safety_stock' => 2,
        'stock_in_transit' => 3,
        'shortage_quantity' => 4,
        'stock_status' => 'critical',
    ]],
    'movements' => [[
        'created_at' => '2026-11-10 10:00:00',
        'warehouse_code' => 'A0101',
        'warehouse_name' => 'Castilla',
        'product_sku' => 'SKU-1',
        'product_name' => 'Llanta & prueba',
        'product_brand' => 'Marca',
        'product_measure' => '205/55 R16',
        'movement_type' => 'adjustment',
        'reference_type' => 'purchase_cancel',
        'quantity_change' => -2,
        'notes' => 'Anulación de compra COM-1: motivo de prueba',
        'user_name' => 'Usuario de prueba',
    ]],
];

$content = (new InventoryReportExcelExporter())->generate($report);
$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'siva-report-test-' . bin2hex(random_bytes(8)) . '.xlsx';
try {
    if (file_put_contents($path, $content) !== strlen($content)) {
        throw new RuntimeException('The report workbook could not be written for validation.');
    }

    $workbook = new PharData($path, 0, null, Phar::ZIP);
    $workbookXml = $workbook['xl/workbook.xml']->getContent();
    $stockXml = $workbook['xl/worksheets/sheet1.xml']->getContent();
    $movementXml = $workbook['xl/worksheets/sheet2.xml']->getContent();

    foreach (['Existencias', 'Movimientos'] as $sheetName) {
        if (!str_contains($workbookXml, $sheetName)) {
            throw new RuntimeException('The workbook must contain the ' . $sheetName . ' worksheet.');
        }
    }
    foreach (['Llanta &amp; prueba', 'Crítico', 'A0101', 'Unidades para llegar al mínimo'] as $value) {
        if (!str_contains($stockXml, $value)) {
            throw new RuntimeException('The stock worksheet is missing: ' . $value);
        }
    }
    foreach (['Anulación de compra', 'motivo de prueba', 'Usuario de prueba'] as $value) {
        if (!str_contains($movementXml, $value)) {
            throw new RuntimeException('The movement worksheet is missing: ' . $value);
        }
    }
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

$countReport = [[
    'counted_at' => '2026-11-12 10:00:00',
    'warehouse_code' => 'A0101',
    'warehouse_name' => 'Castilla',
    'sku' => 'SKU-1',
    'product_name' => 'Llanta de prueba',
    'brand' => 'Marca',
    'measure' => '205/55 R16',
    'previous_quantity' => 13,
    'physical_quantity' => 9,
    'difference' => -4,
    'reason' => 'Conteo de prueba',
    'user_name' => 'Usuario de prueba',
], [
    'counted_at' => '2026-11-12 09:30:00',
    'warehouse_code' => 'A0101',
    'warehouse_name' => 'Castilla',
    'sku' => 'SKU-2',
    'product_name' => 'Otra llanta',
    'brand' => 'Otra marca',
    'measure' => '215/60 R16',
    'previous_quantity' => 3,
    'physical_quantity' => 2,
    'difference' => -1,
    'reason' => 'Segundo conteo',
    'user_name' => 'Usuario de prueba',
], [
    'counted_at' => '2026-11-11 08:15:00',
    'warehouse_code' => 'A0101',
    'warehouse_name' => 'Castilla',
    'sku' => 'SKU-3',
    'product_name' => 'Tercera llanta',
    'brand' => 'Otra marca',
    'measure' => '225/60 R16',
    'previous_quantity' => 1,
    'physical_quantity' => 1,
    'difference' => 0,
    'reason' => 'Tercer conteo',
    'user_name' => 'Usuario de prueba',
]];
$countContent = (new InventoryReportExcelExporter())->generatePhysicalCount($countReport);
$countPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'siva-count-report-test-' . bin2hex(random_bytes(8)) . '.xlsx';
try {
    if (file_put_contents($countPath, $countContent) !== strlen($countContent)) {
        throw new RuntimeException('The physical count workbook could not be written for validation.');
    }

    $countWorkbook = new PharData($countPath, 0, null, Phar::ZIP);
    $countWorkbookXml = $countWorkbook['xl/workbook.xml']->getContent();
    $countSheetXml = $countWorkbook['xl/worksheets/sheet1.xml']->getContent();
    if (!str_contains($countWorkbookXml, 'Conteo físico')) {
        throw new RuntimeException('The workbook must contain the physical count worksheet.');
    }
    foreach ([
        'Fecha de conteo', 'Hora', '12/11/2026', '10:00:00', 'Saldo anterior',
        'Unidades contadas', 'Conteo de prueba', 'Usuario de prueba', 'SKU-1', '<cols>',
    ] as $value) {
        if (!str_contains($countSheetXml, $value)) {
            throw new RuntimeException('The physical count worksheet is missing: ' . $value);
        }
    }
} finally {
    if (is_file($countPath)) {
        unlink($countPath);
    }
}

$pdf = (new InventoryPhysicalCountPdfExporter())->generate($countReport);
if (!str_starts_with($pdf, "%PDF-1.4\n")
    || !str_contains($pdf, '/MediaBox [0 0 842 595]')
    || !str_contains($pdf, 'Llanta de prueba')
    || !str_contains($pdf, '12/11/2026')
    || !str_contains($pdf, '10:00:00')
    || !str_contains($pdf, 'FECHA 12/11/2026')
    || substr_count($pdf, 'FECHA 12/11/2026') !== 1
    || substr_count($pdf, 'FECHA 11/11/2026') !== 1
    || !str_contains($pdf, 'Producto / marca / medida')
    || !str_contains($pdf, 'Saldo anterior')
    || !str_contains($pdf, 'Conteo de prueba')
    || !str_contains($pdf, 'Usuario de prueba')
    || !preg_match('/startxref\n(\d+)\n%%EOF\z/', $pdf, $xrefMatch)
) {
    throw new RuntimeException('The physical count PDF is missing report data or its PDF structure.');
}
$xrefOffset = (int) $xrefMatch[1];
if (substr($pdf, $xrefOffset, 5) !== "xref\n") {
    throw new RuntimeException('The physical count PDF contains an invalid cross-reference offset.');
}

fwrite(STDOUT, "InventoryReportExcelExporter tests passed.\n");
