// Agrega líneas de salida y muestra el stock disponible por almacén.
const saleLines = document.getElementById('sale-lines');
const saleLineTemplate = document.getElementById('sale-line-template');
const addSaleLineButton = document.getElementById('sale-add-line');
const warehouseSelect = document.getElementById('sale-warehouse');
const scannedBarcodeInput = document.getElementById('sale-barcode-input');
const scannedBarcodeStatus = document.getElementById('sale-barcode-status');
const scannedBarcodeStatusText = document.getElementById('sale-barcode-status-text');
const scannedBarcodeFields = document.getElementById('sale-scanned-codes');
const saleScanDialog = document.querySelector('[data-sale-scan-dialog]');
const saleScanDialogTitle = saleScanDialog?.querySelector('[data-sale-scan-dialog-title]');
const saleScanDialogMessage = saleScanDialog?.querySelector('[data-sale-scan-dialog-message]');
const saleFormElement = document.querySelector('.sale-form');
let scannedBarcodeTimer;

const salesHistoryFilterForm = document.querySelector('.sales-history-filters');
const salesHistoryWarehouseFilter = salesHistoryFilterForm?.querySelector('select[name="warehouse"]');
salesHistoryWarehouseFilter?.addEventListener('change', () => {
  const fromDate = salesHistoryFilterForm.querySelector('input[name="from"]');
  const toDate = salesHistoryFilterForm.querySelector('input[name="to"]');
  if (fromDate instanceof HTMLInputElement) fromDate.value = '';
  if (toDate instanceof HTMLInputElement) toDate.value = '';
  salesHistoryFilterForm.requestSubmit();
});

function updateLineStock(line) {
  const productSelect = line.querySelector('.sale-product');
  const availableStock = line.querySelector('.available-stock');
  const linePrice = line.querySelector('.sale-line-price');
  if (!productSelect || !warehouseSelect || !availableStock || !linePrice) return;
  const selectedProduct = productSelect.selectedOptions[0];
  const selectedWarehouse = warehouseSelect.value;
  const quantityInput = line.querySelector('[name="quantities[]"]');

  if (!selectedProduct?.value) {
    if (quantityInput) quantityInput.readOnly = false;
    availableStock.textContent = 'Selecciona un producto';
    linePrice.textContent = 'Precio: — · Importe: —';
    updateSaleTotal();
    return;
  }

  if (quantityInput) {
    quantityInput.readOnly = false;
    quantityInput.min = '1';
    if (selectedProduct.dataset.category?.toLowerCase() === 'llantas') {
      const productId = selectedProduct.value;
      const unitCount = [...(scannedBarcodeFields?.querySelectorAll('.sale-scanned-barcode') ?? [])]
        .filter((field) => field.name === `scanned_barcodes[${productId}][]`).length;
      const quantity = Number.parseInt(quantityInput.value, 10);
      quantityInput.min = String(Math.max(1, unitCount));
      if (!Number.isInteger(quantity) || quantity < unitCount) {
        quantityInput.value = String(unitCount);
      }
    }
  }

  const stockAttribute = `stock${selectedWarehouse}`;
  const stock = selectedProduct.dataset[stockAttribute];
  const priceCents = Number.parseInt(selectedProduct.dataset.priceCents ?? '0', 10);
  const quantity = Number.parseInt(quantityInput?.value ?? '0', 10);
  const price = Number.isFinite(priceCents) ? priceCents : 0;
  const units = Number.isFinite(quantity) && quantity > 0 ? quantity : 0;
  availableStock.textContent = `${stock ?? 0} unidades disponibles`;
  linePrice.textContent = `Precio: $${(price / 100).toFixed(2)} · Importe: $${((price * units) / 100).toFixed(2)}`;
  updateSaleTotal();
}

