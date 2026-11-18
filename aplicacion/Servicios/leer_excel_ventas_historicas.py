import json
import sys
import unicodedata
from datetime import date, datetime
from io import BytesIO
from pathlib import Path

from openpyxl import load_workbook

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

EXPECTED_HEADERS = [
    "llantacodigo",
    "descripcion",
    "fecha",
    "medida",
    "modelo",
    "almacen",
    "cantidad",
]
REPORT_HEADERS = [
    "fecha",
    "hojaorigen",
    "ref",
    "cliente",
    "marca",
    "medida",
    "modelo",
    "cant",
]
MAX_ROWS = 50_000
MAX_SHEETS = 20


def normalize_header(value):
    text = unicodedata.normalize("NFKD", str(value or ""))
    text = text.encode("ascii", "ignore").decode("ascii")
    return "".join(character.lower() for character in text if character.isalnum())


def cell_value(value):
    if isinstance(value, datetime):
        return value.date().isoformat()
    if isinstance(value, date):
        return value.isoformat()
    return value


def read_workbook(path):
    workbook = load_workbook(BytesIO(path.read_bytes()), read_only=True, data_only=True)
    if len(workbook.worksheets) > MAX_SHEETS:
        raise ValueError(f"El archivo no puede tener más de {MAX_SHEETS} hojas.")

    visible_sheets = [
        worksheet for worksheet in workbook.worksheets
        if worksheet.sheet_state == "visible"
    ]
    standard_sheets = []
    report_sheets = []
    for worksheet in workbook.worksheets:
        if worksheet.sheet_state != "visible":
            continue
        first_row = next(
            worksheet.iter_rows(min_row=1, max_row=1, values_only=True), ()
        )
        first_headers = [normalize_header(value) for value in first_row if value is not None]
        if first_headers == EXPECTED_HEADERS:
            standard_sheets.append(worksheet)
            continue

        for header_row in range(1, min(5, worksheet.max_row) + 1):
            header = next(
                worksheet.iter_rows(
                    min_row=header_row,
                    max_row=header_row,
                    max_col=10,
                    values_only=True,
                ),
                (),
            )
            normalized = [normalize_header(value) for value in header]
            if normalized[: len(REPORT_HEADERS)] == REPORT_HEADERS:
                report_sheets.append((worksheet, header_row))
                break

    if standard_sheets and report_sheets:
        raise ValueError("El libro mezcla el formato histórico y el reporte de ventas.")

    rows = []
    if report_sheets:
        if len(report_sheets) != 1:
            raise ValueError("El reporte de ventas debe tener una sola hoja de detalle.")
        worksheet, header_row = report_sheets[0]
        if worksheet.max_row > MAX_ROWS + header_row:
            raise ValueError(f"La hoja '{worksheet.title}' supera el límite de {MAX_ROWS} filas.")
        for row_number, values in enumerate(
            worksheet.iter_rows(
                min_row=header_row + 1,
                max_col=10,
                values_only=True,
            ),
            start=header_row + 1,
        ):
            if not any(value is not None and str(value).strip() for value in values):
                continue
            if normalize_header(values[0]) in {"total", "totales"}:
                continue

            brand = str(values[4] or "").strip()
            model = str(values[6] or "").strip()
            description = " ".join(part for part in (brand, model) if part)
            rows.append(
                {
                    "sheet": worksheet.title,
                    "row": row_number,
                    "format": "brand_report",
                    "brand": brand,
                    "reference": str(values[2] or "").strip(),
                    "values": [
                        "",
                        description,
                        cell_value(values[0]),
                        str(values[5] or "").strip(),
                        model,
                        "",
                        values[7],
                    ],
                }
            )
            if len(rows) > MAX_ROWS:
                raise ValueError(f"El archivo supera el límite de {MAX_ROWS} filas.")
        workbook.close()
        if not rows:
            raise ValueError("El reporte no contiene filas de ventas con detalle.")
        return {"format": "brand_report", "rows": rows}

    if not standard_sheets or len(standard_sheets) != len(visible_sheets):
        raise ValueError(
            "No se reconoce el formato. Usa el histórico de siete columnas o el reporte "
            "con Fecha, Hoja origen, Ref., Cliente, Marca, Medida, Modelo y Cant."
        )

    for worksheet in standard_sheets:
        if worksheet.max_row > MAX_ROWS + 1:
            raise ValueError(f"La hoja '{worksheet.title}' supera el límite de {MAX_ROWS} filas.")
        for row_number, values in enumerate(
            worksheet.iter_rows(min_row=2, max_col=7, values_only=True),
            start=2,
        ):
            if not any(value is not None and str(value).strip() for value in values):
                continue
            rows.append(
                {
                    "sheet": worksheet.title,
                    "row": row_number,
                    "format": "standard",
                    "values": [cell_value(value) for value in values],
                }
            )
            if len(rows) > MAX_ROWS:
                raise ValueError(f"El archivo supera el límite de {MAX_ROWS} filas.")

    workbook.close()
    if not rows:
        raise ValueError("El archivo Excel no contiene ventas.")
    return {"format": "standard", "rows": rows}


try:
    if len(sys.argv) != 2:
        raise ValueError("No se recibió el archivo Excel que se debe leer.")
    print(json.dumps(read_workbook(Path(sys.argv[1])), ensure_ascii=False, default=str))
except Exception as error:
    print(str(error), file=sys.stderr)
    sys.exit(1)
