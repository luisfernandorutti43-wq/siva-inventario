<?php
declare(strict_types=1);

function mostrarBarraSuperior(
  array $currentUser,
  int $alertCount,
  array $alerts,
  string $title,
  string $subtitle = '',
  string $modifierClass = ''
): void
{
    $role = (string) $currentUser['role'];
    $canOpenInventory = in_array($role, ['administrador', 'almacen'], true);
    $alertLabel = $alertCount === 1 ? '1 alerta de stock' : $alertCount . ' alertas de stock';
    ?>
    <header class="topbar<?= $modifierClass !== '' ? ' ' . escape_html($modifierClass) : '' ?>">
      <div class="topbar-heading<?= $modifierClass !== '' ? ' ' . escape_html($modifierClass) . '-title' : '' ?>">
        <h1><?= escape_html($title) ?></h1>
        <?php if ($subtitle !== ''): ?><p><?= escape_html($subtitle) ?></p><?php endif; ?>
      </div>
      <div class="topbar-actions<?= $modifierClass !== '' ? ' ' . escape_html($modifierClass) . '-actions' : '' ?>">
        <details class="notification-menu">
          <summary aria-label="<?= escape_html($alertLabel) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
              <path d="M10 21h4"></path>
            </svg>
            <?php if ($alertCount > 0): ?><span class="notification-count"><?= $alertCount > 99 ? '99+' : $alertCount ?></span><?php endif; ?>
          </summary>
          <div class="notification-panel">
            <strong><?= $alertCount > 0 ? escape_html($alertLabel) : 'Sin alertas de stock' ?></strong>
            <?php if ($alerts === []): ?>
              <p>Las existencias están por encima del mínimo.</p>
            <?php else: ?>
              <ul>
                <?php foreach ($alerts as $alert): ?>
                  <?php $criticalStock = (int) $alert['quantity'] <= (int) $alert['safety_stock']; ?>
                  <li>
                    <span>
                      <b><?= escape_html($alert['product_name']) ?></b>
                      <small><?= escape_html($alert['sku'] . ' · ' . $alert['warehouse_code']) ?></small>
                      <small><?= $criticalStock ? 'Stock crítico' : 'En el mínimo' ?> · mínimo <?= (int) $alert['minimum_stock'] ?> ud.</small>
                    </span>
                    <em<?= $criticalStock ? ' class="notification-critical"' : '' ?>><?= (int) $alert['quantity'] ?> ud.</em>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php if ($alertCount > count($alerts)): ?><small>Mostrando las <?= count($alerts) ?> alertas más urgentes de <?= $alertCount ?>. Revisa el inventario para ver el detalle.</small><?php endif; ?>
              <?php if ($canOpenInventory): ?><a href="index.php?pagina=inventario">Revisar inventario</a><?php else: ?><small>Solicita al personal de almacén que revise estas existencias.</small><?php endif; ?>
            <?php endif; ?>
          </div>
        </details>
        <div class="account-info">
          <span class="account-avatar" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $currentUser['full_name'], 0, 1))) ?></span>
          <span><strong><?= escape_html((string) $currentUser['full_name']) ?></strong><small><?= escape_html(ucfirst($role)) ?></small></span>
        </div>
      </div>
    </header>
    <?php
}
