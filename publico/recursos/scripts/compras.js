const lines = document.querySelector("#purchase-lines");
const template = document.querySelector("#purchase-line-template");
const addButton = document.querySelector("#add-purchase-line");
const purchaseForm = document.querySelector(".sale-form");

if (purchaseForm instanceof HTMLFormElement) {
  purchaseForm.addEventListener("invalid", (event) => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) return;

    let message = "";
    if (field.validity.valueMissing) {
      message = "Completa este campo.";
    } else if (field.validity.rangeUnderflow) {
      message = "Ingresa un valor mayor que cero.";
    } else if (field.validity.rangeOverflow) {
      message = "El valor supera el límite permitido.";
    } else if (field.validity.stepMismatch || field.validity.badInput) {
      message = "Ingresa un valor válido.";
    }

    if (message !== "") {
      field.setCustomValidity(message);
    }
  }, true);

  purchaseForm.addEventListener("input", (event) => {
    const field = event.target;
    if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
      field.setCustomValidity("");
    }
  });

  purchaseForm.addEventListener("change", (event) => {
    const field = event.target;
    if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
      field.setCustomValidity("");
    }
  });
}

if (lines instanceof HTMLElement && template instanceof HTMLTemplateElement && addButton instanceof HTMLButtonElement) {
  const updateAddButton = () => {
    addButton.disabled = lines.children.length >= 30;
  };

  addButton.addEventListener("click", () => {
    if (lines.children.length < 30) {
      lines.append(template.content.cloneNode(true));
      updateAddButton();
    }
  });

  lines.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLButtonElement) || !target.classList.contains("purchase-remove")) {
      return;
    }

    if (lines.children.length === 1) {
      const row = target.closest(".purchase-line");
      row?.querySelectorAll("select, input").forEach((field) => {
        if (field instanceof HTMLInputElement) {
          field.value = field.name === "quantities[]" ? "1" : "";
        } else if (field instanceof HTMLSelectElement) {
          field.selectedIndex = 0;
        }
      });
    } else {
      target.closest(".purchase-line")?.remove();
    }
    updateAddButton();
  });

  updateAddButton();
}
