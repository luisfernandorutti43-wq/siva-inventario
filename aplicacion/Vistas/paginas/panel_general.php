<!doctype html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#f4f5f8">
	<title>Panel general | BLACK4X4 LLANTAS</title>
	<link rel="stylesheet" href="estilos/modulos.css?v=20261110-dashboard-report-1">
</head>
<body>
	<div class="products-app">
		<?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
		<div class="workspace">
			<?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, 'Panel general', 'Elige lo que necesitas hacer'); ?>
			<main class="products-main dashboard-main">
				<?php if ($demoNotice !== ''): ?><p class="notice notice-warning" role="status"><?= escape_html($demoNotice) ?></p><?php endif; ?>
				<section class="dashboard-hero" aria-labelledby="dashboard-welcome-title">
					<div class="dashboard-hero-copy">
						<span class="dashboard-hero-eyebrow"><span></span> PANEL DE OPERACIONES</span>
						<h2 id="dashboard-welcome-title">Bienvenido, <?= escape_html((string) $currentUser['full_name']) ?></h2>
						<p>Gestiona tus productos, existencias y movimientos desde un solo lugar.</p>
						<div class="dashboard-hero-tags"><span>Compras</span><i></i><span>Ventas</span><i></i><span>Inventario por almacén</span></div>
					</div>
					<?php if (in_array($currentUser['role'], ['administrador', 'almacen'], true)): ?>
						<a class="dashboard-hero-action" href="index.php?pagina=inventario">Revisar inventario <span aria-hidden="true">→</span></a>
					<?php elseif (in_array($currentUser['role'], ['administrador', 'vendedor'], true)): ?>
						<a class="dashboard-hero-action" href="index.php?pagina=ventas">Ver salidas / ventas <span aria-hidden="true">→</span></a>
					<?php endif; ?>
					<div class="dashboard-hero-orbit dashboard-hero-orbit-one"></div>
					<div class="dashboard-hero-orbit dashboard-hero-orbit-two"></div>
				</section>
				<?php if (in_array($currentUser['role'], ['administrador', 'almacen'], true)): ?>
					<section class="dashboard-stats" aria-label="Resumen del inventario operativo">
						<article class="dashboard-stat-products"><span class="dashboard-stat-icon" aria-hidden="true">P</span><div><span>Productos registrados</span><strong><?= number_format((int) $summary['product_count']) ?></strong><small>En el catálogo operativo</small></div></article>
						<article class="dashboard-stat-units"><span class="dashboard-stat-icon" aria-hidden="true">U</span><div><span>Unidades disponibles</span><strong><?= number_format((int) $summary['unit_count']) ?></strong><small>Solo Ventas y Compras</small></div></article>
						<article class="dashboard-stat-alerts"><span class="dashboard-stat-icon" aria-hidden="true">!</span><div><span>Productos por revisar</span><strong><?= number_format((int) $summary['low_stock_count']) ?></strong><small>En o por debajo del mínimo</small></div></article>
						<article class="dashboard-stat-sales"><span class="dashboard-stat-icon" aria-hidden="true">↗</span><div><span>Ventas este mes</span><strong><?= number_format((int) $summary['monthly_sale_count']) ?></strong><small>Operaciones registradas</small></div></article>
					</section>
					<section class="dashboard-inventory-scopes" aria-label="Saldos de inventario independientes">
						<article class="dashboard-inventory-scope dashboard-inventory-scope-operational">
							<div><span>INVENTARIO OPERATIVO · VENTAS Y COMPRAS</span><strong><?= number_format((int) $summary['unit_count']) ?> unidades</strong><small>Compras, ventas y conteos actualizan este saldo.</small></div>
							<a href="index.php?pagina=inventario&amp;view=report">Ver reporte <span aria-hidden="true">→</span></a>
						</article>
						<article class="dashboard-inventory-scope dashboard-inventory-scope-b4x">
							<div><span>INVENTARIO B4X01 · ETIQUETAS Y ESCANEO QR</span><strong><?= number_format((int) $summary['b4x01_unit_count']) ?> unidades</strong><small><?= number_format((int) $summary['b4x01_product_count']) ?> productos; es un saldo independiente.</small></div>
							<a href="index.php?pagina=inventario&amp;view=count">Contar inventario <span aria-hidden="true">→</span></a>
						</article>
					</section>
					<section class="dashboard-priority" aria-labelledby="dashboard-priority-title">
						<div class="dashboard-priority-heading"><div><span class="dashboard-section-eyebrow">REPOSICIÓN · SALDO OPERATIVO</span><h2 id="dashboard-priority-title">Productos que requieren atención</h2><p>Prioridad calculada por almacén; no mezcla existencias B4X01.</p></div><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=alerts">Ver todas las alertas</a></div>
						<?php if ($priorityAlerts === []): ?>
							<p class="dashboard-priority-empty">No hay productos por debajo de su mínimo en los almacenes activos.</p>
						<?php else: ?>
							<div class="table-wrap"><table class="dashboard-priority-table"><thead><tr><th>Producto</th><th>Almacén</th><th>Existencia / mínimo</th><th>Prioridad</th><th>Acción</th></tr></thead><tbody>
								<?php foreach ($priorityAlerts as $alert): ?>
									<tr><td><strong><?= escape_html($alert['name']) ?></strong><span><?= escape_html($alert['sku']) ?></span></td><td><strong><?= escape_html($alert['warehouse_code']) ?></strong><span><?= escape_html($alert['warehouse_name']) ?></span></td><td><?= number_format((int) $alert['quantity']) ?> / <?= number_format((int) $alert['minimum_stock']) ?></td><td><span class="dashboard-priority-badge<?= $alert['stock_status'] === 'critical' ? ' is-critical' : '' ?>"><?= $alert['stock_status'] === 'critical' ? 'Crítico' : 'Por reponer' ?></span></td><td><a href="index.php?pagina=compras&amp;view=new&amp;product_id=<?= (int) $alert['product_id'] ?>&amp;warehouse_code=<?= rawurlencode((string) $alert['warehouse_code']) ?>">Registrar compra</a></td></tr>
								<?php endforeach; ?>
							</tbody></table></div>
						<?php endif; ?>
					</section>
				<?php endif; ?>
				<section class="dashboard-quick-actions" aria-labelledby="quick-actions-title">
					<div class="dashboard-section-heading"><div><span class="dashboard-section-eyebrow">ACCESOS DIRECTOS</span><h2 id="quick-actions-title">¿Qué necesitas hacer?</h2><p>Accede rápidamente a las tareas principales.</p></div></div>
					<div class="quick-action-grid">
						<?php if (in_array($currentUser['role'], ['administrador', 'vendedor'], true)): ?>
							<a class="quick-action-card quick-action-sale" href="index.php?pagina=ventas&amp;view=new"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 19 19 5M8 5h11v11"/></svg></span><span class="quick-action-copy"><strong>Registrar una venta</strong><span>Registra una salida y actualiza el stock del almacén.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
						<?php endif; ?>
						<?php if (in_array($currentUser['role'], ['administrador', 'almacen'], true)): ?>
							<a class="quick-action-card quick-action-purchase" href="index.php?pagina=compras&amp;view=new"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 4v11m-4-4 4 4 4-4M5 17v3h14v-3"/></svg></span><span class="quick-action-copy"><strong>Recibir una compra</strong><span>Registra mercadería recibida en el almacén.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
							<a class="quick-action-card quick-action-inventory" href="index.php?pagina=inventario"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/></svg></span><span class="quick-action-copy"><strong>Revisar existencias</strong><span>Consulta cantidades y movimientos por almacén.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
							<a class="quick-action-card quick-action-forecast" href="index.php?pagina=inventario&amp;view=report"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 20V9m7 11V4m7 16v-7M3 20h18"/></svg></span><span class="quick-action-copy"><strong>Reportes por almacén</strong><span>Existencias, reposición y movimientos en Excel.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
						<?php endif; ?>
						<?php if ($currentUser['role'] === 'administrador'): ?>
							<a class="quick-action-card quick-action-product" href="index.php?pagina=productos&amp;view=new"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span class="quick-action-copy"><strong>Agregar un producto</strong><span>Incorpora un producto al catálogo.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
						<?php endif; ?>
						<a class="quick-action-card quick-action-search" href="index.php?pagina=productos"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg></span><span class="quick-action-copy"><strong>Buscar un producto</strong><span>Encuentra un SKU, marca o medida.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
						<a class="quick-action-card quick-action-forecast" href="index.php?pagina=prediccion_marcas"><span class="quick-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V5m0 14h17M7 15l4-4 3 2 6-7"/></svg></span><span class="quick-action-copy"><strong>Pronóstico de ventas</strong><span>Consulta estimaciones diarias generadas por IA.</span></span><span class="quick-action-arrow" aria-hidden="true">→</span></a>
					</div>
				</section>
				<?php if ((int) $summary['product_count'] === 0 && $recentSales === []): ?>
					<section class="dashboard-empty"><strong>Aún no hay movimientos</strong><p>Cuando registres compras o ventas, aquí aparecerá un resumen de la actividad.</p></section>
				<?php else: ?>
					<section class="dashboard-section product-catalog" aria-labelledby="recent-sales-title">
						<div class="catalog-heading"><div><span class="dashboard-section-eyebrow">ACTIVIDAD RECIENTE</span><h2 id="recent-sales-title">Últimas ventas</h2></div><?php if (in_array($currentUser['role'], ['administrador', 'vendedor'], true)): ?><a class="button button-primary" href="index.php?pagina=ventas&amp;view=new">Registrar venta <span aria-hidden="true">→</span></a><?php endif; ?></div>
						<?php if ($recentSales === []): ?><div class="catalog-empty"><strong>Sin ventas registradas</strong><p>Las ventas que registres aparecerán aquí.</p></div>
						<?php else: ?><label class="quick-filter"><span>Buscar entre las últimas ventas</span><input type="search" data-quick-filter="dashboard-recent-sales" placeholder="Documento o almacén" autocomplete="off"><small data-filter-status role="status" aria-live="polite"></small></label><div class="table-wrap"><table id="dashboard-recent-sales"><thead><tr><th>Documento</th><th>Fecha</th><th>Almacén</th><th>Total</th></tr></thead><tbody>
							<?php foreach ($recentSales as $sale): ?><tr><td><?= escape_html($sale['sale_number']) ?></td><td><?= escape_html($sale['sale_date']) ?></td><td><?= escape_html($sale['warehouse_code']) ?></td><td>$<?= number_format((int) $sale['total_cents'] / 100, 2, '.', ',') ?></td></tr><?php endforeach; ?>
						</tbody></table></div><?php endif; ?>
					</section>
				<?php endif; ?>
				<details class="demo-walkthrough">
					<summary><span class="demo-walkthrough-heading"><small>GUÍA PARA PRESENTAR</small><strong>Recorrido sugerido para demostrar SIVA</strong></span><span class="demo-walkthrough-toggle">Mostrar / ocultar</span></summary>
					<div class="demo-walkthrough-content">
						<p class="demo-walkthrough-intro">Presenta el flujo principal de SIVA paso a paso. Puedes abrir cada módulo para explicar sus funciones sin guardar operaciones de prueba.</p>
						<ol>
							<?php if (in_array($currentUser['role'], ['administrador', 'almacen'], true)): ?>
								<li><div class="demo-step-copy"><small>INVENTARIO</small><strong>Revisar existencias por almacén</strong><p>Compara el stock operativo y consulta el registro de movimientos.</p></div><div class="demo-step-links"><a href="index.php?pagina=inventario">Ver existencias</a><a href="index.php?pagina=ventas">Ver salidas</a></div></li>
							<?php endif; ?>
							<?php if (in_array($currentUser['role'], ['administrador', 'vendedor'], true)): ?>
								<li><div class="demo-step-copy"><small>SALIDAS / VENTAS</small><strong>Explicar el registro de una salida</strong><p>Al confirmar una venta autorizada, se descuenta el stock y queda guardado el movimiento.</p></div><a class="demo-step-link" href="index.php?pagina=ventas&amp;view=new">Abrir módulo</a></li>
							<?php endif; ?>
							<?php if (in_array($currentUser['role'], ['administrador', 'almacen'], true)): ?>
								<li><div class="demo-step-copy"><small>CONTROL DE STOCK</small><strong>Mostrar el conteo físico</strong><p>Se registra la cantidad encontrada y, si hay diferencia, el sistema guarda el ajuste con su motivo.</p></div><a class="demo-step-link" href="index.php?pagina=inventario&amp;view=count">Abrir conteo</a></li>
							<?php endif; ?>
							<li><div class="demo-step-copy"><small>PRONÓSTICO CON IA</small><strong>Terminar con el pronóstico</strong><p>La IA genera ventas diarias por llanta a partir del historial; no crea compras ni cambia existencias.</p></div><a class="demo-step-link" href="index.php?pagina=prediccion_marcas">Ver pronóstico</a></li>
						</ol>
						<p class="demo-walkthrough-note"><strong>Dato clave</strong><span>El inventario operativo de Ventas / Compras y la copia B4X01 del flujo QR son saldos independientes; no se deben sumar ni presentar como una misma existencia.</span></p>
					</div>
				</details>
			</main>
		</div>
	</div>
	<script src="scripts/filtro-facil.js?v=20261106-1630" defer></script>
</body>
</html>