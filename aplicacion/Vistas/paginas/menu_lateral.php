<?php
declare(strict_types=1);

function mostrarMenuLateral(array $currentUser, string $csrfToken): void
{
  $role = (string) $currentUser['role'];
  $currentPage = (string) ($_GET['pagina'] ?? 'home') . '.php';
  $currentView = (string) ($_GET['view'] ?? '');
  if ($currentView === '' && (string) ($_GET['scan'] ?? '') === '1') {
    $currentView = 'scan';
  }
  $navigationItems = [
    ['href' => 'index.php?pagina=home', 'page' => 'panel_general.php', 'label' => 'Panel general', 'roles' => ['administrador', 'vendedor', 'almacen']],
    ['href' => 'index.php?pagina=productos', 'page' => 'productos.php', 'label' => 'Productos', 'roles' => ['administrador', 'vendedor', 'almacen']],
    ['href' => 'index.php?pagina=compras', 'page' => 'compras.php', 'label' => 'Compras', 'roles' => ['administrador', 'almacen']],
    ['href' => 'index.php?pagina=ventas', 'page' => 'ventas.php', 'label' => 'Salidas / Ventas', 'roles' => ['administrador', 'vendedor']],
    [
      'href' => 'index.php?pagina=inventario', 'page' => 'inventario.php', 'label' => 'Inventario', 'roles' => ['administrador', 'almacen'],
      'children' => [
        ['href' => 'index.php?pagina=inventario&view=alerts', 'page' => 'inventario.php', 'view' => 'alerts', 'label' => 'Alertas de faltantes'],
        ['href' => 'index.php?pagina=inventario&view=count', 'page' => 'inventario.php', 'view' => 'count', 'label' => 'Conteo físico'],
        ['href' => 'index.php?pagina=prediccion_marcas', 'page' => 'prediccion_marcas.php', 'view' => '', 'label' => 'Pronóstico de ventas'],
      ],
    ],
    [
      'href' => 'index.php?pagina=movimientos', 'page' => 'movimientos.php',
      'view' => 'movements', 'label' => 'Movimientos', 'roles' => ['administrador', 'almacen'],
    ],
    ['href' => 'index.php?pagina=prediccion_marcas', 'page' => 'prediccion_marcas.php', 'label' => 'Pronóstico de ventas', 'roles' => ['vendedor']],
  ];
?>
<aside class="sidebar">
  <a class="brand" href="index.php?pagina=home" aria-label="BLACK4X4 LLANTAS, panel general"><img src="imagenes/BLACK4X4.png" alt=""><span>BLACK4X4 LLANTAS</span></a>
  <p class="nav-label">Menú</p>
  <nav class="side-nav" aria-label="Módulos del sistema">
    <?php foreach ($navigationItems as $item): ?>
      <?php if (in_array($role, $item['roles'], true)): ?>
        <?php $children = array_values(array_filter($item['children'] ?? [], static fn (array $child): bool => in_array($role, $child['roles'] ?? $item['roles'], true))); $isCurrentPage = $currentPage === $item['page'] && $currentView === (string) ($item['view'] ?? ''); $activeChild = null; foreach ($children as $child) { if ($currentPage === $child['page'] && $currentView === (string) ($child['view'] ?? '')) { $activeChild = $child; break; } } $groupActive = $isCurrentPage || $activeChild !== null; ?>
        <div class="side-nav-group<?= $groupActive ? ' is-active' : '' ?>">
          <a href="<?= escape_html($item['href']) ?>"<?= $isCurrentPage ? ' class="active" aria-current="page"' : ($groupActive ? ' class="group-active"' : '') ?>><span class="nav-dot"></span><?= escape_html($item['label']) ?></a>
          <?php if ($groupActive): ?>
            <?php foreach ($children as $child): ?>
              <?php $childIsCurrent = $currentPage === $child['page'] && $currentView === (string) ($child['view'] ?? ''); ?>
              <a class="side-nav-child<?= $childIsCurrent ? ' active' : '' ?>" href="<?= escape_html($child['href']) ?>"<?= $childIsCurrent ? ' aria-current="page"' : '' ?>><span class="nav-dot"></span><?= escape_html($child['label']) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-secondary">
    <?php if ($role === 'administrador'): ?>
      <p class="nav-label nav-label-other">Administración</p>
      <nav class="side-nav secondary-nav" aria-label="Administración">
        <a href="index.php?pagina=usuarios"<?= $currentPage === 'usuarios.php' ? ' class="active" aria-current="page"' : '' ?>><span class="nav-dot"></span>Usuarios</a>
      </nav>
    <?php endif; ?>
    <form class="sidebar-logout" method="post" action="index.php?pagina=login"><input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button type="submit"><span class="nav-dot"></span>Cerrar sesión</button></form>
  </div>
</aside>
<?php
}