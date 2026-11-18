<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR . 'Modelos' . DIRECTORY_SEPARATOR . 'Modelos.php';

if (!function_exists('text_length')) {
    function text_length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : preg_match_all('/./us', $value);
    }
}

function expectWarehouseIsolationSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function inventoryQuantity(PDO $database, int $productId, string $warehouse): int
{
    $query = $database->prepare(
        'SELECT cantidad FROM inventario WHERE producto_id = :product AND almacen_codigo = :warehouse'
    );
    $query->execute(['product' => $productId, 'warehouse' => $warehouse]);

    return (int) $query->fetchColumn();
}

$database = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database->sqliteCreateFunction('GREATEST', static fn (...$values): int => max(array_map('intval', $values)), -1);
$database->sqliteCreateFunction('LEAST', static fn (...$values): int => min(array_map('intval', $values)), -1);
$database->exec(
    'CREATE TABLE almacenes (codigo TEXT PRIMARY KEY, nombre TEXT NOT NULL, activo INTEGER NOT NULL);
     CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre_completo TEXT NOT NULL);
     CREATE TABLE productos (
         id INTEGER PRIMARY KEY, codigo_sku TEXT NOT NULL, nombre TEXT NOT NULL,
         categoria TEXT NOT NULL, marca TEXT NOT NULL, medida TEXT NOT NULL, descripcion TEXT NOT NULL,
         precio_unitario_centavos INTEGER NOT NULL, proveedor TEXT NOT NULL,
         ruta_imagen TEXT, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
     );
     CREATE TABLE inventario (
         producto_id INTEGER NOT NULL, almacen_codigo TEXT NOT NULL, cantidad INTEGER NOT NULL,
         stock_minimo INTEGER NOT NULL, stock_seguridad INTEGER NOT NULL,
         stock_en_transito INTEGER NOT NULL DEFAULT 0, actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (producto_id, almacen_codigo)
     );
     CREATE TABLE inventario_unidades (
         id INTEGER PRIMARY KEY AUTOINCREMENT, codigo_barras TEXT NOT NULL UNIQUE,
         codigo_fabricante TEXT, producto_id INTEGER NOT NULL, almacen_codigo TEXT NOT NULL,
         compra_id INTEGER, venta_id INTEGER, estado TEXT NOT NULL DEFAULT \'disponible\'
     );
     CREATE TABLE inventario_b4x01 (
         id INTEGER PRIMARY KEY AUTOINCREMENT, codigo_barras TEXT NOT NULL, nombre TEXT NOT NULL, marca TEXT NOT NULL,
         medida TEXT NOT NULL DEFAULT \'\', categoria TEXT NOT NULL DEFAULT \'\', cantidad INTEGER NOT NULL DEFAULT 0,
         almacen_codigo TEXT NOT NULL
     );
     CREATE TABLE compras (
         id INTEGER PRIMARY KEY AUTOINCREMENT, numero_compra TEXT NOT NULL, almacen_codigo TEXT NOT NULL,
         proveedor TEXT NOT NULL, documento_proveedor TEXT NOT NULL, fecha_compra TEXT NOT NULL,
         creado_por INTEGER NOT NULL, notas TEXT NOT NULL, creado_en TEXT DEFAULT CURRENT_TIMESTAMP,
         estado TEXT NOT NULL DEFAULT \'activa\', motivo_anulacion TEXT NOT NULL DEFAULT \'\',
         anulada_en TEXT, anulada_por INTEGER
     );
     CREATE TABLE detalle_compras (
         id INTEGER PRIMARY KEY AUTOINCREMENT, compra_id INTEGER NOT NULL, producto_id INTEGER NOT NULL,
         cantidad INTEGER NOT NULL, costo_unitario_centavos INTEGER NOT NULL, total_linea_centavos INTEGER NOT NULL
     );
     CREATE TABLE ventas (
         id INTEGER PRIMARY KEY AUTOINCREMENT, numero_venta TEXT NOT NULL, almacen_codigo TEXT NOT NULL,
         fecha_venta TEXT NOT NULL, creado_por INTEGER NOT NULL, notas TEXT NOT NULL,
         creado_en TEXT DEFAULT CURRENT_TIMESTAMP
     );
     CREATE TABLE detalle_ventas (
         id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER NOT NULL, producto_id INTEGER NOT NULL,
         cantidad INTEGER NOT NULL, precio_unitario_centavos INTEGER NOT NULL, total_linea_centavos INTEGER NOT NULL,
         estado TEXT NOT NULL DEFAULT \'activa\', anulada_en TEXT, anulada_por INTEGER,
         motivo_anulacion TEXT NOT NULL DEFAULT \'\'
     );
     CREATE TABLE reportes_ventas (
         id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER NOT NULL, detalle_venta_id INTEGER NOT NULL,
         reportado_por INTEGER NOT NULL, motivo TEXT NOT NULL, detalle TEXT NOT NULL,
         estado TEXT NOT NULL DEFAULT \'pendiente\', creado_en TEXT DEFAULT CURRENT_TIMESTAMP,
         revisado_por INTEGER, revisado_en TEXT, notas_revision TEXT NOT NULL DEFAULT \'\'
     );
     CREATE TABLE historico_ventas_excel (
         id INTEGER PRIMARY KEY AUTOINCREMENT, archivo_hash TEXT NOT NULL, hoja TEXT NOT NULL,
         fila_origen INTEGER NOT NULL, codigo TEXT NOT NULL, marca TEXT NOT NULL, medida TEXT NOT NULL,
         almacen_codigo TEXT NOT NULL, fecha_venta TEXT NOT NULL, cantidad INTEGER NOT NULL,
         tipo_origen TEXT NOT NULL
     );
     CREATE TABLE movimientos_inventario (
         id INTEGER PRIMARY KEY AUTOINCREMENT, producto_id INTEGER NOT NULL, almacen_codigo TEXT NOT NULL,
         tipo_movimiento TEXT NOT NULL, cambio_cantidad INTEGER NOT NULL, tipo_referencia TEXT NOT NULL,
         referencia_id INTEGER, creado_por INTEGER, notas TEXT NOT NULL, creado_en TEXT DEFAULT CURRENT_TIMESTAMP
     );'
);
$database->exec(
    "INSERT INTO almacenes (codigo, nombre, activo) VALUES
        ('A0101', 'Castilla', 1), ('A0102', 'Huancayo', 1), ('A0103', 'Atalaya', 1);
     INSERT INTO usuarios (id, nombre_completo) VALUES (1, 'Usuario de prueba');
     INSERT INTO productos (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor)
        VALUES (11, 'SKU-11', 'Llanta de prueba', 'Llantas', 'Marca', '205/55 R16', 'Producto inicial', 25000, 'Proveedor');
     INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
        VALUES (11, 'A0101', 10, 5, 2), (11, 'A0102', 20, 6, 3);
     INSERT INTO inventario_b4x01 (codigo_barras, nombre, marca, almacen_codigo)
        VALUES ('B4X01-TEST-11', 'Llanta de prueba', 'Marca', 'A0101');"
);
$insertUnit = $database->prepare(
    'INSERT INTO inventario_unidades (codigo_barras, producto_id, almacen_codigo)
     VALUES (:barcode, 11, :warehouse)'
);
foreach (['A0101' => 10, 'A0102' => 20] as $warehouse => $quantity) {
    for ($index = 1; $index <= $quantity; $index++) {
        $insertUnit->execute([
            'barcode' => 'LLATEST' . $warehouse . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'warehouse' => $warehouse,
        ]);
    }
}

