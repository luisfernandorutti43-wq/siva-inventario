document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.matches('[data-physical-count-form]')) return;

  const physicalInput = form.elements.namedItem('physical_quantity');
  const physicalQuantity = Number.parseInt(physicalInput?.value ?? '', 10);
  const systemQuantity = Number.parseInt(form.dataset.systemQuantity ?? '', 10);
  if (!Number.isInteger(physicalQuantity) || !Number.isInteger(systemQuantity)) return;

  const difference = physicalQuantity - systemQuantity;
  const question = difference === 0
    ? `Confirmas que contaste físicamente ${physicalQuantity} unidades y que coinciden con el saldo de SIVA?`
    : `El saldo de SIVA es ${systemQuantity} y el conteo físico es ${physicalQuantity} (diferencia ${difference > 0 ? '+' : ''}${difference}). ¿Confirmas reemplazar el saldo por la cantidad contada?`;

  if (!window.confirm(question)) {
    event.preventDefault();
  }
});

document.querySelectorAll('[data-physical-count-row]').forEach((row) => {
  const quantityInput = row.querySelector('[data-physical-quantity]');
  const differenceOutput = row.querySelector('[data-physical-count-difference]');
  const countStatus = row.querySelector('[data-physical-count-status]');
  const systemQuantity = Number(row.dataset.systemQuantity);
  if (!(quantityInput instanceof HTMLInputElement)
    || !(differenceOutput instanceof HTMLElement)
    || !Number.isInteger(systemQuantity)
  ) {
    return;
  }

  const updateDifference = () => {
    if (quantityInput.value === '') {
      differenceOutput.textContent = '—';
      differenceOutput.dataset.differenceState = 'pending';
      return;
    }

    const physicalQuantity = Number(quantityInput.value);
    if (!Number.isInteger(physicalQuantity) || physicalQuantity < 0) {
      differenceOutput.textContent = '—';
      differenceOutput.dataset.differenceState = 'pending';
      return;
    }

    const difference = physicalQuantity - systemQuantity;
    differenceOutput.textContent = `${difference > 0 ? '+' : ''}${difference}`;
    differenceOutput.dataset.differenceState = difference > 0 ? 'positive' : difference < 0 ? 'negative' : 'zero';
    differenceOutput.setAttribute(
      'aria-label',
      difference > 0 ? `${difference} unidades de más`
        : difference < 0 ? `${Math.abs(difference)} unidades de menos`
          : 'Sin diferencia'
    );
  };

  const updateCountStatus = () => {
    if (!(countStatus instanceof HTMLElement)) return;
    if (quantityInput.value === '') {
      countStatus.hidden = true;
      return;
    }

    const quantity = Number(quantityInput.value);
    if (!Number.isInteger(quantity) || quantity < 0) {
      countStatus.hidden = true;
      return;
    }

    const pending = countStatus.dataset.pending === 'true';
    countStatus.textContent = `${pending ? 'Pendiente de confirmar' : 'Último conteo confirmado'}: ${new Intl.NumberFormat('es-PE').format(quantity)} ${quantity === 1 ? 'unidad' : 'unidades'}`;
    countStatus.hidden = false;
  };

  quantityInput.addEventListener('input', () => {
    if (countStatus instanceof HTMLElement) countStatus.dataset.pending = 'true';
    updateDifference();
    updateCountStatus();
  });
  updateDifference();
  updateCountStatus();
});

