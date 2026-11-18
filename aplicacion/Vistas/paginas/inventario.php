<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#090b0d">
  <title>Inventario | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261118-count-pending-status-1">
  <link rel="stylesheet" href="estilos/inventario.css?v=20261118-count-pending-status-1">
</head>
<body>
  <?php if ($inventoryScanSuccess !== '' || $inventoryScanError !== ''): ?>
    <?php $scanToastIsError = $inventoryScanError !== ''; ?>
    <aside class="inventory-scan-toast<?= $scanToastIsError ? ' inventory-scan-toast-error' : ' inventory-scan-toast-success' ?>"
      role="<?= $scanToastIsError ? 'alert' : 'status' ?>"
      aria-live="<?= $scanToastIsError ? 'assertive' : 'polite' ?>"
      <?= ($inventoryScanVoiceMessage !== '' || $deletedScanBatchVoiceMessage !== '') ? 'data-voice-message="' . escape_html($deletedScanBatchVoiceMessage !== '' ? $deletedScanBatchVoiceMessage : $inventoryScanVoiceMessage) . '"' : '' ?>
      <?= ($inventoryScanVoiceMessage !== '' || $deletedScanBatchVoiceMessage !== '') ? 'data-voice-autoplay="true"' : '' ?>
      <?= $scanToastIsError ? '' : 'data-auto-dismiss="true"' ?>>
      <span class="inventory-scan-toast-icon" aria-hidden="true"><?= $scanToastIsError ? '!' : '✓' ?></span>
      <p><?= escape_html($scanToastIsError ? $inventoryScanError : $inventoryScanSuccess) ?></p>
      <button type="button" class="inventory-scan-toast-close" aria-label="Cerrar aviso">&times;</button>
      <?php if ($inventoryScanVoiceMessage !== ''): ?>
        <button type="button" class="button button-quiet inventory-scan-replay" data-voice-replay>Repetir aviso</button>
        <span class="inventory-scan-voice-status" data-voice-status role="status" aria-live="polite"></span>
      <?php endif; ?>
    </aside>
  <?php endif; ?>
  <div class="products-app<?= $showScanner ? ' inventory-scan-app' : '' ?>">
    <?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
    <div class="workspace">
      <?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, $showMovements ? 'Movimientos' : 'Inventario'); ?>
      <main class="products-main">
        <?php
          $inventoryCurrentView = (string) ($_GET['view'] ?? '');
          if ($inventoryCurrentView === '' && $showWarehouseComparison) {
              $inventoryCurrentView = 'compare';
          }
          $inventorySubmodules = [
              [
                  'view' => 'compare',
                  'label' => 'Comparar almacenes',
                  'description' => 'Consulta los productos y sus existencias en cada almacén.',
              ],
              [
                  'view' => 'alerts',
                  'label' => 'Alertas de faltantes',
                  'description' => 'Encuentra productos con existencias bajo el mínimo.',
              ],
              [
                  'view' => 'count',
                  'label' => 'Conteo físico',
                  'description' => 'Cuenta productos y corrige el saldo operativo.',
              ],
          ];
        ?>
        <?php if (!$showScanner && !$showMovements): ?>
          <nav class="inventory-submodule-nav<?= $inventoryCurrentView === 'compare' ? ' is-overview' : ' is-compact' ?>" aria-label="Submódulos del inventario operativo">
            <div class="inventory-submodule-nav-heading">
              <p class="inventory-stock-eyebrow">Inventario operativo · Ventas y Compras</p>
            <h2><?= $inventoryCurrentView === 'compare' ? 'Existencias operativas por almacén' : 'Ir a otro submódulo' ?></h2>
            <p><?= $inventoryCurrentView === 'compare'
                ? 'Aquí ves el stock real que usan Ventas y Compras, separado por almacén. Las compras lo aumentan, las ventas lo descuentan y el conteo físico lo corrige. Usa las herramientas para revisar faltantes o consultar el historial. B4X01 se mantiene aparte para etiquetas y escaneo.'
                : 'Estas herramientas consultan o actualizan el mismo stock operativo que aparece en Existencias.' ?></p>
            </div>
            <div class="inventory-submodule-links">
              <?php foreach ($inventorySubmodules as $submodule): ?>
                <?php $submoduleIsCurrent = $inventoryCurrentView === $submodule['view']; ?>
                <a class="inventory-submodule-link<?= $submoduleIsCurrent ? ' is-current' : '' ?>"
                  href="index.php?pagina=inventario&amp;view=<?= escape_html($submodule['view']) ?>"
                  <?= $submoduleIsCurrent ? 'aria-current="page"' : '' ?>>
                  <strong><?= escape_html($submodule['label']) ?></strong>
                  <span><?= escape_html($submodule['description']) ?></span>
                  <?php if ($inventoryCurrentView !== $submodule['view']): ?><span class="inventory-submodule-open">Abrir submódulo <span aria-hidden="true">→</span></span><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </nav>
        <?php endif; ?>
        <?php if ($showScanner): ?>
          <a class="inventory-scan-back" href="index.php?pagina=inventario">Volver a existencias</a>
        <?php endif; ?>
        <?php if ($addedProduct !== null): ?>
          <section class="product-created-notice" role="status"<?= $productVoiceMessage !== '' ? ' data-voice-message="' . escape_html($productVoiceMessage) . '" data-voice-autoplay="true"' : '' ?>>
            <span class="product-created-icon" aria-hidden="true">✓</span>
            <div class="product-created-copy">
              <p class="product-created-eyebrow">Producto agregado</p>
              <h2><?= escape_html((string) $addedProduct['name']) ?></h2>
              <p>SKU <strong><?= escape_html((string) $addedProduct['sku']) ?></strong><span aria-hidden="true"> · </span>Stock inicial: <strong><?= number_format((int) $addedProduct['initial_stock']) ?> unidades</strong></p>
              <?php if ((int) $addedProduct['initial_stock'] > 0): ?>
                <p>El movimiento de entrada aparece abajo con su fecha y almacén.</p>
              <?php else: ?>
                <p>Se guardó sin stock inicial, por eso no se generó un movimiento de entrada.</p>
              <?php endif; ?>
              <?php if ($productVoiceMessage !== ''): ?><span class="visually-hidden" data-voice-status aria-live="polite"></span><?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
        <?php if ($showStockAlerts): ?>
          <?php
            $alertVoiceGuide = 'En Alertas de faltantes se listan los productos con stock igual o menor al mínimo. '
                . 'Usa la búsqueda, el almacén y la prioridad para filtrar. La cantidad para salir de alerta indica '
                . 'lo necesario para superar el mínimo; las unidades en tránsito se muestran aparte y no se restan '
                . 'del disponible. Usa Verificar saldo o Registrar compra para continuar. Las alertas solo informan; '
                . 'no cambian las existencias.';
          ?>
          <section class="product-catalog inventory-panel inventory-followup-panel" aria-labelledby="stock-alerts-title">
            <div class="inventory-feature-heading">
              <div><p class="inventory-stock-eyebrow">Seguimiento operativo · Inventario</p><h2 id="stock-alerts-title">Alertas de faltantes</h2><p>Alertas calculadas con el inventario operativo de Ventas y Compras. Se actualizan al registrar movimientos; no modifican existencias por sí solas.</p></div>
              <div class="inventory-alert-heading-actions">
                <div class="inventory-alert-voice" data-voice-message="<?= escape_html($alertVoiceGuide) ?>" data-voice-manual="true">
                  <span class="inventory-alert-voice-label"><span aria-hidden="true">◖</span> Guía por voz</span>
                  <button class="button button-quiet inventory-alert-voice-replay" type="button" data-voice-replay>Escuchar cómo funciona</button>
                  <span data-voice-status role="status" aria-live="polite">La guía está en silencio. Pulsa el botón si quieres escucharla.</span>
                </div>
                <a class="button button-quiet" href="index.php?pagina=inventario">Ver existencias operativas</a>
              </div>
            </div>
            <div class="inventory-followup-stats" aria-label="Resumen de alertas">
              <article><span>Existencias bajo mínimo</span><strong><?= number_format($stockAlerts['total_rows']) ?></strong></article>
              <article><span>En nivel crítico</span><strong><?= number_format($stockAlerts['critical_count']) ?></strong></article>
              <article><span>Con unidades en tránsito</span><strong><?= number_format($stockAlerts['in_transit_count']) ?></strong></article>
            </div>
            <form class="inventory-followup-filters" method="get" action="index.php" data-inventory-live-search-form>
              <input type="hidden" name="pagina" value="inventario"><input type="hidden" name="view" value="alerts">
              <label>Buscar producto
                <div class="inventory-live-search-control">
                  <input id="inventory-alert-search" type="search" name="q" value="<?= escape_html($stockAlerts['search']) ?>" placeholder="SKU, nombre, marca o medida" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="inventory-alert-search-suggestions" data-inventory-live-search-input>
                  <div id="inventory-alert-search-suggestions" class="physical-count-search-suggestions" role="listbox" aria-label="Productos encontrados" hidden data-inventory-live-search-suggestions></div>
                </div>
                <span class="physical-count-search-status" role="status" aria-live="polite" data-inventory-live-search-status>Mientras escribes aparecerán sugerencias.</span>
              </label>
              <label>Almacén<select name="warehouse"><option value="">Todos los almacenes</option><?php foreach ($stockAlerts['warehouses'] as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $stockAlerts['warehouse'] === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
              <label>Prioridad<select name="status"><option value="">Todas</option><option value="critical" <?= $stockAlerts['status'] === 'critical' ? 'selected' : '' ?>>Crítico</option><option value="warning" <?= $stockAlerts['status'] === 'warning' ? 'selected' : '' ?>>Por reponer</option></select></label>
              <div class="inventory-followup-filter-actions"><button class="button button-primary" type="submit">Aplicar filtros</button><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=alerts">Limpiar</a></div>
            </form>
            <?php if ($stockAlerts['rows'] === []): ?>
              <div class="catalog-empty"><strong><?= $stockAlerts['total_rows'] === 0 ? 'Sin faltantes por atender' : 'No hay resultados en esta página' ?></strong><p><?= $stockAlerts['total_rows'] === 0 ? 'No hay existencias operativas iguales o inferiores a su mínimo con estos filtros.' : 'Prueba con otros filtros para encontrar alertas.' ?></p></div>
            <?php else: ?>
              <p class="inventory-followup-note">Cada alerta se mantiene visible mientras el stock esté en o por debajo del mínimo. “Para salir de alerta” indica las unidades necesarias para superar ese mínimo. Confirma la recepción con una compra o revisa el saldo con un conteo físico; el sistema no descuenta unidades en tránsito del stock actual.</p>
              <div class="table-wrap"><table class="inventory-alert-table"><thead><tr><th>Prioridad / producto</th><th>Almacén</th><th>Disponible</th><th>Mínimo</th><th>Para salir de alerta</th><th>En tránsito</th><th>Seguimiento</th></tr></thead><tbody>
                <?php foreach ($stockAlerts['rows'] as $alert): ?>
                  <?php $criticalAlert = $alert['stock_status'] === 'critical'; ?>
                  <tr>
                    <td><span class="inventory-alert-priority<?= $criticalAlert ? ' is-critical' : '' ?>"><?= $criticalAlert ? 'Crítico' : 'Por reponer' ?></span><strong class="inventory-alert-product"><?= escape_html($alert['name']) ?></strong><span class="table-secondary"><?= escape_html($alert['sku'] . ' · ' . trim($alert['brand'] . ' ' . $alert['size'])) ?></span></td>
                    <td><span class="table-warehouse-code"><?= escape_html($alert['warehouse_code']) ?></span><span class="table-warehouse-name"><?= escape_html($alert['warehouse_name']) ?></span></td>
                    <td><strong class="inventory-alert-quantity"><?= (int) $alert['quantity'] ?></strong> ud.</td>
                    <td><?= (int) $alert['minimum_stock'] ?> ud.</td>
                    <td><strong><?= (int) $alert['shortage_quantity'] ?></strong> ud.</td>
                    <td><?= (int) $alert['stock_in_transit'] ?> ud.</td>
                    <td><div class="inventory-alert-actions"><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=count&amp;warehouse=<?= rawurlencode((string) $alert['warehouse_code']) ?>&amp;q=<?= rawurlencode((string) $alert['sku']) ?>">Verificar saldo</a><a class="button button-quiet" href="index.php?pagina=movimientos&amp;warehouse=<?= rawurlencode((string) $alert['warehouse_code']) ?>&amp;q=<?= rawurlencode((string) $alert['sku']) ?>">Ver movimientos</a><a class="button button-primary" href="index.php?pagina=compras&amp;view=new&amp;product_id=<?= (int) $alert['product_id'] ?>&amp;warehouse_code=<?= rawurlencode((string) $alert['warehouse_code']) ?>">Registrar compra</a></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <nav class="inventory-pagination" aria-label="Paginación de alertas">
                <span>Mostrando <?= $stockAlerts['page_offset'] + 1 ?>–<?= min($stockAlerts['page_offset'] + $stockAlerts['page_size'], $stockAlerts['total_rows']) ?> de <?= number_format($stockAlerts['total_rows']) ?> alertas</span>
                <div><?php if ($stockAlerts['page'] > 1): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => null, 'page' => $stockAlerts['page'] - 1]))) ?>">Anterior</a><?php endif; ?><span>Página <?= $stockAlerts['page'] ?> de <?= $stockAlerts['total_pages'] ?></span><?php if ($stockAlerts['page'] < $stockAlerts['total_pages']): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => null, 'page' => $stockAlerts['page'] + 1]))) ?>">Siguiente</a><?php endif; ?></div>
              </nav>
            <?php endif; ?>
          </section>
        <?php elseif ($showOperationalReport): ?>
          <?php
            $reportDownloadQuery = http_build_query([
                'pagina' => 'inventario',
                'view' => 'report',
                'warehouse' => $operationalReport['warehouse'],
                'from' => $operationalReport['from'],
                'to' => $operationalReport['to'],
                'descargar_reporte' => '1',
            ]);
          ?>
          <section class="product-catalog inventory-panel inventory-report-panel" aria-labelledby="operational-report-title">
            <div class="inventory-feature-heading">
              <div><p class="inventory-stock-eyebrow">Reporte · Inventario operativo</p><h2 id="operational-report-title">Existencias y movimientos por almacén</h2><p>El reporte incluye reposición, historial y trazabilidad. No incluye el inventario B4X01 de etiquetas y escaneo.</p></div>
              <a class="button button-primary" href="index.php?<?= escape_html($reportDownloadQuery) ?>">Descargar Excel</a>
            </div>
            <p class="inventory-report-date-note">El almacén filtra tanto existencias actuales como movimientos. Las fechas filtran movimientos; las existencias son una fotografía actual.</p>
            <form class="inventory-followup-filters inventory-report-filters" method="get" action="index.php">
              <input type="hidden" name="pagina" value="inventario"><input type="hidden" name="view" value="report">
              <label>Almacén<select name="warehouse"><option value="">Todos los almacenes</option><?php foreach ($operationalReport['warehouses'] as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $operationalReport['warehouse'] === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
              <label>Movimientos desde<input type="date" name="from" value="<?= escape_html($operationalReport['from']) ?>"></label>
              <label>Movimientos hasta<input type="date" name="to" value="<?= escape_html($operationalReport['to']) ?>"></label>
              <div class="inventory-followup-filter-actions"><button class="button button-primary" type="submit">Aplicar filtros</button><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=report">Limpiar</a></div>
            </form>
            <div class="inventory-followup-stats inventory-report-stats" aria-label="Resumen del reporte">
              <article><span>Registros de existencias</span><strong><?= number_format($operationalReport['total_products']) ?></strong></article>
              <article><span>Unidades operativas</span><strong><?= number_format($operationalReport['total_units']) ?></strong></article>
              <article><span>Críticos</span><strong><?= number_format($operationalReport['critical_count']) ?></strong></article>
              <article><span>Por reponer</span><strong><?= number_format($operationalReport['replenishment_count']) ?></strong></article>
            </div>
            <div class="inventory-report-section-heading"><div><h3>Productos por revisar</h3><span>Hasta 20 prioridades; el Excel incluye todas las existencias.</span></div><a href="index.php?pagina=inventario&amp;view=alerts">Abrir alertas</a></div>
            <?php if ($operationalReport['alert_rows'] === []): ?>
              <div class="catalog-empty"><strong>Sin faltantes por atender</strong><p>No hay existencias iguales o inferiores al mínimo para este filtro.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table class="inventory-report-table"><thead><tr><th>Producto / SKU</th><th>Almacén</th><th>Existencia</th><th>Mínimo</th><th>En tránsito</th><th>Faltan</th><th>Prioridad</th></tr></thead><tbody>
                <?php foreach ($operationalReport['alert_rows'] as $alert): ?>
                  <tr><td><strong><?= escape_html($alert['name']) ?></strong><span><?= escape_html($alert['sku'] . ' · ' . trim($alert['brand'] . ' ' . $alert['size'])) ?></span></td><td><strong><?= escape_html($alert['warehouse_code']) ?></strong><span><?= escape_html($alert['warehouse_name']) ?></span></td><td><?= number_format((int) $alert['quantity']) ?></td><td><?= number_format((int) $alert['minimum_stock']) ?></td><td><?= number_format((int) $alert['stock_in_transit']) ?></td><td><strong><?= number_format((int) $alert['shortage_quantity']) ?></strong></td><td><span class="dashboard-priority-badge<?= $alert['stock_status'] === 'critical' ? ' is-critical' : '' ?>"><?= $alert['stock_status'] === 'critical' ? 'Crítico' : 'Por reponer' ?></span></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
            <div class="inventory-report-section-heading"><div><h3>Movimientos del período</h3><span>Mostrando hasta 20 de <?= number_format($operationalReport['movement_count']) ?>; el Excel incluye el historial completo filtrado.</span></div><a href="index.php?pagina=movimientos&amp;warehouse=<?= rawurlencode($operationalReport['warehouse']) ?>&amp;movement_from=<?= rawurlencode($operationalReport['from']) ?>&amp;movement_to=<?= rawurlencode($operationalReport['to']) ?>">Ver trazabilidad general</a></div>
            <?php if ($operationalReport['movements'] === []): ?>
              <div class="catalog-empty"><strong>Sin movimientos en el período</strong><p>Prueba con otras fechas o selecciona todos los almacenes.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table class="inventory-report-table"><thead><tr><th>Fecha y hora</th><th>Producto</th><th>Almacén</th><th>Tipo</th><th>Variación</th><th>Detalle / motivo</th><th>Usuario</th></tr></thead><tbody>
                <?php foreach ($operationalReport['movements'] as $movement): ?>
                  <?php
                    $referenceType = (string) $movement['reference_type'];
                    $movementLabel = (string) $movement['movement_type'] === 'adjustment'
                        ? match ($referenceType) {
                            'purchase_cancel' => 'Anulación de compra',
                            'purchase_edit' => 'Corrección de compra',
                            'physical_count' => 'Ajuste de conteo',
                            default => 'Ajuste',
                        }
                        : (['opening' => 'Stock inicial', 'outgoing' => 'Salida / venta', 'purchase' => 'Compra'][$movement['movement_type']] ?? $movement['movement_type']);
                    $movementDate = (string) $movement['created_at'];
                    $quantityChange = (int) $movement['quantity_change'];
                  ?>
                  <tr><td><time datetime="<?= escape_html(str_replace(' ', 'T', $movementDate)) ?>"><?= escape_html($movementDate) ?></time></td><td><strong><?= escape_html($movement['product_name']) ?></strong><span><?= escape_html($movement['product_sku'] . ' · ' . trim($movement['product_brand'] . ' ' . $movement['product_measure'])) ?></span></td><td><strong><?= escape_html($movement['warehouse_code']) ?></strong><span><?= escape_html($movement['warehouse_name']) ?></span></td><td><?= escape_html($movementLabel) ?></td><td class="movement-quantity<?= $quantityChange < 0 ? ' movement-quantity-out' : ($quantityChange > 0 ? ' movement-quantity-in' : '') ?>"><?= $quantityChange > 0 ? '+' : '' ?><?= number_format($quantityChange) ?></td><td><?= escape_html($movement['notes']) ?></td><td><?= escape_html($movement['user_name']) ?></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>
        <?php elseif ($showWarehouseComparison): ?>
          <?php
            $comparisonVoiceGuide = 'En Comparar almacenes ves todos los productos y cuántas unidades hay en cada almacén. '
                . 'Busca por código, nombre, marca o medida para encontrar una llanta. El total operativo suma las unidades '
                . 'de los almacenes activos. Las compras agregan stock, las ventas lo descuentan y los conteos físicos lo '
                . 'corrigen. Esta pantalla solo consulta; no cambia el inventario.';
          ?>
          <section class="product-catalog inventory-panel inventory-comparison-panel" aria-labelledby="warehouse-comparison-title">
            <div class="inventory-feature-heading">
              <div><p class="inventory-stock-eyebrow">Inventario usado por Ventas y Compras</p><h2 id="warehouse-comparison-title">Comparar almacenes</h2><p>Aquí aparecen todos los productos del catálogo, incluso sin stock, con sus existencias por almacén. Las compras, ventas y conteos actualizan este inventario; los productos recién creados aparecen primero. El inventario B4X01 se consulta por separado.</p></div>
              <div class="inventory-feature-actions"><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=alerts">Ver faltantes</a><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=scan">Escaneo B4X01 · QR y barras</a></div>
            </div>
            <div class="inventory-guide-voice" data-voice-message="<?= escape_html($comparisonVoiceGuide) ?>" data-voice-manual="true">
              <button class="button button-quiet" type="button" data-voice-replay>Escuchar cómo funciona</button>
              <span data-voice-status role="status" aria-live="polite">La guía está en silencio. Pulsa el botón si quieres escucharla.</span>
            </div>
            <section class="inventory-slow-moving-panel" aria-labelledby="slow-moving-title">
              <div class="inventory-slow-moving-heading">
                <div>
                  <p class="inventory-stock-eyebrow">Recomendación de venta · <?= (int) $slowMovingProducts['threshold_days'] ?> días</p>
                  <h3 id="slow-moving-title">Prioridad de venta</h3>
                  <p>Productos con stock que no registran ventas en este almacén durante <?= (int) $slowMovingProducts['threshold_days'] ?> días o más. Es solo una recomendación: no bloquea la venta ni cambia el precio.</p>
                </div>
                <strong class="inventory-slow-moving-count"><?= number_format((int) $slowMovingProducts['total_rows']) ?> productos</strong>
              </div>
              <form class="inventory-followup-filters inventory-slow-moving-filter" method="get" action="index.php">
                <input type="hidden" name="pagina" value="inventario">
                <input type="hidden" name="view" value="compare">
                <?php if ($warehouseComparison['search'] !== ''): ?><input type="hidden" name="q" value="<?= escape_html($warehouseComparison['search']) ?>"><?php endif; ?>
                <label>Almacén
                  <select name="slow_warehouse">
                    <option value="">Todos los almacenes</option>
                    <?php foreach ($slowMovingProducts['warehouses'] as $warehouse): ?>
                      <option value="<?= escape_html($warehouse['code']) ?>"<?= $slowMovingProducts['warehouse'] === $warehouse['code'] ? ' selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button class="button button-primary" type="submit">Filtrar prioridad</button>
                <?php if ($slowMovingProducts['warehouse'] !== ''): ?><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=compare<?= $warehouseComparison['search'] !== '' ? '&amp;q=' . rawurlencode($warehouseComparison['search']) : '' ?>">Ver todos</a><?php endif; ?>
              </form>
              <p class="inventory-followup-note">Se consideran ventas manuales y ventas importadas, separadas por almacén. Si nunca se vendió, el plazo se cuenta desde la creación del producto.</p>
              <?php if ($slowMovingProducts['rows'] === []): ?>
                <div class="catalog-empty"><strong>No hay productos estancados en este filtro</strong><p>Los productos con stock y sin ventas durante <?= (int) $slowMovingProducts['threshold_days'] ?> días aparecerán aquí.</p></div>
              <?php else: ?>
                <div class="table-wrap">
                  <table class="inventory-alert-table inventory-slow-moving-table">
                    <thead><tr><th>Producto / SKU</th><th>Almacén</th><th>Stock actual</th><th>Días sin venta</th><th>Última venta</th></tr></thead>
                    <tbody>
                      <?php foreach ($slowMovingProducts['rows'] as $product): ?>
                        <tr>
                          <td><strong><?= escape_html($product['name']) ?></strong><span><?= escape_html($product['sku'] . ' · ' . trim($product['brand'] . ' ' . $product['size'])) ?></span></td>
                          <td><strong><?= escape_html($product['warehouse_code']) ?></strong><span><?= escape_html($product['warehouse_name']) ?></span></td>
                          <td><strong><?= number_format((int) $product['stock']) ?></strong></td>
                          <td><span class="inventory-slow-moving-days"><?= number_format((int) $product['days_without_sale']) ?> días</span></td>
                          <td><?= $product['never_sold'] ? 'Sin ventas registradas' : escape_html($product['last_sale_date']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php if ($slowMovingProducts['total_rows'] > count($slowMovingProducts['rows'])): ?>
                  <p class="inventory-slow-moving-more">Mostrando los <?= count($slowMovingProducts['rows']) ?> más antiguos de <?= number_format((int) $slowMovingProducts['total_rows']) ?> productos.</p>
                <?php endif; ?>
              <?php endif; ?>
            </section>
            <div class="inventory-followup-stats" aria-label="Resumen de comparación">
              <article><span>Productos consultados</span><strong><?= number_format($warehouseComparison['total_rows']) ?></strong></article>
              <article><span>Unidades en almacenes activos</span><strong><?= number_format($warehouseComparison['total_units']) ?></strong></article>
              <article><span>Con stock desigual</span><strong><?= number_format($warehouseComparison['differing_product_count']) ?></strong></article>
            </div>
            <form class="inventory-followup-filters inventory-comparison-search" method="get" action="index.php" data-inventory-live-search-form>
              <input type="hidden" name="pagina" value="inventario">
              <label>Buscar producto
                <div class="inventory-live-search-control">
                  <input id="inventory-comparison-search" type="search" name="q" value="<?= escape_html($warehouseComparison['search']) ?>" placeholder="SKU, nombre, marca o medida" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="inventory-comparison-search-suggestions" data-inventory-live-search-input>
                  <div id="inventory-comparison-search-suggestions" class="physical-count-search-suggestions" role="listbox" aria-label="Productos encontrados" hidden data-inventory-live-search-suggestions></div>
                </div>
                <span class="physical-count-search-status" role="status" aria-live="polite" data-inventory-live-search-status>Mientras escribes aparecerán sugerencias.</span>
              </label>
              <button class="button button-primary" type="submit">Buscar</button>
              <?php if ($warehouseComparison['search'] !== ''): ?><a class="button button-quiet" href="index.php?pagina=inventario">Limpiar</a><?php endif; ?>
            </form>
            <?php if ($warehouseComparison['rows'] === []): ?>
              <div class="catalog-empty"><strong>Sin productos para mostrar</strong><p>Revisa el término de búsqueda o el catálogo operativo.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table class="inventory-comparison-table"><thead><tr><th>Producto / SKU</th><?php foreach ($warehouseComparison['warehouses'] as $warehouse): ?><th><?= escape_html($warehouse['code']) ?><small><?= escape_html($warehouse['name']) ?></small></th><?php endforeach; ?><th>Total operativo</th><th>Distribución</th></tr></thead><tbody>
                <?php foreach ($warehouseComparison['rows'] as $product): ?>
                  <tr><td><strong><?= escape_html($product['name']) ?></strong><span class="table-secondary"><?= escape_html($product['sku'] . ' · ' . trim($product['brand'] . ' ' . $product['size'])) ?></span></td>
                    <?php foreach ($warehouseComparison['warehouses'] as $index => $warehouse): ?><?php $warehouseStock = (int) $product['stock_' . $index]; ?><td><span class="comparison-stock<?= $warehouseStock === 0 ? ' is-empty' : '' ?>"><?= number_format($warehouseStock) ?></span></td><?php endforeach; ?>
                    <td><strong class="comparison-total"><?= number_format((int) $product['total_units']) ?></strong></td>
                    <td><span class="comparison-coverage"><?= (int) $product['warehouse_count'] ?> de <?= count($warehouseComparison['warehouses']) ?> con stock</span><?php if ((int) $product['stock_spread'] > 0): ?><small class="comparison-spread">Diferencia entre almacenes: <?= number_format((int) $product['stock_spread']) ?> ud.</small><?php else: ?><small class="comparison-spread is-even">Stock distribuido por igual</small><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <nav class="inventory-pagination" aria-label="Paginación de comparación">
                <span>Mostrando <?= $warehouseComparison['page_offset'] + 1 ?>–<?= min($warehouseComparison['page_offset'] + $warehouseComparison['page_size'], $warehouseComparison['total_rows']) ?> de <?= number_format($warehouseComparison['total_rows']) ?> productos</span>
                <div><?php if ($warehouseComparison['page'] > 1): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => null, 'page' => $warehouseComparison['page'] - 1]))) ?>">Anterior</a><?php endif; ?><span>Página <?= $warehouseComparison['page'] ?> de <?= $warehouseComparison['total_pages'] ?></span><?php if ($warehouseComparison['page'] < $warehouseComparison['total_pages']): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => null, 'page' => $warehouseComparison['page'] + 1]))) ?>">Siguiente</a><?php endif; ?></div>
              </nav>
            <?php endif; ?>
          </section>
        <?php elseif ($showPhysicalCount): ?>
          <?php
            $physicalCountVoiceGuide = 'En Conteo físico, primero selecciona el almacén. Busca el producto y cuenta todas las unidades '
                . 'que hay físicamente. Escanea el código QR o de barras para sumar una unidad, o corrige el total manualmente. '
                . 'Escribe el total real, no la diferencia. Añade el motivo y pulsa Confirmar conteo para actualizar el inventario. '
                . 'Escanear por sí solo no cambia el stock; el cambio ocurre al confirmar.';
          ?>
          <section class="product-catalog inventory-panel" aria-labelledby="physical-count-title">
            <div class="catalog-heading"><h2 id="physical-count-title">Conteo físico del inventario operativo</h2></div>
            <div class="inventory-guide-voice" data-voice-message="<?= escape_html($physicalCountVoiceGuide) ?>" data-voice-manual="true">
              <button class="button button-quiet" type="button" data-voice-replay>Escuchar cómo funciona</button>
              <span data-voice-status role="status" aria-live="polite">La guía está en silencio. Pulsa el botón si quieres escucharla.</span>
            </div>
            <?php if ($physicalCount['warehouse'] !== ''): ?>
              <div class="physical-count-report-actions" aria-label="Descargar reportes del conteo físico">
                <a class="button button-quiet" href="index.php?pagina=inventario&amp;exportar_conteo_fisico_pdf=1&amp;warehouse=<?= rawurlencode($physicalCount['warehouse']) ?>">Descargar PDF</a>
                <a class="button button-quiet" href="index.php?pagina=inventario&amp;exportar_conteo_fisico=1&amp;warehouse=<?= rawurlencode($physicalCount['warehouse']) ?>">Descargar Excel</a>
              </div>
            <?php endif; ?>
            <?php if ($showSimilarProductAnalysis): ?>
              <?php
              $similarityCount = (int) $similarProductAnalysis['total_matches'];
              $similarityVoiceMessage = $similarityCount === 0
                  ? 'No encontré llantas parecidas.'
                  : 'Encontré ' . $similarityCount . ' '
                      . ($similarityCount === 1 ? 'llanta parecida.' : 'llantas parecidas.')
                      . ' Revisa el aviso.';
              ?>
              <dialog class="physical-count-similarity-dialog" aria-labelledby="physical-count-similarity-title" data-physical-count-similarity-dialog data-voice-message="<?= escape_html($similarityVoiceMessage) ?>" data-voice-autoplay="true" data-voice-manual="true">
                <div class="physical-count-similarity-dialog-heading">
                  <span>Revisión de producto similar</span>
                  <div class="physical-count-similarity-dialog-actions">
                    <span data-voice-status aria-live="polite"></span>
                    <button class="button button-quiet" type="button" data-voice-replay>Reproducir aviso</button>
                    <button class="button button-quiet" type="button" aria-label="Cerrar aviso" data-close-physical-count-similarity>Cerrar</button>
                  </div>
                </div>
                <section class="physical-count-similarity-results" aria-labelledby="physical-count-similarity-title" aria-live="polite">
                <h3 id="physical-count-similarity-title">Posibles cruces para <?= escape_html($similarProductAnalysis['focus_product']['name']) ?></h3>
                <p>Se compararon medida, marca y nombre/modelo con los productos de <?= escape_html($physicalCount['warehouse']) ?>. Son sugerencias para revisar; el análisis no modifica existencias.</p>
                <?php if ($similarProductAnalysis['matches'] === []): ?>
                  <p>No encontramos llantas suficientemente parecidas a esta en el almacén.</p>
                <?php else: ?>
                  <p><?= number_format($similarProductAnalysis['total_matches']) ?> posibles cruces. Revisa las coincidencias antes de contar.</p>
                  <div class="table-wrap">
                    <table class="physical-count-similarity-table">
                      <thead><tr><th>Llanta buscada</th><th>Posible cruce</th><th>Similitud</th><th>Detalle</th></tr></thead>
                      <tbody>
                        <?php foreach ($similarProductAnalysis['matches'] as $match): ?>
                          <?php
                          $firstSimilarProduct = $match['first'];
                          $secondSimilarProduct = $match['second'];
                          if ((int) $firstSimilarProduct['product_id'] !== $similarProductId) {
                              [$firstSimilarProduct, $secondSimilarProduct] = [$secondSimilarProduct, $firstSimilarProduct];
                          }
                          ?>
                          <tr>
                            <?php foreach ([$firstSimilarProduct, $secondSimilarProduct] as $similarProduct): ?>
                              <td>
                                <strong><?= escape_html($similarProduct['name']) ?></strong>
                                <span><?= escape_html($similarProduct['sku'] . ' · ' . $similarProduct['brand'] . ' · ' . $similarProduct['measure']) ?></span>
                              </td>
                            <?php endforeach; ?>
                            <td><strong><?= number_format((int) $match['similarity']) ?>%</strong></td>
                            <td><?= escape_html($match['reason']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
                </section>
              </dialog>
            <?php endif; ?>
            <details class="simple-help inventory-count-guide">
              <summary>Ayuda para realizar el conteo físico</summary>
              <p><strong>Antes de contar:</strong> este módulo corrige el stock que usan Ventas y Compras. Cada etiqueta incluye QR y código de barras; puedes escanearla con el celular o con un lector, o escribir la cantidad manualmente. El conteo no cambia el stock hasta que lo confirmes.</p>
              <ol>
                <li>Selecciona el almacén y busca el producto que estás contando.</li>
                <li>Revisa el total contado; puedes corregirlo si una lectura falla. Escribe el total, no la diferencia.</li>
                <li>Indica el motivo y pulsa <strong>Confirmar conteo</strong>. Si el total difiere, SIVA reemplazará el saldo y guardará el ajuste con tu usuario y la fecha.</li>
              </ol>
              <a href="index.php?pagina=inventario&amp;view=scan">Abrir el flujo anterior de escaneo B4X01</a>
            </details>
            <?php if ($physicalCountScanSuccess || $physicalCountScanError): ?>
              <?php $physicalCountScanIsError = $physicalCountScanError; ?>
              <dialog class="sale-scan-dialog<?= $physicalCountScanIsError ? ' sale-scan-dialog-error' : '' ?>" aria-labelledby="physical-count-scan-dialog-title" data-physical-count-scan-dialog<?= !$physicalCountScanIsError ? ' data-voice-message="' . escape_html($physicalCountScanVoiceMessage !== '' ? $physicalCountScanVoiceMessage : $physicalCountSuccess) . '" data-voice-autoplay="true" data-voice-manual="true"' : '' ?>>
                <div class="sale-scan-dialog-card">
                  <p class="sale-scan-dialog-eyebrow">CONTEO RÁPIDO</p>
                  <h2 class="sale-scan-dialog-title" id="physical-count-scan-dialog-title"><?= $physicalCountScanIsError ? 'No se pudo contar' : 'Escaneo agregado' ?></h2>
                  <p class="sale-scan-dialog-message"><?= escape_html($physicalCountScanIsError ? $physicalCountError : $physicalCountSuccess) ?></p>
                  <?php if (!$physicalCountScanIsError): ?>
                    <div class="sale-scan-dialog-voice">
                      <button class="button button-quiet" type="button" data-voice-replay>Escuchar el total contado</button>
                      <span data-voice-status role="status" aria-live="polite">El teléfono intentará anunciarlo. Si no escuchas la voz, pulsa este botón.</span>
                    </div>
                  <?php endif; ?>
                  <button class="button button-primary" type="button" data-physical-count-scan-close autofocus>Continuar conteo</button>
                </div>
              </dialog>
            <?php endif; ?>
            <?php if ($physicalCountSuccess !== '' && $physicalCountScanSuccess): ?>
              <p class="notice notice-success" role="status" aria-live="polite" data-voice-message="<?= escape_html($physicalCountScanVoiceMessage !== '' ? $physicalCountScanVoiceMessage : $physicalCountSuccess) ?>" data-voice-manual="true">
                <?= escape_html($physicalCountSuccess) ?>
                <button class="button button-quiet" type="button" data-voice-replay>Escuchar el total contado</button>
                <span data-voice-status aria-live="polite"></span>
              </p>
            <?php elseif ($physicalCountSuccess !== ''): ?>
              <p class="notice notice-success" role="status"<?= $physicalCountVoiceMessage !== '' ? ' data-voice-message="' . escape_html($physicalCountVoiceMessage) . '" data-voice-autoplay="true"' : '' ?>>
                <?= escape_html($physicalCountSuccess) ?>
                <?php if ($physicalCountVoiceMessage !== ''): ?>
                  <button class="button button-quiet" type="button" data-voice-replay>Reproducir aviso</button>
                  <span data-voice-status aria-live="polite"></span>
                <?php endif; ?>
              </p>
            <?php endif; ?>
            <?php if ($physicalCountError !== ''): ?><p class="notice notice-error" role="alert"><?= escape_html($physicalCountError) ?></p><?php endif; ?>
            <form class="inventory-count-filters" method="get" action="index.php">
              <input type="hidden" name="pagina" value="inventario">
              <input type="hidden" name="view" value="count">
              <fieldset class="warehouse-choice">
                <legend>Almacén que se está contando</legend>
                <div class="warehouse-choice-options">
                  <?php foreach ($physicalCount['warehouses'] as $warehouse): ?><label class="warehouse-choice-option"><input type="radio" name="warehouse" value="<?= escape_html($warehouse['code']) ?>" required <?= $physicalCount['warehouse'] === $warehouse['code'] ? 'checked' : '' ?>><span><strong><?= escape_html($warehouse['code']) ?></strong><small><?= escape_html($warehouse['name']) ?></small></span></label><?php endforeach; ?>
                </div>
              </fieldset>
              <button class="button button-primary" type="submit">Mostrar productos</button>
            </form>
            <?php if ($physicalCount['warehouse'] === ''): ?>
              <p class="notice notice-warning" role="status">Selecciona el almacén que vas a contar. Este módulo usa el stock operativo de Ventas/Compras.</p>
            <?php else: ?>
              <form class="inventory-scan-form" method="post" action="index.php?pagina=inventario&amp;view=count" data-physical-count-scan-form>
                <input type="hidden" name="token_csrf" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="accion" value="escanear_conteo_fisico">
                <input type="hidden" name="warehouse" value="<?= escape_html($physicalCount['warehouse']) ?>">
                <label class="form-field">Escanear código de barras
                  <input type="text" name="barcode" inputmode="none" autocomplete="off" maxlength="80" placeholder="Escanea; se agregará automáticamente" required autofocus>
                </label>
              </form>
              <p class="physical-count-search-status">Puedes escanear el mismo ticket una vez por cada llanta igual. Cada lectura suma una unidad; confirma el conteo cuando termines.</p>
              <?php if ($countScanAutoSubmit): ?>
                <form method="post" action="index.php?pagina=inventario&amp;view=count" hidden data-qr-auto-submit="true">
                  <input type="hidden" name="token_csrf" value="<?= escape_html($csrfToken) ?>">
                  <input type="hidden" name="accion" value="escanear_conteo_fisico">
                  <input type="hidden" name="warehouse" value="<?= escape_html($physicalCount['warehouse']) ?>">
                  <input type="hidden" name="barcode" value="<?= escape_html((string) ($_GET['barcode'] ?? '')) ?>">
                </form>
              <?php endif; ?>
              <form class="inventory-count-search" method="get" action="index.php" data-physical-count-search-form>
                <input type="hidden" name="pagina" value="inventario">
                <input type="hidden" name="view" value="count">
                <input type="hidden" name="warehouse" value="<?= escape_html($physicalCount['warehouse']) ?>">
                <label for="physical-count-search">Busca una llanta por SKU, marca, nombre o medida</label>
                <div class="physical-count-search-input-row">
                  <input id="physical-count-search" type="search" name="q" value="<?= escape_html($physicalCount['search']) ?>" placeholder="Escribe, por ejemplo, WINDA 185/65/15" autocomplete="off" minlength="2" aria-autocomplete="list" aria-expanded="false" aria-controls="physical-count-search-suggestions" data-physical-count-search-input>
                  <div id="physical-count-search-suggestions" class="physical-count-search-suggestions" role="listbox" aria-label="Llantas encontradas" hidden data-physical-count-search-suggestions></div>
                </div>
                <p class="physical-count-search-status" role="status" aria-live="polite" data-physical-count-search-status>Mientras escribes aparecerán sugerencias; elige una para revisar posibles cruces.</p>
              </form>
              <p class="inventory-count-summary" role="status"><strong><?= number_format($physicalCount['total_rows']) ?></strong> productos para contar en <?= escape_html($physicalCount['warehouse']) ?>.</p>
              <?php if ($physicalCount['rows'] === []): ?>
                <div class="catalog-empty"><strong>Sin productos</strong><p>No hay productos operativos que coincidan con el filtro.</p></div>
              <?php else: ?>
                <div hidden aria-hidden="true">
                  <?php foreach ($physicalCount['rows'] as $row): ?>
                    <?php $countFormId = 'physical-count-' . (int) $row['product_id']; ?>
                    <form id="<?= escape_html($countFormId) ?>" method="post" action="index.php?pagina=inventario&amp;view=count" data-physical-count-form data-system-quantity="<?= (int) $row['system_quantity'] ?>">
                      <input type="hidden" name="token_csrf" value="<?= escape_html($csrfToken) ?>">
                      <input type="hidden" name="accion" value="guardar_conteo_fisico">
                      <input type="hidden" name="product_id" value="<?= (int) $row['product_id'] ?>">
                      <input type="hidden" name="warehouse" value="<?= escape_html($physicalCount['warehouse']) ?>">
                      <input type="hidden" name="q" value="<?= escape_html($physicalCount['search']) ?>">
                    </form>
                  <?php endforeach; ?>
                </div>
                <div class="table-wrap"><table class="physical-count-table"><thead><tr><th>Producto</th><th>Almacén</th><th>Saldo</th><th>Diferencia</th><th>Unidades contadas</th><th>Imprimir</th><th>Motivo del ajuste</th><th>Acción</th></tr></thead><tbody>
                  <?php foreach ($physicalCount['rows'] as $row): ?>
                    <?php $productId = (int) $row['product_id']; $countFormId = 'physical-count-' . $productId; $hasScannedQuantity = array_key_exists($productId, $scannedPhysicalCountByProduct); $hasPendingPhysicalCount = isset($pendingPhysicalCountByProduct[$productId]); $scanQuantity = (int) ($scannedPhysicalCountByProduct[$productId] ?? 0); ?>
                    <tr data-physical-count-row<?= $hasScannedQuantity ? ' class="physical-count-row-scanned"' : '' ?> data-system-quantity="<?= (int) $row['system_quantity'] ?>">
                      <td>
                        <strong><?= escape_html($row['name']) ?></strong>
                        <span class="table-secondary"><?= escape_html($row['sku'] . ' · ' . $row['brand'] . ' · ' . $row['size']) ?></span>
                        <span class="physical-count-row-count<?= $hasPendingPhysicalCount ? ' physical-count-row-count-pending' : ' physical-count-row-count-confirmed' ?>" data-physical-count-status data-pending="<?= $hasPendingPhysicalCount ? 'true' : 'false' ?>"<?= $hasScannedQuantity ? '' : ' hidden' ?>><?= $hasScannedQuantity ? ($hasPendingPhysicalCount ? 'Pendiente de confirmar: ' : 'Último conteo confirmado: ') . number_format($scanQuantity) . ' ' . ($scanQuantity === 1 ? 'unidad' : 'unidades') : '' ?></span>
                      </td>
                      <td><span class="table-warehouse-code"><?= escape_html($row['warehouse_code']) ?></span><span class="table-warehouse-name"><?= escape_html($row['warehouse_name']) ?></span></td>
                      <td><strong><?= number_format((int) $row['system_quantity']) ?></strong><span class="table-secondary">Cantidad registrada antes del conteo</span></td>
                      <td><strong class="physical-count-difference" data-physical-count-difference data-difference-state="pending">—</strong><span class="table-secondary">Contado − SIVA</span></td>
                      <td><label class="visually-hidden" for="count-quantity-<?= $productId ?>">Total de unidades físicas de <?= escape_html($row['sku']) ?></label><input id="count-quantity-<?= $productId ?>" class="physical-count-input" data-physical-quantity type="number" min="0" max="2147483647" step="1" name="physical_quantity" value="<?= $hasScannedQuantity ? $scanQuantity : '' ?>" placeholder="Total contado" form="<?= escape_html($countFormId) ?>" required></td>
                      <td class="physical-count-scan-cell">
                        <a class="button button-quiet physical-count-label-link" href="index.php?pagina=etiquetas_inventario&amp;operational_product_id=<?= $productId ?>&amp;warehouse=<?= rawurlencode($physicalCount['warehouse']) ?>">Imprimir QR y barras</a>
                      </td>
                      <td><label class="visually-hidden" for="count-reason-<?= $productId ?>">Motivo del ajuste de <?= escape_html($row['sku']) ?></label><input id="count-reason-<?= $productId ?>" class="physical-count-reason" type="text" name="reason" value="Conteo físico" maxlength="255" form="<?= escape_html($countFormId) ?>" required></td>
                      <td><button class="button button-primary physical-count-save" type="submit" form="<?= escape_html($countFormId) ?>">Confirmar conteo</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody></table></div>
                <?php if ($physicalCount['total_pages'] > 1): ?>
                  <nav class="inventory-pagination" aria-label="Paginación del conteo físico">
                    <span>Mostrando <?= $physicalCount['page_offset'] + 1 ?>–<?= min($physicalCount['page_offset'] + $physicalCount['page_size'], $physicalCount['total_rows']) ?> de <?= $physicalCount['total_rows'] ?> productos</span>
                    <div>
                      <?php if ($physicalCount['page'] > 1): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => 'inventario', 'view' => 'count', 'page' => $physicalCount['page'] - 1]))) ?>">Anterior</a><?php endif; ?>
                      <span>Página <?= $physicalCount['page'] ?> de <?= $physicalCount['total_pages'] ?></span>
                      <?php if ($physicalCount['page'] < $physicalCount['total_pages']): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => 'inventario', 'view' => 'count', 'page' => $physicalCount['page'] + 1]))) ?>">Siguiente</a><?php endif; ?>
                    </div>
                  </nav>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </section>
        <?php elseif ($showScanner): ?>
          <section class="product-catalog inventory-panel" aria-labelledby="scanner-title">
            <div class="catalog-heading"><h2 id="scanner-title">Escaneo con QR o código de barras</h2></div>
            <p class="inventory-scan-intro">Escanea cada llanta para sumarla al Conteo físico. El stock cambia cuando confirmes el total; ya no necesitas descargar ni subir un Excel.</p>
            <?php if ((string) ($_GET['scan'] ?? '') === '1'): ?>
              <form method="post" action="index.php?pagina=inventario&amp;view=count" hidden data-qr-auto-submit="true">
                <input type="hidden" name="token_csrf" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="accion" value="escanear_conteo_fisico">
                <input type="hidden" name="warehouse" value="<?= escape_html($warehouseFilter) ?>">
                <input type="hidden" name="barcode" value="<?= escape_html($barcodeSearch) ?>">
              </form>
            <?php endif; ?>
            <section class="inventory-scan-labels" aria-labelledby="scan-labels-title">
              <h3 id="scan-labels-title">Imprimir etiquetas QR y de barras</h3>
              <p>Las etiquetas conservan el QR para la cámara del celular e incluyen un código de barras para lector.</p>
              <form class="inventory-print-form" method="get" action="index.php">
                <input type="hidden" name="pagina" value="inventario">
                <input type="hidden" name="view" value="scan">
                <fieldset class="warehouse-choice">
                  <legend>Almacén</legend>
                  <div class="warehouse-choice-options">
                    <?php foreach ($warehouses as $warehouse): ?><label class="warehouse-choice-option"><input type="radio" name="warehouse" value="<?= escape_html($warehouse['code']) ?>" required <?= $warehouseFilter === $warehouse['code'] ? 'checked' : '' ?>><span><strong><?= escape_html($warehouse['code']) ?></strong><small><?= escape_html($warehouse['name']) ?></small></span></label><?php endforeach; ?>
                  </div>
                </fieldset>
                <button class="button button-primary" type="submit">Mostrar llantas</button>
              </form>
              <?php if ($warehouseFilter !== ''): ?>
                <form class="inventory-scan-form" method="post" action="index.php?pagina=inventario&amp;view=count" data-physical-count-scan-form>
                  <input type="hidden" name="token_csrf" value="<?= escape_html($csrfToken) ?>">
                  <input type="hidden" name="accion" value="escanear_conteo_fisico">
                  <input type="hidden" name="warehouse" value="<?= escape_html($warehouseFilter) ?>">
                  <label class="form-field">Escanear código de barras para conteo físico
                    <input type="text" name="barcode" inputmode="none" autocomplete="off" maxlength="80" placeholder="Escanea; se sumará al Conteo físico" required autofocus>
                  </label>
                  <button class="button button-primary" type="submit">Sumar al Conteo físico</button>
                </form>
                <p class="physical-count-search-status">Cada lectura suma una unidad. Revisa y confirma el total en Conteo físico para actualizar las existencias.</p>
                <form class="inventory-tire-search" method="get" action="index.php" data-inventory-live-search-form>
                  <input type="hidden" name="pagina" value="inventario">
                  <input type="hidden" name="view" value="scan">
                  <input type="hidden" name="warehouse" value="<?= escape_html($warehouseFilter) ?>">
                  <label for="scan-label-search">Buscar llanta por código, nombre, marca o medida</label>
                  <div class="inventory-live-search-actions">
                    <div class="inventory-live-search-control">
                      <input id="scan-label-search" type="search" name="q" value="<?= escape_html($search) ?>" placeholder="Ej.: código, marca, 245/70R17.5" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="scan-label-search-suggestions" data-inventory-live-search-input>
                      <div id="scan-label-search-suggestions" class="physical-count-search-suggestions" role="listbox" aria-label="Productos encontrados" hidden data-inventory-live-search-suggestions></div>
                    </div>
                    <button class="button button-primary" type="submit">Buscar llanta</button>
                    <?php if ($search !== ''): ?><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=scan&amp;warehouse=<?= rawurlencode($warehouseFilter) ?>">Mostrar todas</a><?php endif; ?>
                  </div>
                  <p class="physical-count-search-status" role="status" aria-live="polite" data-inventory-live-search-status>Mientras escribes aparecerán sugerencias.</p>
                </form>
                <?php if ($rows === []): ?>
                  <p class="notice notice-warning" role="status">No encontramos llantas para ese almacén y búsqueda.</p>
                <?php else: ?>
                  <div class="table-wrap"><table><thead><tr><th>Llanta</th><th>Código</th><th>Medida</th><th>Almacén</th><th>Existencias</th><th>Etiquetas</th></tr></thead><tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr><td><strong><?= escape_html($row['name']) ?></strong><br><span class="table-secondary"><?= escape_html($row['brand']) ?></span></td><td><span class="product-code"><?= trim((string) ($row['barcode'] ?? '')) !== '' ? escape_html((string) $row['barcode']) : '—' ?></span></td><td><?= escape_html($row['size']) ?></td><td><span class="table-warehouse-code"><?= escape_html($row['warehouse_code']) ?></span><span class="table-warehouse-name"><?= escape_html($row['warehouse_name']) ?></span></td><td><?= number_format((int) $row['quantity']) ?></td><td><?php if ((string) ($row['barcode'] ?? '') !== ''): ?><a class="button button-primary inventory-label-button" href="index.php?pagina=etiquetas_inventario&amp;<?= escape_html(http_build_query(['product_id' => $row['product_id'], 'warehouse' => $row['warehouse_code']])) ?>">Imprimir ticket</a><?php else: ?>—<?php endif; ?></td></tr>
                    <?php endforeach; ?>
                  </tbody></table></div>
                  <?php if ($totalPages > 1): ?>
                    <nav class="inventory-pagination" aria-label="Paginación de etiquetas">
                      <span>Mostrando <?= $pageOffset + 1 ?>–<?= min($pageOffset + $pageSize, $totalRows) ?> de <?= $totalRows ?> llantas</span>
                      <div>
                        <?php if ($page > 1): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => 'inventario', 'view' => 'scan', 'page' => $page - 1]))) ?>">Anterior</a><?php endif; ?>
                        <span>Página <?= $page ?> de <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?><a class="button button-quiet" href="index.php?<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => 'inventario', 'view' => 'scan', 'page' => $page + 1]))) ?>">Siguiente</a><?php endif; ?>
                      </div>
                    </nav>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
            </section>
            <div class="inventory-scan-summary">
              <div><span>Cómo se actualiza el stock</span><strong>Conteo físico</strong></div>
              <p>Los escaneos se acumulan en el conteo del almacén seleccionado. Confirma el total para actualizar las existencias; no necesitas Excel.</p>
            </div>
            <?php if ($warehouseFilter !== ''): ?>
              <div class="inventory-scan-actions">
                <a class="button button-primary" href="index.php?pagina=inventario&amp;view=count&amp;warehouse=<?= rawurlencode($warehouseFilter) ?>">Abrir Conteo físico</a>
              </div>
            <?php endif; ?>
          </section>
        <?php elseif ($showMovements): ?>
          <section class="product-catalog inventory-panel inventory-movements-panel" aria-labelledby="movement-title">
            <div class="catalog-heading inventory-movements-heading">
              <div><p class="inventory-stock-eyebrow">Registro general de actividad</p><h2 id="movement-title">Movimientos</h2><p class="inventory-movements-intro">Consulta en un solo lugar las compras recibidas, las ventas y salidas, el stock inicial y los ajustes de conteo. Revisa cuánto cambió, en qué almacén y quién lo registró.</p></div>
              <a class="button button-quiet" href="index.php?pagina=movimientos&amp;movement_type=adjustment">Ver ajustes de conteo</a>
            </div>
            <form class="inventory-print-form inventory-movements-filters" method="get" action="index.php">
              <input type="hidden" name="pagina" value="movimientos">
              <div class="inventory-movements-filter-heading"><div><span>BUSCAR EN EL HISTORIAL</span><strong>Encuentra un movimiento</strong></div><small>Mostrando hasta 200 de <?= number_format((int) $movementCount) ?> resultados</small></div>
              <label>Buscar producto, SKU, usuario o motivo<input type="search" name="q" value="<?= escape_html($search) ?>" placeholder="Producto, usuario o detalle" autocomplete="off"></label>
              <label>Almacén<select name="warehouse"><option value="">Todos</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $warehouseFilter === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
              <label>Tipo de movimiento<select name="movement_type"><option value="">Todos</option><?php foreach (['opening' => 'Stock inicial', 'outgoing' => 'Salida', 'purchase' => 'Compra', 'adjustment' => 'Ajuste de conteo'] as $type => $label): ?><option value="<?= escape_html($type) ?>" <?= $movementTypeFilter === $type ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
              <label>Desde<input type="date" name="movement_from" value="<?= escape_html($movementFromFilter) ?>"></label>
              <label>Hasta<input type="date" name="movement_to" value="<?= escape_html($movementToFilter) ?>"></label>
              <div class="inventory-filter-actions"><button class="button button-primary" type="submit">Filtrar movimientos</button><a class="button button-quiet" href="index.php?pagina=movimientos">Limpiar</a></div>
            </form>
            <?php if ($movements === []): ?>
              <div class="catalog-empty"><strong>Sin movimientos</strong><p>No se encontraron movimientos para estos filtros.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table class="inventory-movements-table"><colgroup><col class="movement-date-column"><col class="movement-product-column"><col class="movement-warehouse-column"><col class="movement-type-column"><col class="movement-quantity-column"><col class="movement-detail-column"><col></colgroup><thead><tr><th>Fecha y hora</th><th>Producto</th><th>Almacén</th><th>Tipo</th><th>Variación</th><th>Detalle</th><th>Usuario</th></tr></thead><tbody>
                <?php foreach ($movements as $movement): ?>
                  <?php
                    $movementType = (string) $movement['movement_type'];
                    $movementLabel = $movementType === 'adjustment'
                        ? match ((string) $movement['reference_type']) {
                            'purchase_cancel' => 'Anulación de compra',
                            'purchase_edit' => 'Corrección de compra',
                            'physical_count' => 'Ajuste de conteo',
                            default => 'Ajuste',
                        }
                        : (['opening' => 'Stock inicial', 'outgoing' => 'Salida / venta', 'purchase' => 'Compra'][$movementType] ?? $movementType);
                    $quantityChange = (int) $movement['quantity_change'];
                    $movementDate = (string) $movement['created_at'];
                  ?>
                  <tr>
                    <td class="movement-date"><time datetime="<?= escape_html(str_replace(' ', 'T', $movementDate)) ?>"><strong><?= escape_html(substr($movementDate, 0, 10)) ?></strong><span><?= escape_html(substr($movementDate, 11, 8)) ?></span></time></td>
                    <td class="movement-product"><strong><?= escape_html($movement['product_name']) ?></strong><span><?= escape_html($movement['product_sku']) ?> · <?= escape_html($movement['product_brand'] . ' · ' . $movement['product_measure']) ?></span></td>
                    <td><span class="table-warehouse-code"><?= escape_html($movement['warehouse_code']) ?></span><span class="table-warehouse-name"><?= escape_html($movement['warehouse_name']) ?></span></td>
                    <td><span class="movement-type movement-type-<?= escape_html($movementType) ?>"><?= escape_html($movementLabel) ?></span></td>
                    <td><strong class="movement-quantity<?= $quantityChange < 0 ? ' movement-quantity-out' : ($quantityChange > 0 ? ' movement-quantity-in' : '') ?>"><?= $quantityChange > 0 ? '+' : '' ?><?= number_format($quantityChange) ?></strong><span class="movement-unit"><?= abs($quantityChange) === 1 ? 'unidad' : 'unidades' ?></span></td>
                    <td class="movement-detail"><?= escape_html($movement['notes']) ?></td>
                    <td><?= escape_html($movement['user_name']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>
        <?php else: ?>
          <section class="product-catalog inventory-panel inventory-stock-panel" aria-labelledby="inventory-title">
            <div class="catalog-heading inventory-stock-heading">
              <div class="inventory-stock-heading-copy">
                <p class="inventory-stock-eyebrow">Consulta de existencias · B4X01</p>
                <h2 id="inventory-title">Existencias B4X01 por almacén</h2>
                <p>Esta pantalla muestra el inventario B4X01 de etiquetas y escaneo. Ventas y Compras usan el stock operativo; ambos inventarios son independientes.</p>
              </div>
              <div class="inventory-actions"><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=report">Reportes operativos</a><a class="button button-quiet" href="index.php?pagina=inventario&amp;view=alerts">Alertas operativas</a><a class="button button-primary" href="index.php?pagina=inventario&amp;view=scan">Escaneo B4X01 · QR y barras</a></div>
            </div>
            <section class="inventory-stock-filter" aria-labelledby="inventory-filter-title">
              <h3 id="inventory-filter-title">Filtrar por almacén</h3>
              <form class="inventory-print-form" method="get" action="index.php">
                <input type="hidden" name="pagina" value="inventario">
                <fieldset class="warehouse-choice">
                  <legend>Almacén</legend>
                  <div class="warehouse-choice-options">
                    <?php foreach ($warehouses as $warehouse): ?><label class="warehouse-choice-option"><input type="radio" name="warehouse" value="<?= escape_html($warehouse['code']) ?>" required <?= $warehouseFilter === $warehouse['code'] ? 'checked' : '' ?>><span><strong><?= escape_html($warehouse['code']) ?></strong><small><?= escape_html($warehouse['name']) ?></small></span></label><?php endforeach; ?>
                  </div>
                </fieldset>
                <button class="button button-primary" type="submit">Mostrar llantas</button>
              </form>
              <?php if ($warehouseFilter !== ''): ?><p class="inventory-stock-selected" role="status">Almacén: <strong><?= escape_html($warehouseFilter) ?></strong></p><?php endif; ?>
              <?php if ($warehouseFilter !== ''): ?>
                <form class="inventory-tire-search" method="get" action="index.php" data-inventory-live-search-form>
                    <input type="hidden" name="pagina" value="inventario">
                  <input type="hidden" name="warehouse" value="<?= escape_html($warehouseFilter) ?>">
                  <label for="inventory-tire-search">Buscar llanta por código, nombre, marca o medida</label>
                  <div class="inventory-live-search-actions">
                    <div class="inventory-live-search-control">
                      <input id="inventory-tire-search" type="search" name="q" value="<?= escape_html($search) ?>" placeholder="Ej.: código, marca, 245/70R17.5" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="inventory-main-search-suggestions" data-inventory-live-search-input>
                      <div id="inventory-main-search-suggestions" class="physical-count-search-suggestions" role="listbox" aria-label="Productos encontrados" hidden data-inventory-live-search-suggestions></div>
                    </div>
                    <button class="button button-primary" type="submit">Buscar llanta</button>
                    <?php if ($search !== ''): ?><a class="button button-quiet" href="index.php?pagina=inventario&amp;warehouse=<?= rawurlencode($warehouseFilter) ?>">Mostrar todas</a><?php endif; ?>
                  </div>
                  <p class="physical-count-search-status" role="status" aria-live="polite" data-inventory-live-search-status>Mientras escribes aparecerán sugerencias.</p>
                </form>
              <?php endif; ?>
            </section>
            <?php if ($inventoryImportSuccess !== ''): ?><p class="notice notice-success" role="status"><?= escape_html($inventoryImportSuccess) ?></p><?php endif; ?>
            <?php if ($inventoryImportError !== ''): ?><p class="notice notice-error" role="alert"><?= escape_html($inventoryImportError) ?></p><?php endif; ?>
            <?php if ($rows === [] && $warehouseFilter !== '' && $search !== ''): ?><p class="notice notice-warning" role="status">No encontramos llantas que coincidan con “<?= escape_html($search) ?>”. Prueba con otro código, marca o medida.</p><?php elseif ($rows === [] && $warehouseFilter === ''): ?><p class="notice notice-warning" role="status">Selecciona un almacén y pulsa “Mostrar llantas”.</p><?php elseif ($rows === []): ?><p class="notice notice-warning" role="status">No hay llantas cargadas para este almacén.</p><?php endif; ?>
            <?php if ($warehouseFilter !== ''): ?><h3 class="inventory-products-title">Llantas disponibles</h3><?php endif; ?>
            <div class="stock-legend" aria-label="Estados de stock">
              <span><i class="status-dot status-healthy"></i>Suficiente</span><span><i class="status-dot status-warning"></i>Por reponer</span><span><i class="status-dot status-critical"></i>Muy bajo</span>
              <span class="legend-counts"><strong><?= $criticalCount ?></strong> muy bajos · <strong><?= $warningCount ?></strong> por revisar · <strong><?= $totalUnits ?></strong> unidades B4X01</span>
            </div>
            <?php if ($rows === [] && $totalRows === 0): ?>
              <div class="catalog-empty"><strong>Sin resultados</strong><p>No hay productos con esos filtros.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table class="inventory-stock-table"><thead><tr><th>Llanta</th><th>Código</th><th>Medida</th><th>Almacén</th><th>Existencia B4X01</th></tr></thead><tbody>
                <?php foreach ($rows as $row): ?>
                  <tr><td><strong><?= escape_html($row['name']) ?></strong><br><span class="table-secondary"><?= escape_html($row['brand']) ?></span></td><td><span class="product-code"><?= trim((string) $row['barcode']) !== '' ? escape_html((string) $row['barcode']) : '—' ?></span></td><td><?= escape_html($row['size']) ?></td><td><span class="table-warehouse-code"><?= escape_html($row['warehouse_code']) ?></span><span class="table-warehouse-name"><?= escape_html($row['warehouse_name']) ?></span></td><td><strong><?= (int) $row['quantity'] ?></strong></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <nav class="inventory-pagination" aria-label="Paginación del inventario">
                <span>Mostrando <?= $totalRows === 0 ? 0 : $pageOffset + 1 ?>–<?= min($pageOffset + $pageSize, $totalRows) ?> de <?= $totalRows ?> existencias</span>
                <div>
                  <?php if ($page > 1): ?><a class="button button-quiet" href="index.php?pagina=inventario&amp;<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => null, 'page' => $page - 1]))) ?>">Anterior</a><?php endif; ?>
                  <span>Página <?= $page ?> de <?= $totalPages ?></span>
                  <?php if ($page < $totalPages): ?><a class="button button-quiet" href="index.php?pagina=inventario&amp;<?= escape_html(http_build_query(array_merge($_GET, ['pagina' => null, 'page' => $page + 1]))) ?>">Siguiente</a><?php endif; ?>
                </div>
              </nav>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <script type="module" src="scripts/efecto-puntero.js"></script>
  <script type="module" src="scripts/registrar-escaneo-qr.js"></script>
  <script type="module" src="scripts/aviso-escaneo-inventario.js?v=20261117-fullscreen-count-alert-1"></script>
  <script src="scripts/confirmar-conteo.js?v=20261118-count-pending-status-1" defer></script>
  <script src="scripts/avisos-voz.js?v=20261118-voice-runtime-1" defer></script>
  <script src="scripts/filtro-facil.js?v=20261110-validacion-es-1" defer></script>
</body>
</html>