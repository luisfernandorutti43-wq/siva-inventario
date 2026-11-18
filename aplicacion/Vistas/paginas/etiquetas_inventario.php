<!doctype html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Etiquetas de inventario | BLACK4X4 LLANTAS</title>
	<link rel="stylesheet" href="estilos/modulos.css?v=20261110-guide-light-1">
	<link rel="stylesheet" href="estilos/etiquetas-inventario.css?v=20261116-qr-size-1">
</head>
<body class="inventory-label-page" data-print-labels="<?= $imprimirEtiquetas ? 'true' : 'false' ?>">
	<main class="label-page-main">
		<?php if (!$imprimirEtiquetas): ?>
			<section class="label-preview-panel">
				<h1>Imprimir etiquetas</h1>
				<p><strong><?= escape_html($producto['name']) ?></strong> · <?= escape_html($producto['brand'] . ' · ' . $producto['measure']) ?></p>
				<p>Almacén: <strong><?= escape_html($producto['warehouse_code'] . ' · ' . $producto['warehouse_name']) ?></strong></p>
				<form method="get" action="index.php">
					<input type="hidden" name="pagina" value="etiquetas_inventario">
					<input type="hidden" name="<?= escape_html($parametroProducto) ?>" value="<?= (int) $idProducto ?>">
					<input type="hidden" name="warehouse" value="<?= escape_html($producto['warehouse_code']) ?>">
					<label class="form-field">¿Cuántas llantas vas a etiquetar?
						<input type="number" name="copies" min="1" max="500" value="<?= $copias ?>" required>
					</label>
					<div class="label-page-actions">
						<a class="button button-quiet" href="index.php?pagina=inventario&amp;<?= escape_html(http_build_query(['view' => $esProductoOperativo ? 'count' : null, 'warehouse' => $producto['warehouse_code']])) ?>">Volver al inventario</a>
						<button class="button button-primary" type="submit" name="print" value="1">Imprimir etiquetas</button>
					</div>
				</form>
				<div class="label-preview">
					<div class="label-code-pair"><?= $codigoQrSvg ?><?= $codigoBarrasSvg ?></div>
					<strong><?= escape_html($producto['name']) ?></strong>
					<span><?= escape_html($producto['brand'] . ' · ' . $producto['measure']) ?></span>
					<span><?= escape_html($producto['barcode']) ?></span>
				</div>
				<?php if ($advertenciaCodigoBarras !== ''): ?><p class="notice notice-warning" role="status"><?= escape_html($advertenciaCodigoBarras) ?></p><?php endif; ?>
				<p class="label-print-hint">Cada ticket incluye QR ampliado y código de barras y se envía en papel térmico de 57 mm. Conecta Barcode+Printer por Bluetooth y prueba primero con una sola unidad.</p>
			</section>
		<?php else: ?>
			<div class="label-print-controls">
				<p data-print-status role="status" aria-live="polite">1. Conecta Barcode+Printer por Bluetooth y selecciona COM8. 2. Pulsa «Imprimir en la máquina». No se usará la impresora predeterminada.</p>
				<button class="button button-quiet" type="button" data-connect-printer>Conectar Barcode+Printer</button>
				<button class="button button-primary" type="button" data-print-direct disabled>Imprimir en la máquina</button>
				<button class="button button-quiet" type="button" data-disconnect-printer disabled>Desconectar</button>
				<a class="button button-quiet" href="index.php?<?= escape_html(http_build_query([
					'pagina' => 'inventario',
					'view' => $esProductoOperativo ? 'count' : 'scan',
					'warehouse' => $producto['warehouse_code'],
				])) ?>" data-cancel-print>Cancelar</a>
			</div>
			<?php if ($advertenciaCodigoBarras !== ''): ?><p class="notice notice-warning" role="status"><?= escape_html($advertenciaCodigoBarras) ?></p><?php endif; ?>
			<section class="label-sheet" aria-label="Etiquetas listas para imprimir">
				<?php for ($copia = 0; $copia < $copias; $copia++): ?>
					<article class="tire-label">
						<strong class="tire-label-brand">BLACK4X4 · <?= escape_html($producto['brand']) ?></strong>
						<span class="tire-label-name"><?= escape_html($producto['name']) ?></span>
						<span class="tire-label-measure"><?= escape_html($producto['measure']) ?></span>
						<div class="label-code-pair"><?= $codigoQrSvg ?><?= $codigoBarrasSvg ?></div>
						<span class="tire-label-code"><?= escape_html($producto['barcode']) ?></span>
						<span class="tire-label-warehouse"><?= escape_html($producto['warehouse_code']) ?></span>
					</article>
				<?php endfor; ?>
			</section>
		<?php endif; ?>
	</main>
	<script src="scripts/imprimir_etiquetas.js?v=20261116-label-qr-1" defer></script>
</body>
</html>