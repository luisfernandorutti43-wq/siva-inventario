<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'aplicacion' . DIRECTORY_SEPARATOR
    . 'Servicios' . DIRECTORY_SEPARATOR . 'Servicios.php';

$url = 'http://192.168.18.54/siva-inventario/index.php?pagina=inventario'
    . '&view=count&warehouse=A0101&barcode=sku_tire-15&count_scan=1';
$svg = InventoryQrCode::svg($url);
if (!str_contains($svg, '<svg')) {
    throw new RuntimeException('Physical-count URLs with lowercase SKUs must generate QR SVG.');
}

$saleUrl = 'http://192.168.18.54/siva-inventario/index.php?pagina=ventas'
    . '&view=new&warehouse=A0101&scan_sku=SKU-11';
if (!str_contains(InventoryQrCode::svg($saleUrl), '<svg')) {
    throw new RuntimeException('Product sales URLs must generate QR SVG.');
}

$barcodeSvg = Code39Barcode::svg('1378DOU03');
if (!str_contains($barcodeSvg, 'class="barcode-svg"')
    || !str_contains($barcodeSvg, 'aria-label="Código de barras 1378DOU03"')
) {
    throw new RuntimeException('B4X01 product codes must generate a readable Code 39 barcode SVG.');
}

$invalidBarcode = "SKU-\x01TIRE";
$invalidCodeRejected = false;
try {
    InventoryQrCode::svg($invalidBarcode);
} catch (InvalidArgumentException) {
    $invalidCodeRejected = true;
}
if (!$invalidCodeRejected) {
    throw new RuntimeException('QR generation must reject control characters.');
}

fwrite(STDOUT, "Inventory QR and barcode tests passed.\n");
