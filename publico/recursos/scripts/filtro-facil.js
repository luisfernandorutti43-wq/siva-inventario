function normalizeSearch(value) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase();
}

document.addEventListener('invalid', (event) => {
  const input = event.target;
  if (
    input instanceof HTMLInputElement
    && input.type === 'radio'
    && input.required
    && input.closest('.warehouse-choice')
    && input.validity.valueMissing
  ) {
    input.setCustomValidity('Selecciona una de estas opciones.');
  }
}, true);

document.querySelectorAll('[data-quick-filter]').forEach((input) => {
  const table = document.getElementById(input.dataset.quickFilter);
  const body = table?.tBodies[0];
  const status = input.closest('.quick-filter')?.querySelector('[data-filter-status]');
  if (!body) return;

  const filterRows = () => {
    const query = normalizeSearch(input.value.trim());
    let visibleRows = 0;
    body.querySelectorAll('tr').forEach((row) => {
      const editableValues = [...row.querySelectorAll('input:not([type="hidden"]), select')]
        .map((field) => field instanceof HTMLSelectElement
          ? field.selectedOptions[0]?.textContent ?? ''
          : field.value);
      const searchableText = `${row.textContent} ${editableValues.join(' ')}`;
      const matches = query === '' || normalizeSearch(searchableText).includes(query);
      row.hidden = !matches;
      if (matches) visibleRows += 1;
    });
    if (status) {
      status.textContent = query === ''
        ? `${visibleRows} registros`
        : `${visibleRows} resultado${visibleRows === 1 ? '' : 's'}`;
    }
  };

  input.addEventListener('input', filterRows);
  filterRows();
});

document.addEventListener('input', (event) => {
  const input = event.target.closest('[data-filter-product-options]');
  const select = input?.closest('.form-field')?.querySelector('select');
  if (!input || !select) return;
  const query = normalizeSearch(input.value.trim());
  [...select.options].forEach((option) => {
    if (option.value !== '') {
      option.hidden = query !== '' && !normalizeSearch(option.textContent).includes(query);
    }
  });
});

document.addEventListener('change', (event) => {
  const radio = event.target;
  if (radio instanceof HTMLInputElement && radio.type === 'radio' && radio.name !== '') {
    radio.form?.querySelectorAll(`input[type="radio"][name="${CSS.escape(radio.name)}"]`)
      .forEach((groupInput) => groupInput.setCustomValidity(''));
  }

  const select = event.target.closest('.form-field')?.querySelector('[data-filter-product-options] + select');
  if (select) {
    const input = select.parentElement.querySelector('[data-filter-product-options]');
    input.value = '';
    [...select.options].forEach((option) => {
      option.hidden = false;
    });
  }

  const confirmation = event.target.closest('[data-required-message]');
  if (confirmation) {
    confirmation.setCustomValidity(confirmation.checked ? '' : confirmation.dataset.requiredMessage);
  }
});

document.querySelectorAll('[data-required-message]').forEach((input) => {
  input.setCustomValidity(input.checked ? '' : input.dataset.requiredMessage);
});
