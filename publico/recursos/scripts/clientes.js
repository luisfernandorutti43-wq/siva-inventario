// Controla el diálogo para crear y editar clientes.
const customerDialog = document.getElementById('customer-dialog');
const openCustomerDialog = document.getElementById('open-customer-dialog');
const customerForm = customerDialog?.querySelector('form');
const closeDialogButtons = customerDialog?.querySelectorAll('[data-close-dialog]') ?? [];
const editCustomerButtons = document.querySelectorAll('[data-edit-customer]');
const customerAction = customerForm?.elements.namedItem('action');
const customerId = customerForm?.elements.namedItem('customer_id');
const customerTitle = document.getElementById('customer-dialog-title');

function showNewCustomerForm() {
  if (!customerDialog || !customerForm) return;
  customerForm.reset();
  customerAction.value = 'create_customer';
  customerId.value = '';
  customerTitle.textContent = 'Registrar cliente';
  customerDialog.showModal();
}

openCustomerDialog?.addEventListener('click', showNewCustomerForm);

editCustomerButtons.forEach((button) => {
  button.addEventListener('click', () => {
    if (!customerDialog || !customerForm) return;
    customerForm.reset();
    customerAction.value = 'save_customer';
    customerId.value = button.dataset.customerId;
    customerForm.elements.namedItem('document_type').value = button.dataset.documentType;
    customerForm.elements.namedItem('document_number').value = button.dataset.documentNumber;
    customerForm.elements.namedItem('display_name').value = button.dataset.displayName;
    customerForm.elements.namedItem('email').value = button.dataset.email;
    customerForm.elements.namedItem('phone').value = button.dataset.phone;
    customerTitle.textContent = 'Editar cliente';
    customerDialog.showModal();
  });
});

closeDialogButtons.forEach((button) => {
  button.addEventListener('click', () => customerDialog?.close());
});

customerDialog?.addEventListener('click', (event) => {
  if (event.target === customerDialog) customerDialog.close();
});

if (customerDialog?.dataset.openOnLoad === 'true') customerDialog.showModal();

document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirmMessage)) event.preventDefault();
  });
});
