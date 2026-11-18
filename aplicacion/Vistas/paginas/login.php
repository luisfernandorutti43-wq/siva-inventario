<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#202522">
  <title>BLACK4X4 LLANTAS | Iniciar sesión</title>
  <link rel="stylesheet" href="estilos/login.css?v=20261110-css-format-1">
</head>
<body>
  <div class="login-background" aria-hidden="true">
    <video autoplay muted loop playsinline><source src="imagenes/login1.mp4" type="video/mp4"></video>
  </div>
  <main class="page-shell">
    <section class="hero-panel">
      <p class="hero-kicker">Acceso al personal</p>
      <h1 class="hero-title">Panel de ventas e inventario de BLACK4X4 LLANTAS</h1>
      <p class="hero-copy">Acceso exclusivo para el personal de mostrador, almacén y administración. Entra con el usuario y la contraseña que te asignaron.</p>
      <a class="request-access" href="#access-help">Solicitar acceso</a>
    </section>
    <section class="login-layout" aria-label="Acceso a la cuenta">
      <div class="login-intro">
        <p class="section-kicker">Inicio de sesión</p>
        <h2 class="title">Ingresa a tu cuenta</h2>
        <p class="subtitle">Usa tu correo del trabajo y tu contraseña. Todo lo que registres queda a tu nombre.</p>
      </div>
      <div class="login-panel">
        <?php if (!$databaseReady): ?>
          <div class="system-message">No se pudo conectar con MySQL. Comprueba que MySQL esté activo, que importaste <code>base_datos/siva_inventario_esquema.sql</code> y que PHP tenga habilitado PDO MySQL.</div>
        <?php elseif ($currentUser !== null): ?>
          <p class="section-kicker">Sesión iniciada</p>
          <h2 class="title">Hola, <?= escape_html((string) $currentUser['full_name']) ?></h2>
          <p class="subtitle">Tu acceso está activo para <?= escape_html((string) $currentUser['email']) ?>.</p>
          <form method="post" action="index.php?pagina=login"><input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button class="btn recovery-submit" type="submit">Cerrar sesión</button></form>
        <?php elseif ($showSetup): ?>
          <section aria-labelledby="setup-title">
            <p class="section-kicker">Configuración inicial</p>
            <h2 class="title" id="setup-title">Crear administrador</h2>
            <p class="subtitle">Crea la primera cuenta administradora. Después, el alta inicial se cerrará.</p>
            <form method="post" action="index.php?pagina=login">
              <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="setup">
              <div class="field"><label for="full-name">Nombre completo</label><div class="input-wrap"><input class="input-field" id="full-name" name="full_name" type="text" maxlength="120" autocomplete="name" required></div></div>
              <div class="field"><label for="setup-email">Correo electrónico</label><div class="input-wrap"><input class="input-field" id="setup-email" name="email" type="email" autocomplete="email" required></div></div>
              <div class="field"><label for="setup-password">Contraseña (mínimo 12 caracteres)</label><div class="input-wrap"><input class="input-field" id="setup-password" name="password" type="password" minlength="12" autocomplete="new-password" required></div></div>
              <div class="field"><label for="password-confirmation">Confirmar contraseña</label><div class="input-wrap"><input class="input-field" id="password-confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required></div></div>
              <button class="btn" type="submit">Crear administrador</button>
            </form>
          </section>
        <?php elseif ($showLogin): ?>
          <section id="login-view">
            <form id="login-form" method="post" action="index.php?pagina=login">
              <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="login">
              <?php if ($returnTo !== null): ?><input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>"><?php endif; ?>
              <div class="field">
                <label for="logemail">Correo electrónico</label>
                <div class="input-wrap"><svg class="input-icon" viewBox="0 0 500 500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M207.8 20.73c-93.45 18.32-168.7 93.66-187 187.1c-27.64 140.9 68.65 266.2 199.1 285.1c19.01 2.888 36.17-12.26 36.17-31.49v-.6631c0-15.74-11.44-28.88-26.84-31.24c-84.35-12.98-149.2-86.13-149.2-174.2c0-102.9 88.61-185.5 193.4-175.4c91.54 8.869 158.6 91.25 158.6 183.2v16.16c0 22.09-17.94 40.05-40 40.05s-40.01-17.96-40.01-40.05v-120.1c0-8.847-7.161-16.02-16.01-16.02l-31.98.0036c-7.299 0-13.2 4.992-15.12 11.68c-24.85-12.15-54.24-16.38-86.06-5.106c-38.75 13.73-68.12 48.91-73.72 89.64c-9.483 69.01 43.81 128 110.9 128c26.44 0 50.43-9.544 69.59-24.88c24 31.3 65.23 48.69 109.4 37.49C465.2 369.3 496 324.1 495.1 277.2V256.3C495.1 107.1 361.2-9.332 207.8 20.73zM239.1 304.3c-26.47 0-48-21.56-48-48.05s21.53-48.05 48-48.05s48 21.56 48 48.05S266.5 304.3 239.1 304.3z" /></svg><input class="input-field" id="logemail" name="email" type="email" value="<?= escape_html($oldEmail) ?>" placeholder="nombre@empresa.com" autocomplete="username" required></div>
              </div>
              <div class="field">
                <label for="logpass">Contraseña</label>
                <div class="input-wrap">
                  <svg class="input-icon" viewBox="0 0 448 512" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M80 192V144C80 64.47 144.5 0 224 0C303.5 0 368 64.47 368 144V192H384C419.3 192 448 220.7 448 256V448C448 483.3 419.3 512 384 512H64C28.65 512 0 483.3 0 448V256C0 220.7 28.65 192 64 192H80zM144 192H304V144C304 99.82 268.2 64 224 64C179.8 64 144 99.82 144 144V192z" /></svg>
                  <input class="input-field" id="logpass" name="password" type="password" placeholder="Escribe tu contraseña" autocomplete="current-password" required>
                  <button class="password-visibility-toggle" id="toggle-password-visibility" type="button" aria-label="Mostrar contraseña" aria-pressed="false">
                    <svg class="password-eye-icon" data-eye-show viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>
                    <svg class="password-eye-icon" data-eye-hide viewBox="0 0 24 24" aria-hidden="true" focusable="false" hidden><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /><path d="m3 3 18 18" /></svg>
                  </button>
                </div>
              </div>
              <div class="form-meta"><a href="#" class="btn-link" id="forgot-link">¿Olvidaste tu contraseña?</a></div>
              <button class="btn" type="submit">Iniciar sesión</button>
              <p class="status" id="form-status" role="status" aria-live="polite"><?= escape_html($error) ?></p>
            </form>
          </section>
          <section id="recovery-view" hidden><p class="section-kicker">Ayuda de acceso</p><h2 class="title">¿Olvidaste tu contraseña?</h2><p class="subtitle">Pídele al administrador que la restablezca desde Configuración y Usuarios. Al entrar, podrás cambiarla por una tuya.</p><button class="back-link" id="back-to-login" type="button">Volver a iniciar sesión</button></section>
        <?php else: ?>
          <div class="system-message">No hay una cuenta administradora. Por seguridad, abre esta página desde el mismo equipo que ejecuta el servidor para crearla.</div>
        <?php endif; ?>
        <?php if ($error !== '' && !$showLogin): ?><p class="status" role="status" aria-live="polite"><?= escape_html($error) ?></p><?php endif; ?>
      </div>
    </section>
    <section class="access-help" id="access-help">
      <div class="help-intro"><p class="section-kicker">Ayuda de acceso</p><h2 class="title">¿Problemas para entrar?</h2><p class="subtitle">Las dudas que aparecen en el primer día de uso, resueltas sin tener que buscar al administrador.</p></div>
      <div class="help-list">
        <details><summary>No tengo usuario, ¿cómo lo pido?</summary><p>El administrador creará las cuentas desde Configuración y Usuarios y te asigna el rol que corresponde a tu puesto.</p></details>
        <details><summary>Olvidé mi contraseña</summary><p>Pídele al administrador que restablezca tu contraseña desde Usuarios y te entregue la nueva clave directamente.</p></details>
        <details><summary>¿Qué veo según mi rol?</summary><p>El vendedor registra ventas y consulta productos. Almacén consulta productos e inventario. El administrador tiene acceso completo y asigna los roles desde Usuarios.</p></details>
        <details><summary>¿Puedo entrar desde mi celular?</summary><p>Sí: el sistema funciona en el navegador del celular o de la tablet del mostrador, con el mismo usuario y los mismos permisos.</p></details>
      </div>
    </section>
  </main>
  <script src="scripts/login.js?v=20261109-03" defer></script>
  <script type="module" src="scripts/efecto-puntero.js"></script>
</body>
</html>