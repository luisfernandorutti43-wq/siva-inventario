<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f4f5f8">
  <title>Pronóstico de ventas | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261118-prediction-hidden-1">
</head>
<body class="prediction-page">
  <div class="products-app">
    <?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
    <div class="workspace">
      <?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, 'Pronóstico de ventas', 'La IA estima ventas por marca y llanta a partir del historial importado', 'prediction-topbar'); ?>
      <main class="products-main dashboard-main">
        <?php
          $forecastVoiceGuide = 'El Pronóstico de ventas ayuda a planificar, pero no garantiza ventas. Muestra estimaciones por día '
              . 'y por semana, organizadas por marca o por llanta; puedes buscar una marca para encontrarla. Las estimaciones se '
              . 'calculan a partir del historial de ventas importado y no modifican existencias ni registran compras. Si importas '
              . 'ventas, revisa que no estén cargadas previamente para evitar duplicarlas.';
        ?>
        <div class="inventory-forecast-context">
          <span>Inventario <span aria-hidden="true">/</span> Pronóstico de ventas</span>
          <a class="button button-quiet" href="index.php?pagina=inventario">Volver a Inventario</a>
        </div>
        <div class="inventory-guide-voice" data-voice-message="<?= escape_html($forecastVoiceGuide) ?>" data-voice-manual="true">
          <button class="button button-quiet" type="button" data-voice-replay>Escuchar cómo funciona</button>
          <span data-voice-status role="status" aria-live="polite">La guía está en silencio. Pulsa el botón si quieres escucharla.</span>
        </div>
        <?php if ($showDuplicateUploadWarning): ?>
          <div class="sales-import-warning" role="alertdialog" aria-modal="true" aria-labelledby="sales-import-warning-title" aria-describedby="sales-import-warning-description" tabindex="-1">
            <section class="sales-import-warning-card"><span class="sales-import-warning-icon" aria-hidden="true">!</span><h2 id="sales-import-warning-title">Este archivo ya se subió</h2><p id="sales-import-warning-description">Este Excel ya se subió. Por favor, no lo vuelvas a subir.</p><a class="button button-primary" href="index.php?pagina=prediccion_marcas">Entendido</a></section>
          </div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?><p class="notice notice-success" role="status"><?= escape_html($successMessage) ?></p><?php endif; ?>
        <?php if ($importErrorMessage !== '' && !$showDuplicateUploadWarning): ?><p class="notice notice-error" role="alert"><?= escape_html($importErrorMessage) ?></p><?php endif; ?>
        <?php if ($isReviewingSalesImport): ?>
          <?php
            $cutoffDate = new DateTimeImmutable($pendingSalesImport['last_sale_date']);
            $forecastStartDate = new DateTimeImmutable($pendingSalesImport['forecast_start_date']);
            $forecastEndDate = new DateTimeImmutable($pendingSalesImport['forecast_end_date']);
          ?>
          <section class="prediction-howto sales-import-review" aria-label="Revisión de importación">
            <p><strong>Revisa el archivo antes de guardarlo.</strong> Todavía no se ha guardado ninguna venta ni el Excel en la base de datos.</p>
            <p><strong>Archivo:</strong> <?= escape_html($pendingSalesImport['original_name']) ?></p>
            <p><strong>Mes de ventas:</strong> <?= escape_html($pendingSalesImport['month']) ?> · <strong>Filas válidas:</strong> <?= number_format((int) $pendingSalesImport['row_count']) ?></p>
            <p><strong>Rango de ventas del Excel:</strong> <?= escape_html((new DateTimeImmutable($pendingSalesImport['first_sale_date']))->format('d/m/Y')) ?> a <?= escape_html($cutoffDate->format('d/m/Y')) ?>.</p>
            <p><strong>Pronóstico que se generará:</strong> para la semana completa, del lunes <?= escape_html($forecastStartDate->format('d/m/Y')) ?> al domingo <?= escape_html($forecastEndDate->format('d/m/Y')) ?>. Se estiman las ventas de lunes a sábado; el domingo queda en 0 porque no se trabaja.</p>
            <?php if ($pendingSalesImport['report_format']): ?><p>El reporte no indica el almacén; se conservará sin asignarlo a uno.</p><?php endif; ?>
            <?php if ((int) $pendingSalesImport['excluded_warehouse_rows'] > 0): ?><p>Se excluirán <?= number_format((int) $pendingSalesImport['excluded_warehouse_rows']) ?> filas de almacenes distintos de A0101 y A0102.</p><?php endif; ?>
            <p>Al confirmar, se guardarán el Excel original y sus ventas. Las cargas del mismo mes se suman; no vuelvas a incluir ventas ya cargadas porque se contarían dos veces. El inventario no se modificará.</p>
            <?php if ((int) ($pendingSalesImport['overlap']['row_count'] ?? 0) > 0): ?>
              <p class="notice notice-warning" role="alert"><strong>Atención: hay fechas repetidas.</strong> Esta importación coincide con <?= number_format((int) $pendingSalesImport['overlap']['row_count']) ?> filas en <?= number_format((int) $pendingSalesImport['overlap']['date_count']) ?> fechas de <?= number_format((int) $pendingSalesImport['overlap']['file_count']) ?> importaciones anteriores, entre <?= escape_html((new DateTimeImmutable($pendingSalesImport['overlap']['first_date']))->format('d/m/Y')) ?> y <?= escape_html((new DateTimeImmutable($pendingSalesImport['overlap']['last_date']))->format('d/m/Y')) ?>. Las filas no se eliminan automáticamente: si vuelves a incluir las mismas ventas, se sumarán y podrían duplicar el pronóstico. Revisa el archivo antes de continuar.</p>
            <?php endif; ?>
            <form method="post" action="index.php?pagina=importar" class="prediction-history-form"><input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="confirmation_token" value="<?= escape_html($pendingSalesImport['token']) ?>"><?php if ((int) ($pendingSalesImport['overlap']['row_count'] ?? 0) > 0): ?><label class="notice notice-warning duplicate-sale-confirmation"><input type="checkbox" name="confirmar_traslape" value="1" required> Revisé las fechas y confirmo que estas filas corresponden a ventas distintas que debo agregar.</label><?php endif; ?><button class="button button-primary" type="submit" name="action" value="confirm_monthly_sales">Confirmar y guardar Excel y ventas</button><button class="button button-quiet" type="submit" name="action" value="cancel_monthly_sales">Cancelar, no guardar nada</button></form>
          </section>
        <?php elseif ($errorMessage !== ''): ?>
          <p class="notice notice-error" role="alert"><?= escape_html($errorMessage) ?></p>
        <?php elseif (!is_array($selectedForecastWeek)): ?>
          <section class="prediction-howto" aria-label="Cómo funciona el pronóstico"><p><strong>Pronóstico con IA:</strong> combina 75 % de Random Forest con 25 % del promedio de ventas del mismo día de las últimas ocho semanas. Estima la semana completa, de lunes a domingo; el domingo queda en 0 porque no se trabaja. Al comenzar la siguiente semana, el pronóstico se actualiza automáticamente.</p><?php if ($currentUser['role'] !== 'administrador'): ?><p>Solo el Administrador puede importar el Excel.</p><?php endif; ?></section>
          <p class="notice notice-warning" role="status"><?= escape_html($forecastMessage) ?></p>
        <?php else: ?>
          <?php
            $forecastWeekGroups = [];
            $weekStartDate = new DateTimeImmutable($selectedForecastWeek['forecast_dates'][0]);
            $weekStartDate = $weekStartDate->modify('-' . ((int) $weekStartDate->format('N') - 1) . ' days');
            $weekEndDate = $weekStartDate->modify('+6 days');
            $forecastWeekGroups[] = [
                'start_index' => 0,
                'dates' => $selectedForecastWeek['forecast_dates'],
                'label' => $weekStartDate->format('d/m') . '–' . $weekEndDate->format('d/m'),
            ];
            $weekDayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
          ?>
          <section class="prediction-howto" aria-label="Cómo funciona el pronóstico" hidden><p><strong>Pronóstico diario con IA combinada:</strong> mezcla 75 % de Random Forest y 25 % del promedio de ventas del mismo día de las últimas ocho semanas. Aprende del historial hasta el <strong><?= escape_html((new DateTimeImmutable($selectedForecastWeek['ai_trained_through']))->format('d/m/Y')) ?></strong> y estima de lunes <strong><?= escape_html($weekStartDate->format('d/m/Y')) ?></strong> a domingo <strong><?= escape_html($weekEndDate->format('d/m/Y')) ?></strong>. Cada estimación se incorpora al historial temporal para calcular el día siguiente. El domingo queda en 0 porque no se trabaja. Al comenzar la siguiente semana, el pronóstico se actualiza.</p></section>
          <section class="prediction-summary" aria-label="Resumen de ventas estimadas"><span>Estimación total de la semana</span><strong><?= number_format((float) $selectedForecastWeek['total_units'], 0) ?> llantas</strong><small><?= escape_html($selectedForecastWeek['label']) ?> · IA entrenada con ventas hasta <?= escape_html((new DateTimeImmutable($selectedForecastWeek['ai_trained_through']))->format('d/m/Y')) ?></small></section>
          <p class="prediction-table-help">La tabla muestra los siete días de la semana y el total semanal estimado. Es una ayuda para planificar; no garantiza ventas ni modifica existencias o compras.</p>
          <div class="prediction-forecast-tabs" role="tablist" aria-label="Tipo de pronóstico">
            <button type="button" class="button button-primary" id="brand-forecast-tab" role="tab" aria-selected="true" aria-controls="brand-forecast-panel">Pronóstico por marca</button>
            <button type="button" class="button button-quiet" id="tire-forecast-tab" role="tab" aria-selected="false" aria-controls="tire-forecast-panel" tabindex="-1">Pronóstico por llanta</button>
          </div>
          <section class="product-catalog prediction-results" id="brand-forecast-panel" role="tabpanel" aria-labelledby="brand-forecast-tab">
            <div class="catalog-heading"><div><h2>Ventas estimadas por marca</h2><p class="catalog-description">Suma por marca de las mismas predicciones mostradas por llanta.</p></div></div>
            <p class="prediction-table-help">Se muestran las estimaciones de lunes a sábado y el domingo en 0; la última columna suma la semana.</p>
            <?php if ($visibleBrands !== []): ?>
              <div class="prediction-brand-filter">
                <label for="prediction-brand-search">Buscar marca</label>
                <input id="prediction-brand-search" type="search" autocomplete="off" placeholder="Escribe el nombre de la marca" aria-controls="prediction-brand-table">
                <p id="prediction-brand-count" role="status" aria-live="polite" data-total-items="<?= count($visibleBrands) ?>">Mostrando <?= number_format(count($visibleBrands)) ?> marcas.</p>
              </div>
            <?php endif; ?>
            <div class="table-wrap"><table id="prediction-brand-table"><thead><tr><th>Marca</th><th>Ventas importadas<br><small>Excel confirmado</small></th><?php foreach ($forecastWeekGroups as $week): ?><?php foreach ($week['dates'] as $dateText): ?><?php $date = new DateTimeImmutable($dateText); ?><th><?= escape_html($weekDayNames[(int) $date->format('N') - 1]) ?><br><small><?= escape_html($date->format('d/m')) ?></small></th><?php endforeach; ?><th class="prediction-week-total">Total semanal<br><small><?= escape_html($week['label']) ?></small></th><?php endforeach; ?></tr></thead><tbody>
              <?php foreach ($visibleBrands as $row): ?><tr data-prediction-search="<?= escape_html($row['marca']) ?>"><td><span class="prediction-brand"><?php foreach ($row['logos'] as $brandLogo): ?><img src="<?= escape_html($brandLogo) ?>" alt="" width="96" height="36" loading="lazy" decoding="async"><?php endforeach; ?><strong><?= escape_html($row['marca']) ?></strong></span></td><td data-units="<?= escape_html((string) $row['recent_units']) ?>"><?= number_format((float) $row['recent_units'], 0) ?></td><?php $dataIndex = 0; foreach ($forecastWeekGroups as $week): ?><?php foreach ($week['dates'] as $dateText): ?><?php $units = (int) ($row['daily_units'][$dataIndex] ?? 0); ?><td data-units="<?= $units ?>"><?= number_format($units, 0) ?></td><?php $dataIndex++; endforeach; ?><?php $weekUnits = array_sum(array_slice($row['daily_units'], $week['start_index'], count($week['dates']))); ?><td class="prediction-week-total" data-units="<?= $weekUnits ?>"><strong><?= number_format($weekUnits, 0) ?></strong></td><?php endforeach; ?></tr><?php endforeach; ?>
              <?php $columnCount = count($selectedForecastWeek['forecast_dates']) + count($forecastWeekGroups) + 2; if ($visibleBrands === []): ?><tr><td colspan="<?= $columnCount ?>">El Excel no contiene marcas para pronosticar.</td></tr><?php endif; ?>
              <?php if ($visibleBrands !== []): ?><tr id="prediction-brand-no-results" hidden><td colspan="<?= $columnCount ?>">No se encontraron marcas con ese nombre.</td></tr><?php endif; ?>
            </tbody><tfoot><tr><th>Total visible</th><th data-total-index="1" data-units="<?= escape_html((string) $recentBrandSalesTotal) ?>"><?= number_format($recentBrandSalesTotal, 0) ?></th><?php $dataIndex = 2; foreach ($forecastWeekGroups as $week): ?><?php foreach ($week['dates'] as $dateOffset => $_dateText): ?><?php $units = (int) ($dailyBrandForecastTotals[$week['start_index'] + $dateOffset] ?? 0); ?><th data-total-index="<?= $dataIndex ?>" data-units="<?= $units ?>"><?= number_format($units, 0) ?></th><?php $dataIndex++; endforeach; ?><?php $weekUnits = array_sum(array_slice($dailyBrandForecastTotals, $week['start_index'], count($week['dates']))); ?><th data-total-index="<?= $dataIndex ?>" data-units="<?= $weekUnits ?>"><?= number_format($weekUnits, 0) ?></th><?php $dataIndex++; endforeach; ?></tr></tfoot></table></div>
          </section>
          <section class="product-catalog prediction-results" id="tire-forecast-panel" role="tabpanel" aria-labelledby="tire-forecast-tab" hidden>
            <div class="catalog-heading"><div><h2 id="tire-forecast-title">Ventas estimadas por llanta</h2><p class="catalog-description">Consulta las ventas y la estimación de cada medida y modelo.</p></div></div>
            <p class="prediction-table-help">El código mostrado es el mismo código de barras B4X01 que aparece en Inventario y Escaneo. Consulta las cantidades previstas por día y el total semanal para cada llanta.</p>
            <?php if ($visibleProducts !== []): ?>
              <div class="prediction-brand-filter">
                <label for="prediction-tire-search">Buscar llanta</label>
                <input id="prediction-tire-search" type="search" autocomplete="off" placeholder="Marca, medida, modelo o código" aria-controls="prediction-tire-table">
                <p id="prediction-tire-count" role="status" aria-live="polite" data-total-items="<?= count($visibleProducts) ?>">Mostrando <?= number_format(count($visibleProducts)) ?> llantas.</p>
              </div>
            <?php endif; ?>
            <div class="table-wrap"><table id="prediction-tire-table"><thead><tr><th>Marca</th><th>Código</th><th>Medida</th><th>Modelo</th><th>Ventas importadas<br><small>Excel confirmado</small></th><?php foreach ($forecastWeekGroups as $week): ?><?php foreach ($week['dates'] as $dateText): ?><?php $date = new DateTimeImmutable($dateText); ?><th><?= escape_html($weekDayNames[(int) $date->format('N') - 1]) ?><br><small><?= escape_html($date->format('d/m')) ?></small></th><?php endforeach; ?><th class="prediction-week-total">Total semanal<br><small><?= escape_html($week['label']) ?></small></th><?php endforeach; ?></tr></thead><tbody>
              <?php foreach ($visibleProducts as $row): ?><tr data-prediction-search="<?= escape_html(implode(' ', [$row['marca'], $row['codigo_inventario'], $row['codigo'], $row['medida'], $row['modelo']])) ?>"><td><span class="prediction-brand"><?php foreach ($row['logos'] as $brandLogo): ?><img src="<?= escape_html($brandLogo) ?>" alt="" width="96" height="36" loading="lazy" decoding="async"><?php endforeach; ?><strong><?= escape_html($row['marca']) ?></strong></span></td><td><span class="product-code"<?= $row['codigo_inventario'] === '' ? ' title="No se encontró una coincidencia única en el inventario B4X01"' : '' ?>><?= $row['codigo_inventario'] !== '' ? escape_html($row['codigo_inventario']) : 'Pendiente de asociar' ?></span></td><td><?= escape_html($row['medida']) ?></td><td><?= $row['modelo'] === '' ? '—' : escape_html($row['modelo']) ?></td><td data-units="<?= escape_html((string) $row['recent_units']) ?>"><?= number_format((float) $row['recent_units'], 0) ?></td><?php $dataIndex = 0; foreach ($forecastWeekGroups as $week): ?><?php foreach ($week['dates'] as $dateText): ?><?php $units = (int) ($row['daily_units'][$dataIndex] ?? 0); ?><td data-units="<?= $units ?>"><?= number_format($units, 0) ?></td><?php $dataIndex++; endforeach; ?><?php $weekUnits = array_sum(array_slice($row['daily_units'], $week['start_index'], count($week['dates']))); ?><td class="prediction-week-total" data-units="<?= $weekUnits ?>"><strong><?= number_format($weekUnits, 0) ?></strong></td><?php endforeach; ?></tr><?php endforeach; ?>
              <?php $columnCount = count($selectedForecastWeek['forecast_dates']) + count($forecastWeekGroups) + 5; if ($visibleProducts === []): ?><tr><td colspan="<?= $columnCount ?>">El Excel no contiene llantas con marca y medida para pronosticar.</td></tr><?php endif; ?>
              <?php if ($visibleProducts !== []): ?><tr id="prediction-tire-no-results" hidden><td colspan="<?= $columnCount ?>">No se encontraron llantas con esos datos.</td></tr><?php endif; ?>
            </tbody><tfoot><tr><th>Total visible</th><th></th><th></th><th></th><th data-total-index="4" data-units="<?= escape_html((string) $recentSalesTotal) ?>"><?= number_format($recentSalesTotal, 0) ?></th><?php $dataIndex = 5; foreach ($forecastWeekGroups as $week): ?><?php foreach ($week['dates'] as $dateOffset => $_dateText): ?><?php $units = (int) ($dailyForecastTotals[$week['start_index'] + $dateOffset] ?? 0); ?><th data-total-index="<?= $dataIndex ?>" data-units="<?= $units ?>"><?= number_format($units, 0) ?></th><?php $dataIndex++; endforeach; ?><?php $weekUnits = array_sum(array_slice($dailyForecastTotals, $week['start_index'], count($week['dates']))); ?><th data-total-index="<?= $dataIndex ?>" data-units="<?= $weekUnits ?>"><?= number_format($weekUnits, 0) ?></th><?php $dataIndex++; endforeach; ?></tr></tfoot></table></div>
          </section>
          <details class="prediction-details"><summary>¿Cómo aprende la IA?</summary><section class="prediction-model" aria-label="Entrenamiento diario y pronóstico semanal de IA"><p>Random Forest analiza las ventas diarias y el comportamiento de cada llanta durante las últimas ocho semanas. Su resultado aporta 75 % de la estimación y el promedio de ventas del mismo día aporta 25 %; si Random Forest estima cero pero ese promedio muestra ventas, se conserva el promedio para no ocultar una demanda intermitente. Después pronostica un día y agrega la estimación combinada al historial temporal para calcular el siguiente, de lunes a sábado. El domingo se fuerza a 0. Si varios modelos comparten marca y medida y el histórico no distingue sus SKU, el total se reparte entre ellos según sus ventas recientes. El pronóstico por marca suma las predicciones por llanta. Es una estimación, no una garantía de venta ni una modificación del inventario.</p></section></details>
        <?php endif; ?>
        <?php if ($currentUser['role'] === 'administrador' && !$isReviewingSalesImport): ?>
          <details class="prediction-details ai-excel-import" <?= !is_array($selectedForecastWeek) ? 'open' : '' ?>><summary>¿Tienes un Excel con ventas que aún no están en el sistema?</summary>
            <div class="ai-excel-content">
              <p>Usa esta opción solo si <strong>no registraste esas ventas en Salidas / Ventas</strong>. Los archivos nuevos del mismo mes se agregan a los ya importados para el pronóstico. Sube solo ventas nuevas: volver a incluir las mismas filas duplicará las ventas. No modifica el inventario.</p>
              <details class="prediction-details"><summary>Formatos aceptados</summary><p>Histórico detallado: Llanta (Código), Descripción, Fecha, Medida, Modelo, Almacén y Cantidad. Reporte por marca: Fecha, Hoja origen, Ref., Cliente, Marca, Medida, Modelo y Cant.; se ignora la fila TOTAL.</p><p>El reporte por marca no incluye almacén ni SKU; se guarda sin asignarlo a un almacén. Las referencias de producto generadas se marcarán para revisión.</p></details>
              <p>El sistema suma las cargas del mismo mes y usa las ventas hasta el domingo anterior para entrenar la IA. Después estima la semana completa de lunes a domingo; el domingo se muestra en 0. Al comenzar la semana siguiente, el pronóstico se actualiza automáticamente.</p>
              <form method="post" action="index.php?pagina=importar" enctype="multipart/form-data" class="ai-excel-form"><input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="stage_monthly_sales"><label class="form-field">Selecciona el Excel del mes<input type="file" name="sales_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label><button class="button button-quiet" type="submit">Revisar Excel antes de guardar</button></form>
            </div>
          </details>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <script src="scripts/avisos-voz.js?v=20261118-voice-runtime-1" defer></script>
  <script type="module" src="scripts/efecto-puntero.js"></script>
  <script type="module" src="scripts/prediccion-marcas.js?v=20261113-weekly-ai-1"></script>
</body>
</html>