$purchaseModel = new ComprasModelo($database);
$purchase = $purchaseModel->registrarCompra(
    [[
        'product_id' => 11,
        'quantity' => 3,
        'unit_cost_cents' => 20000,
        'factory_barcodes' => ['*FACTORY-A*', 'FACTORY-B', 'FACTORY-B'],
    ]],
    'Proveedor de prueba',
    'DOC-1',
    'A0101',
    date('Y-m-d'),
    1,
    'Compra de prueba'
);
expectWarehouseIsolationSame(13, inventoryQuantity($database, 11, 'A0101'), 'A purchase must update its selected warehouse');
expectWarehouseIsolationSame(20, inventoryQuantity($database, 11, 'A0102'), 'A purchase must not change another warehouse');
expectWarehouseIsolationSame(
    [[
        'product_id' => 11,
        'sku' => 'SKU-11',
        'name' => 'Llanta de prueba',
        'previous_quantity' => 10,
        'received_quantity' => 3,
        'current_quantity' => 13,
    ]],
    $purchase['stock_changes'],
    'Purchase result must include the before, received, and after quantities'
);
$purchaseMovement = $database->query(
    "SELECT almacen_codigo, tipo_movimiento, cambio_cantidad FROM movimientos_inventario WHERE tipo_movimiento = 'purchase'"
)->fetch();
expectWarehouseIsolationSame('A0101', $purchaseMovement['almacen_codigo'], 'Purchase movement must retain the selected warehouse');
expectWarehouseIsolationSame(3, (int) $purchaseMovement['cambio_cantidad'], 'Purchase movement must record received units');
$purchaseUnitsQuery = $database->prepare(
    'SELECT codigo_barras AS barcode, codigo_fabricante AS factory_code
     FROM inventario_unidades WHERE compra_id = :purchase ORDER BY id'
);
$purchaseIdQuery = $database->prepare('SELECT id FROM compras WHERE numero_compra = :number');
$purchaseIdQuery->execute(['number' => $purchase['numero']]);
$purchaseUnitsQuery->execute(['purchase' => (int) $purchaseIdQuery->fetchColumn()]);
$purchaseUnits = $purchaseUnitsQuery->fetchAll();
expectWarehouseIsolationSame(3, count($purchaseUnits), 'A purchase must create one individual record per tire');
expectWarehouseIsolationSame('FACTORY-A', $purchaseUnits[0]['barcode'], 'A unique factory barcode must be used for the tire label');
expectWarehouseIsolationSame('FACTORY-A', $purchaseUnits[0]['factory_code'], 'A factory barcode must be retained for reference');
expectWarehouseIsolationSame(
    true,
    str_starts_with((string) $purchaseUnits[1]['barcode'], 'LLA')
        && str_starts_with((string) $purchaseUnits[2]['barcode'], 'LLA')
        && $purchaseUnits[1]['barcode'] !== $purchaseUnits[2]['barcode'],
    'Repeated factory barcodes must receive distinct internal labels'
);

try {
    $purchaseModel->registrarCompra(
        [
            ['product_id' => 11, 'quantity' => 2, 'unit_cost_cents' => 20000],
            ['product_id' => 999, 'quantity' => 1, 'unit_cost_cents' => 10000],
        ],
        'Proveedor de prueba',
        'DOC-ROLLBACK',
        'A0101',
        date('Y-m-d'),
        1,
        ''
    );
    throw new RuntimeException('An invalid purchase line must roll back the transaction.');
} catch (RuntimeException $exception) {
    expectWarehouseIsolationSame(
        'Un producto no tiene inventario configurado en el almacén seleccionado.',
        $exception->getMessage(),
        'Invalid purchase must report the missing warehouse product'
    );
}
expectWarehouseIsolationSame(13, inventoryQuantity($database, 11, 'A0101'), 'A failed purchase must roll back earlier stock changes');
expectWarehouseIsolationSame(1, (int) $database->query('SELECT COUNT(*) FROM compras')->fetchColumn(), 'A failed purchase must not remain in history');
$database->exec(
    "INSERT INTO compras
        (numero_compra, almacen_codigo, proveedor, documento_proveedor, fecha_compra, creado_por, notas)
     VALUES ('COMP-A0102', 'A0102', 'Proveedor Huancayo', 'DOC-2', '2026-11-16', 1, '')"
);
$castillaPurchaseHistory = $purchaseModel->obtenerHistorial(false, 'A0101')['compras'];
$huancayoPurchaseHistory = $purchaseModel->obtenerHistorial(false, 'A0102')['compras'];
expectWarehouseIsolationSame(1, count($castillaPurchaseHistory), 'A warehouse filter must return only purchases for Castilla');
expectWarehouseIsolationSame('A0101', $castillaPurchaseHistory[0]['warehouse_code'], 'Castilla filter must return its warehouse code');
expectWarehouseIsolationSame(1, count($huancayoPurchaseHistory), 'A warehouse filter must return only purchases for Huancayo');
expectWarehouseIsolationSame('A0102', $huancayoPurchaseHistory[0]['warehouse_code'], 'Huancayo filter must return its warehouse code');

