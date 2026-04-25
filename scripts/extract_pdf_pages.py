import json
import sys
from pathlib import Path

from pypdf import PdfReader


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"error": "missing_pdf_path"}))
        return 1

    pdf_path = Path(sys.argv[1])

    if not pdf_path.exists() or not pdf_path.is_file():
        print(json.dumps({"error": "pdf_not_found", "path": str(pdf_path)}))
        return 1

    reader = PdfReader(str(pdf_path))
    pages = []

    for index, page in enumerate(reader.pages, start=1):
        text = page.extract_text() or ""
        pages.append({
            "page": index,
            "text": text,
        })

    output = json.dumps({
        "source": pdf_path.name,
        "pages": pages,
    }, ensure_ascii=False)
    sys.stdout.buffer.write(output.encode("utf-8"))
    sys.stdout.buffer.write(b"\n")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