const physicalCountSearchForm = document.querySelector('[data-physical-count-search-form]');
if (physicalCountSearchForm instanceof HTMLFormElement) {
  const searchInput = physicalCountSearchForm.querySelector('[data-physical-count-search-input]');
  const suggestions = physicalCountSearchForm.querySelector('[data-physical-count-search-suggestions]');
  const searchStatus = physicalCountSearchForm.querySelector('[data-physical-count-search-status]');
  const warehouseInput = physicalCountSearchForm.elements.namedItem('warehouse');

  if (searchInput instanceof HTMLInputElement
    && suggestions instanceof HTMLElement
    && searchStatus instanceof HTMLElement
    && warehouseInput instanceof HTMLInputElement
  ) {
    let debounceTimer;
    let latestSearchId = 0;
    let activeSearchController = null;

    const hideSuggestions = () => {
      suggestions.hidden = true;
      searchInput.setAttribute('aria-expanded', 'false');
    };

    const navigateToSimilarProducts = (productId) => {
      const destination = new URL(physicalCountSearchForm.action, window.location.href);
      destination.search = '';
      destination.searchParams.set('pagina', 'inventario');
      destination.searchParams.set('view', 'count');
      destination.searchParams.set('warehouse', warehouseInput.value);
      destination.searchParams.set('q', searchInput.value.trim());
      destination.searchParams.set('analizar_similares', '1');
      destination.searchParams.set('producto_similar', String(productId));
      window.location.assign(destination.toString());
    };

    const renderSuggestions = (products, searchId) => {
      if (searchId !== latestSearchId) return;
      suggestions.replaceChildren();

      if (products.length === 0) {
        hideSuggestions();
        searchStatus.textContent = 'No encontramos llantas con esa búsqueda.';
        return;
      }

      products.forEach((product) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'physical-count-search-suggestion';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');

        const name = document.createElement('strong');
        name.textContent = product.name;
        const detail = document.createElement('span');
        detail.textContent = `${product.sku} · ${product.brand} · ${product.measure} · Saldo ${product.system_quantity}`;
        option.append(name, detail);
        option.addEventListener('click', () => navigateToSimilarProducts(product.product_id));
        suggestions.append(option);
      });

      suggestions.hidden = false;
      searchInput.setAttribute('aria-expanded', 'true');
      searchStatus.textContent = `Se muestran hasta ${products.length} coincidencias. Elige la llanta que quieres revisar.`;
    };

    searchInput.addEventListener('input', () => {
      window.clearTimeout(debounceTimer);
      latestSearchId++;
      const searchId = latestSearchId;
      if (activeSearchController instanceof AbortController) {
        activeSearchController.abort();
      }

      const query = searchInput.value.trim();
      suggestions.replaceChildren();
      if (query.length < 2) {
        hideSuggestions();
        searchStatus.textContent = 'Escribe al menos 2 caracteres para ver sugerencias.';
        return;
      }

      searchStatus.textContent = 'Buscando llantas...';
      debounceTimer = window.setTimeout(async () => {
        const controller = new AbortController();
        activeSearchController = controller;
        const parameters = new URLSearchParams({
          pagina: 'inventario',
          ajax: 'physical_count_suggestions',
          warehouse: warehouseInput.value,
          q: query,
        });

        try {
          const response = await fetch(`index.php?${parameters.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
          });
          const payload = await response.json();
          if (!response.ok) {
            throw new Error(payload.error || 'No se pudieron cargar las sugerencias.');
          }
          if (!Array.isArray(payload.results)) {
            throw new Error('La respuesta de búsqueda no tiene el formato esperado.');
          }
          renderSuggestions(payload.results, searchId);
        } catch (error) {
          if (error instanceof DOMException && error.name === 'AbortError') return;
          if (searchId !== latestSearchId) return;
          hideSuggestions();
          searchStatus.textContent = 'No se pudieron cargar las sugerencias. Inténtalo nuevamente.';
          console.error('No se pudieron buscar llantas para el conteo físico:', error);
        }
      }, 250);
    });

    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        hideSuggestions();
      } else if (event.key === 'ArrowDown' && !suggestions.hidden) {
        event.preventDefault();
        suggestions.querySelector('button')?.focus();
      } else if (event.key === 'Enter' && !suggestions.hidden) {
        const firstSuggestion = suggestions.querySelector('button');
        if (firstSuggestion instanceof HTMLButtonElement) {
          event.preventDefault();
          firstSuggestion.click();
        }
      }

    });

    document.addEventListener('click', (event) => {
      if (!physicalCountSearchForm.contains(event.target)) {
        hideSuggestions();
      }
    });

    const similarityDialogIsOpen = new URLSearchParams(window.location.search)
      .get('analizar_similares') === '1';
    if (searchInput.value.trim().length >= 2 && !similarityDialogIsOpen) {
      searchInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }
}

document.querySelectorAll('[data-inventory-live-search-form]').forEach((form) => {
  const searchInput = form.querySelector('[data-inventory-live-search-input]');
  const suggestions = form.querySelector('[data-inventory-live-search-suggestions]');
  const searchStatus = form.querySelector('[data-inventory-live-search-status]');
  const viewField = form.elements.namedItem('view');
  const warehouseField = form.elements.namedItem('warehouse');

  if (!(searchInput instanceof HTMLInputElement)
    || !(suggestions instanceof HTMLElement)
    || !(searchStatus instanceof HTMLElement)
  ) {
    return;
  }

  let debounceTimer;
  let latestSearchId = 0;
  let activeSearchController = null;

  const hideSuggestions = () => {
    suggestions.hidden = true;
    searchInput.setAttribute('aria-expanded', 'false');
  };

  const getFieldValue = (field) => (
    field instanceof HTMLInputElement || field instanceof HTMLSelectElement ? field.value : ''
  );

  const selectProduct = (product) => {
    const selectedSearch = String(product.barcode || product.sku || product.name || '').trim();
    if (selectedSearch === '') return;
    searchInput.value = selectedSearch;
    form.requestSubmit();
  };

  const renderSuggestions = (products, searchId) => {
    if (searchId !== latestSearchId) return;
    suggestions.replaceChildren();

    if (products.length === 0) {
      hideSuggestions();
      searchStatus.textContent = 'No encontramos productos con esa búsqueda.';
      return;
    }

    products.forEach((product) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'physical-count-search-suggestion';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');

      const name = document.createElement('strong');
      name.textContent = String(product.name || '');
      const details = [
        product.sku || product.barcode,
        product.brand,
        product.measure,
        product.warehouse_code || (product.warehouse_count ? `${product.warehouse_count} almacenes` : ''),
        product.system_quantity === undefined ? '' : `Saldo ${product.system_quantity}`,
      ].filter((detail) => String(detail || '').trim() !== '');
      const detail = document.createElement('span');
      detail.textContent = details.join(' · ');
      option.append(name, detail);
      option.addEventListener('click', () => selectProduct(product));
      suggestions.append(option);
    });

    suggestions.hidden = false;
    searchInput.setAttribute('aria-expanded', 'true');
    searchStatus.textContent = `Se muestran hasta ${products.length} coincidencias. Elige un producto para filtrar.`;
  };

  searchInput.addEventListener('input', () => {
    window.clearTimeout(debounceTimer);
    latestSearchId++;
    const searchId = latestSearchId;
    if (activeSearchController instanceof AbortController) {
      activeSearchController.abort();
    }

    const query = searchInput.value.trim();
    suggestions.replaceChildren();
    if (query.length < 2) {
      hideSuggestions();
      searchStatus.textContent = 'Escribe al menos 2 caracteres para ver sugerencias.';
      return;
    }

    searchStatus.textContent = 'Buscando productos...';
    debounceTimer = window.setTimeout(async () => {
      const controller = new AbortController();
      activeSearchController = controller;
      const parameters = new URLSearchParams({
        pagina: 'inventario',
        ajax: 'inventory_search_suggestions',
        view: getFieldValue(viewField),
        warehouse: getFieldValue(warehouseField),
        q: query,
      });

      try {
        const response = await fetch(`index.php?${parameters.toString()}`, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
          signal: controller.signal,
        });
        const payload = await response.json();
        if (!response.ok) {
          throw new Error(payload.error || 'No se pudieron cargar las sugerencias.');
        }
        if (!Array.isArray(payload.results)) {
          throw new Error('La respuesta de búsqueda no tiene el formato esperado.');
        }
        renderSuggestions(payload.results, searchId);
      } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        if (searchId !== latestSearchId) return;
        hideSuggestions();
        searchStatus.textContent = 'No se pudieron cargar las sugerencias. Inténtalo nuevamente.';
        console.error('No se pudieron buscar productos del inventario:', error);
      }
    }, 250);
  });

  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      hideSuggestions();
    } else if (event.key === 'ArrowDown' && !suggestions.hidden) {
      event.preventDefault();
      suggestions.querySelector('button')?.focus();
    } else if (event.key === 'Enter' && !suggestions.hidden) {
      const firstSuggestion = suggestions.querySelector('button');
      if (firstSuggestion instanceof HTMLButtonElement) {
        event.preventDefault();
        firstSuggestion.click();
      }
    }
  });

  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) {
      hideSuggestions();
    }
  });

  if (searchInput.value.trim().length >= 2) {
    searchInput.dispatchEvent(new Event('input', { bubbles: true }));
  }
});

const physicalCountSimilarityDialog = document.querySelector('[data-physical-count-similarity-dialog]');
if (typeof HTMLDialogElement !== 'undefined'
  && physicalCountSimilarityDialog instanceof HTMLDialogElement
  && typeof physicalCountSimilarityDialog.showModal === 'function'
) {
  const closeButton = physicalCountSimilarityDialog.querySelector('[data-close-physical-count-similarity]');
  physicalCountSimilarityDialog.showModal();
  closeButton?.addEventListener('click', () => physicalCountSimilarityDialog.close());
  physicalCountSimilarityDialog.addEventListener('click', (event) => {
    if (event.target === physicalCountSimilarityDialog) {
      physicalCountSimilarityDialog.close();
    }
  });
}

const physicalCountScanForm = document.querySelector('[data-physical-count-scan-form]');
if (physicalCountScanForm instanceof HTMLFormElement) {
  const barcodeInput = physicalCountScanForm.elements.namedItem('barcode');
  if (barcodeInput instanceof HTMLInputElement) {
    let submitTimer;
    let submitting = false;
    barcodeInput.focus();

    physicalCountScanForm.addEventListener('submit', () => {
      submitting = true;
      window.clearTimeout(submitTimer);
    });

    barcodeInput.addEventListener('input', () => {
      window.clearTimeout(submitTimer);
      if (submitting || barcodeInput.value.trim() === '') return;

      submitTimer = window.setTimeout(() => {
        if (submitting || barcodeInput.value.trim() === '') return;
        physicalCountScanForm.requestSubmit();
      }, 300);
    });
  }
}