$salesModel = new VentasModelo($database);
$saleCodes = $database->query(
    "SELECT codigo_barras FROM inventario_unidades
     WHERE producto_id = 11 AND almacen_codigo = 'A0102' AND estado = 'disponible'
     ORDER BY id LIMIT 4"
)->fetchAll(PDO::FETCH_COLUMN);
$codeFromAnotherWarehouse = $database->query(
    "SELECT codigo_barras FROM inventario_unidades
     WHERE producto_id = 11 AND almacen_codigo = 'A0101' AND estado = 'disponible'
     ORDER BY id LIMIT 1"
)->fetchColumn();
$wrongWarehouseCodeRejected = false;
try {
    $salesModel->registrarSalida(
        [['product_id' => 11, 'quantity' => 1]],
        'A0102',
        date('Y-m-d'),
        'Código de otro almacén',
        1,
        [(string) $codeFromAnotherWarehouse]
    );
} catch (RuntimeException $exception) {
    $wrongWarehouseCodeRejected = str_contains($exception->getMessage(), 'no está disponible');
}
expectWarehouseIsolationSame(true, $wrongWarehouseCodeRejected, 'A tire code from another warehouse must be rejected');
$duplicateUnitCodeRejected = false;
try {
    $salesModel->registrarSalida(
        [['product_id' => 11, 'quantity' => 2]],
        'A0102',
        date('Y-m-d'),
        'Código duplicado',
        1,
        [(string) $saleCodes[0], (string) $saleCodes[0]]
    );
} catch (RuntimeException $exception) {
    $duplicateUnitCodeRejected = str_contains($exception->getMessage(), 'repetido');
}
expectWarehouseIsolationSame(true, $duplicateUnitCodeRejected, 'A duplicate tire code must be rejected');
$excessScansRejected = false;
try {
    $salesModel->registrarSalida(
        [['product_id' => 11, 'quantity' => 1]],
        'A0102',
        date('Y-m-d'),
        'Más escaneos que cantidad',
        1,
        ['SKU-11', 'SKU-11']
    );
} catch (RuntimeException $exception) {
    $excessScansRejected = str_contains($exception->getMessage(), 'cantidad no puede ser menor');
}
expectWarehouseIsolationSame(true, $excessScansRejected, 'A manual quantity must not be smaller than its scanned tires');
expectWarehouseIsolationSame(20, inventoryQuantity($database, 11, 'A0102'), 'Rejected scans must not change stock');
$salesModel->registrarSalida(
    [['product_id' => 11, 'quantity' => 4]],
    'A0102',
    date('Y-m-d'),
    'Venta de prueba',
    1,
    $saleCodes
);
expectWarehouseIsolationSame(13, inventoryQuantity($database, 11, 'A0101'), 'A sale must not change another warehouse');
expectWarehouseIsolationSame(16, inventoryQuantity($database, 11, 'A0102'), 'A sale must subtract from its selected warehouse');
expectWarehouseIsolationSame(
    4,
    (int) $database->query("SELECT COUNT(*) FROM inventario_unidades WHERE estado = 'vendida' AND almacen_codigo = 'A0102'")->fetchColumn(),
    'A sale must mark the exact scanned tire units as sold'
);
$saleMovement = $database->query(
    "SELECT almacen_codigo, tipo_movimiento, cambio_cantidad FROM movimientos_inventario WHERE tipo_movimiento = 'outgoing'"
)->fetch();
expectWarehouseIsolationSame('A0102', $saleMovement['almacen_codigo'], 'Sale movement must retain the selected warehouse');
expectWarehouseIsolationSame(-4, (int) $saleMovement['cambio_cantidad'], 'Sale movement must record outgoing units');
$soldUnitCodeRejected = false;
try {
    $salesModel->registrarSalida(
        [['product_id' => 11, 'quantity' => 1]],
        'A0102',
        date('Y-m-d'),
        'Código ya vendido',
        1,
        [(string) $saleCodes[0]]
    );
} catch (RuntimeException $exception) {
    $soldUnitCodeRejected = str_contains($exception->getMessage(), 'no está disponible');
}
expectWarehouseIsolationSame(true, $soldUnitCodeRejected, 'A tire code already sold must not be reusable');
expectWarehouseIsolationSame(16, inventoryQuantity($database, 11, 'A0102'), 'A rejected sold code must not change stock');

$skuLookup = $salesModel->buscarProductoDisponiblePorSku('sku-11', 'A0102');
expectWarehouseIsolationSame(11, (int) ($skuLookup['product_id'] ?? 0), 'A tire SKU must resolve in its warehouse');
$salesModel->registrarSalida(
    [['product_id' => 11, 'quantity' => 2]],
    'A0102',
    date('Y-m-d'),
    'Venta con SKU repetido',
    1,
    ['SKU-11', 'SKU-11']
);
expectWarehouseIsolationSame(14, inventoryQuantity($database, 11, 'A0102'), 'Repeated SKU scans must subtract each tire');
expectWarehouseIsolationSame(
    6,
    (int) $database->query("SELECT COUNT(*) FROM inventario_unidades WHERE estado = 'vendida' AND almacen_codigo = 'A0102'")->fetchColumn(),
    'Repeated SKU scans must mark matching internal tire units as sold'
);
$warehouseOnlySalesHistory = $salesModel->obtenerHistorial(
    ['warehouse' => 'A0102'],
    'administrador',
    1
);
expectWarehouseIsolationSame(2, count($warehouseOnlySalesHistory['ventas']), 'Filtering the sales history by warehouse must not require dates');
foreach ($warehouseOnlySalesHistory['ventas'] as $historySale) {
    expectWarehouseIsolationSame('A0102', $historySale['warehouse_code'], 'A warehouse-only sales filter must exclude other warehouses');
}
expectWarehouseIsolationSame('', $warehouseOnlySalesHistory['desde'], 'Warehouse-only sales filtering must leave the start date empty');
expectWarehouseIsolationSame('', $warehouseOnlySalesHistory['hasta'], 'Warehouse-only sales filtering must leave the end date empty');

