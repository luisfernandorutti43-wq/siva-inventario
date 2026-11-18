import io
import re
import sys
from urllib.parse import parse_qs, urlparse

import qrcode
from qrcode.image.svg import SvgPathImage


def main() -> int:
    if len(sys.argv) != 2:
        print("Falta el código que se convertirá en QR.", file=sys.stderr)
        return 2

    value = sys.argv[1]
    barcode_pattern = r"[\x20-\x7E]{1,80}"
    barcode_valid = re.fullmatch(barcode_pattern, value) is not None
    parsed_url = urlparse(value)
    query = parse_qs(parsed_url.query, strict_parsing=True)
    scan_barcode = query.get("barcode", [""])[0]
    scan_parameters_valid = (
        len(value) <= 250
        and parsed_url.scheme in {"http", "https"}
        and parsed_url.hostname is not None
        and parsed_url.path.endswith("/index.php")
        and parsed_url.username is None
        and parsed_url.password is None
        and not parsed_url.fragment
        and query.get("pagina") == ["inventario"]
        and query.get("warehouse", [""])[0] in {"A0101", "A0102", "A0103"}
        and re.fullmatch(barcode_pattern, scan_barcode) is not None
    )
    scan_url_valid = scan_parameters_valid and (
        query.get("scan") == ["1"]
        or (
            query.get("view") == ["count"]
            and query.get("count_scan") == ["1"]
        )
    )
    if not barcode_valid and not scan_url_valid:
        print("El código contiene caracteres no válidos.", file=sys.stderr)
        return 2

    qr = qrcode.QRCode(
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=8,
        border=4,
    )
    qr.add_data(value)
    qr.make(fit=True)
    image = qr.make_image(image_factory=SvgPathImage)
    output = io.BytesIO()
    image.save(output)
    sys.stdout.buffer.write(output.getvalue())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
