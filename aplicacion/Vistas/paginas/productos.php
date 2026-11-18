<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#090b0d">
  <title>Productos | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261118-warehouse-columns-1">
</head>
<body>
  <div class="products-app">
    <?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
    <div class="workspace">
      <?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, 'Productos'); ?>
      <main class="products-main">
        <?php if ($successMessage !== ''): ?><p class="notice notice-success" role="status"><?= escape_html($successMessage) ?></p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= escape_html($errorMessage) ?></p><?php endif; ?>
        <?php if ($showProductForm && $currentUser['role'] === 'administrador'): ?>
          <div class="page-heading"><div><p class="eyebrow">Productos</p><h2><?= $editingProduct ? 'Editar producto' : 'Nuevo producto' ?></h2></div><?php if ($editingProduct): ?><a class="button button-quiet" href="index.php?pagina=productos&amp;catalog_q=<?= rawurlencode((string) ($productBeingEdited['sku'] ?? '')) ?>">Cancelar edición</a><?php endif; ?></div>
          <form class="product-form product-form-page" method="post" action="<?= $editingProduct ? 'index.php?pagina=productos&amp;view=edit&amp;product_id=' . (int) ($productBeingEdited['id'] ?? 0) : 'index.php?pagina=productos' ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="action" value="<?= $editingProduct ? 'update_product_details' : 'create_product' ?>">
            <?php if ($editingProduct): ?><input type="hidden" name="product_id" value="<?= (int) ($productBeingEdited['id'] ?? 0) ?>"><?php endif; ?>
            <p class="new-product-intro"><?= $editingProduct ? 'Actualiza los datos del producto. Los cambios de existencias se hacen por separado y quedan anotados en Movimientos.' : 'Completa los datos de la llanta. Puedes usar el código del proveedor o generar un SKU; todas las unidades del mismo producto compartirán ese SKU. Si aún no tienes unidades, deja el stock inicial en 0 y regístralas después al recibir una compra.' ?></p>
            <div class="form-grid">
              <div class="form-field product-sku-field">
                <label for="product-sku">Código SKU<?= $editingProduct ? ' *' : '' ?></label>
                <div class="product-sku-control">
                  <input id="product-sku" name="sku" value="<?= escape_html($formValues['sku']) ?>" maxlength="40" placeholder="Código del proveedor o genera uno" <?= $editingProduct ? 'required' : '' ?>>
                  <?php if (!$editingProduct): ?><button class="button button-quiet" type="button" data-generate-product-sku>Generar SKU</button><?php endif; ?>
                </div>
                <?php if ($editingProduct): ?>
                  <small>Este código identifica el modelo y lo comparten todas sus llantas. Puedes cambiarlo aquí; las etiquetas anteriores conservarán el código viejo, así que vuelve a imprimirlas después de guardarlo.</small>
                <?php else: ?>
                  <small>Si no tiene código, pulsa Generar SKU o deja el campo vacío; SIVA creará uno al guardar. Las unidades idénticas comparten este SKU.</small>
                  <small class="product-sku-generator-status" data-product-sku-status role="status" aria-live="polite"></small>
                <?php endif; ?>
              </div>
              <label class="form-field">Categoría<select name="category" required><?php if ($editingProduct && !in_array($formValues['category'], $categories, true)): ?><option value="<?= escape_html($formValues['category']) ?>" selected><?= escape_html($formValues['category']) ?></option><?php endif; ?><?php foreach ($categories as $category): ?><option value="<?= escape_html($category) ?>" <?= $formValues['category'] === $category ? 'selected' : '' ?>><?= escape_html($category) ?></option><?php endforeach; ?></select></label>
              <label class="form-field">Nombre del producto *<input name="name" value="<?= escape_html($formValues['name']) ?>" maxlength="160" placeholder="Marca, modelo y características" required></label>
              <label class="form-field form-field-wide">Descripción<textarea name="description" rows="2" maxlength="1000" placeholder="Detalles del producto"><?= escape_html($formValues['description']) ?></textarea></label>
              <label class="form-field">Marca<select name="brand" required data-custom-brand-select><?php if ($editingProduct && !in_array($formValues['brand'], $brands, true)): ?><option value="<?= escape_html($formValues['brand']) ?>" selected><?= escape_html($formValues['brand']) ?></option><?php endif; ?><?php foreach ($brands as $brand): ?><option value="<?= escape_html($brand) ?>" <?= $formValues['brand'] === $brand ? 'selected' : '' ?>><?= escape_html($brand) ?></option><?php endforeach; ?></select></label>
              <?php if (!$editingProduct): ?>
                <label class="form-field" data-custom-brand-field <?= $formValues['brand'] === 'Otra' ? '' : 'hidden' ?>>Nombre de la marca *
                  <input name="custom_brand" value="<?= escape_html($formValues['custom_brand']) ?>" maxlength="120" data-custom-brand-input <?= $formValues['brand'] === 'Otra' ? 'required' : 'disabled' ?>>
                  <small>Escribe aquí la marca que no aparece en la lista.</small>
                </label>
              <?php endif; ?>
              <label class="form-field">Medida / tipo *<input name="size" value="<?= escape_html($formValues['size']) ?>" maxlength="80" placeholder="Ej. 205/55 R16" required></label>
              <label class="form-field">Precio de venta *<span class="input-prefix"><span>$</span><input name="unit_price" value="<?= escape_html($formValues['unit_price']) ?>" inputmode="decimal" placeholder="0.00" required></span><small>Este será el precio que se mostrará en Ventas.</small></label>
              <?php if (!$editingProduct): ?>
                <label class="form-field">Stock inicial · A0101 / Castilla<input name="stock_a0101" type="number" min="0" step="1" value="<?= escape_html($formValues['stock_a0101']) ?>" required><small>Unidades que hay actualmente en este almacén.</small></label>
                <label class="form-field">Stock inicial · A0102 / Jr. Arequipa · Huancayo<input name="stock_a0102" type="number" min="0" step="1" value="<?= escape_html($formValues['stock_a0102']) ?>" required><small>Unidades que hay actualmente en este almacén.</small></label>
                <label class="form-field">Stock inicial · A0103 / Atalaya<input name="stock_a0103" type="number" min="0" step="1" value="<?= escape_html($formValues['stock_a0103']) ?>" required><small>Unidades que hay actualmente en este almacén.</small></label>
              <?php else: ?>
                <p class="form-field form-field-wide product-edit-stock-note">Las existencias no se cambian al editar los datos del producto. Usa «Guardar stock» en el catálogo para registrar un ajuste.</p>
              <?php endif; ?>
              <label class="form-field form-field-wide">Proveedor<input name="supplier" value="<?= escape_html($formValues['supplier']) ?>" maxlength="160" placeholder="Nombre del proveedor"></label>
              <?php if (!$editingProduct): ?>
                <label class="form-field form-field-wide">Imagen del producto · JPG, PNG o WEBP · máximo 5 MB<input id="product-image" name="image" type="file" accept="image/jpeg,image/png,image/webp"></label>
                <img class="image-preview" id="product-image-preview" alt="Vista previa del producto" hidden>
              <?php elseif (!empty($productBeingEdited['image_path'])): ?>
                <p class="form-field form-field-wide product-edit-stock-note">La imagen actual se conservará al guardar.</p>
              <?php endif; ?>
            </div>
            <footer class="dialog-actions"><button class="button button-primary" type="submit"><?= $editingProduct ? 'Guardar cambios' : 'Guardar producto' ?></button><a class="button button-quiet" href="<?= $editingProduct ? 'index.php?pagina=productos&amp;catalog_q=' . rawurlencode((string) ($productBeingEdited['sku'] ?? '')) : 'index.php?pagina=productos' ?>">Cancelar</a></footer>
          </form>
        <?php else: ?>
          <section class="product-catalog" aria-labelledby="sale-catalog-title">
            <header class="product-sales-heading">
              <div>
                <span class="product-sales-eyebrow">CATÁLOGO DE VENTA</span>
                <h2 id="sale-catalog-title">Precios y etiquetas</h2>
                <p>Define el precio de cada llanta y prepara una etiqueta de código de barras por unidad para registrar sus ventas.</p>
              </div>
              <?php if ($currentUser['role'] === 'administrador'): ?><a class="button button-primary product-new-button" href="index.php?pagina=productos&amp;view=new"><span aria-hidden="true">+</span> Nuevo producto</a><?php endif; ?>
            </header>
            <details class="simple-help">
              <summary>¿Cómo preparo una llanta para venderla?</summary>
              <ol>
                <li>Busca la llanta por código, marca o medida.</li>
                <li>Escribe el precio al que la venderás y pulsa <strong>Guardar</strong>.</li>
                <li>Pulsa <strong>Imprimir etiquetas</strong>, selecciona el almacén e imprime una etiqueta individual por llanta disponible.</li>
                <li>Pega cada etiqueta en su llanta. En Ventas, escanea el código de cada unidad; el precio se toma del catálogo y el stock baja al confirmar la salida.</li>
              </ol>
              <p>El administrador puede corregir las cantidades de cada almacén. Cada cambio queda anotado en Movimientos.</p>
              <p>Si cambia el precio, actualízalo aquí antes de registrar nuevas ventas.</p>
            </details>
            <form class="filters catalog-filters product-price-search" method="get" action="index.php?pagina=productos">
              <input type="hidden" name="pagina" value="productos">
              <input type="hidden" name="catalog_page" value="1">
              <label class="search-box"><span class="visually-hidden">Buscar en el catálogo de ventas</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5" /><path d="m16 16 4 4" /></svg><input type="search" name="catalog_q" value="<?= escape_html($catalogSearch) ?>" placeholder="SKU, nombre, marca o medida"></label>
              <button class="button button-primary" type="submit">Buscar</button>
            </form>
            <p class="physical-count-search-status" role="status" aria-live="polite" data-product-catalog-search-status>Escribe para filtrar los productos de esta página o pulsa Buscar para consultar todo el catálogo.</p>
            <?php if ($catalogProducts === []): ?>
              <div class="catalog-empty"><strong>Sin productos en el catálogo</strong><p>Prueba otra búsqueda o registra un producto nuevo.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table class="product-price-table">
                <thead><tr><th scope="col">Producto</th><th scope="col">SKU</th><th scope="col">Marca</th><th scope="col">Medida / tipo</th><?php if ($currentUser['role'] === 'administrador'): ?><th scope="col">Existencias por almacén</th><?php else: ?><th class="product-warehouse-heading" scope="col">A0101<small>Castilla</small></th><th class="product-warehouse-heading" scope="col">A0102<small>Jr. Arequipa · Huancayo</small></th><th class="product-warehouse-heading" scope="col">A0103<small>Atalaya</small></th><th class="product-warehouse-heading" scope="col">Total</th><?php endif; ?><th scope="col">Precio de venta</th><th scope="col">Etiquetas de barras</th><?php if ($currentUser['role'] === 'administrador'): ?><th scope="col">Acciones</th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($catalogProducts as $product): ?>
                  <?php $skuVisible = (string) ($product['sku_display'] ?? $product['sku']); ?>
                  <?php $searchableProductText = strtolower(implode(' ', [
                      (string) $product['name'],
                      (string) $product['sku'],
                      $skuVisible,
                      (string) $product['brand'],
                      (string) $product['size'],
                  ])); ?>
                  <tr data-product-search="<?= escape_html($searchableProductText) ?>"><td><div class="product-catalog-product-cell"><?php if (trim((string) ($product['image_path'] ?? '')) !== ''): ?><img class="product-catalog-image" src="<?= escape_html((string) $product['image_path']) ?>" alt="Imagen de <?= escape_html((string) $product['name']) ?>" loading="lazy"><?php endif; ?><span><?= escape_html((string) $product['name']) ?></span></div></td><td><span class="product-code"><?= escape_html($skuVisible) ?></span></td><td><?= escape_html((string) $product['brand']) ?></td><td><?= escape_html((string) $product['size']) ?></td>
                    <?php if ($currentUser['role'] === 'administrador'): ?>
                      <td>
                        <form class="product-stock-form" method="post" action="index.php?pagina=productos">
                          <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                          <input type="hidden" name="action" value="update_product_stock">
                          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                          <input type="hidden" name="catalog_q" value="<?= escape_html($catalogSearch) ?>">
                          <input type="hidden" name="catalog_page" value="<?= (int) $catalogPage ?>">
                          <div class="product-stock-fields">
                            <?php foreach (['A0101' => 'stock_a0101', 'A0102' => 'stock_a0102', 'A0103' => 'stock_a0103'] as $warehouse => $field): ?>
                              <label><?= escape_html($warehouse) ?><input aria-label="Stock <?= escape_html($warehouse) ?> para <?= escape_html((string) $product['name']) ?>" name="<?= escape_html($field) ?>" type="number" min="0" max="2147483647" step="1" value="<?= (int) $product[$field] ?>" required></label>
                            <?php endforeach; ?>
                          </div>
                          <div class="product-stock-footer"><strong>Total automático: <output data-stock-total aria-live="polite"><?= (int) $product['stock'] ?></output></strong><button class="button button-quiet product-stock-save" type="submit">Guardar stock</button></div>
                        </form>
                      </td>
                    <?php else: ?>
                      <?php foreach (['A0101' => 'stock_a0101', 'A0102' => 'stock_a0102', 'A0103' => 'stock_a0103'] as $warehouse => $field): ?>
                        <td class="product-stock-column"><span class="product-stock-value"><?= (int) $product[$field] ?></span></td>
                      <?php endforeach; ?>
                      <td class="product-stock-column"><strong class="product-stock-value product-stock-total"><?= (int) $product['stock'] ?></strong></td>
                    <?php endif; ?>
                    <td><?php if ($currentUser['role'] === 'administrador'): ?>
                      <form class="product-price-form" method="post" action="index.php?pagina=productos">
                        <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_product_price">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" name="catalog_q" value="<?= escape_html($catalogSearch) ?>">
                        <input type="hidden" name="catalog_page" value="<?= (int) $catalogPage ?>">
                        <input type="hidden" name="q" value="<?= escape_html($search) ?>">
                        <input type="hidden" name="category" value="<?= escape_html($filterCategory) ?>">
                        <input type="hidden" name="brand" value="<?= escape_html($filterBrand) ?>">
                        <input type="hidden" name="history_page" value="<?= (int) $historyPage ?>">
                        <label class="visually-hidden" for="catalog-price-<?= (int) $product['id'] ?>">Precio de <?= escape_html((string) $product['name']) ?></label>
                        <span class="input-prefix"><span>$</span><input id="catalog-price-<?= (int) $product['id'] ?>" name="unit_price" type="number" min="0" step="0.01" value="<?= number_format((int) $product['unit_price_cents'] / 100, 2, '.', '') ?>" required></span>
                        <button class="button button-quiet product-price-save" type="submit">Guardar</button>
                      </form>
                    <?php else: ?>$<?= number_format((int) $product['unit_price_cents'] / 100, 2, '.', ',') ?><?php endif; ?></td>
                    <td><a class="button button-quiet" href="index.php?pagina=etiquetas_producto&amp;product_id=<?= (int) $product['id'] ?>">Imprimir etiquetas</a></td>
                    <?php if ($currentUser['role'] === 'administrador'): ?><td><div class="product-catalog-actions"><a class="button button-quiet" href="index.php?pagina=productos&amp;view=edit&amp;product_id=<?= (int) $product['id'] ?>">Editar</a><form method="post" action="index.php?pagina=productos" data-confirm-message="¿Eliminar el producto <?= escape_html((string) $product['name']) ?>? Solo se podrán eliminar productos sin stock ni historial."><input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="catalog_q" value="<?= escape_html($catalogSearch) ?>"><button class="button button-danger" type="submit">Eliminar</button></form></div></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?></tbody>
              </table></div>
              <nav class="catalog-pagination" aria-label="Paginación del catálogo de ventas">
                <span>Página <?= (int) $catalogPage ?> de <?= (int) $catalogPages ?> · <?= (int) $catalogTotal ?> productos</span>
                <div>
                  <?php if ($catalogPage > 1): ?><a class="button button-quiet" href="index.php?pagina=productos&amp;<?= escape_html(http_build_query(['catalog_q' => $catalogSearch, 'catalog_page' => $catalogPage - 1])) ?>">Anterior</a><?php endif; ?>
                  <?php if ($catalogPage < $catalogPages): ?><a class="button button-quiet" href="index.php?pagina=productos&amp;<?= escape_html(http_build_query(['catalog_q' => $catalogSearch, 'catalog_page' => $catalogPage + 1])) ?>">Siguiente</a><?php endif; ?>
                </div>
              </nav>
            <?php endif; ?>
          </section>
          <details class="historical-products-details" <?= ($search !== '' || $filterCategory !== '' || $filterBrand !== '' || $historyPage > 1) ? 'open' : '' ?>>
            <summary><span>Ver ventas históricas 2026</span><small>Información de ventas por producto y almacén</small></summary>
            <section class="product-catalog historical-products-catalog" aria-labelledby="catalog-title">
              <header class="historical-products-heading">
                <div class="historical-products-title"><h2 id="catalog-title">Ventas por producto</h2><span>2026</span></div>
                <p>Ventas netas por almacén; no representan el stock disponible. El precio aparece cuando el código coincide con un producto del catálogo.</p>
              </header>
            <form class="filters catalog-filters historical-product-filters" method="get" action="index.php?pagina=productos">
              <input type="hidden" name="pagina" value="productos">
              <input type="hidden" name="history_page" value="1">
              <label class="search-box"><span class="visually-hidden">Buscar productos</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5" /><path d="m16 16 4 4" /></svg><input type="search" name="q" value="<?= escape_html($search) ?>" placeholder="Código, descripción, marca o medida"></label>
              <label class="filter-select"><span class="visually-hidden">Filtrar por categoría</span><select name="category"><option value="">Todas las categorías</option><?php foreach ($categories as $category): ?><option value="<?= escape_html($category) ?>" <?= $filterCategory === $category ? 'selected' : '' ?>><?= escape_html($category) ?></option><?php endforeach; ?></select></label>
              <label class="filter-select"><span class="visually-hidden">Filtrar por marca</span><select name="brand"><option value="">Todas las marcas</option><?php foreach ($historicalBrands as $brand): ?><option value="<?= escape_html((string) $brand) ?>" <?= $filterBrand === $brand ? 'selected' : '' ?>><?= escape_html((string) $brand) ?></option><?php endforeach; ?></select></label>
              <button class="button button-primary" type="submit">Filtrar</button>
            </form>
            <?php if ($products === []): ?>
              <div class="catalog-empty"><strong>Sin resultados</strong><p>No hay códigos de producto del histórico de 2026 con esos filtros.</p></div>
            <?php else: ?>
              <div class="table-wrap"><table>
                <thead><tr><th scope="col">Producto / código histórico</th><th scope="col">SKU</th><th scope="col">Categoría</th><th scope="col">Marca</th><th scope="col">Medida / tipo</th><th scope="col">Ventas netas 2026 · A0101 Castilla</th><th scope="col">Ventas netas 2026 · A0102 Jr. Arequipa / Huancayo</th><th scope="col">Ventas netas 2026 · A0103 Atalaya</th><th scope="col">Total ventas netas 2026</th><th scope="col">Precio unitario</th></tr></thead>
                <tbody><?php foreach ($products as $product): ?>
                  <tr><td><div class="product-cell"><?php if ($product['image_path'] !== null): ?><img class="product-thumb" src="<?= escape_html((string) $product['image_path']) ?>" alt=""><?php else: ?><span class="product-thumb product-thumb-empty" aria-hidden="true">B4</span><?php endif; ?><span class="product-name"><?= escape_html((string) $product['name']) ?></span></div></td>
                    <td><span class="product-code"><?= escape_html((string) $product['sku']) ?></span></td><td><?= escape_html((string) $product['category']) ?></td><td><?= escape_html((string) $product['brand']) ?></td><td><?= escape_html((string) $product['size']) ?></td>
                    <td><?= (int) $product['sales_a0101'] ?></td><td><?= (int) $product['sales_a0102'] ?></td><td><?= (int) $product['sales_a0103'] ?></td><td><strong><?= (int) $product['historical_units'] ?></strong></td>
                    <td><?= $product['unit_price_cents'] === null ? '—' : '$' . number_format((int) $product['unit_price_cents'] / 100, 2, '.', ',') ?></td></tr>
                <?php endforeach; ?></tbody>
              </table></div>
              <nav class="catalog-pagination historical-product-pagination" aria-label="Paginación del histórico de productos">
                <span>Mostrando <?= number_format(($historyPage - 1) * $historyPerPage + 1) ?>–<?= number_format(min($historyPage * $historyPerPage, $historyTotal)) ?> de <?= number_format((int) $historyTotal) ?> productos · Página <?= (int) $historyPage ?> de <?= (int) $historyPages ?></span>
                <div>
                  <?php if ($historyPage > 1): ?><a class="button button-quiet" href="index.php?pagina=productos&amp;<?= escape_html(http_build_query(['q' => $search, 'category' => $filterCategory, 'brand' => $filterBrand, 'history_page' => $historyPage - 1])) ?>">Anterior</a><?php endif; ?>
                  <?php if ($historyPage < $historyPages): ?><a class="button button-primary" href="index.php?pagina=productos&amp;<?= escape_html(http_build_query(['q' => $search, 'category' => $filterCategory, 'brand' => $filterBrand, 'history_page' => $historyPage + 1])) ?>">Siguiente</a><?php endif; ?>
                </div>
              </nav>
              <p class="table-footnote">Las ventas son netas: las cantidades negativas del histórico se descuentan. El precio histórico solo aparece cuando el SKU coincide con un producto registrado.</p>
            <?php endif; ?>
            </section>
          </details>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <script src="scripts/productos.js?v=20261117-uppercase-product-fields-1" defer></script>
  <script type="module" src="scripts/efecto-puntero.js"></script>
</body>
</html>