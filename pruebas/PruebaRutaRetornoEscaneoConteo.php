<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR . 'Controladores' . DIRECTORY_SEPARATOR . 'web.php';

$scanParameters = [
    'pagina' => 'inventario',
    'view' => 'count',
    'warehouse' => 'A0101',
    'barcode' => 'SKU-11',
    'count_scan' => '1',
];
$expectedPath = 'index.php?pagina=inventario&view=count&warehouse=A0101&barcode=SKU-11&count_scan=1';

if (app_inventory_count_scan_path($scanParameters) !== $expectedPath
    || app_login_return_path($expectedPath) !== $expectedPath
) {
    throw new RuntimeException('Physical-count QR scans must survive the login redirect.');
}

$scanParameters['warehouse'] = 'OTHER';
if (app_inventory_count_scan_path($scanParameters) !== null) {
    throw new RuntimeException('Physical-count scan return paths must reject unknown warehouses.');
}

if (app_login_return_path('https://example.test/index.php?pagina=inventario&view=count') !== null) {
    throw new RuntimeException('Login return paths must reject external URLs.');
}

$saleScanParameters = [
    'pagina' => 'ventas',
    'view' => 'new',
    'warehouse' => 'A0101',
    'scan_sku' => 'SKU-11',
];
$expectedSalePath = 'index.php?pagina=ventas&view=new&scan_sku=SKU-11&warehouse=A0101';
if (app_sale_scan_path($saleScanParameters) !== $expectedSalePath
    || app_login_return_path($expectedSalePath) !== $expectedSalePath
) {
    throw new RuntimeException('Product QR scans must open the selected warehouse in Sales after login.');
}

$saleScanParameters['warehouse'] = 'OTHER';
if (app_sale_scan_path($saleScanParameters) !== null) {
    throw new RuntimeException('Product QR scan paths must reject unknown warehouses.');
}

echo "Inventory and sales scan return path tests passed.\n";
