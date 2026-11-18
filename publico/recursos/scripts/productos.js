// Controla el formulario modal de productos y la vista previa de imágenes.
const productDialog = document.getElementById('add-product-dialog');
const openProductDialog = document.getElementById('open-product-dialog');
const closeDialogButtons = document.querySelectorAll('[data-close-dialog]');
const productImageInput = document.getElementById('product-image');
const productImagePreview = document.getElementById('product-image-preview');
const productSkuInput = document.getElementById('product-sku');
const generateProductSkuButton = document.querySelector('[data-generate-product-sku]');
const productSkuStatus = document.querySelector('[data-product-sku-status]');
const productCatalogSearchForm = document.querySelector('.product-price-search');
let previewObjectUrl = null;

document.querySelectorAll('.product-form').forEach((form) => {
  const textFields = [...form.querySelectorAll(
    'input:not([type="hidden"]):not([type="file"]):not([type="number"]), textarea',
  )];
  const uppercaseField = (field) => {
    const originalValue = field.value;
    const uppercaseValue = originalValue.toLocaleUpperCase();
    if (originalValue === uppercaseValue) return;

    const selectionStart = field.selectionStart;
    const selectionEnd = field.selectionEnd;
    field.value = uppercaseValue;

    if (selectionStart !== null && selectionEnd !== null) {
      field.setSelectionRange(
        originalValue.slice(0, selectionStart).toLocaleUpperCase().length,
        originalValue.slice(0, selectionEnd).toLocaleUpperCase().length,
      );
    }
  };

  textFields.forEach((field) => {
    uppercaseField(field);
    field.addEventListener('input', () => uppercaseField(field));
  });
  form.addEventListener('submit', () => textFields.forEach(uppercaseField));
});

const customBrandSelect = document.querySelector('[data-custom-brand-select]');
const customBrandField = document.querySelector('[data-custom-brand-field]');
const customBrandInput = document.querySelector('[data-custom-brand-input]');

if (customBrandSelect instanceof HTMLSelectElement
    && customBrandField instanceof HTMLLabelElement
    && customBrandInput instanceof HTMLInputElement
) {
  const updateCustomBrandField = () => {
    const usesCustomBrand = customBrandSelect.value === 'Otra';
    customBrandField.hidden = !usesCustomBrand;
    customBrandInput.disabled = !usesCustomBrand;
    customBrandInput.required = usesCustomBrand;
    if (!usesCustomBrand) customBrandInput.value = '';
  };

  customBrandSelect.addEventListener('change', updateCustomBrandField);
  updateCustomBrandField();
}

if (productCatalogSearchForm) {
  const searchInput = productCatalogSearchForm.querySelector('input[name="catalog_q"]');
  const searchStatus = document.querySelector('[data-product-catalog-search-status]');
  const productRows = [...document.querySelectorAll('.product-price-table tbody tr[data-product-search]')];

  if (searchInput instanceof HTMLInputElement && searchStatus instanceof HTMLElement) {
    const filterVisibleProducts = () => {
      const query = searchInput.value.trim().toLocaleLowerCase();
      const terms = query.split(/\s+/).filter(Boolean);
      let visibleCount = 0;

      productRows.forEach((row) => {
        const searchableText = (row.dataset.productSearch ?? '').toLocaleLowerCase();
        const matches = terms.every((term) => searchableText.includes(term));
        row.hidden = !matches;
        if (matches) visibleCount++;
      });

      if (terms.length === 0) {
        searchStatus.textContent = 'Escribe para filtrar los productos de esta página o pulsa Buscar para consultar todo el catálogo.';
      } else if (visibleCount === 0) {
        searchStatus.textContent = 'No hay coincidencias en esta página. Pulsa Buscar para consultar todo el catálogo.';
      } else {
        searchStatus.textContent = `${visibleCount} coincidencias en esta página. Pulsa Buscar para consultar todo el catálogo.`;
      }
    };

    searchInput.addEventListener('input', filterVisibleProducts);
    filterVisibleProducts();
  }
}

generateProductSkuButton?.addEventListener('click', () => {
  if (!(productSkuInput instanceof HTMLInputElement)) return;
  if (!window.crypto?.getRandomValues) {
    if (productSkuStatus) productSkuStatus.textContent = 'Este navegador no puede generar un código automático.';
    return;
  }

  const randomBytes = new Uint8Array(5);
  window.crypto.getRandomValues(randomBytes);
  const uniquePart = [...randomBytes]
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()
    .slice(0, 9);
  productSkuInput.value = `B4X01${uniquePart}`;
  productSkuInput.dispatchEvent(new Event('input', { bubbles: true }));
  productSkuInput.focus();
  if (productSkuStatus) productSkuStatus.textContent = 'SKU generado. Todas las unidades de este producto usarán el mismo código.';
});

if (productDialog && openProductDialog) {
  openProductDialog.addEventListener('click', () => productDialog.showModal());

  closeDialogButtons.forEach((button) => {
    button.addEventListener('click', () => productDialog.close());
  });

  productDialog.addEventListener('click', (event) => {
    if (event.target === productDialog) productDialog.close();
  });

  if (productDialog.dataset.openOnLoad === 'true') productDialog.showModal();
}

productImageInput?.addEventListener('change', () => {
  const [file] = productImageInput.files ?? [];
  if (!file || !productImagePreview) return;

  if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
  previewObjectUrl = URL.createObjectURL(file);
  productImagePreview.src = previewObjectUrl;
  productImagePreview.hidden = false;
});

document.querySelectorAll('.product-stock-form').forEach((form) => {
  const inputs = [...form.querySelectorAll('.product-stock-fields input')];
  const total = form.querySelector('[data-stock-total]');
  if (!total) return;

  const updateTotal = () => {
    const quantities = inputs.map((input) => input.valueAsNumber);
    total.textContent = quantities.every((quantity) => Number.isSafeInteger(quantity) && quantity >= 0)
      ? String(quantities.reduce((sum, quantity) => sum + quantity, 0))
      : '—';
  };
  inputs.forEach((input) => input.addEventListener('input', updateTotal));
});

document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirmMessage ?? '¿Confirmas esta acción?')) {
      event.preventDefault();
    }
  });
});
