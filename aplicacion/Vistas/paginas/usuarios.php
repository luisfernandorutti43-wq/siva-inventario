<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f4f5f8">
  <title>Usuarios | BLACK4X4 LLANTAS</title>
  <link rel="stylesheet" href="estilos/modulos.css?v=20261110-guide-light-1">
</head>
<body>
  <div class="products-app">
    <?php require_once __DIR__ . '/menu_lateral.php'; mostrarMenuLateral($currentUser, $csrfToken); ?>
    <div class="workspace">
      <?php require_once __DIR__ . '/barra_superior.php'; mostrarBarraSuperior($currentUser, $alertCount, $alerts, 'Usuarios'); ?>
      <main class="products-main users-main">
        <?php if ($successMessage !== ''): ?><p class="notice notice-success" role="status"><?= escape_html($successMessage) ?></p><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice-error" role="alert"><?= escape_html($errorMessage) ?></p><?php endif; ?>
        <section class="product-catalog users-permissions" aria-labelledby="permissions-title">
          <div class="catalog-heading"><h2 id="permissions-title">Permisos por rol</h2><span class="users-admin-count"><?= $activeAdministratorCount ?> administrador<?= $activeAdministratorCount === 1 ? '' : 'es' ?> activo<?= $activeAdministratorCount === 1 ? '' : 's' ?></span></div>
          <p class="users-explanation">Los permisos vienen definidos por rol y se verifican también en el servidor. Asigna el rol que corresponde al trabajo de cada persona.</p>
          <div class="table-wrap"><table><thead><tr><th>Rol</th><th>Panel</th><th>Productos</th><th>Compras</th><th>Ventas / Salidas</th><th>Inventario</th><th>Pronóstico</th><th>Usuarios</th></tr></thead><tbody>
            <?php foreach ($rolePermissions as $roleName => $permissions): ?>
              <tr><td><strong><?= escape_html($roles[$roleName]) ?></strong></td><?php foreach (['Panel', 'Productos', 'Compras', 'Ventas / Salidas', 'Inventario', 'Pronóstico', 'Usuarios'] as $permission): ?><td><?= in_array($permission, $permissions, true) ? '<span class="permission-yes">Permitido</span>' : '<span class="permission-no">—</span>' ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </section>
        <section class="product-catalog users-create" aria-labelledby="create-user-title">
          <div class="catalog-heading"><h2 id="create-user-title">Crear cuenta</h2></div>
          <form class="form-grid users-create-form" method="post" action="index.php?pagina=usuarios">
            <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="action" value="create_user">
            <label class="form-field">Nombre completo<input name="name" maxlength="120" value="<?= escape_html($newUserValues['name']) ?>" autocomplete="name" required></label>
            <label class="form-field">Correo electrónico<input name="email" type="email" maxlength="254" value="<?= escape_html($newUserValues['email']) ?>" autocomplete="email" required></label>
            <label class="form-field">Rol<select name="role" required><?php foreach ($roles as $roleName => $label): ?><option value="<?= escape_html($roleName) ?>" <?= $newUserValues['role'] === $roleName ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
            <label class="form-field">Contraseña inicial (mínimo 12 caracteres)<input name="password" type="password" minlength="12" autocomplete="new-password" required></label>
            <div class="users-create-submit"><button class="button button-primary" type="submit">Crear usuario</button></div>
          </form>
        </section>
        <section class="product-catalog users-list" aria-labelledby="users-list-title">
          <div class="catalog-heading"><h2 id="users-list-title">Cuentas del sistema</h2></div>
          <p class="users-explanation">Puedes modificar datos, rol y estado, o restablecer una contraseña. No se puede desactivar ni quitar el último administrador activo.</p>
          <?php if ($users === []): ?>
            <div class="catalog-empty"><strong>No hay cuentas</strong><p>Crea una cuenta para comenzar.</p></div>
          <?php else: ?>
            <label class="quick-filter"><span>Buscar una cuenta</span><input type="search" data-quick-filter="users-list-table" placeholder="Nombre, correo, rol o estado" autocomplete="off"><small data-filter-status role="status" aria-live="polite"></small></label>
            <div class="table-wrap"><table id="users-list-table" class="users-table"><thead><tr><th>Persona / correo</th><th>Rol</th><th>Estado</th><th>Creada</th><th>Guardar cambios</th><th>Restablecer contraseña</th></tr></thead><tbody>
              <?php foreach ($users as $user): ?>
                <?php $isCurrentUser = (int) $user['id'] === (int) $currentUser['id']; $isLastActiveAdmin = $user['role'] === 'administrador' && (int) $user['is_active'] === 1 && $activeAdministratorCount === 1; ?>
                <tr>
                  <td><form class="users-edit-form" id="user-form-<?= (int) $user['id'] ?>" method="post" action="index.php?pagina=usuarios">
                    <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <label class="visually-hidden" for="user-name-<?= (int) $user['id'] ?>">Nombre completo</label><input class="users-inline-input" id="user-name-<?= (int) $user['id'] ?>" name="name" maxlength="120" value="<?= escape_html($user['full_name']) ?>" required>
                    <label class="visually-hidden" for="user-email-<?= (int) $user['id'] ?>">Correo electrónico</label><input class="users-inline-input" id="user-email-<?= (int) $user['id'] ?>" name="email" type="email" maxlength="254" value="<?= escape_html($user['email']) ?>" required>
                  </form></td>
                  <td><?php if ($isCurrentUser || $isLastActiveAdmin): ?>
                    <input type="hidden" name="role" value="<?= escape_html($user['role']) ?>" form="user-form-<?= (int) $user['id'] ?>"><span class="users-locked-value" title="<?= $isCurrentUser ? 'No puedes cambiar tu propio rol.' : 'Debe quedar al menos un administrador activo.' ?>"><?= escape_html($roles[$user['role']]) ?></span>
                    <?php else: ?><label class="visually-hidden" for="user-role-<?= (int) $user['id'] ?>">Rol de <?= escape_html($user['full_name']) ?></label><select class="users-inline-select" id="user-role-<?= (int) $user['id'] ?>" name="role" form="user-form-<?= (int) $user['id'] ?>"><?php foreach ($roles as $roleName => $label): ?><option value="<?= escape_html($roleName) ?>" <?= $user['role'] === $roleName ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><?php endif; ?>
                  </td>
                  <td><?php if ($isCurrentUser || $isLastActiveAdmin): ?>
                    <input type="hidden" name="status" value="<?= (int) $user['is_active'] === 1 ? 'active' : 'inactive' ?>" form="user-form-<?= (int) $user['id'] ?>"><span class="users-locked-value" title="<?= $isCurrentUser ? 'No puedes desactivar tu propia cuenta.' : 'Debe quedar al menos un administrador activo.' ?>"><?= (int) $user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span><?php if ($isCurrentUser): ?><small class="table-secondary">Tu sesión</small><?php endif; ?>
                    <?php else: ?><label class="visually-hidden" for="user-status-<?= (int) $user['id'] ?>">Estado de <?= escape_html($user['full_name']) ?></label><select class="users-inline-select" id="user-status-<?= (int) $user['id'] ?>" name="status" form="user-form-<?= (int) $user['id'] ?>"><option value="active" <?= (int) $user['is_active'] === 1 ? 'selected' : '' ?>>Activo</option><option value="inactive" <?= (int) $user['is_active'] !== 1 ? 'selected' : '' ?>>Inactivo</option></select><?php endif; ?>
                  </td>
                  <td><?= escape_html(substr((string) $user['created_at'], 0, 10)) ?></td>
                  <td><button class="button button-quiet users-save" type="submit" form="user-form-<?= (int) $user['id'] ?>">Guardar</button></td>
                  <td><form class="users-password-form" method="post" action="index.php?pagina=usuarios"><input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><label class="visually-hidden" for="password-<?= (int) $user['id'] ?>">Nueva contraseña para <?= escape_html($user['full_name']) ?></label><input class="users-inline-input" id="password-<?= (int) $user['id'] ?>" type="password" name="password" minlength="12" autocomplete="new-password" placeholder="Mínimo 12 caracteres" required><button class="button button-quiet users-save" type="submit">Restablecer</button></form></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table></div>
          <?php endif; ?>
        </section>
      </main>
    </div>
  </div>
  <script src="scripts/filtro-facil.js?v=20261106-1630" defer></script>
</body>
</html>