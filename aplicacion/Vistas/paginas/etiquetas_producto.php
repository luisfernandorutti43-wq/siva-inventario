<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Etiquetas de producto | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261110-guide-light-1">
  <link rel="stylesheet" href="estilos/etiquetas-inventario.css?v=20261116-qr-size-1">
  <link rel="stylesheet" href="estilos/etiquetas-producto.css?v=20261116-qr-product-1">
</head>
<body class="inventory-label-page" data-print-labels="<?= $imprimirEtiquetas ? 'true' : 'false' ?>">
  <?php $codigoEtiquetaVisible = preg_replace('/\AB4X01/i', '', (string) $producto['sku']); ?>
  <main class="label-page-main">
    <?php if (!$imprimirEtiquetas): ?>
      <section class="label-preview-panel">
        <h1>Imprimir etiquetas de producto</h1>
        <p><strong><?= escape_html((string) $producto['name']) ?></strong></p>
        <p><?= escape_html((string) $producto['brand']) ?> · SKU <?= escape_html((string) $producto['sku']) ?> · <?= (int) $producto['stock'] ?> unidades disponibles en <?= escape_html($almacenSeleccionado) ?></p>
        <?php if ((int) $producto['unit_price_cents'] <= 0): ?>
          <p class="product-label-warning" role="alert">Este producto tiene precio $0.00. Actualiza el precio en Productos antes de imprimir la etiqueta.</p>
        <?php endif; ?>
        <form method="get" action="index.php">
          <input type="hidden" name="pagina" value="etiquetas_producto">
          <input type="hidden" name="product_id" value="<?= (int) $producto['id'] ?>">
          <label class="form-field">Almacén
            <select name="warehouse" required><?php foreach ($almacenes as $almacen): ?><option value="<?= escape_html($almacen['code']) ?>" <?= $almacenSeleccionado === $almacen['code'] ? 'selected' : '' ?>><?= escape_html($almacen['code'] . ' · ' . $almacen['name']) ?></option><?php endforeach; ?></select>
          </label>
          <?php if ($esLlanta): ?><label class="form-field">Unidades ya impresas
            <input type="number" name="offset" min="0" max="<?= (int) $producto['stock'] ?>" value="<?= (int) $desplazamiento ?>" required>
          </label><?php endif; ?>
          <label class="form-field">¿Cuántas etiquetas imprimir?
            <input type="number" name="copies" min="1" max="<?= max(1, min((int) $etiquetasDisponibles, 500)) ?>" value="<?= $copias ?>" required>
          </label>
          <div class="label-page-actions">
            <a class="button button-quiet" href="index.php?pagina=productos">Volver a Productos</a>
            <button class="button button-quiet" type="submit">Actualizar</button>
            <button class="button button-primary" type="submit" name="print" value="1" <?= $etiquetas === [] ? 'disabled' : '' ?>>Imprimir etiquetas</button>
          </div>
        </form>
        <?php if ($etiquetas !== []): ?>
          <div class="product-label-preview">
            <div class="product-label-barcode"><?= $etiquetas[0]['qr_svg'] ?><?= $etiquetas[0]['barcode_svg'] ?><small><?= escape_html($codigoEtiquetaVisible) ?></small></div>
            <div class="product-label-copy">
              <strong><?= escape_html((string) $producto['brand']) ?></strong>
              <span><?= escape_html((string) $producto['name']) ?></span>
              <small>SKU <?= escape_html($codigoEtiquetaVisible) ?></small>
              <b>$<?= number_format((int) $producto['unit_price_cents'] / 100, 2, '.', ',') ?></b>
            </div>
          </div>
        <?php else: ?>
          <p class="notice notice-warning">No hay unidades disponibles en este almacén para imprimir etiquetas.</p>
        <?php endif; ?>
        <p class="label-print-hint">Cada unidad recibe el mismo código de barras. Al escanear el QR con el celular se abre Ventas con este producto y almacén seleccionados; revisa la salida y pulsa Registrar salida para descontar existencias. Formato térmico de 57 mm.</p>
      </section>
    <?php else: ?>
      <div class="label-print-controls">
        <p data-print-status role="status" aria-live="polite">1. Conecta Barcode+Printer por Bluetooth y selecciona COM8. 2. Pulsa «Imprimir en la máquina». No se usará la impresora predeterminada.</p>
        <button class="button button-quiet" type="button" data-connect-printer>Conectar Barcode+Printer</button>
        <button class="button button-primary" type="button" data-print-direct disabled>Imprimir en la máquina</button>
        <button class="button button-quiet" type="button" data-disconnect-printer disabled>Desconectar</button>
        <a class="button button-quiet" href="index.php?pagina=productos" data-cancel-print>Cancelar</a>
      </div>
      <section class="label-sheet" aria-label="Etiquetas listas para imprimir">
        <?php foreach ($etiquetas as $etiqueta): ?>
          <article class="product-tire-label">
            <div class="product-tire-label-barcode"><?= $etiqueta['qr_svg'] ?><?= $etiqueta['barcode_svg'] ?><small><?= escape_html($codigoEtiquetaVisible) ?></small></div>
            <div class="product-tire-label-copy">
              <strong><?= escape_html((string) $producto['brand']) ?></strong>
              <span><?= escape_html((string) $producto['name']) ?></span>
              <small>SKU <?= escape_html($codigoEtiquetaVisible) ?></small>
              <b>$<?= number_format((int) $producto['unit_price_cents'] / 100, 2, '.', ',') ?></b>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>
  <script src="scripts/imprimir_etiquetas.js?v=20261116-label-qr-1" defer></script>
</body>
</html>
