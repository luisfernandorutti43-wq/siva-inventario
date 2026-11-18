<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#090b0d">
  <title>Ventas | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261117-sale-review-1">
</head>
<body>
  <div class="products-app">
    <?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
    <div class="workspace">
      <?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, 'Ventas / Salidas'); ?>
      <main class="products-main">
        <?php if ($successMessage !== ''): ?>
          <p class="notice notice-success" role="status"<?= $saleVoiceMessage !== '' ? ' data-voice-message="' . escape_html($saleVoiceMessage) . '" data-voice-autoplay="true"' : '' ?>>
            <?= escape_html($successMessage) ?>
            <?php if ($saleVoiceMessage !== ''): ?>
              <button class="button button-quiet voice-replay" type="button" data-voice-replay>Escuchar aviso</button>
              <span data-voice-status aria-live="polite"></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= escape_html($errorMessage) ?></p><?php endif; ?>
        <?php if ($scanMessage !== ''): ?>
          <p class="notice notice-success sale-scan-inline-notice" role="status"<?= $scanVoiceMessage !== '' ? ' data-voice-message="' . escape_html($scanVoiceMessage) . '" data-voice-manual="true"' : '' ?>>
            <?= escape_html($scanMessage) ?>
            <?php if ($scanVoiceMessage !== ''): ?>
              <button class="button button-quiet voice-replay" type="button" data-voice-replay>Escuchar aviso</button>
              <span data-voice-status aria-live="polite"></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($editingSaleLine !== null): ?>
          <div class="page-heading"><div><p class="eyebrow">Ventas · administrador</p><h2>Corregir <?= escape_html($editingSaleLine['sale_number']) ?></h2><p>Al cambiar producto o cantidad, el sistema devuelve o descuenta stock y registra el ajuste. La fecha y el almacén se conservan.</p></div></div>
          <form class="product-form sale-form" method="post" action="index.php?pagina=ventas&amp;view=edit-sale&amp;detail_id=<?= (int) $editingSaleLine['detail_id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="action" value="update_sale_detail">
            <input type="hidden" name="detail_id" value="<?= (int) $editingSaleLine['detail_id'] ?>">
            <div class="form-grid sale-header-fields">
              <p class="form-field">Almacén · <?= escape_html($editingSaleLine['warehouse_code']) ?></p>
              <p class="form-field">Fecha · <?= escape_html((new DateTimeImmutable((string) $editingSaleLine['sale_date']))->format('d/m/Y')) ?></p>
              <label class="form-field">Producto correcto *
                <select name="product_id" required>
                  <?php foreach ($products as $product): ?>
                    <?php
                      $stockKey = 'stock_' . strtolower((string) $editingSaleLine['warehouse_code']);
                      $availableStock = (int) ($product[$stockKey] ?? 0);
                      if ((int) $editingSaleLine['product_id'] === (int) $product['id']) {
                          $availableStock += (int) $editingSaleLine['quantity'];
                      }
                    ?>
                    <option value="<?= (int) $product['id'] ?>" <?= (int) $editingSaleLine['product_id'] === (int) $product['id'] ? 'selected' : '' ?>>
                      <?= escape_html($product['sku'] . ' · ' . $product['name'] . ' · ' . $product['brand'] . ' · ' . $product['size'] . ' · stock ' . $availableStock) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="form-field">Cantidad correcta *
                <input type="number" name="quantity" min="1" step="1" value="<?= (int) $editingSaleLine['quantity'] ?>" required>
              </label>
            </div>
            <p class="sale-help">Si el producto cambia, se aplicará su precio de venta actual. Si solo corriges la cantidad, se conservará el precio registrado.</p>
            <label class="form-field">Motivo de la corrección *
              <input name="correction_reason" maxlength="450" required placeholder="Ej. El cliente pidió otra medida">
            </label>
            <div class="sale-actions"><span>Total actual: $<?= number_format((int) $editingSaleLine['line_total_cents'] / 100, 2, '.', ',') ?></span><div><button class="button button-primary" type="submit">Guardar corrección</button> <a class="button button-quiet" href="index.php?pagina=ventas">Cancelar</a></div></div>
          </form>
          <form class="product-form sale-cancel-line-form" method="post" action="index.php?pagina=ventas" data-confirm-message="¿Anular esta línea y devolver sus unidades al stock?">
            <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="action" value="cancel_sale_detail">
            <input type="hidden" name="detail_id" value="<?= (int) $editingSaleLine['detail_id'] ?>">
            <label class="form-field">Motivo para anular esta línea *
              <input name="cancellation_reason" maxlength="450" required placeholder="Ej. El cliente no realizó la compra">
            </label>
            <button class="button button-quiet" type="submit">Anular línea y devolver stock</button>
          </form>
          <form class="product-form sale-cancel-line-form" method="post" action="index.php?pagina=ventas" data-confirm-message="¿Anular toda esta venta y devolver al stock todos sus productos?">
            <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="action" value="cancel_sale">
            <input type="hidden" name="sale_id" value="<?= (int) $editingSaleLine['sale_id'] ?>">
            <label>Motivo para anular toda la venta *
              <input name="cancellation_reason" maxlength="450" required placeholder="Ej. El cliente canceló toda la compra">
            </label>
            <button class="button button-quiet" type="submit">Anular toda la venta</button>
          </form>
        <?php elseif ($showSaleForm): ?>
          <div class="page-heading"><div><p class="eyebrow">Ventas</p><h2>Registrar una venta</h2><p>Elige el almacén, agrega los productos y confirma. Las existencias bajarán según las cantidades registradas.</p></div></div>
          <?php if ($products === []): ?>
            <p class="notice notice-error">Para registrar una salida necesitas al menos un producto. <a href="index.php?pagina=productos">Abrir Productos</a></p>
          <?php else: ?>
            <form class="product-form sale-form" method="post" action="index.php?pagina=ventas&amp;view=new">
              <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
              <input type="hidden" name="action" value="create_sale">
              <?php if ($showDuplicateConfirmation): ?><label class="notice notice-warning duplicate-sale-confirmation"><input type="checkbox" name="confirmar_posible_duplicado" value="1" required> Confirmo que es otra venta y no corresponde a la que ya aparece en el Excel o el historial.</label><?php endif; ?>
              <h3>Registrar salida</h3>
              <p class="sale-help">Si no hay suficientes unidades de algún producto, no se guardará la venta.</p>
              <section class="sale-qr-guide" aria-labelledby="sale-qr-guide-title">
                <dialog class="sale-scan-dialog" aria-labelledby="sale-scan-dialog-title" data-sale-scan-dialog data-initial-message="<?= escape_html($scanMessage) ?>" data-initial-voice-message="<?= escape_html($scanVoiceMessage) ?>" data-voice-message="" data-voice-autoplay="false" data-voice-manual="true">
                  <div class="sale-scan-dialog-card">
                    <p class="sale-scan-dialog-eyebrow">LECTURA CONFIRMADA</p>
                    <h2 class="sale-scan-dialog-title" id="sale-scan-dialog-title" data-sale-scan-dialog-title>Producto agregado</h2>
                    <p class="sale-scan-dialog-message" data-sale-scan-dialog-message></p>
                    <div class="sale-scan-dialog-voice">
                      <button class="button button-quiet" type="button" data-voice-replay>Escuchar cantidad escaneada</button>
                      <span data-voice-status role="status" aria-live="polite">La voz anunciará cuántas unidades llevas de este producto.</span>
                    </div>
                    <button class="button button-primary" type="button" data-sale-scan-dialog-close autofocus>Continuar venta</button>
                  </div>
                </dialog>
                <div class="sale-qr-guide-heading">
                  <span class="sale-qr-guide-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                      <path d="M3 5v14M6 5v14M9 5v14M13 5v14M16 5v14M19 5v14M22 5v14"></path>
                    </svg>
                  </span>
                  <div>
                    <span class="sale-qr-guide-eyebrow">LECTOR DE CÓDIGO DE BARRAS</span>
                    <h4 id="sale-qr-guide-title">Escanea las llantas o escribe la cantidad</h4>
                    <p>El escaneo es opcional. Al leer el QR, Ventas seleccionará automáticamente el almacén de la etiqueta.</p>
                  </div>
                  <a class="sale-qr-guide-link" href="index.php?pagina=productos">Imprimir etiquetas <span aria-hidden="true">→</span></a>
                </div>
                <label class="form-field sale-barcode-scanner">Código de barras de la llanta
                  <input id="sale-barcode-input" type="text" inputmode="none" autocomplete="off" maxlength="300" placeholder="Escanea las barras o el QR; se agregará automáticamente" data-lookup-url="index.php?pagina=ventas" autofocus>
                </label>
                <p class="sale-qr-guide-note" id="sale-barcode-status" role="status" aria-live="polite" data-voice-message="" data-voice-autoplay="false">
                  <span id="sale-barcode-status-text">Cada lectura agrega una llanta. También puedes escribir la cantidad manualmente. El stock se descuenta al pulsar <strong>Registrar salida</strong>.</span>
                  <button class="button button-quiet voice-replay" type="button" data-voice-replay hidden>Escuchar aviso</button>
                  <span data-voice-status aria-live="polite"></span>
                </p>
                <div class="sale-scanned-codes" id="sale-scanned-codes" aria-live="polite">
                  <?php foreach ($formValues['scanned_barcodes_by_product'] as $codigoProducto => $codigos): ?>
                    <?php foreach ($codigos as $codigoUnidad): ?>
                      <input type="hidden" class="sale-scanned-barcode" name="scanned_barcodes[<?= (int) $codigoProducto ?>][]" value="<?= escape_html($codigoUnidad) ?>">
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </div>
              </section>
              <details class="simple-help sale-simple-help">
                <summary>¿Cómo se registra la cantidad?</summary>
                <p>Puedes escribir manualmente la cantidad de cualquier producto. Para llantas, el escaneo es opcional; al registrar la venta se descuentan unidades disponibles del almacén seleccionado.</p>
              </details>
              <div class="form-grid sale-header-fields">
                <label class="form-field">Almacén del que salen los productos *<select id="sale-warehouse" name="warehouse_code" required><?php foreach ($warehouses as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $formValues['warehouse_code'] === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
                <label class="form-field">Fecha *<input name="sale_date" type="date" value="<?= escape_html($formValues['sale_date']) ?>" required></label>
                <label class="form-field">Número de comprobante (opcional)<input name="notes" value="<?= escape_html($formValues['notes']) ?>" maxlength="500" placeholder="Ej. NV-0001"></label>
              </div>
              <div class="sale-lines-heading"><span>Producto</span><span>Cantidad</span><span>Disponibilidad y precio</span><span></span></div>
              <div class="purchase-lines sale-lines" id="sale-lines">
                <?php for ($lineIndex = 0, $lineCount = max(1, count($formValues['product_ids'])); $lineIndex < $lineCount; $lineIndex++): ?>
                  <?php $selectedProduct = $formValues['product_ids'][$lineIndex] ?? ''; ?>
                  <div class="purchase-line sale-line">
                    <label class="form-field">Producto *<input type="search" data-filter-product-options placeholder="Filtra por SKU, marca o medida" autocomplete="off"><select name="product_ids[]" class="sale-product" required><option value="">Selecciona un producto</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" data-sku="<?= escape_html((string) $product['sku']) ?>" data-category="<?= escape_html((string) $product['category']) ?>" data-price-cents="<?= (int) $product['unit_price_cents'] ?>" data-stock-a0101="<?= (int) $product['stock_a0101'] ?>" data-stock-a0102="<?= (int) $product['stock_a0102'] ?>" data-stock-a0103="<?= (int) $product['stock_a0103'] ?>" <?= $selectedProduct === (string) $product['id'] ? 'selected' : '' ?>><?= escape_html($product['sku'] . ' · ' . $product['name'] . ' · ' . $product['brand'] . ' · ' . $product['size']) ?></option><?php endforeach; ?></select></label>
                    <label class="form-field">Cantidad *<input name="quantities[]" type="number" min="1" step="1" value="<?= escape_html($formValues['quantities'][$lineIndex] ?? '1') ?>" required></label>
                    <div class="sale-line-info"><output class="available-stock" aria-live="polite">Selecciona un producto</output><output class="sale-line-price">Precio: — · Importe: —</output></div>
                    <button class="button button-quiet sale-remove" type="button" aria-label="Quitar producto">Quitar</button>
                  </div>
                <?php endfor; ?>
              </div>
              <template id="sale-line-template"><div class="purchase-line sale-line"><label class="form-field">Producto *<input type="search" data-filter-product-options placeholder="Filtra por SKU, marca o medida" autocomplete="off"><select name="product_ids[]" class="sale-product" required><option value="">Selecciona un producto</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" data-sku="<?= escape_html((string) $product['sku']) ?>" data-category="<?= escape_html((string) $product['category']) ?>" data-price-cents="<?= (int) $product['unit_price_cents'] ?>" data-stock-a0101="<?= (int) $product['stock_a0101'] ?>" data-stock-a0102="<?= (int) $product['stock_a0102'] ?>" data-stock-a0103="<?= (int) $product['stock_a0103'] ?>"><?= escape_html($product['sku'] . ' · ' . $product['name'] . ' · ' . $product['brand'] . ' · ' . $product['size']) ?></option><?php endforeach; ?></select></label><label class="form-field">Cantidad *<input name="quantities[]" type="number" min="1" step="1" value="1" required></label><div class="sale-line-info"><output class="available-stock" aria-live="polite">Selecciona un producto</output><output class="sale-line-price">Precio: — · Importe: —</output></div><button class="button button-quiet sale-remove" type="button" aria-label="Quitar producto">Quitar</button></div></template>
              <button class="button button-quiet" id="sale-add-line" type="button">+ Agregar producto</button>
              <div class="sale-voice-controls" aria-label="Lectura de preparación del pedido">
                <button class="button button-quiet" id="sale-read-pick-list" type="button">Leer lista para surtir</button>
                <button class="button button-quiet" id="sale-pause-pick-list" type="button" hidden>Pausar</button>
                <button class="button button-quiet" id="sale-stop-pick-list" type="button" hidden>Detener</button>
                <span id="sale-pick-list-status" role="status" aria-live="polite"></span>
              </div>
              <p class="sale-total" id="sale-total" aria-live="polite">Total estimado: $0.00</p>
              <div class="sale-actions"><span>Se usará el precio de venta actual de cada producto.</span><div><button class="button button-primary" type="submit"><?= $showDuplicateConfirmation ? 'Confirmar y registrar esta otra venta' : 'Registrar salida' ?></button> <a class="button button-quiet" href="index.php?pagina=ventas">Cancelar</a></div></div>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($isSalesAdministrator && $pendingSaleReports !== []): ?>
            <section class="product-catalog sales-history-panel" aria-labelledby="pending-sale-reports-title">
              <header class="sales-history-heading">
                <div class="sales-history-title">
                  <span class="sales-history-eyebrow">REVISIÓN REQUERIDA</span>
                  <h2 id="pending-sale-reports-title">Reportes pendientes (<?= count($pendingSaleReports) ?>)</h2>
                  <p>La venta y el stock permanecen intactos hasta que revises cada aviso.</p>
                </div>
              </header>
              <div class="table-wrap sales-history-table-wrap"><table class="sales-history-table"><thead><tr><th>Venta</th><th>Producto reportado</th><th>Motivo y detalle</th><th>Reportado por</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($pendingSaleReports as $report): ?>
                  <?php
                    $reasonLabels = [
                        'producto_incorrecto' => 'Producto equivocado',
                        'cantidad_incorrecta' => 'Cantidad equivocada',
                        'venta_no_realizada' => 'La venta no se realizó',
                        'otro' => 'Otro motivo',
                    ];
                  ?>
                  <tr>
                    <td><span class="sale-document"><?= escape_html($report['sale_number']) ?></span></td>
                    <td><?= escape_html($report['sku'] . ' · ' . $report['product_name']) ?> · <?= (int) $report['quantity'] ?> ud.</td>
                    <td><strong><?= escape_html($reasonLabels[$report['reason']] ?? 'Otro motivo') ?></strong><br><?= escape_html($report['details']) ?></td>
                    <td><?= escape_html($report['reporter_name']) ?><br><?= escape_html((new DateTimeImmutable((string) $report['reported_at']))->format('d/m/Y H:i')) ?></td>
                    <td>
                      <a class="button button-primary" href="index.php?pagina=ventas&amp;view=edit-sale&amp;detail_id=<?= (int) $report['detail_id'] ?>">Revisar / editar</a>
                      <form method="post" action="index.php?pagina=ventas">
                        <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="action" value="close_sale_report">
                        <input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>">
                        <input type="hidden" name="resolution_note" value="Revisado por el administrador; no se modificó la venta.">
                        <button class="button button-quiet" type="submit">Cerrar aviso</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
            </section>
          <?php endif; ?>
          <section class="product-catalog sales-history-panel" aria-labelledby="sales-history-title">
            <header class="sales-history-heading">
              <span class="sales-history-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M5 4.5h14v15H5zM8 8h8M8 12h5M8 16h3" /><path d="M8 2.5v4M16 2.5v4" /></svg>
              </span>
              <div class="sales-history-title">
                <span class="sales-history-eyebrow">MOVIMIENTOS COMERCIALES</span>
                <h2 id="sales-history-title">Historial de salidas</h2>
                <p>Elige un almacén para ver solo sus salidas; las fechas son opcionales.</p>
              </div>
              <a class="button button-primary sales-new-button" href="index.php?pagina=ventas&amp;view=new"><span aria-hidden="true">+</span> Nueva salida</a>
            </header>
            <form class="filters catalog-filters sales-filters sales-history-filters" method="get" action="index.php?pagina=ventas">
              <input type="hidden" name="pagina" value="ventas">
              <label class="search-box"><span class="visually-hidden">Buscar salida</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5" /><path d="m16 16 4 4" /></svg><input type="search" name="q" value="<?= escape_html($historySearch) ?>" placeholder="Producto, código o número de documento"></label>
              <label class="sales-filter-field"><span>Almacén · filtra al elegir</span><select name="warehouse"><option value="">Todos los almacenes</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $historyWarehouse === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code']) ?></option><?php endforeach; ?></select></label>
              <label class="filter-date"><span>Desde</span><input type="date" name="from" value="<?= escape_html($historyFrom) ?>"></label>
              <label class="filter-date"><span>Hasta</span><input type="date" name="to" value="<?= escape_html($historyTo) ?>"></label>
              <button class="button button-primary" type="submit">Aplicar filtros</button>
            </form>
            <?php if ($recentSales === []): ?><div class="catalog-empty"><strong>Sin salidas</strong><p>Registra una nueva salida para verla aquí.</p></div>
            <?php else: ?><div class="table-wrap sales-history-table-wrap"><table class="sales-history-table"><thead><tr><th>Documento</th><th>Fecha de venta</th><th>Registrada (fecha y hora)</th><th>Producto</th><th>Almacén</th><th>Cantidad</th><th>Total</th><th>Acciones</th></tr></thead><tbody>
              <?php foreach ($recentSales as $sale): ?><tr><td><span class="sale-document"><?= escape_html($sale['sale_number']) ?></span></td><td><?= escape_html((new DateTimeImmutable((string) $sale['sale_date']))->format('d/m/Y')) ?></td><td><?= escape_html((new DateTimeImmutable((string) $sale['registered_at']))->format('d/m/Y H:i:s')) ?></td><td class="sale-history-product"><?= escape_html($sale['sku'] . ' · ' . $sale['name']) ?></td><td><span class="sale-warehouse"><?= escape_html($sale['warehouse_code']) ?></span><span class="sale-warehouse-name"><?= escape_html($sale['warehouse_name']) ?></span></td><td><span class="sale-quantity"><?= (int) $sale['quantity'] ?></span></td><td class="sale-history-total">$<?= number_format((int) $sale['line_total_cents'] / 100, 2, '.', ',') ?></td><td>
                <?php if ($isSalesAdministrator): ?>
                  <a class="button button-primary" href="index.php?pagina=ventas&amp;view=edit-sale&amp;detail_id=<?= (int) $sale['detail_id'] ?>">Editar</a>
                <?php elseif ($isSalesVendor && (int) $sale['has_pending_report'] === 0): ?>
                  <details class="sale-report-details">
                    <summary class="button button-quiet">Reportar error</summary>
                    <form class="sale-report-form" method="post" action="index.php?pagina=ventas">
                      <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                      <input type="hidden" name="action" value="report_sale_issue">
                      <input type="hidden" name="sale_id" value="<?= (int) $sale['sale_id'] ?>">
                      <input type="hidden" name="detail_id" value="<?= (int) $sale['detail_id'] ?>">
                      <label>Motivo
                        <select name="reason" required>
                          <option value="">Selecciona el motivo</option>
                          <option value="producto_incorrecto">Se registró otra llanta</option>
                          <option value="cantidad_incorrecta">La cantidad es incorrecta</option>
                          <option value="venta_no_realizada">La venta no se realizó</option>
                          <option value="otro">Otro</option>
                        </select>
                      </label>
                      <label>Explica qué pasó
                        <textarea name="details" maxlength="500" rows="3" required placeholder="Ej. Se registró una llanta distinta a la que compró el cliente"></textarea>
                      </label>
                      <button class="button button-primary" type="submit">Enviar reporte</button>
                    </form>
                  </details>
                <?php elseif ($isSalesVendor): ?>
                  <span class="sale-quantity">Reporte pendiente</span>
                <?php endif; ?>
              </td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
          </section>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <script src="scripts/filtro-facil.js?v=20261106-1630" defer></script>
  <script src="scripts/ventas.js?v=20261117-sale-review-1" defer></script>
  <script src="scripts/avisos-voz.js?v=20261118-voice-runtime-1" defer></script>
  <script type="module" src="scripts/efecto-puntero.js"></script>
</body>
</html>