try {
    $salesModel->registrarSalida(
        [
            ['product_id' => 11, 'quantity' => 1],
            ['product_id' => 999, 'quantity' => 1],
        ],
        'A0102',
        date('Y-m-d'),
        'Venta incompleta',
        1
    );
    throw new RuntimeException('An invalid sale line must roll back the transaction.');
} catch (RuntimeException $exception) {
    expectWarehouseIsolationSame(
        'Un producto no tiene inventario configurado en ese almacén.',
        $exception->getMessage(),
        'Invalid sale must report the missing warehouse product'
    );
}
expectWarehouseIsolationSame(14, inventoryQuantity($database, 11, 'A0102'), 'A failed sale must roll back earlier stock changes');
expectWarehouseIsolationSame(2, (int) $database->query('SELECT COUNT(*) FROM ventas')->fetchColumn(), 'A failed sale must not remain in history');

$inventoryModel = new InventarioModelo($database);
$scannedSkuProduct = $inventoryModel->buscarProductoConteoFisicoPorCodigoBarras('SKU-11', 'A0101');
expectWarehouseIsolationSame(11, (int) $scannedSkuProduct['product_id'], 'Printed count tickets must resolve by operational SKU');
$scannedProduct = $inventoryModel->buscarProductoConteoFisicoPorCodigoBarras('b4x01-test-11', 'A0101');
expectWarehouseIsolationSame(11, (int) $scannedProduct['product_id'], 'QR scans must resolve to the matching operational product');
expectWarehouseIsolationSame(13, (int) $scannedProduct['system_quantity'], 'QR scans must return the operational warehouse balance');
expectWarehouseIsolationSame(13, inventoryQuantity($database, 11, 'A0101'), 'Resolving a QR must not change operational stock');
$countLabel = (new EtiquetasInventarioModelo($database))->obtenerProductoOperativo(11, 'A0101');
expectWarehouseIsolationSame('SKU-11', $countLabel['barcode'], 'Physical count tickets must encode the operational SKU');
$scanLookupFailed = false;
try {
    $inventoryModel->buscarProductoConteoFisicoPorCodigoBarras('UNKNOWN', 'A0101');
} catch (RuntimeException) {
    $scanLookupFailed = true;
}
expectWarehouseIsolationSame(true, $scanLookupFailed, 'Unknown QR codes must be rejected for physical counting');
$count = $inventoryModel->ajustarConteoFisico(11, 'A0101', 9, 'Conteo de prueba', 1);
expectWarehouseIsolationSame(-4, $count['difference'], 'Physical count must calculate the delta from the selected warehouse');
expectWarehouseIsolationSame(9, inventoryQuantity($database, 11, 'A0101'), 'Physical count must set the selected warehouse balance');
expectWarehouseIsolationSame(14, inventoryQuantity($database, 11, 'A0102'), 'Physical count must not change another warehouse');
$countMovement = $database->query(
    "SELECT almacen_codigo, tipo_referencia, cambio_cantidad, notas
     FROM movimientos_inventario WHERE tipo_referencia = 'physical_count'"
)->fetch();
expectWarehouseIsolationSame('A0101', $countMovement['almacen_codigo'], 'Count movement must retain the selected warehouse');
expectWarehouseIsolationSame(-4, (int) $countMovement['cambio_cantidad'], 'Count movement must record the adjustment');
if (!str_contains((string) $countMovement['notas'], 'Conteo de prueba')) {
    throw new RuntimeException('Count movements must retain their reason.');
}
$physicalCountReport = $inventoryModel->obtenerReporteConteoFisico('A0101');
expectWarehouseIsolationSame(1, count($physicalCountReport), 'Physical count report must include saved counts from its warehouse');
expectWarehouseIsolationSame(13, $physicalCountReport[0]['previous_quantity'], 'Physical count report must include the previous balance');
expectWarehouseIsolationSame(9, $physicalCountReport[0]['physical_quantity'], 'Physical count report must include the counted quantity');
expectWarehouseIsolationSame(-4, $physicalCountReport[0]['difference'], 'Physical count report must include the adjustment difference');
expectWarehouseIsolationSame('Conteo de prueba', $physicalCountReport[0]['reason'], 'Physical count report must include the reason');
expectWarehouseIsolationSame([], $inventoryModel->obtenerReporteConteoFisico('A0102'), 'Physical count report must exclude counts from other warehouses');
$latestPhysicalCounts = $inventoryModel->obtenerUltimosConteosFisicos('A0101');
expectWarehouseIsolationSame(9, $latestPhysicalCounts[11] ?? null, 'The last confirmed physical quantity must remain available for the count table');
expectWarehouseIsolationSame([], $inventoryModel->obtenerUltimosConteosFisicos('A0102'), 'Last confirmed counts must be isolated by warehouse');

$report = $inventoryModel->obtenerReporteOperativo([
    'warehouse' => 'A0101',
    'from' => '2000-01-01',
    'to' => '2099-12-31',
], true);
expectWarehouseIsolationSame(1, $report['total_products'], 'Report stock must be scoped to its selected warehouse');
expectWarehouseIsolationSame(9, $report['total_units'], 'Report total units must reflect the selected warehouse only');
expectWarehouseIsolationSame(2, $report['movement_count'], 'Report movements must filter to the selected warehouse');
foreach ($report['movements'] as $movement) {
    expectWarehouseIsolationSame('A0101', $movement['warehouse_code'], 'A warehouse report must exclude other warehouse movements');
}
$emptyPeriodReport = $inventoryModel->obtenerReporteOperativo([
    'warehouse' => 'A0101',
    'from' => '2000-01-01',
    'to' => '2000-01-02',
], true);
expectWarehouseIsolationSame(0, $emptyPeriodReport['movement_count'], 'Report movement dates must filter the ledger');
expectWarehouseIsolationSame(9, $emptyPeriodReport['total_units'], 'Date filters must not alter the current stock snapshot');
$invalidDateReport = $inventoryModel->obtenerReporteOperativo([
    'warehouse' => 'A0101',
    'from' => 'not-a-date',
    'to' => '',
], true);
expectWarehouseIsolationSame('', $invalidDateReport['from'], 'Invalid report dates must not be used as SQL filters');

