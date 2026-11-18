// Alterna las vistas de acceso y recuperación de contraseña.
const forgotLink = document.getElementById('forgot-link');
const loginView = document.getElementById('login-view');
const recoveryView = document.getElementById('recovery-view');
const loginEmail = document.getElementById('logemail');
const loginPassword = document.getElementById('logpass');
const passwordVisibilityToggle = document.getElementById('toggle-password-visibility');
const showPasswordIcon = passwordVisibilityToggle?.querySelector('[data-eye-show]');
const hidePasswordIcon = passwordVisibilityToggle?.querySelector('[data-eye-hide]');

passwordVisibilityToggle?.addEventListener('click', () => {
  if (!loginPassword) return;

  const isVisible = loginPassword.type === 'text';
  loginPassword.type = isVisible ? 'password' : 'text';
  passwordVisibilityToggle.setAttribute('aria-pressed', String(!isVisible));
  passwordVisibilityToggle.setAttribute(
    'aria-label',
    isVisible ? 'Mostrar contraseña' : 'Ocultar contraseña'
  );
  showPasswordIcon?.toggleAttribute('hidden', !isVisible);
  hidePasswordIcon?.toggleAttribute('hidden', isVisible);
});

forgotLink?.addEventListener('click', (event) => {
  event.preventDefault();
  if (!loginView || !recoveryView) return;
  loginView.hidden = true;
  recoveryView.hidden = false;
  document.getElementById('back-to-login')?.focus();
});

document.getElementById('back-to-login')?.addEventListener('click', () => {
  if (!loginView || !recoveryView) return;
  recoveryView.hidden = true;
  loginView.hidden = false;
  loginEmail?.focus();
});