function updateSaleTotal() {
  const totalOutput = document.getElementById('sale-total');
  if (!saleLines || !totalOutput) return;
  let totalCents = 0;
  saleLines.querySelectorAll('.sale-line').forEach((line) => {
    const option = line.querySelector('.sale-product')?.selectedOptions[0];
    const quantity = Number.parseInt(line.querySelector('[name="quantities[]"]')?.value ?? '0', 10);
    const price = Number.parseInt(option?.dataset.priceCents ?? '0', 10);
    if (option?.value && Number.isFinite(quantity) && quantity > 0 && Number.isFinite(price)) {
      totalCents += quantity * price;
    }
  });
  totalOutput.textContent = `Total estimado: $${(totalCents / 100).toFixed(2)}`;
}

function updateSaleLineControls() {
  if (!saleLines || !addSaleLineButton) return;
  const lineCount = saleLines.children.length;
  saleLines.querySelectorAll('.sale-remove').forEach((button) => {
    button.disabled = lineCount === 1;
  });
  addSaleLineButton.disabled = lineCount >= 30;
}

function mostrarAvisoEscaneoMovil(message, isError = false, voiceMessage = message) {
  if (!(saleScanDialog instanceof HTMLDialogElement)
      || !(saleScanDialogTitle instanceof HTMLElement)
      || !(saleScanDialogMessage instanceof HTMLElement)
      || !window.matchMedia('(max-width: 680px)').matches
  ) return;

  saleScanDialogTitle.textContent = isError ? 'No se pudo agregar' : 'Producto agregado';
  saleScanDialogMessage.textContent = message;
  saleScanDialog.classList.toggle('sale-scan-dialog-error', isError);
  saleScanDialog.dataset.voiceMessage = voiceMessage;
  saleScanDialog.dataset.voiceAutoplay = isError ? 'false' : 'true';
  if (!saleScanDialog.open) saleScanDialog.showModal();
}

