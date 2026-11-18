<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f4f5f8">
  <title>Compras | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261117-purchase-warehouse-filter-1">
</head>
<body>
  <div class="products-app">
    <?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
    <div class="workspace">
      <?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, 'Compras'); ?>
      <main class="products-main">
        <?php if ($successMessage !== ''): ?><p class="notice notice-success" role="status"<?= $purchaseVoiceMessage !== '' ? ' data-voice-message="' . escape_html($purchaseVoiceMessage) . '" data-voice-autoplay="true"' : '' ?>><?= escape_html($successMessage) ?><?php if ($purchaseVoiceMessage !== ''): ?><span data-voice-status aria-live="polite"></span><?php endif; ?></p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= escape_html($errorMessage) ?></p><?php endif; ?>
        <?php if ($purchaseStockChanges !== [] && is_array($purchaseStockChanges['items'] ?? null)): ?>
          <section class="purchase-stock-result" aria-labelledby="purchase-stock-result-title">
            <div class="purchase-stock-result-heading">
              <div>
                <p class="eyebrow">Compra <?= escape_html((string) ($purchaseStockChanges['purchase_number'] ?? '')) ?></p>
                <h2 id="purchase-stock-result-title">Así quedó el inventario</h2>
                <p>Se actualizaron las existencias operativas del almacén <?= escape_html((string) ($purchaseStockChanges['warehouse_code'] ?? '')) ?>.</p>
              </div>
              <a class="button button-primary" href="index.php?pagina=inventario">Ver inventario operativo</a>
            </div>
            <div class="table-wrap">
              <table class="purchase-stock-result-table">
                <thead><tr><th>Producto</th><th>Antes</th><th>Recibido</th><th>Ahora en existencia</th><th>Detalle</th></tr></thead>
                <tbody>
                  <?php foreach ($purchaseStockChanges['items'] as $stockChange): ?>
                    <tr>
                      <td><strong><?= escape_html((string) $stockChange['name']) ?></strong><span class="table-secondary"><?= escape_html((string) $stockChange['sku']) ?></span></td>
                      <td><?= number_format((int) $stockChange['previous_quantity']) ?></td>
                      <td class="purchase-stock-received">+<?= number_format((int) $stockChange['received_quantity']) ?></td>
                      <td><strong><?= number_format((int) $stockChange['current_quantity']) ?></strong></td>
                      <td><a href="index.php?pagina=inventario&amp;q=<?= rawurlencode((string) $stockChange['sku']) ?>">Ver este producto en Inventario</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="purchase-stock-result-note">El stock mostrado corresponde al saldo del inventario operativo después de registrar esta compra.</p>
          </section>
        <?php endif; ?>
        <?php if ($showPurchaseForm): ?>
          <div class="page-heading"><div><p class="eyebrow">Compras</p><h2><?= $editingPurchase ? 'Editar compra ' . escape_html((string) ($purchaseBeingEdited['purchase_number'] ?? '')) : 'Registrar productos recibidos' ?></h2><p><?= $editingPurchase ? 'Revisa los datos. Cualquier diferencia en el stock se registrará en Movimientos.' : 'Al confirmar, las cantidades se sumarán a las existencias del almacén elegido.' ?></p></div><?php if ($editingPurchase): ?><a class="button button-quiet" href="index.php?pagina=compras">Cancelar edición</a><?php endif; ?></div>
          <?php if ($products === []): ?>
            <p class="notice notice-error">Registra al menos un producto antes de ingresar una compra.</p>
          <?php else: ?>
            <form class="product-form sale-form" method="post" action="<?= $editingPurchase ? 'index.php?pagina=compras&amp;view=edit&amp;purchase_id=' . (int) ($purchaseBeingEdited['id'] ?? $_POST['purchase_id'] ?? 0) : 'index.php?pagina=compras&amp;view=new' ?>">
              <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
              <input type="hidden" name="action" value="<?= $editingPurchase ? 'update_purchase' : 'create_purchase' ?>">
              <?php if ($editingPurchase): ?><input type="hidden" name="purchase_id" value="<?= (int) ($purchaseBeingEdited['id'] ?? $_POST['purchase_id'] ?? 0) ?>"><?php endif; ?>
              <h3>Datos de la compra</h3>
              <div class="form-grid sale-header-fields">
                <label class="form-field">Proveedor *<input name="supplier" value="<?= escape_html($formValues['supplier']) ?>" maxlength="200" required></label>
                <label class="form-field">Factura / documento *<input name="supplier_document" value="<?= escape_html($formValues['supplier_document']) ?>" maxlength="120" required placeholder="Número de factura o documento"></label>
                <?php if ($editingPurchase): ?><input type="hidden" name="warehouse_code" value="<?= escape_html($formValues['warehouse_code']) ?>"><div class="form-field"><span>Almacén registrado</span><strong class="purchase-warehouse-readonly"><?= escape_html($formValues['warehouse_code']) ?></strong></div>
                <?php else: ?><label class="form-field">Almacén donde se guardarán *<select name="warehouse_code" required><?php foreach ($warehouses as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $formValues['warehouse_code'] === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label class="form-field">Fecha de compra *<input name="purchase_date" type="date" value="<?= escape_html($formValues['purchase_date']) ?>" required></label>
                <label class="form-field form-field-wide">Notas<input name="notes" value="<?= escape_html($formValues['notes']) ?>" maxlength="500"></label>
              </div>
              <div class="purchase-line-heading"><span>Producto</span><span>Cantidad</span><span>Costo unitario</span><span></span></div>
              <div class="purchase-lines" id="purchase-lines">
                <?php for ($lineIndex = 0, $lineCount = max(1, count($formValues['product_ids'])); $lineIndex < $lineCount; $lineIndex++): ?>
                  <?php $selectedProduct = $formValues['product_ids'][$lineIndex] ?? ''; ?>
                  <div class="purchase-line">
                    <label class="form-field">Producto *<input type="search" data-filter-product-options placeholder="Filtra por SKU, marca o medida" autocomplete="off"><select name="product_ids[]" required><option value="">Selecciona un producto</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= $selectedProduct === (string) $product['id'] ? 'selected' : '' ?>><?= escape_html($product['sku'] . ' · ' . $product['name'] . ' · ' . $product['brand'] . ' · ' . $product['size']) ?></option><?php endforeach; ?></select></label>
                    <label class="form-field">Cantidad *<input name="quantities[]" type="number" min="1" max="1000000" step="1" value="<?= escape_html($formValues['quantities'][$lineIndex] ?? '1') ?>" required></label>
                    <label class="form-field">Costo unitario *<input name="unit_costs[]" type="number" min="0.01" step="0.01" value="<?= escape_html($formValues['unit_costs'][$lineIndex] ?? '') ?>" placeholder="0.00" required></label>
                    <button class="button button-quiet purchase-remove" type="button" aria-label="Quitar producto">Quitar</button>
                    <?php if (!$editingPurchase): ?><label class="form-field purchase-barcode-field">Códigos de fábrica por llanta (opcional)
                      <textarea name="factory_barcodes[]" rows="2" placeholder="Escanea un código por renglón"></textarea>
                      <small>Si un código se repite, SIVA asignará códigos internos individuales.</small>
                    </label><?php endif; ?>
                  </div>
                <?php endfor; ?>
              </div>
              <template id="purchase-line-template"><div class="purchase-line"><label class="form-field">Producto *<input type="search" data-filter-product-options placeholder="Filtra por SKU, marca o medida" autocomplete="off"><select name="product_ids[]" required><option value="">Selecciona un producto</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= escape_html($product['sku'] . ' · ' . $product['name'] . ' · ' . $product['brand'] . ' · ' . $product['size']) ?></option><?php endforeach; ?></select></label><label class="form-field">Cantidad *<input name="quantities[]" type="number" min="1" max="1000000" step="1" value="1" required></label><label class="form-field">Costo unitario *<input name="unit_costs[]" type="number" min="0.01" step="0.01" placeholder="0.00" required></label><button class="button button-quiet purchase-remove" type="button" aria-label="Quitar producto">Quitar</button><?php if (!$editingPurchase): ?><label class="form-field purchase-barcode-field">Códigos de fábrica por llanta (opcional)<textarea name="factory_barcodes[]" rows="2" placeholder="Escanea un código por renglón"></textarea><small>Si un código se repite, SIVA asignará códigos internos individuales.</small></label><?php endif; ?></div></template>
              <div class="purchase-actions"><button class="button button-quiet" id="add-purchase-line" type="button">Agregar producto</button><div><a class="button button-quiet" href="index.php?pagina=compras">Cancelar</a> <button class="button button-primary" type="submit"><?= $editingPurchase ? 'Guardar cambios' : 'Registrar compra y recibir stock' ?></button></div></div>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <section class="product-catalog" aria-labelledby="purchase-history-title">
            <div class="catalog-heading"><h2 id="purchase-history-title">Historial de compras</h2><div class="inventory-actions"><?php if ($includeCancelledPurchases): ?><a class="button button-quiet" href="index.php?pagina=compras<?= $purchaseHistoryWarehouse !== '' ? '&amp;warehouse_code=' . rawurlencode($purchaseHistoryWarehouse) : '' ?>">Ocultar anuladas</a><?php else: ?><a class="button button-quiet" href="index.php?pagina=compras&amp;include_cancelled=1<?= $purchaseHistoryWarehouse !== '' ? '&amp;warehouse_code=' . rawurlencode($purchaseHistoryWarehouse) : '' ?>">Ver anuladas</a><?php endif; ?><a class="button button-primary" href="index.php?pagina=compras&amp;view=new">Nueva compra</a></div></div>
            <form class="filters purchase-history-warehouse-filter" method="get" action="index.php">
              <input type="hidden" name="pagina" value="compras">
              <?php if ($includeCancelledPurchases): ?><input type="hidden" name="include_cancelled" value="1"><?php endif; ?>
              <label><span>Filtrar por almacén</span><select name="warehouse_code"><option value="">Todos los almacenes</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= escape_html($warehouse['code']) ?>" <?= $purchaseHistoryWarehouse === $warehouse['code'] ? 'selected' : '' ?>><?= escape_html($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
              <button class="button button-primary" type="submit">Filtrar</button>
            </form>
            <?php if ($purchases === []): ?>
              <div class="catalog-empty"><strong><?= $includeCancelledPurchases ? 'Sin compras registradas' : 'Sin compras activas' ?></strong><p><?= $includeCancelledPurchases ? 'Cuando ingreses una compra aparecerá en este historial.' : 'No hay compras activas. Puedes revisar las compras anuladas o registrar una nueva.' ?></p></div>
            <?php else: ?>
              <label class="quick-filter"><span>Buscar en compras</span><input type="search" data-quick-filter="purchase-history-table" placeholder="Proveedor, documento, producto o almacén" autocomplete="off"><small data-filter-status role="status" aria-live="polite"></small></label>
              <div class="table-wrap"><table id="purchase-history-table"><thead><tr><th>Documento</th><th>Fecha</th><th>Proveedor</th><th>Almacén</th><th>Productos</th><th>Registró</th><th>Total</th><?php if ($includeCancelledPurchases): ?><th>Estado</th><?php endif; ?><?php if ($isPurchaseAdministrator): ?><th>Acciones</th><?php endif; ?></tr></thead><tbody>
                <?php foreach ($purchases as $purchase): ?>
                  <tr><td><strong><?= escape_html($purchase['purchase_number']) ?></strong><?php if ($purchase['supplier_document'] !== ''): ?><small class="table-secondary"><?= escape_html($purchase['supplier_document']) ?></small><?php endif; ?></td><td><?= escape_html($purchase['purchase_date']) ?></td><td><?= escape_html($purchase['supplier']) ?></td><td><?= escape_html($purchase['warehouse_code'] . ' · ' . $purchase['warehouse_name']) ?></td><td><?= (int) $purchase['line_count'] ?><?php if (($purchaseItemsById[(int) $purchase['id']] ?? []) !== []): ?><details class="purchase-detail"><summary>Ver detalle</summary><ul><?php foreach ($purchaseItemsById[(int) $purchase['id']] as $item): ?><li><?= escape_html($item['sku'] . ' · ' . $item['product_name']) ?> — <?= (int) $item['quantity'] ?> × $<?= number_format((int) $item['unit_cost_cents'] / 100, 2, '.', ',') ?> = $<?= number_format((int) $item['line_total_cents'] / 100, 2, '.', ',') ?></li><?php endforeach; ?></ul></details><?php endif; ?></td><td><?= escape_html($purchase['created_by']) ?></td><td>$<?= number_format((int) $purchase['total_cents'] / 100, 2, '.', ',') ?></td>
                    <?php if ($includeCancelledPurchases): ?><td><span class="purchase-status<?= $purchase['status'] === 'anulada' ? ' is-cancelled' : '' ?>"><?= $purchase['status'] === 'anulada' ? 'Anulada' : 'Activa' ?></span><?php if ($purchase['status'] === 'anulada'): ?><small class="table-secondary"><?= escape_html($purchase['cancellation_reason']) ?> · <?= escape_html($purchase['cancelled_by']) ?> · <?= escape_html((string) $purchase['cancelled_at']) ?></small><?php endif; ?></td><?php endif; ?>
                    <?php if ($isPurchaseAdministrator): ?><td><div class="purchase-record-actions">
                      <?php if ($purchase['status'] === 'activa'): ?>
                        <a class="button button-quiet" href="index.php?pagina=compras&amp;view=edit&amp;purchase_id=<?= (int) $purchase['id'] ?>">Editar</a>
                        <form class="purchase-cancel-form" method="post" action="index.php?pagina=compras">
                          <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="cancel_purchase"><input type="hidden" name="purchase_id" value="<?= (int) $purchase['id'] ?>">
                          <label class="purchase-cancel-reason">Motivo<input name="cancellation_reason" maxlength="500" required placeholder="Motivo de anulación"></label>
                          <label class="purchase-cancel-confirm"><input type="checkbox" name="confirm_cancellation" value="1" required> Confirmo anular y revertir stock</label>
                          <button class="button button-danger" type="submit">Anular compra</button>
                        </form>
                      <?php else: ?><span class="table-secondary">Sin acciones: compra anulada</span><?php endif; ?>
                      <?php if (($purchaseAuditById[(int) $purchase['id']] ?? []) !== []): ?>
                        <details class="purchase-audit"><summary>Ver auditoría</summary>
                          <?php foreach ($purchaseAuditById[(int) $purchase['id']] as $audit): ?>
                            <?php
                              $previousAudit = json_decode((string) $audit['datos_anteriores'], true);
                              $currentAudit = json_decode((string) $audit['datos_nuevos'], true);
                            ?>
                            <article><strong><?= $audit['evento'] === 'anulacion' ? 'Anulación' : 'Edición' ?></strong><small><?= escape_html($audit['created_by']) ?> · <?= escape_html((string) $audit['creado_en']) ?></small>
                              <?php if ($audit['motivo'] !== ''): ?><p>Motivo: <?= escape_html($audit['motivo']) ?></p><?php endif; ?>
                              <div><span>Antes</span><pre><?= escape_html(json_encode($previousAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></div>
                              <div><span>Después</span><pre><?= escape_html(json_encode($currentAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></div>
                            </article>
                          <?php endforeach; ?>
                        </details>
                      <?php endif; ?>
                    </div></td><?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <script src="scripts/filtro-facil.js?v=20261106-1630" defer></script>
  <script src="scripts/avisos-voz.js?v=20261118-voice-runtime-1" defer></script>
  <script type="module" src="scripts/compras.js?v=20261110-purchase-flow-2"></script>
</body>
</html>