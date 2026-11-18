const forecastTabs = Array.from(document.querySelectorAll("[role='tab'][aria-controls]"));

forecastTabs.forEach((tab) => {
    tab.addEventListener("click", () => {
        forecastTabs.forEach((candidate) => {
            const selected = candidate === tab;
            const panel = document.getElementById(candidate.getAttribute("aria-controls") ?? "");
            candidate.setAttribute("aria-selected", String(selected));
            candidate.classList.toggle("button-primary", selected);
            candidate.classList.toggle("button-quiet", !selected);
            candidate.tabIndex = selected ? 0 : -1;
            if (panel instanceof HTMLElement) {
                panel.hidden = !selected;
            }
        });
    });

    tab.addEventListener("keydown", (event) => {
        if (!(event instanceof KeyboardEvent) || !["ArrowLeft", "ArrowRight"].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const direction = event.key === "ArrowRight" ? 1 : -1;
        const currentIndex = forecastTabs.indexOf(tab);
        const nextIndex = (currentIndex + direction + forecastTabs.length) % forecastTabs.length;
        forecastTabs[nextIndex]?.focus();
        forecastTabs[nextIndex]?.click();
    });
});

const numberFormat = new Intl.NumberFormat("es-PE", { maximumFractionDigits: 0 });
const normalize = (value) => value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es");

[
    {
        input: "#prediction-brand-search",
        table: "#prediction-brand-table",
        count: "#prediction-brand-count",
        emptyRow: "#prediction-brand-no-results",
        itemName: "marcas",
    },
    {
        input: "#prediction-tire-search",
        table: "#prediction-tire-table",
        count: "#prediction-tire-count",
        emptyRow: "#prediction-tire-no-results",
        itemName: "llantas",
    },
].forEach((config) => {
    const searchInput = document.querySelector(config.input);
    const forecastTable = document.querySelector(config.table);
    const itemCount = document.querySelector(config.count);
    const noResultsRow = document.querySelector(config.emptyRow);

    if (!(searchInput instanceof HTMLInputElement)
        || !(forecastTable instanceof HTMLTableElement)
        || !(itemCount instanceof HTMLParagraphElement)
        || !(noResultsRow instanceof HTMLTableRowElement)) {
        return;
    }

    const rows = Array.from(forecastTable.querySelectorAll("tbody tr[data-prediction-search]"));
    const totalCells = Array.from(forecastTable.querySelectorAll("tfoot [data-total-index]"));
    const totalItems = Number(itemCount.dataset.totalItems);

    searchInput.addEventListener("input", () => {
        const query = normalize(searchInput.value.trim());
        const visibleRows = rows.filter((row) => {
            const matches = normalize(row.dataset.predictionSearch ?? "").includes(query);
            row.hidden = !matches;
            return matches;
        });

        noResultsRow.hidden = visibleRows.length !== 0;
        itemCount.textContent = query === ""
            ? `Mostrando ${numberFormat.format(totalItems)} ${config.itemName}.`
            : `Mostrando ${numberFormat.format(visibleRows.length)} de ${numberFormat.format(totalItems)} ${config.itemName}.`;

        if (query === "") {
            totalCells.forEach((cell) => {
                cell.textContent = numberFormat.format(Number(cell.dataset.units));
            });
            return;
        }

        const totals = new Array(rows[0]?.cells.length ?? 0).fill(0);
        visibleRows.forEach((row) => {
            Array.from(row.cells).forEach((cell, index) => {
                if (cell.hasAttribute("data-units")) {
                    totals[index] += Number(cell.dataset.units);
                }
            });
        });

        totalCells.forEach((cell) => {
            const index = Number(cell.dataset.totalIndex);
            cell.textContent = numberFormat.format(totals[index] ?? 0);
        });
    });
});