if (saleScanDialog instanceof HTMLDialogElement) {
  const initialScanMessage = saleScanDialog.dataset.initialMessage?.trim();
  if (initialScanMessage) {
    const initialVoiceMessage = saleScanDialog.dataset.initialVoiceMessage?.trim()
      || 'Llevas 1 unidad escaneada.';
    mostrarAvisoEscaneoMovil(initialScanMessage, false, initialVoiceMessage);
    if (typeof window.hablarAviso === 'function') window.hablarAviso(saleScanDialog);
  }

  saleScanDialog.querySelector('[data-sale-scan-dialog-close]')?.addEventListener('click', () => {
    saleScanDialog.close();
    saleFormElement?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

if (saleLines && saleLineTemplate && addSaleLineButton) {
  addSaleLineButton.addEventListener('click', () => {
    if (saleLines.children.length >= 30) return;
    saleLines.append(saleLineTemplate.content.cloneNode(true));
    updateSaleLineControls();
    updateLineStock(saleLines.lastElementChild);
  });

  saleLines.addEventListener('change', (event) => {
    const line = event.target.closest('.sale-line');
    if (line) updateLineStock(line);
  });

  saleLines.addEventListener('input', (event) => {
    const line = event.target.closest('.sale-line');
    if (line) updateLineStock(line);
  });

  saleLines.addEventListener('click', (event) => {
    const removeButton = event.target.closest('.sale-remove');
    if (!removeButton || saleLines.children.length === 1) return;
    const line = removeButton.closest('.sale-line');
    const productId = line.querySelector('.sale-product')?.value ?? '';
    line.remove();
    if (productId !== '' && ![...saleLines.querySelectorAll('.sale-product')].some((select) => select.value === productId)) {
      scannedBarcodeFields?.querySelectorAll('.sale-scanned-barcode').forEach((field) => {
        if (field.name === `scanned_barcodes[${productId}][]`) field.remove();
      });
    }
    updateSaleLineControls();
    saleLines.querySelectorAll('.sale-line').forEach(updateLineStock);
  });

  warehouseSelect?.addEventListener('change', () => {
    saleLines.querySelectorAll('.sale-line').forEach(updateLineStock);
  });

  saleLines.querySelectorAll('.sale-line').forEach(updateLineStock);
  updateSaleLineControls();
}

function actualizarEstadoEscaneo(message, isError = false, voiceMessage = message) {
  if (!scannedBarcodeStatus || !scannedBarcodeStatusText) return;
  scannedBarcodeStatusText.textContent = message;
  scannedBarcodeStatus.classList.toggle('notice-error', isError);
  scannedBarcodeStatus.classList.toggle('notice-success', !isError);
  scannedBarcodeStatus.dataset.voiceMessage = voiceMessage;
  mostrarAvisoEscaneoMovil(message, isError, voiceMessage);
  if (isError) return;

  const voiceTarget = saleScanDialog instanceof HTMLDialogElement && saleScanDialog.open
    ? saleScanDialog
    : scannedBarcodeStatus;
  voiceTarget.dataset.voiceMessage = voiceMessage;
  if (typeof window.hablarAviso !== 'function' || !window.hablarAviso(voiceTarget)) {
    const voiceStatus = voiceTarget.querySelector('[data-voice-status]');
    const replayButton = voiceTarget.querySelector('[data-voice-replay]');
    if (voiceStatus) voiceStatus.textContent = 'La voz no está disponible en este navegador.';
    if (replayButton instanceof HTMLButtonElement) replayButton.hidden = false;
  }
}

function extraerCodigoDeEtiqueta(codigoLeido) {
  const codigo = codigoLeido.trim().replace(/^\*|\*$/g, '');
  if (!/^https?:\/\//i.test(codigo)) return codigo.toUpperCase();

  let urlEtiqueta;
  try {
    urlEtiqueta = new URL(codigo);
  } catch {
    throw new Error('El QR de la etiqueta no tiene un formato válido.');
  }

  const parametros = urlEtiqueta.searchParams;
  const almacenEtiqueta = (parametros.get('warehouse') ?? '').toUpperCase();
  if (!urlEtiqueta.pathname.endsWith('/index.php')) {
    throw new Error('El QR no corresponde a una etiqueta de producto.');
  }
  const esQrVenta = parametros.get('pagina') === 'ventas'
    && parametros.get('view') === 'new';
  const esQrConteo = parametros.get('pagina') === 'inventario'
    && parametros.get('view') === 'count'
    && parametros.get('count_scan') === '1';
  if (!esQrVenta && !esQrConteo) {
    throw new Error('El QR no corresponde a una etiqueta de producto.');
  }
  if (almacenEtiqueta !== ''
      && !['A0101', 'A0102', 'A0103'].includes(almacenEtiqueta)) {
    throw new Error('El almacén de la etiqueta no es válido.');
  }
  if (almacenEtiqueta !== ''
      && warehouseSelect instanceof HTMLSelectElement
      && warehouseSelect.value !== almacenEtiqueta) {
    const yaSeEscaneo = scannedBarcodeFields?.querySelector('.sale-scanned-barcode');
    if (yaSeEscaneo) {
      throw new Error(`Esta venta ya tiene escaneos de ${warehouseSelect.value}. Registra otra venta para usar ${almacenEtiqueta}.`);
    }
    warehouseSelect.value = almacenEtiqueta;
    warehouseSelect.dispatchEvent(new Event('change', { bubbles: true }));
  }

  const codigoQr = (
    esQrVenta ? parametros.get('scan_sku') : parametros.get('barcode')
  )?.trim().toUpperCase() ?? '';
  if (codigoQr === '' || codigoQr.length > (esQrVenta ? 40 : 80)) {
    throw new Error('El QR no contiene un SKU válido.');
  }

  return codigoQr;
}

async function procesarCodigoEscaneado() {
  if (!(scannedBarcodeInput instanceof HTMLInputElement)
      || !(scannedBarcodeFields instanceof HTMLElement)
      || !(warehouseSelect instanceof HTMLSelectElement)
  ) return;

  window.clearTimeout(scannedBarcodeTimer);
  const codeRead = scannedBarcodeInput.value.trim();
  scannedBarcodeInput.value = '';
  if (codeRead === '') return;

  scannedBarcodeInput.disabled = true;
  try {
    const code = extraerCodigoDeEtiqueta(codeRead);
    if (code === '') return;
    const url = new URL(scannedBarcodeInput.dataset.lookupUrl, window.location.href);
    url.searchParams.set('barcode_lookup', code);
    url.searchParams.set('warehouse', warehouseSelect.value);
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!response.ok) {
      throw new Error(`${result.error || 'No se pudo validar el código.'} Código leído: ${code}.`);
    }
    if (result.scan_type !== 'sku'
        && [...scannedBarcodeFields.querySelectorAll('.sale-scanned-barcode')]
          .some((field) => field.value === code)) {
      throw new Error('Ese código individual ya está agregado a esta venta.');
    }

    const productId = String(result.product_id);
    let line = [...(saleLines?.querySelectorAll('.sale-line') ?? [])]
      .find((candidate) => candidate.querySelector('.sale-product')?.value === productId);
    if (!line) {
      line = [...(saleLines?.querySelectorAll('.sale-line') ?? [])]
        .find((candidate) => candidate.querySelector('.sale-product')?.value === '');
    }
    if (!line && saleLines && saleLineTemplate && saleLines.children.length < 30) {
      saleLines.append(saleLineTemplate.content.cloneNode(true));
      line = saleLines.lastElementChild;
      updateSaleLineControls();
    }
    const productSelect = line?.querySelector('.sale-product');
    if (!line || !(productSelect instanceof HTMLSelectElement)) {
      throw new Error('La venta alcanzó el máximo de productos. Quita una línea vacía e inténtalo otra vez.');
    }
    const lineWasEmpty = productSelect.value === '';
    productSelect.value = productId;
    const productOption = productSelect.selectedOptions[0];
    if (!productOption) {
      throw new Error('El producto escaneado no aparece en la lista de Ventas.');
    }

    if (productOption.dataset.category?.toLowerCase() === 'llantas') {
      const hiddenCode = document.createElement('input');
      hiddenCode.type = 'hidden';
      hiddenCode.className = 'sale-scanned-barcode';
      hiddenCode.name = `scanned_barcodes[${productId}][]`;
      hiddenCode.value = result.barcode;
      scannedBarcodeFields.append(hiddenCode);
    } else {
      const quantityInput = line.querySelector('[name="quantities[]"]');
      if (quantityInput instanceof HTMLInputElement && !lineWasEmpty) {
        const quantity = Number.parseInt(quantityInput.value, 10);
        quantityInput.value = String(Number.isFinite(quantity) && quantity > 0 ? quantity + 1 : 1);
      }
    }

    updateLineStock(line);
    const quantity = Number.parseInt(line.querySelector('[name="quantities[]"]')?.value ?? '0', 10);
    const quantityLabel = quantity === 1 ? 'unidad' : 'unidades';
    actualizarEstadoEscaneo(
      `Agregado ${result.sku} · ${result.name}. Llevas ${quantity} ${quantityLabel} de este producto en la venta. Escanea el siguiente producto.`,
      false,
      `Llevas ${quantity} ${quantity === 1 ? 'unidad escaneada' : 'unidades escaneadas'}.`
    );
  } catch (error) {
    actualizarEstadoEscaneo(error instanceof Error ? error.message : 'No se pudo validar el código.', true);
  } finally {
    scannedBarcodeInput.disabled = false;
    scannedBarcodeInput.focus();
  }
}

scannedBarcodeInput?.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  void procesarCodigoEscaneado();
});
if (scannedBarcodeInput instanceof HTMLInputElement) {
  scannedBarcodeInput.addEventListener('input', () => {
    window.clearTimeout(scannedBarcodeTimer);
    if (scannedBarcodeInput.value.trim() === '') return;
    scannedBarcodeTimer = window.setTimeout(() => {
      void procesarCodigoEscaneado();
    }, 300);
  });
}
scannedBarcodeInput?.addEventListener('change', () => {
  if (scannedBarcodeInput.value.trim() !== '') void procesarCodigoEscaneado();
});
if (scannedBarcodeFields?.querySelector('.sale-scanned-barcode')) {
  actualizarEstadoEscaneo('Se conservaron los escaneos mientras revisas esta venta.');
}

const readPickListButton = document.getElementById('sale-read-pick-list');
const pausePickListButton = document.getElementById('sale-pause-pick-list');
const stopPickListButton = document.getElementById('sale-stop-pick-list');
const pickListStatus = document.getElementById('sale-pick-list-status');
let pickListItems = [];
let pickListIndex = 0;
let pickListActive = false;

function finishPickList(message) {
  pickListActive = false;
  window.speechSynthesis?.cancel();
  if (pausePickListButton) pausePickListButton.hidden = true;
  if (stopPickListButton) stopPickListButton.hidden = true;
  if (readPickListButton) readPickListButton.disabled = false;
  if (pickListStatus) pickListStatus.textContent = message;
}

function speakPickListItem() {
  if (!pickListActive || !('speechSynthesis' in window)) return;
  if (pickListIndex >= pickListItems.length) {
    finishPickList('Preparación terminada.');
    return;
  }

  const utterance = new SpeechSynthesisUtterance(pickListItems[pickListIndex]);
  utterance.lang = 'es-ES';
  const spanishVoice = window.speechSynthesis.getVoices()
    .find((voice) => voice.lang.toLowerCase().startsWith('es'));
  if (spanishVoice) utterance.voice = spanishVoice;
  utterance.onstart = () => {
    if (pickListStatus) {
      pickListStatus.textContent = `Leyendo producto ${pickListIndex + 1} de ${pickListItems.length}.`;
    }
  };
  utterance.onend = () => {
    pickListIndex += 1;
    speakPickListItem();
  };
  utterance.onerror = () => finishPickList('Se detuvo la lectura de la lista.');
  window.speechSynthesis.speak(utterance);
}

readPickListButton?.addEventListener('click', () => {
  if (!saleLines || !('speechSynthesis' in window)) {
    if (pickListStatus) pickListStatus.textContent = 'Este navegador no dispone de lectura de voz.';
    return;
  }

  window.speechSynthesis.cancel();
  pickListItems = [...saleLines.querySelectorAll('.sale-line')].flatMap((line) => {
    const option = line.querySelector('.sale-product')?.selectedOptions[0];
    const quantity = Number.parseInt(line.querySelector('[name="quantities[]"]')?.value ?? '0', 10);
    if (!option?.value || !Number.isInteger(quantity) || quantity < 1) return [];
    const availability = line.querySelector('.available-stock')?.textContent?.trim() ?? '';
    return [`Almacén ${warehouseSelect?.selectedOptions[0]?.textContent?.trim() ?? ''}. Preparar ${quantity} unidades de ${option.textContent?.trim() ?? 'producto'}. ${availability}.`];
  });
  if (pickListItems.length === 0) {
    if (pickListStatus) pickListStatus.textContent = 'Agrega al menos un producto con cantidad válida.';
    return;
  }

  pickListIndex = 0;
  pickListActive = true;
  readPickListButton.disabled = true;
  if (pausePickListButton) {
    pausePickListButton.hidden = false;
    pausePickListButton.textContent = 'Pausar';
  }
  if (stopPickListButton) stopPickListButton.hidden = false;
  speakPickListItem();
});

pausePickListButton?.addEventListener('click', () => {
  if (!('speechSynthesis' in window) || !pickListActive) return;
  if (window.speechSynthesis.paused) {
    window.speechSynthesis.resume();
    pausePickListButton.textContent = 'Pausar';
    if (pickListStatus) pickListStatus.textContent = 'Lectura reanudada.';
  } else {
    window.speechSynthesis.pause();
    pausePickListButton.textContent = 'Continuar';
    if (pickListStatus) pickListStatus.textContent = 'Lectura pausada.';
  }
});

stopPickListButton?.addEventListener('click', () => finishPickList('Lectura detenida.'));
window.addEventListener('pagehide', () => window.speechSynthesis?.cancel());

document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirmMessage ?? '¿Confirmas esta acción?')) {
      event.preventDefault();
    }
  });
});