$productsModel = new ProductosModelo($database);
$productsModel->guardarProducto([
    'sku' => 'SKU-NEW',
    'name' => 'Producto nuevo',
    'category' => 'Llantas',
    'brand' => 'Marca',
    'size' => '205/55 R16',
    'description' => 'Producto de prueba',
    'stock_a0101' => '3',
    'stock_a0102' => '0',
    'stock_a0103' => '2',
    'minimum_stock' => '6',
    'safety_stock' => '2',
    'supplier' => 'Proveedor',
], 25000, null, 1);
$newProductId = (int) $database->query("SELECT id FROM productos WHERE codigo_sku = 'SKU-NEW'")->fetchColumn();
expectWarehouseIsolationSame(3, inventoryQuantity($database, $newProductId, 'A0101'), 'Initial stock must be stored in the operational inventory');
expectWarehouseIsolationSame(0, inventoryQuantity($database, $newProductId, 'A0102'), 'Initial stock must retain zero for an empty warehouse');
expectWarehouseIsolationSame(2, inventoryQuantity($database, $newProductId, 'A0103'), 'Initial stock must be stored for every operational warehouse');
expectWarehouseIsolationSame(2, (int) $database->query(
    "SELECT COUNT(*) FROM movimientos_inventario WHERE producto_id = {$newProductId} AND tipo_referencia = 'product'"
)->fetchColumn(), 'Positive initial stock must be recorded in the movement ledger');

$database->exec(
    "INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor)
     VALUES
        (21, 'SKU-WINDA-15', 'WINDA T 185/65/15', 'Llantas', 'WINDA', '185/65R15', 'Prueba de similitud', 25000, 'Proveedor'),
        (22, 'SKU-WINDA-14', 'WINDA G 185/65/14', 'Llantas', 'WINDA', '185/65/14', 'Prueba de similitud', 25000, 'Proveedor');
     INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
        VALUES (21, 'A0101', 8, 5, 2), (22, 'A0101', 7, 5, 2);"
);
$database->exec(
    "INSERT INTO inventario_b4x01
        (codigo_barras, nombre, marca, medida, categoria, cantidad, almacen_codigo)
     VALUES
        ('B4X01-WINDA-15', 'WINDA T 185/65/15', 'WINDA', '185/65R15', 'Llantas', 4, 'A0101');"
);
$similarProducts = $inventoryModel->analizarCoincidenciasConteoFisico('A0101');
expectWarehouseIsolationSame(4, $similarProducts['products_analyzed'], 'Similarity analysis must inspect all tire products in the selected warehouse');
expectWarehouseIsolationSame(1, $similarProducts['total_matches'], 'Similarity analysis must identify a one-digit tire size and model-letter variation');
expectWarehouseIsolationSame(89, $similarProducts['matches'][0]['similarity'], 'Similarity analysis must report the combined match strength');
if (!str_contains($similarProducts['matches'][0]['reason'], 'Medida muy parecida')) {
    throw new RuntimeException('Similarity analysis must explain the near-match tire size.');
}
$focusedSimilarProducts = $inventoryModel->analizarCoincidenciasConteoFisico('A0101', 21);
expectWarehouseIsolationSame('SKU-WINDA-15', $focusedSimilarProducts['focus_product']['sku'], 'Focused analysis must identify the selected tire');
expectWarehouseIsolationSame(1, $focusedSimilarProducts['total_matches'], 'Focused analysis must only show matches for the selected tire');
$countWithScannedProductFirst = $inventoryModel->obtenerConteoFisico([
    'warehouse' => 'A0101',
    'scanned_product_ids' => [22],
]);
expectWarehouseIsolationSame(
    'SKU-WINDA-14',
    $countWithScannedProductFirst['rows'][0]['sku'],
    'Most recently scanned products must appear first without filtering the count list'
);
expectWarehouseIsolationSame(
    4,
    $countWithScannedProductFirst['total_rows'],
    'Prioritizing scanned products must not filter the physical-count list'
);
$countWithConfirmedProductFirst = $inventoryModel->obtenerConteoFisico([
    'warehouse' => 'A0101',
    'scanned_product_ids' => [11],
]);
expectWarehouseIsolationSame(
    'SKU-11',
    $countWithConfirmedProductFirst['rows'][0]['sku'],
    'A confirmed count must remain at the top when its product is prioritized'
);
$searchedTires = $inventoryModel->obtenerConteoFisico([
    'warehouse' => 'A0101',
    'q' => 'WINDA 185/65/15',
]);
expectWarehouseIsolationSame(1, $searchedTires['total_rows'], 'Tire search must match brand and size tokens across product fields');
expectWarehouseIsolationSame('SKU-WINDA-15', $searchedTires['rows'][0]['sku'], 'Tire search must return the intended product');
$tireSuggestions = $inventoryModel->buscarSugerenciasConteoFisico('A0101', 'WINDA 185/65/15');
expectWarehouseIsolationSame(1, count($tireSuggestions), 'Live suggestions must match brand and tire size across product fields');
expectWarehouseIsolationSame('SKU-WINDA-15', $tireSuggestions[0]['sku'], 'Live suggestions must identify the matching tire');
$partialTireSuggestions = $inventoryModel->buscarSugerenciasConteoFisico('A0101', 'WIN');
expectWarehouseIsolationSame(2, count($partialTireSuggestions), 'Live suggestions must return partial matches while the user is still typing');
$operationalSuggestions = $inventoryModel->buscarSugerenciasInventarioOperativo('WINDA 185/65/15', 'A0101');
expectWarehouseIsolationSame(1, count($operationalSuggestions), 'Inventory search suggestions must match multiple product fields');
expectWarehouseIsolationSame('SKU-WINDA-15', $operationalSuggestions[0]['sku'], 'Inventory search suggestions must identify the matching operational tire');
$allWarehouseSuggestions = $inventoryModel->buscarSugerenciasInventarioOperativo('Llanta de prueba');
expectWarehouseIsolationSame(1, count($allWarehouseSuggestions), 'Product suggestions must not duplicate products stocked in multiple warehouses');
expectWarehouseIsolationSame(2, (int) $allWarehouseSuggestions[0]['warehouse_count'], 'Product suggestions must summarize all matching warehouses');
$b4x01Suggestions = $inventoryModel->buscarSugerenciasInventarioB4x01('WINDA 185/65/15', 'A0101');
expectWarehouseIsolationSame(1, count($b4x01Suggestions), 'B4X01 search suggestions must match brand and tire size across fields');
expectWarehouseIsolationSame('B4X01-WINDA-15', $b4x01Suggestions[0]['barcode'], 'B4X01 search suggestions must return the matching barcode');
expectWarehouseIsolationSame(8, inventoryQuantity($database, 21, 'A0101'), 'Similarity analysis must not change stock');

