#!/usr/bin/env python3
"""Emit a minimal, uncompressed, multi-page PDF with selectable Helvetica text.

Written by hand rather than pulled from a PDF library so the test fixtures have
no extra dependency. pdftotext reads these fine, which is what the document
extraction lane is being tested against.
"""
import sys
import zlib  # noqa: F401  (kept out of use deliberately: streams stay uncompressed)


def escape(text):
    return text.replace("\\", r"\\").replace("(", r"\(").replace(")", r"\)")


def build(pages, out_path):
    objects = []          # 1-indexed list of object bodies
    font_obj = 3 + len(pages) * 2  # font comes after pages + contents

    # 1: Catalog, 2: Pages tree
    kids = " ".join(f"{3 + i * 2} 0 R" for i in range(len(pages)))
    objects.append("<< /Type /Catalog /Pages 2 0 R >>")
    objects.append(
        f"<< /Type /Pages /Count {len(pages)} /Kids [{kids}] >>"
    )

    for i, lines in enumerate(pages):
        page_obj = 3 + i * 2
        content_obj = page_obj + 1

        objects.append(
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            f"/Resources << /Font << /F1 {font_obj} 0 R >> >> "
            f"/Contents {content_obj} 0 R >>"
        )

        stream = ["BT", "/F1 11 Tf", "72 720 Td", "14 TL"]
        for line in lines:
            stream.append(f"({escape(line)}) Tj")
            stream.append("T*")
        stream.append("ET")
        body = "\n".join(stream)

        objects.append(
            f"<< /Length {len(body)} >>\nstream\n{body}\nendstream"
        )

    objects.append("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")

    # Serialise with a correct xref table — pdftotext validates offsets.
    out = bytearray(b"%PDF-1.4\n")
    offsets = [0]

    for n, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += f"{n} 0 obj\n{body}\nendobj\n".encode("latin-1")

    xref_pos = len(out)
    out += f"xref\n0 {len(objects) + 1}\n".encode()
    out += b"0000000000 65535 f \n"
    for off in offsets[1:]:
        out += f"{off:010d} 00000 n \n".encode()
    out += (
        f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\n"
        f"startxref\n{xref_pos}\n%%EOF\n"
    ).encode()

    with open(out_path, "wb") as fh:
        fh.write(out)


if __name__ == "__main__":
    which = sys.argv[1]
    out = sys.argv[2]

    if which == "rfq":
        build([
            [
                "JWA MATS - REQUEST FOR QUOTATION",
                "RFQ-2026-0417  |  Rail Access Package",
                "",
                "Client: Northern Rail Infrastructure",
                "Site: Bay Junction, Sector 7",
                "",
                "1. SCOPE",
                "Supply and installation of temporary access matting for a",
                "crane pad and haul route serving overhead line renewals.",
                "",
                "2. DELIVERY WINDOW",
                "Mobilisation required by Monday 14 September 2026.",
                "Site access is restricted to weekend possessions only.",
            ],
            [
                "3. TECHNICAL BASIS",
                "",
                "The access platform shall be designed for a 70 tonne",
                "operating crane as shown in drawing Rev B.",
                "Ground bearing pressure assumed at 150 kPa.",
                "",
                "4. COMMERCIAL",
                "Pricing to be held firm for 60 days from submission.",
                "Retrieval of matting is included in the lump sum.",
            ],
        ], out)
    elif which == "drawing":
        build([
            ["JWA MATS - DRAWING REGISTER", "Rail Access Package"],
            [
                "DRAWING 4417-02  REVISION B",
                "",
                "CRANE PAD GENERAL ARRANGEMENT",
                "",
                "Operating crane assumption: 70 t",
                "Outrigger load per pad: 42 t",
                "Mat configuration: 3-layer heavy duty",
                "Ground bearing pressure: 150 kPa",
                "",
                "NOTE: This revision supersedes Rev A.",
                "Any change to crane class requires re-analysis of the",
                "mat build-up and ground bearing assumptions.",
            ],
        ], out)
    elif which == "geotech":
        build([
            [
                "GEOTECHNICAL SUMMARY - BAY JUNCTION",
                "",
                "Report ref: GEO-2026-118",
                "",
                "Trial pits indicate made ground to 0.8 m over firm",
                "cohesive material.",
                "",
                "Allowable bearing pressure: 120 kPa",
                "",
                "NOTE: The 150 kPa figure used in the RFQ technical basis",
                "is not supported by these results.",
            ],
        ], out)
    else:
        raise SystemExit(f"unknown fixture: {which}")
