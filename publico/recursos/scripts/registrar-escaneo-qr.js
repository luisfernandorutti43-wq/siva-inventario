const form = document.querySelector('form[data-qr-auto-submit="true"]');

if (form instanceof HTMLFormElement && form.dataset.submitting !== 'true') {
  if (form.checkValidity()) {
    form.dataset.submitting = 'true';
    form.requestSubmit();
  }
}