$database->exec(
    "INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor)
     VALUES
        (23, 'ZZZDOU', 'ZZZ OTHER PRODUCT', 'Llantas', 'Marca', '', 'Prueba de prioridad SKU', 25000, 'Proveedor'),
        (24, 'SKU-OTHER', 'AAA DOU PRODUCT', 'Llantas', 'Marca', '', 'Prueba de prioridad SKU', 25000, 'Proveedor');
     INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
        VALUES (23, 'A0101', 1, 0, 0), (24, 'A0101', 1, 0, 0);"
);
$searchedBarcode = $inventoryModel->obtenerConteoFisico([
    'warehouse' => 'A0101',
    'q' => 'DOU',
]);
expectWarehouseIsolationSame(2, $searchedBarcode['total_rows'], 'A barcode search must retain all matching products');
expectWarehouseIsolationSame(
    'ZZZDOU',
    $searchedBarcode['rows'][0]['sku'],
    'A SKU match must rank ahead of alphabetical name-only matches'
);

$database->exec(
    "INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor)
     VALUES
        (30, '1365SIN116', '23.5 25 L3 - USADA', 'Sin clasificar', 'Otra', 'UND', 'Prueba de escaneo de SKU', 25000, 'Proveedor');
     INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
        VALUES (30, 'A0101', 19, 5, 2);"
);
$unclassifiedSkuLookup = $salesModel->buscarProductoDisponiblePorSku('1365sin116', 'A0101');
expectWarehouseIsolationSame(30, (int) ($unclassifiedSkuLookup['product_id'] ?? 0), 'A stocked non-llanta SKU must resolve in its warehouse');
expectWarehouseIsolationSame(
    'Sin clasificar',
    $unclassifiedSkuLookup['category'] ?? null,
    'SKU lookup must return the product category for sale quantity handling'
);
$salesModel->registrarSalida(
    [['product_id' => 30, 'quantity' => 1]],
    'A0101',
    date('Y-m-d'),
    'Venta de producto sin clasificar',
    1
);
expectWarehouseIsolationSame(18, inventoryQuantity($database, 30, 'A0101'), 'A scanned non-llanta product must sell through normal quantity handling');

