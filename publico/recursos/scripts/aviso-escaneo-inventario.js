const scanToast = document.querySelector('.inventory-scan-toast');

if (scanToast instanceof HTMLElement) {
  const closeButton = scanToast.querySelector('.inventory-scan-toast-close');
  let dismissTimer;

  const dismissToast = () => {
    window.clearTimeout(dismissTimer);
    scanToast.remove();
  };

  if (closeButton instanceof HTMLButtonElement) {
    closeButton.addEventListener('click', dismissToast);
  }

  if (scanToast.dataset.autoDismiss === 'true') {
    dismissTimer = window.setTimeout(dismissToast, 6500);
  }
}

const physicalCountScanDialog = document.querySelector('[data-physical-count-scan-dialog]');
if (physicalCountScanDialog instanceof HTMLDialogElement) {
  const closeButton = physicalCountScanDialog.querySelector('[data-physical-count-scan-close]');
  if (window.matchMedia('(max-width: 680px)').matches && !physicalCountScanDialog.open) {
    physicalCountScanDialog.showModal();
  }
  if (closeButton instanceof HTMLButtonElement) {
    closeButton.addEventListener('click', () => physicalCountScanDialog.close());
  }
}