$productsModel = new ProductosModelo($database);
$generatedSku = $productsModel->generarSkuAutomatico();
if (preg_match('/\AB4X01[A-F0-9]{9}\z/', $generatedSku) !== 1) {
    throw new RuntimeException('Automatic product SKUs must use the expected printable format.');
}
$insertGeneratedSku = $database->prepare(
    'INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor, ruta_imagen)
     VALUES (1000, :sku, \'Producto con SKU automático\', \'Llantas\', \'Marca\', \'205/55 R16\', \'\', 25000, \'Proveedor\', \'../uploads/products/test-product.webp\')'
);
$insertGeneratedSku->execute(['sku' => $generatedSku]);
if ($productsModel->generarSkuAutomatico() === $generatedSku) {
    throw new RuntimeException('Automatic SKU generation must avoid an existing product code.');
}
$database->exec(
    "INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
     VALUES (1000, 'A0102', 0, 4, 1)"
);
$updatedProductSku = 'B4X01ABCDEF123';
$productsModel->actualizarProducto(1000, [
    'sku' => $updatedProductSku,
    'name' => 'Producto con SKU actualizado',
    'category' => 'Llantas',
    'brand' => 'Marca',
    'size' => '205/55 R16',
    'description' => '',
    'supplier' => 'Proveedor',
], 26000);
expectWarehouseIsolationSame(
    $updatedProductSku,
    $productsModel->obtenerProductoParaEditar(1000)['sku'],
    'Editing the product SKU must update the shared product identifier'
);
expectWarehouseIsolationSame(
    $updatedProductSku,
    $productsModel->obtenerParaEtiqueta(1000)['sku'],
    'New product labels must use the updated shared SKU'
);
$catalogSearchByPrintedSku = $productsModel->buscarCatalogo('ABCDEF123', 1);
expectWarehouseIsolationSame(
    1,
    count($catalogSearchByPrintedSku['products']),
    'Searching by the printed SKU without its B4X01 prefix must find the product'
);
expectWarehouseIsolationSame(
    $updatedProductSku,
    $catalogSearchByPrintedSku['products'][0]['sku'],
    'Catalog search must preserve the full SKU used internally'
);
expectWarehouseIsolationSame(
    'ABCDEF123',
    $catalogSearchByPrintedSku['products'][0]['sku_display'],
    'Catalog results must show the same shortened SKU as the printed label'
);
expectWarehouseIsolationSame(
    '../uploads/products/test-product.webp',
    $catalogSearchByPrintedSku['products'][0]['image_path'],
    'Catalog results must include the saved product image path'
);
$savedStockThresholds = $database->query(
    "SELECT stock_minimo, stock_seguridad FROM inventario
     WHERE producto_id = 1000 AND almacen_codigo = 'A0102'"
)->fetch();
expectWarehouseIsolationSame(4, (int) $savedStockThresholds['stock_minimo'], 'Editing product details must preserve the existing minimum stock alert');
expectWarehouseIsolationSame(1, (int) $savedStockThresholds['stock_seguridad'], 'Editing product details must preserve the existing safety stock alert');
$database->exec(
    "INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
     VALUES (1000, 'A0101', 1, 5, 2);
     INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor)
     VALUES (1001, 'SKU-OTHER', 'DOUBLE STAR 215/60R17 WILDWOLF', 'Llantas', 'DOUBLE STAR', '215/60R17', '', 25000, 'Proveedor');
     INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
     VALUES (1001, 'A0101', 1, 5, 2);"
);
$newestCatalogProduct = $productsModel->buscarCatalogo('', 1);
expectWarehouseIsolationSame(
    1001,
    (int) ($newestCatalogProduct['products'][0]['id'] ?? 0),
    'The newest product must appear in the first catalog row'
);
$printedSkuSuggestions = $inventoryModel->buscarSugerenciasInventarioOperativo(
    'ABCDEF123',
    'A0101',
    12,
    true
);
expectWarehouseIsolationSame(
    1000,
    (int) ($printedSkuSuggestions[0]['product_id'] ?? 0),
    'Inventory alert suggestions must find the product by its printed SKU without B4X01'
);
$unrelatedWordSuggestions = $inventoryModel->buscarSugerenciasInventarioOperativo(
    'WILDWOLF',
    'A0101',
    12,
    true
);
if (in_array(1001, array_map('intval', array_column($unrelatedWordSuggestions, 'product_id')), true)) {
    throw new RuntimeException('Inventory alert suggestions must not match text embedded in unrelated product names.');
}
$warehouseComparison = $inventoryModel->obtenerComparacionAlmacenesOperativos([]);
expectWarehouseIsolationSame(
    1001,
    (int) ($warehouseComparison['rows'][0]['product_id'] ?? 0),
    'The newest product must appear first in the warehouse comparison table'
);
$insertSlowMovingProduct = $database->prepare(
    'INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor, creado_en)
     VALUES (:id, :sku, :name, "Llantas", "Marca", "205/55 R16", "Producto para rotación lenta", 25000, "Proveedor", :created_at)'
);
foreach ([
    [9100, 'SLOW-NEVER', 'Sin ventas históricas', date('Y-m-d', strtotime('-60 days'))],
    [9101, 'SLOW-NEW', 'Producto recién creado', date('Y-m-d', strtotime('-10 days'))],
    [9102, 'SLOW-WAREHOUSE', 'Venta reciente en otro almacén', date('Y-m-d', strtotime('-100 days'))],
] as [$productId, $sku, $name, $createdAt]) {
    $insertSlowMovingProduct->execute([
        'id' => $productId,
        'sku' => $sku,
        'name' => $name,
        'created_at' => $createdAt,
    ]);
}
$database->exec(
    "INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
     VALUES (9100, 'A0101', 4, 1, 1), (9101, 'A0101', 2, 1, 1),
            (9102, 'A0101', 3, 1, 1), (9102, 'A0102', 3, 1, 1)"
);
$oldSaleDate = date('Y-m-d', strtotime('-45 days'));
$recentSaleDate = date('Y-m-d', strtotime('-10 days'));
$insertHistoricalSale = $database->prepare(
    'INSERT INTO historico_ventas_excel
        (archivo_hash, hoja, fila_origen, codigo, marca, medida, almacen_codigo, fecha_venta, cantidad, tipo_origen)
     VALUES (:hash, "Sheet1", :row, :sku, "Marca", "205/55 R16", :warehouse, :sale_date, 1, "excel")'
);
$insertHistoricalSale->execute([
    'hash' => str_repeat('a', 64),
    'row' => 1,
    'sku' => 'SLOW-WAREHOUSE',
    'warehouse' => 'A0101',
    'sale_date' => $oldSaleDate,
]);
$database->exec(
    "INSERT INTO ventas (id, numero_venta, almacen_codigo, fecha_venta, creado_por, notas)
     VALUES (9102, 'TEST-SLOW-RECENT', 'A0102', '$recentSaleDate', 1, 'Venta reciente');
     INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_unitario_centavos, total_linea_centavos, estado)
     VALUES (9102, 9102, 1, 25000, 25000, 'activa'),
            (9102, 9100, 1, 25000, 25000, 'anulada')"
);
$slowMovingA0101 = $inventoryModel->obtenerAlertasRotacionLenta(['slow_warehouse' => 'A0101']);
$slowMovingA0101ById = [];
foreach ($slowMovingA0101['rows'] as $slowProduct) {
    $slowMovingA0101ById[(int) $slowProduct['product_id']] = $slowProduct;
}
expectWarehouseIsolationSame(true, isset($slowMovingA0101ById[9100]), 'An old product with stock and no sales must be prioritized');
expectWarehouseIsolationSame(true, $slowMovingA0101ById[9100]['never_sold'], 'Canceled sale lines must not count as a sale');
expectWarehouseIsolationSame(false, isset($slowMovingA0101ById[9101]), 'A new product must not be prioritized before 30 days');
expectWarehouseIsolationSame(true, isset($slowMovingA0101ById[9102]), 'Imported sales must identify the last sale for their warehouse');
expectWarehouseIsolationSame($oldSaleDate, $slowMovingA0101ById[9102]['last_sale_date'], 'The alert must show the imported last-sale date');
$slowMovingA0102 = $inventoryModel->obtenerAlertasRotacionLenta(['slow_warehouse' => 'A0102']);
expectWarehouseIsolationSame(
    false,
    in_array(9102, array_map('intval', array_column($slowMovingA0102['rows'], 'product_id')), true),
    'A recent sale in one warehouse must not create a slow-moving alert there'
);
$manualTireSale = $salesModel->registrarSalida(
    [['product_id' => 11, 'quantity' => 3]],
    'A0102',
    date('Y-m-d'),
    'Venta manual de llantas',
    1
);
expectWarehouseIsolationSame(
    11,
    inventoryQuantity($database, 11, 'A0102'),
    'A tire sale must accept a manually entered quantity without scans'
);
expectWarehouseIsolationSame(
    9,
    (int) $database->query("SELECT COUNT(*) FROM inventario_unidades WHERE estado = 'vendida' AND almacen_codigo = 'A0102'")->fetchColumn(),
    'A manual tire sale must assign available units when records exist'
);

$saleId = $database->prepare('SELECT id FROM ventas WHERE numero_venta = :number');
$saleId->execute(['number' => $manualTireSale['numero']]);
$saleId = (int) $saleId->fetchColumn();
$detailId = $database->prepare('SELECT id FROM detalle_ventas WHERE venta_id = :sale_id');
$detailId->execute(['sale_id' => $saleId]);
$detailId = (int) $detailId->fetchColumn();
$database->exec(
    "INSERT INTO productos
        (id, codigo_sku, nombre, categoria, marca, medida, descripcion, precio_unitario_centavos, proveedor)
     VALUES (9001, 'SKU-9001', 'Otra llanta de prueba', 'Llantas', 'Marca', '215/60 R16', 'Producto para corregir', 30000, 'Proveedor');
     INSERT INTO inventario (producto_id, almacen_codigo, cantidad, stock_minimo, stock_seguridad)
     VALUES (9001, 'A0102', 4, 2, 1)"
);
$addCorrectionUnit = $database->prepare(
    'INSERT INTO inventario_unidades (codigo_barras, producto_id, almacen_codigo)
     VALUES (:barcode, 9001, \'A0102\')'
);
for ($index = 1; $index <= 4; $index++) {
    $addCorrectionUnit->execute(['barcode' => 'CORRECT-UNIT-' . $index]);
}
$salesModel->reportarErrorVenta($saleId, $detailId, 1, 'producto_incorrecto', 'El cliente pidió otra llanta.');
expectWarehouseIsolationSame(
    1,
    count($salesModel->obtenerReportesPendientes()),
    'A submitted sale report must appear for administrator review'
);
expectWarehouseIsolationSame(
    11,
    inventoryQuantity($database, 11, 'A0102'),
    'Submitting a report must not change the sale stock before administrator review'
);
$salesModel->corregirDetalleVenta($detailId, 9001, 2, 1, 'El cliente pidió otra llanta.');
expectWarehouseIsolationSame(14, inventoryQuantity($database, 11, 'A0102'), 'Correcting a product must restore the mistaken product stock');
expectWarehouseIsolationSame(2, inventoryQuantity($database, 9001, 'A0102'), 'Correcting a product must subtract the corrected product stock');
expectWarehouseIsolationSame(0, count($salesModel->obtenerReportesPendientes()), 'Correcting a reported sale must resolve its pending report');
$correctedSale = $salesModel->obtenerDetalleVentaParaEditar($detailId);
expectWarehouseIsolationSame(9001, (int) $correctedSale['product_id'], 'The sale line must point to the corrected product');
expectWarehouseIsolationSame(2, (int) $correctedSale['quantity'], 'The sale line must retain the corrected quantity');
$salesModel->reportarErrorVenta($saleId, $detailId, 1, 'venta_no_realizada', 'El cliente no llevó la llanta.');
$salesModel->anularDetalleVenta($detailId, 1, 'El cliente no realizó la compra.');
expectWarehouseIsolationSame(4, inventoryQuantity($database, 9001, 'A0102'), 'Cancelling a sale line must restore its stock');
if ($salesModel->obtenerDetalleVentaParaEditar($detailId) !== false) {
    throw new RuntimeException('Cancelled sale lines must not be editable.');
}
expectWarehouseIsolationSame(0, count($salesModel->obtenerReportesPendientes()), 'Cancelling a reported sale must resolve its pending report');
expectWarehouseIsolationSame(
    0,
    (int) $database->query("SELECT COUNT(*) FROM inventario_unidades WHERE producto_id = 9001 AND estado = 'vendida' AND venta_id = " . $saleId)->fetchColumn(),
    'Cancelling a sale line must release its tire unit codes'
);
$saleForCancellation = $salesModel->registrarSalida(
    [['product_id' => 11, 'quantity' => 1], ['product_id' => 9001, 'quantity' => 1]],
    'A0102',
    date('Y-m-d'),
    'Venta de prueba para anulación completa',
    1
);
$saleId = $database->prepare('SELECT id FROM ventas WHERE numero_venta = :number');
$saleId->execute(['number' => $saleForCancellation['numero']]);
$saleId = (int) $saleId->fetchColumn();
$detailId = $database->prepare('SELECT id FROM detalle_ventas WHERE venta_id = :sale_id ORDER BY id LIMIT 1');
$detailId->execute(['sale_id' => $saleId]);
$detailId = (int) $detailId->fetchColumn();
$salesModel->reportarErrorVenta($saleId, $detailId, 1, 'venta_no_realizada', 'El cliente canceló toda la compra.');
$salesModel->anularVentaCompleta($saleId, 1, 'El cliente canceló toda la compra.');
expectWarehouseIsolationSame(14, inventoryQuantity($database, 11, 'A0102'), 'Cancelling a whole sale must restore every product stock');
expectWarehouseIsolationSame(4, inventoryQuantity($database, 9001, 'A0102'), 'Cancelling a whole sale must restore all corrected-product stock');
expectWarehouseIsolationSame(
    0,
    (int) $database->query("SELECT COUNT(*) FROM detalle_ventas WHERE venta_id = " . $saleId . " AND estado = 'activa'")->fetchColumn(),
    'Cancelling a whole sale must mark all of its lines inactive'
);
expectWarehouseIsolationSame(
    0,
    count($salesModel->obtenerHistorial(['q' => $saleForCancellation['numero']], 'administrador', 1)['ventas']),
    'Cancelled sales must not remain in the active sales history'
);
expectWarehouseIsolationSame(0, count($salesModel->obtenerReportesPendientes()), 'Cancelling a whole sale must resolve all of its reports');

fwrite(STDOUT, "InventoryWarehouseIsolation tests passed.\n");
