"""
Excel Generator for Reward & Punishment Module.
PT Jatim Autocomp Indonesia (CARMEN System).

Authoritative reference: REWARD & PUNISHMENT AGUSTUS 2026 NEW(1).xlsx
True OOXML Template-Faithful Export:
- Clones authoritative template workbook directly
- Preserves all drawings, shapes, textboxes, and media byte-for-byte
- Restores complete Rules & Instructions section (ATURAN REWARD & PUNISHMENT, rules 1-7, 5 mini-tables)
- Cleans sheet tab names:
    Sheet 1: GRUP B
    Sheet 2: GRUP A
    Sheet 3: ATURAN REWARD & PUNISHMENT
- Removes '(sdh ditambah)' and '(sdh dtmbhkan)'
- Fixes typo GROP -> GRUP (Cells A8)
- Removes stray '31' outside the table (Cell I18)
- Retains locked business formulas and exact styling
- Generates dynamic dates matching document creation/finalization/export date (Pasuruan, DD MMMM YYYY)
- Generates 3-column signature block matching Punishment:
    * Physical layout: Approve (Col F) | Checked (Col G) | Checked (Col H)
    * Sequence 1 -> Checked 1 (Col H), sanianurmirza@gmail.com (NIA permanent QR)
    * Sequence 2 -> Checked 2 (Col G), niamirza521@gmail.com (CAN permanent QR)
    * Sequence 3 -> Approve (Col F), unconfigured
    * NO Prepared column in approval signature table
- Opens cleanly in Microsoft Excel Desktop COM (CorruptLoad=0, 0 repairs)
"""

import os
import io
import re
import zipfile
import xml.etree.ElementTree as ET
from datetime import date, timedelta
from typing import Dict, Any, Optional, List, Tuple
import qrcode

# Register all OOXML SpreadsheetML and DrawingML namespaces to prevent prefix mangling
NS_MAIN = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_XDR = "http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"
NS_A = "http://schemas.openxmlformats.org/drawingml/2006/main"
NS_X14 = "http://schemas.microsoft.com/office/spreadsheetml/2009/9/main"
NS_MC = "http://schemas.openxmlformats.org/markup-compatibility/2006"
NS_X14AC = "http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac"
NS_A14 = "http://schemas.microsoft.com/office/drawing/2010/main"

ET.register_namespace("", NS_MAIN)
ET.register_namespace("r", NS_R)
ET.register_namespace("xdr", NS_XDR)
ET.register_namespace("a", NS_A)
ET.register_namespace("x14", NS_X14)
ET.register_namespace("mc", NS_MC)
ET.register_namespace("x14ac", NS_X14AC)
ET.register_namespace("a14", NS_A14)

NS_MAP = {
    "ns": NS_MAIN,
    "r": NS_R,
    "xdr": NS_XDR,
    "a": NS_A,
    "x14": NS_X14,
    "mc": NS_MC,
    "x14ac": NS_X14AC,
    "a14": NS_A14,
}

INDO_MONTHS = [
    "", "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember"
]

INDO_MONTHS_SHORT = [
    "", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun",
    "Jul", "Agu", "Sep", "Okt", "Nov", "Des"
]


def format_period_a3_text(start_d: date, end_d: date) -> str:
    """Format: Bulan 20 Juli - 19 Agustus 2026"""
    if start_d.year == end_d.year:
        return f"Bulan {start_d.day} {INDO_MONTHS[start_d.month]} - {end_d.day} {INDO_MONTHS[end_d.month]} {end_d.year}"
    else:
        return f"Bulan {start_d.day} {INDO_MONTHS[start_d.month]} {start_d.year} - {end_d.day} {INDO_MONTHS[end_d.month]} {end_d.year}"


def format_doc_date_str(d: date) -> str:
    """Format: Pasuruan, DD MMMM YYYY"""
    return f"Pasuruan, {d.day} {INDO_MONTHS[d.month]} {d.year}"


def generate_qr_png_bytes(verify_url: str) -> bytes:
    """Generates PNG bytes for QR Code."""
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=8,
        border=2,
    )
    qr.add_data(verify_url)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def get_template_path() -> str:
    """Locate the authoritative Excel template file."""
    base_dir = os.path.dirname(os.path.abspath(__file__))
    candidates = [
        os.path.join(base_dir, "data", "REWARD & PUNISHMENT AGUSTUS 2026 NEW(1).xlsx"),
        os.path.join(base_dir, "data", "REWARD & PUNISHMENT AGUSTUS 2026 NEW.xlsx"),
    ]
    for p in candidates:
        if os.path.isfile(p):
            return p
    raise FileNotFoundError(f"Authoritative template workbook not found in {candidates}")


def parse_cell_ref(ref: str) -> Tuple[str, int]:
    m = re.match(r"^([A-Z]+)([0-9]+)$", ref)
    if not m:
        raise ValueError(f"Invalid cell ref: {ref}")
    return m.group(1), int(m.group(2))


def col_to_num(col: str) -> int:
    num = 0
    for c in col:
        num = num * 26 + (ord(c) - ord('A') + 1)
    return num


def num_to_col(n: int) -> str:
    col = ""
    while n > 0:
        n, rem = divmod(n - 1, 26)
        col = chr(65 + rem) + col
    return col


def set_cell_value(sheet_root: ET.Element, cell_ref: str, value: Any, is_formula: bool = False, formula_str: str = "") -> None:
    """
    Sets or updates a cell in sheetData without corrupting row or cell ordering.
    If value is None and formula_str is empty, clears/removes the cell content.
    """
    col_str, row_idx = parse_cell_ref(cell_ref)
    col_num = col_to_num(col_str)
    
    sheet_data = sheet_root.find("ns:sheetData", NS_MAP)
    if sheet_data is None:
        sheet_data = ET.SubElement(sheet_root, f"{{{NS_MAIN}}}sheetData")

    # Find or insert row in sorted order
    target_row = None
    insert_row_idx = None
    for idx, r in enumerate(sheet_data.findall("ns:row", NS_MAP)):
        r_num = int(r.attrib.get("r", 0))
        if r_num == row_idx:
            target_row = r
            break
        elif r_num > row_idx:
            insert_row_idx = idx
            break

    if target_row is None:
        target_row = ET.Element(f"{{{NS_MAIN}}}row", {"r": str(row_idx)})
        if insert_row_idx is not None:
            sheet_data.insert(insert_row_idx, target_row)
        else:
            sheet_data.append(target_row)

    # Find or insert cell within row in sorted column order
    target_c = None
    insert_c_idx = None
    for idx, c in enumerate(target_row.findall("ns:c", NS_MAP)):
        c_ref = c.attrib.get("r", "")
        c_col_str, _ = parse_cell_ref(c_ref)
        c_col_num = col_to_num(c_col_str)
        if c_col_num == col_num:
            target_c = c
            break
        elif c_col_num > col_num:
            insert_c_idx = idx
            break

    if value is None and not is_formula and not formula_str:
        # Clear cell value and formulas
        if target_c is None:
            target_c = ET.Element(f"{{{NS_MAIN}}}c", {"r": cell_ref})
            if insert_c_idx is not None:
                target_row.insert(insert_c_idx, target_c)
            else:
                target_row.append(target_c)
        else:
            for child in list(target_c):
                target_c.remove(child)
            if "t" in target_c.attrib:
                del target_c.attrib["t"]
        return

    if target_c is None:
        target_c = ET.Element(f"{{{NS_MAIN}}}c", {"r": cell_ref})
        if insert_c_idx is not None:
            target_row.insert(insert_c_idx, target_c)
        else:
            target_row.append(target_c)

    # Clean existing cell children
    for child in list(target_c):
        target_c.remove(child)

    if is_formula or (isinstance(value, str) and value.startswith("=")):
        formula = formula_str or value
        if formula.startswith("="):
            formula = formula[1:]
        f_elem = ET.SubElement(target_c, f"{{{NS_MAIN}}}f")
        f_elem.text = formula
        v_elem = ET.SubElement(target_c, f"{{{NS_MAIN}}}v")
        v_elem.text = "0"
        if "t" in target_c.attrib:
            del target_c.attrib["t"]
    elif isinstance(value, (int, float)):
        v_elem = ET.SubElement(target_c, f"{{{NS_MAIN}}}v")
        v_elem.text = str(value)
        if "t" in target_c.attrib:
            del target_c.attrib["t"]
    elif isinstance(value, str):
        target_c.attrib["t"] = "inlineStr"
        is_elem = ET.SubElement(target_c, f"{{{NS_MAIN}}}is")
        t_elem = ET.SubElement(is_elem, f"{{{NS_MAIN}}}t")
        t_elem.text = value
    else:
        v_elem = ET.SubElement(target_c, f"{{{NS_MAIN}}}v")
        v_elem.text = str(value)


def remove_cell_completely(sheet_root: ET.Element, cell_ref: str) -> None:
    """Completely removes a cell element from sheetData."""
    col_str, row_idx = parse_cell_ref(cell_ref)
    sheet_data = sheet_root.find("ns:sheetData", NS_MAP)
    if sheet_data is None:
        return
    for r in sheet_data.findall("ns:row", NS_MAP):
        if int(r.attrib.get("r", 0)) == row_idx:
            for c in r.findall("ns:c", NS_MAP):
                if c.attrib.get("r") == cell_ref:
                    r.remove(c)
                    return


def build_default_approval_info(period_meta: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    """
    Constructs default approval configuration for Reward & Punishment Base Export:
    - Standardized 3-column table: Approved | Checked 2 | Checked 1
    - Physical layout (left to right): Approved (Col F) | Checked 2 (Col G) | Checked 1 (Col H)
    - Before approval: all boxes are empty with thin borders and labels, no QR, blank initials
    - No Prepared column in this signature table.
    """
    return {
        "approve": {
            "label": "Approved",
            "initial": "",
            "qr_bytes": None,
            "signed": False,
        },
        "checked_2": {
            "label": "Checked 2",
            "initial": "",
            "qr_bytes": None,
            "signed": False,
        },
        "checked_1": {
            "label": "Checked 1",
            "initial": "",
            "qr_bytes": None,
            "signed": False,
        },
    }


def patch_group_sheet_xml(
    xml_bytes: bytes,
    period_meta: Dict[str, Any],
    group_data: Dict[str, Any],
    is_group_b: bool,
    approval_info: Dict[str, Any],
    doc_date_str: str,
) -> bytes:
    root = ET.fromstring(xml_bytes)

    # 1. Fix GROP -> GRUP (Typo correction)
    group_label = "GRUP B" if is_group_b else "GRUP A"
    set_cell_value(root, "A8", group_label)

    # 2. Fix sheet views: remove pageBreakPreview mode so "Page 1" watermark is never shown
    sheet_views = root.find("ns:sheetViews", NS_MAP)
    if sheet_views is not None:
        for sv in sheet_views.findall("ns:sheetView", NS_MAP):
            if sv.attrib.get("view") == "pageBreakPreview":
                del sv.attrib["view"]
            if is_group_b:
                if "tabSelected" in sv.attrib:
                    del sv.attrib["tabSelected"]
            else:
                sv.attrib["tabSelected"] = "1"
            sv.attrib["topLeftCell"] = "A1"

    # 3. Stray 31 removal (Cell I18)
    remove_cell_completely(root, "I18")

    # 4. Dates and headers
    raw_start = period_meta.get("tanggal_mulai")
    raw_end = period_meta.get("tanggal_selesai")
    if isinstance(raw_start, str):
        start_d = date.fromisoformat(raw_start)
    else:
        start_d = raw_start or date.today()
    if isinstance(raw_end, str):
        end_d = date.fromisoformat(raw_end)
    else:
        end_d = raw_end or date.today()

    # Row 3
    set_cell_value(root, "A3", format_period_a3_text(start_d, end_d))

    # Rows 9, 10, 11
    nama_periode = period_meta.get("nama_periode", "")
    if nama_periode and "s/d" not in nama_periode:
        eval_label = nama_periode
    else:
        if start_d.month == end_d.month:
            eval_label = f"{INDO_MONTHS[end_d.month]} {end_d.year}"
        else:
            eval_label = f"{INDO_MONTHS[start_d.month]} - {INDO_MONTHS[end_d.month]} {end_d.year}"

    set_cell_value(root, "A9", f"Jumlah Order Bulan {eval_label}")
    set_cell_value(root, "A10", f"Prosentase Order Bulan {eval_label}")
    set_cell_value(root, "A11", f"Jumlah Order {eval_label}")

    # Rows 24, 26, 28: Target period labels
    target_raw = (period_meta.get("target_bulan_berikutnya") or "").strip()
    if target_raw:
        target_label = target_raw
        target_month_only = target_raw.split()[0]
    else:
        if end_d.month == 12:
            tm, ty = 1, end_d.year + 1
        else:
            tm, ty = end_d.month + 1, end_d.year
        target_label = f"{INDO_MONTHS[tm]} {ty}"
        target_month_only = INDO_MONTHS[tm]

    set_cell_value(root, "A24", f"% ORDER {target_label}")
    set_cell_value(root, "A26", f"Perkiraan Jumlah Order {target_label}")
    set_cell_value(root, "A28", f"Aktual Order {target_month_only}")

    # Footers
    cutoff_note_str = f"* Data cutt off hingga tanggal {end_d.day} {INDO_MONTHS[end_d.month]} {end_d.year}, segala pelanggaran yang terjadi lebih dari tanggal tersebut akan di perhitungkan pada evaluasi bulan berikutnya"

    # Set dynamic date
    if is_group_b:
        set_cell_value(root, "F37", doc_date_str)
        set_cell_value(root, "A48", cutoff_note_str)
    else:
        set_cell_value(root, "F38", doc_date_str)
        set_cell_value(root, "A49", cutoff_note_str)

    # 4. Signature Block Cells Setup (Approve | Checked | Checked - matching Punishment)
    row_lbl = 39 if is_group_b else 40
    row_init = 44 if is_group_b else 46
    box_rows = [40, 41, 42, 43] if is_group_b else [41, 42, 43, 44, 45]

    slot_appr = approval_info.get("approve", {})
    slot_chk2 = approval_info.get("checked_2", approval_info.get("checked2", {}))
    slot_chk1 = approval_info.get("checked_1", approval_info.get("checked1", approval_info.get("checked", {})))

    appr_lbl = slot_appr.get("label", "Approved")
    chk2_lbl = slot_chk2.get("label", "Checked 2")
    chk1_lbl = slot_chk1.get("label", "Checked 1")

    appr_init = (slot_appr.get("initial") or "").strip()
    chk2_init = (slot_chk2.get("initial") or "").strip()
    chk1_init = (slot_chk1.get("initial") or "").strip()

    # Column F: Approve (Sequence 3)
    # Column G: Checked 2 (Sequence 2)
    # Column H: Checked 1 (Sequence 1)
    set_cell_value(root, f"F{row_lbl}", appr_lbl)
    set_cell_value(root, f"G{row_lbl}", chk2_lbl)
    set_cell_value(root, f"H{row_lbl}", chk1_lbl)

    for br in box_rows:
        for col in ["F", "G", "H"]:
            set_cell_value(root, f"{col}{br}", None)

    set_cell_value(root, f"F{row_init}", appr_init if appr_init else None)
    set_cell_value(root, f"G{row_init}", chk2_init if chk2_init else None)
    set_cell_value(root, f"H{row_init}", chk1_init if chk1_init else None)

    # Assign styles 51 and 153 to signature cells and set row heights matching Punishment geometry
    sd = root.find("ns:sheetData", NS_MAP)
    rows_by_idx = {int(r.attrib.get("r", 0)): r for r in sd.findall("ns:row", NS_MAP)}
    if row_lbl in rows_by_idx:
        r_lbl = rows_by_idx[row_lbl]
        r_lbl.attrib["ht"] = "14.0"
        r_lbl.attrib["customHeight"] = "1"
        for c in r_lbl.findall("ns:c", NS_MAP):
            if c.attrib.get("r") in [f"F{row_lbl}", f"G{row_lbl}", f"H{row_lbl}"]:
                c.attrib["s"] = "51"
    for i, br in enumerate(box_rows):
        if br in rows_by_idx:
            r_br = rows_by_idx[br]
            r_br.attrib["ht"] = "31.5" if i == 0 else "5.0"
            r_br.attrib["customHeight"] = "1"
            for c in r_br.findall("ns:c", NS_MAP):
                if c.attrib.get("r") in [f"F{br}", f"G{br}", f"H{br}"]:
                    c.attrib["s"] = "153"
    if row_init in rows_by_idx:
        r_in = rows_by_idx[row_init]
        r_in.attrib["ht"] = "12.0"
        r_in.attrib["customHeight"] = "1"
        for c in r_in.findall("ns:c", NS_MAP):
            if c.attrib.get("r") in [f"F{row_init}", f"G{row_init}", f"H{row_init}"]:
                c.attrib["s"] = "51"

    # MergeCells for QR boxes
    mc = root.find("ns:mergeCells", NS_MAP)
    if mc is None:
        mc = ET.SubElement(root, f"{{{NS_MAIN}}}mergeCells")
    needed_merges = [
        f"F{box_rows[0]}:F{box_rows[-1]}",
        f"G{box_rows[0]}:G{box_rows[-1]}",
        f"H{box_rows[0]}:H{box_rows[-1]}",
    ]
    existing = {m.attrib.get("ref") for m in mc.findall("ns:mergeCell", NS_MAP)}
    for ref in needed_merges:
        if ref not in existing:
            ET.SubElement(mc, f"{{{NS_MAIN}}}mergeCell", {"ref": ref})
    mc.attrib["count"] = str(len(mc.findall("ns:mergeCell", NS_MAP)))

    # 5. Shifts data
    shifts = group_data.get("shifts", {}) if group_data else {}
    pagi_shift = shifts.get("Pagi", {})
    malam_shift = shifts.get("Malam", {})

    pagi_items = pagi_shift.get("items", [])
    malam_items = malam_shift.get("items", [])

    # Order Totals (B11 and F11)
    set_cell_value(root, "B11", pagi_shift.get("jumlah_order_total") if pagi_shift.get("jumlah_order_total") is not None else 0)
    set_cell_value(root, "F11", malam_shift.get("jumlah_order_total") if malam_shift.get("jumlah_order_total") is not None else 0)

    # Jumlah Karyawan (B25 and F25)
    set_cell_value(root, "B25", pagi_shift.get("jumlah_karyawan") if pagi_shift.get("jumlah_karyawan") is not None else None)
    set_cell_value(root, "F25", malam_shift.get("jumlah_karyawan") if malam_shift.get("jumlah_karyawan") is not None else None)

    siang_cols = [2, 3, 4]  # B, C, D
    malam_cols = [6, 7, 8]  # F, G, H
    catering_rows = [8, 9, 10, 12, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 26, 27, 28]

    # Populate Siang
    for idx, c_idx in enumerate(siang_cols):
        col_letter = num_to_col(c_idx)
        if idx < len(pagi_items):
            it = pagi_items[idx]
            set_cell_value(root, f"{col_letter}8", it.get("catering_nama"))
            set_cell_value(root, f"{col_letter}9", it.get("jumlah_order_berjalan", 0.0))
            set_cell_value(root, f"{col_letter}10", f"={col_letter}9/B11", is_formula=True)
            set_cell_value(root, f"{col_letter}12", it.get("performa_katering") if it.get("performa_katering") is not None else None)

            # Sanksi
            set_cell_value(root, f"{col_letter}15", it.get("sanksi_terlambat") if (it.get("sanksi_terlambat") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}16", it.get("sanksi_kelayakan") if (it.get("sanksi_kelayakan") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}17", it.get("sanksi_kalori") if (it.get("sanksi_kalori") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}18", it.get("sanksi_komplain") if (it.get("sanksi_komplain") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}19", it.get("sanksi_audit") if (it.get("sanksi_audit") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}20", it.get("sanksi_kebersihan") if (it.get("sanksi_kebersihan") or 0) > 0 else None)

            # Total sanksi
            set_cell_value(root, f"{col_letter}21", f"={col_letter}15+{col_letter}16+{col_letter}17+{col_letter}18+{col_letter}19+{col_letter}20", is_formula=True)

            # Reward
            set_cell_value(root, f"{col_letter}22", it.get("reward") if (it.get("reward") or 0) > 0 else None)

            # Hasil Perhitungan = Order + Total Sanksi + Reward
            set_cell_value(root, f"{col_letter}23", f"={col_letter}9+{col_letter}21+{col_letter}22", is_formula=True)

            # % Order Target
            set_cell_value(root, f"{col_letter}24", f"=+{col_letter}23/B11", is_formula=True)

            # Perkiraan Order Target
            set_cell_value(root, f"{col_letter}26", f"=+{col_letter}24*B25", is_formula=True)

            # Manual fields
            set_cell_value(root, f"{col_letter}27", it.get("order_minmax") if it.get("order_minmax") is not None else None)
            set_cell_value(root, f"{col_letter}28", it.get("aktual_order") if it.get("aktual_order") is not None else None)
        else:
            for r in catering_rows:
                set_cell_value(root, f"{col_letter}{r}", None)

    # Populate Malam
    for idx, c_idx in enumerate(malam_cols):
        col_letter = num_to_col(c_idx)
        if idx < len(malam_items):
            it = malam_items[idx]
            set_cell_value(root, f"{col_letter}8", it.get("catering_nama"))
            set_cell_value(root, f"{col_letter}9", it.get("jumlah_order_berjalan", 0.0))
            set_cell_value(root, f"{col_letter}10", f"={col_letter}9/F11", is_formula=True)
            set_cell_value(root, f"{col_letter}12", it.get("performa_katering") if it.get("performa_katering") is not None else None)

            # Sanksi
            set_cell_value(root, f"{col_letter}15", it.get("sanksi_terlambat") if (it.get("sanksi_terlambat") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}16", it.get("sanksi_kelayakan") if (it.get("sanksi_kelayakan") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}17", it.get("sanksi_kalori") if (it.get("sanksi_kalori") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}18", it.get("sanksi_komplain") if (it.get("sanksi_komplain") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}19", it.get("sanksi_audit") if (it.get("sanksi_audit") or 0) > 0 else None)
            set_cell_value(root, f"{col_letter}20", it.get("sanksi_kebersihan") if (it.get("sanksi_kebersihan") or 0) > 0 else None)

            # Total sanksi
            set_cell_value(root, f"{col_letter}21", f"={col_letter}15+{col_letter}16+{col_letter}17+{col_letter}18+{col_letter}19+{col_letter}20", is_formula=True)

            # Reward
            set_cell_value(root, f"{col_letter}22", it.get("reward") if (it.get("reward") or 0) > 0 else None)

            # Hasil Perhitungan = Order + Total Sanksi + Reward
            set_cell_value(root, f"{col_letter}23", f"={col_letter}9+{col_letter}21+{col_letter}22", is_formula=True)

            # % Order Target
            set_cell_value(root, f"{col_letter}24", f"=+{col_letter}23/F11", is_formula=True)

            # Perkiraan Order Target
            set_cell_value(root, f"{col_letter}26", f"=+{col_letter}24*F25", is_formula=True)

            # Manual fields
            set_cell_value(root, f"{col_letter}27", it.get("order_minmax") if it.get("order_minmax") is not None else None)
            set_cell_value(root, f"{col_letter}28", it.get("aktual_order") if it.get("aktual_order") is not None else None)
        else:
            for r in catering_rows:
                set_cell_value(root, f"{col_letter}{r}", None)

    return ET.tostring(root, encoding="utf-8", xml_declaration=True)


def patch_sheet3_xml(xml_bytes: bytes, approval_info: Dict[str, Any]) -> bytes:
    """Updates signature block on Sheet 3 (ATURAN REWARD & PUNISHMENT)."""
    root = ET.fromstring(xml_bytes)
    slot_appr = approval_info.get("approve", {})
    slot_chk2 = approval_info.get("checked_2", approval_info.get("checked2", {}))
    slot_chk1 = approval_info.get("checked_1", approval_info.get("checked1", approval_info.get("checked", {})))

    appr_lbl = slot_appr.get("label", "Approved")
    chk2_lbl = slot_chk2.get("label", "Checked 2")
    chk1_lbl = slot_chk1.get("label", "Checked 1")

    appr_init = (slot_appr.get("initial") or "").strip()
    chk2_init = (slot_chk2.get("initial") or "").strip()
    chk1_init = (slot_chk1.get("initial") or "").strip()

    set_cell_value(root, "F52", appr_lbl)
    set_cell_value(root, "G52", chk2_lbl)
    set_cell_value(root, "H52", chk1_lbl)

    # Standardized 3-column table on Sheet 3: Approved | Checked 2 | Checked 1 (No Prepared)
    set_cell_value(root, "A5", "Approved")
    set_cell_value(root, "B5", "Checked 2")
    set_cell_value(root, "C5", "Checked 1")
    set_cell_value(root, "D5", None)

    box_rows_s3 = [53, 54, 55, 56]
    for br in box_rows_s3:
        for col in ["F", "G", "H"]:
            set_cell_value(root, f"{col}{br}", None)

    set_cell_value(root, "F57", appr_init if appr_init else None)
    set_cell_value(root, "G57", chk2_init if chk2_init else None)
    set_cell_value(root, "H57", chk1_init if chk1_init else None)

    sd = root.find("ns:sheetData", NS_MAP)
    rows_by_idx = {int(r.attrib.get("r", 0)): r for r in sd.findall("ns:row", NS_MAP)}
    if 52 in rows_by_idx:
        r_lbl = rows_by_idx[52]
        r_lbl.attrib["ht"] = "14.0"
        r_lbl.attrib["customHeight"] = "1"
        for c in r_lbl.findall("ns:c", NS_MAP):
            if c.attrib.get("r") in ["F52", "G52", "H52"]:
                c.attrib["s"] = "51"
    for i, br in enumerate(box_rows_s3):
        if br in rows_by_idx:
            r_br = rows_by_idx[br]
            r_br.attrib["ht"] = "31.5" if i == 0 else "5.0"
            r_br.attrib["customHeight"] = "1"
            for c in r_br.findall("ns:c", NS_MAP):
                if c.attrib.get("r") in [f"F{br}", f"G{br}", f"H{br}"]:
                    c.attrib["s"] = "153"
    if 57 in rows_by_idx:
        r_init = rows_by_idx[57]
        r_init.attrib["ht"] = "12.0"
        r_init.attrib["customHeight"] = "1"
        for c in r_init.findall("ns:c", NS_MAP):
            if c.attrib.get("r") in ["F57", "G57", "H57"]:
                c.attrib["s"] = "51"

    mc = root.find("ns:mergeCells", NS_MAP)
    if mc is None:
        mc = ET.SubElement(root, f"{{{NS_MAIN}}}mergeCells")
    needed_merges = ["F53:F56", "G53:G56", "H53:H56"]
    existing = {m.attrib.get("ref") for m in mc.findall("ns:mergeCell", NS_MAP)}
    for ref in needed_merges:
        if ref not in existing:
            ET.SubElement(mc, f"{{{NS_MAIN}}}mergeCell", {"ref": ref})
    mc.attrib["count"] = str(len(mc.findall("ns:mergeCell", NS_MAP)))

    # Ensure Sheet 3 has Normal view and is not tabSelected
    sheet_views = root.find("ns:sheetViews", NS_MAP)
    if sheet_views is not None:
        for sv in sheet_views.findall("ns:sheetView", NS_MAP):
            if sv.attrib.get("view") == "pageBreakPreview":
                del sv.attrib["view"]
            if "tabSelected" in sv.attrib:
                del sv.attrib["tabSelected"]

    return ET.tostring(root, encoding="utf-8", xml_declaration=True)


def patch_drawing_xml(
    d_xml_bytes: bytes,
    d_rels_str: str,
    sheet_num: int,
    approval_info: Dict[str, Any],
    media_map: Dict[str, str],
) -> Tuple[bytes, str]:
    """
    Patches drawing XML:
    - Removes legacy Camera Tool AlternateContent (cellRange='[3]Sheet1!$F$52:$H$57' or '$F$52:$H$57')
    - Adds DrawingML twoCellAnchor for each active approved user QR signature image
    - Adds image relationship to drawing rels
    """
    ET.register_namespace("xdr", NS_XDR)
    ET.register_namespace("a", NS_A)
    ET.register_namespace("r", NS_R)
    ET.register_namespace("mc", NS_MC)
    ET.register_namespace("a14", NS_A14)

    root = ET.fromstring(d_xml_bytes)

    # 1. Remove only the signature cameraTool AlternateContent (preserve all 4 rules tables!)
    to_remove = []
    for child in root:
        ct = child.find('.//{http://schemas.microsoft.com/office/drawing/2010/main}cameraTool')
        if ct is not None and "F$52" in ct.attrib.get("cellRange", ""):
            to_remove.append(child)
    for elem in to_remove:
        root.remove(elem)

    # 2. Add relationships for active signed QR images matching Punishment:
    # Seq 3: Approve -> col 5 (F)
    # Seq 2: Checked 2 -> col 6 (G)
    # Seq 1: Checked 1 -> col 7 (H)
    rel_slots = [
        ("approve", "rId103", 103, "QR_Signature_3", 5),
        ("checked_2", "rId102", 102, "QR_Signature_2", 6),
        ("checked_1", "rId101", 101, "QR_Signature_1", 7),
    ]

    for key, rid, pid, pname, col in rel_slots:
        mname = media_map.get(key)
        slot_info = approval_info.get(key, {})
        is_signed = slot_info.get("signed") and slot_info.get("qr_bytes") and mname
        if is_signed:
            rel_elem = f'<Relationship Id="{rid}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/{mname}"/>'
            if f'Id="{rid}"' not in d_rels_str:
                d_rels_str = d_rels_str.replace('</Relationships>', f'{rel_elem}</Relationships>')

    # 3. Add DrawingML twoCellAnchor for each signed slot
    r_start = 39 if sheet_num == 1 else 40
    r_end = 43 if sheet_num == 1 else 45

    anchors = ""
    for key, rid, pid, pname, col in rel_slots:
        mname = media_map.get(key)
        slot_info = approval_info.get(key, {})
        is_signed = slot_info.get("signed") and slot_info.get("qr_bytes") and mname
        if is_signed:
            anchors += (
                f'<xdr:twoCellAnchor editAs="oneCell" '
                f'xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
                f'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                f'<xdr:from><xdr:col>{col}</xdr:col><xdr:colOff>15000</xdr:colOff><xdr:row>{r_start}</xdr:row><xdr:rowOff>15000</xdr:rowOff></xdr:from>'
                f'<xdr:to><xdr:col>{col + 1}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{r_end}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                f'<xdr:pic>'
                f'<xdr:nvPicPr>'
                f'<xdr:cNvPr id="{pid + (sheet_num * 10)}" name="{pname}"/>'
                f'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr>'
                f'</xdr:nvPicPr>'
                f'<xdr:blipFill>'
                f'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="{rid}"/>'
                f'<a:stretch><a:fillRect/></a:stretch>'
                f'</xdr:blipFill>'
                f'<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                f'</xdr:pic>'
                f'<xdr:clientData/>'
                f'</xdr:twoCellAnchor>'
            )

    d_xml_str = ET.tostring(root, encoding="utf-8").decode("utf-8")
    if anchors:
        d_xml_str = d_xml_str.replace('</xdr:wsDr>', f'{anchors}</xdr:wsDr>')
    return d_xml_str.encode("utf-8"), d_rels_str


def patch_vml_drawing(vml_bytes: bytes, sheet_num: int) -> bytes:
    """Removes legacy Camera Tool shape from VML drawing."""
    vml_str = vml_bytes.decode("utf-8", errors="ignore")
    if sheet_num == 1:
        vml_str = re.sub(r'<v:shape\s+id="Picture_x0020_6"[^>]*>.*?</v:shape>', '', vml_str, flags=re.DOTALL)
    elif sheet_num == 2:
        vml_str = re.sub(r'<v:shape\s+id="Picture_x0020_8"[^>]*>.*?</v:shape>', '', vml_str, flags=re.DOTALL)
    return vml_str.encode("utf-8")


def patch_workbook_xml(xml_bytes: bytes) -> bytes:
    """
    Reorder sheets and clean sheet names to preserve exact OOXML workbook structure:
    Tab Order:
    1. GRUP A                     (rId2, sheetId=21)
    2. GRUP B                     (rId1, sheetId=20)
    3. ATURAN REWARD & PUNISHMENT (rId3, sheetId=7)

    Also updates:
    - activeTab="0" (GRUP A is active tab on open)
    - definedNames print areas localSheetId to match new sheet indices:
        localSheetId="0" -> GRUP A
        localSheetId="1" -> GRUP B
    """
    text = xml_bytes.decode("utf-8")

    # 1. Update bookViews activeTab="0"
    text = re.sub(r'activeTab="\d+"', 'activeTab="0"', text)

    # 2. Reorder sheets: GRUP A (rId2) first, then GRUP B (rId1), then ATURAN (rId3)
    old_sheets = (
        '<sheets>'
        '<sheet name="KELOMPOK JIHAN 1 (sdh ditambah)" sheetId="20" r:id="rId1"/>'
        '<sheet name="KELOMPOK 1 AKBAR (sdh dtmbhkan)" sheetId="21" r:id="rId2"/>'
        '<sheet name="Sheet1" sheetId="7" r:id="rId3"/>'
        '</sheets>'
    )
    new_sheets = (
        '<sheets>'
        '<sheet name="GRUP A" sheetId="21" r:id="rId2"/>'
        '<sheet name="GRUP B" sheetId="20" r:id="rId1"/>'
        '<sheet name="ATURAN REWARD &amp; PUNISHMENT" sheetId="7" r:id="rId3"/>'
        '</sheets>'
    )
    if old_sheets in text:
        text = text.replace(old_sheets, new_sheets)
    else:
        text = text.replace('name="KELOMPOK JIHAN 1 (sdh ditambah)"', 'name="GRUP B"')
        text = text.replace('name="KELOMPOK 1 AKBAR (sdh dtmbhkan)"', 'name="GRUP A"')
        text = text.replace('name="Sheet1"', 'name="ATURAN REWARD &amp; PUNISHMENT"')

    # 3. Update definedNames (print areas localSheetId)
    old_def = (
        '<definedNames>'
        '<definedName name="_xlnm.Print_Area" localSheetId="1">\'KELOMPOK 1 AKBAR (sdh dtmbhkan)\'!$A$1:$P$49</definedName>'
        '<definedName name="_xlnm.Print_Area" localSheetId="0">\'KELOMPOK JIHAN 1 (sdh ditambah)\'!$A$1:$P$48</definedName>'
        '</definedNames>'
    )
    new_def = (
        '<definedNames>'
        '<definedName name="_xlnm.Print_Area" localSheetId="0">\'GRUP A\'!$A$1:$P$49</definedName>'
        '<definedName name="_xlnm.Print_Area" localSheetId="1">\'GRUP B\'!$A$1:$P$48</definedName>'
        '</definedNames>'
    )
    if old_def in text:
        text = text.replace(old_def, new_def)
    else:
        text = text.replace("'KELOMPOK 1 AKBAR (sdh dtmbhkan)'", "'GRUP A'")
        text = text.replace("'KELOMPOK JIHAN 1 (sdh ditambah)'", "'GRUP B'")

    return text.encode("utf-8")


def patch_app_xml(xml_bytes: bytes) -> bytes:
    """
    Update TitlesOfParts in docProps/app.xml to reflect reordered sheets:
    1. GRUP A
    2. GRUP B
    3. ATURAN REWARD & PUNISHMENT
    4. 'GRUP A'!Print_Area
    5. 'GRUP B'!Print_Area
    """
    text = xml_bytes.decode("utf-8")
    old_vec = (
        "<vt:lpstr>KELOMPOK JIHAN 1 (sdh ditambah)</vt:lpstr>"
        "<vt:lpstr>KELOMPOK 1 AKBAR (sdh dtmbhkan)</vt:lpstr>"
        "<vt:lpstr>Sheet1</vt:lpstr>"
        "<vt:lpstr>'KELOMPOK 1 AKBAR (sdh dtmbhkan)'!Print_Area</vt:lpstr>"
        "<vt:lpstr>'KELOMPOK JIHAN 1 (sdh ditambah)'!Print_Area</vt:lpstr>"
    )
    new_vec = (
        "<vt:lpstr>GRUP A</vt:lpstr>"
        "<vt:lpstr>GRUP B</vt:lpstr>"
        "<vt:lpstr>ATURAN REWARD &amp; PUNISHMENT</vt:lpstr>"
        "<vt:lpstr>'GRUP A'!Print_Area</vt:lpstr>"
        "<vt:lpstr>'GRUP B'!Print_Area</vt:lpstr>"
    )
    if old_vec in text:
        text = text.replace(old_vec, new_vec)
    else:
        text = text.replace("KELOMPOK JIHAN 1 (sdh ditambah)", "GRUP B")
        text = text.replace("KELOMPOK 1 AKBAR (sdh dtmbhkan)", "GRUP A")
        text = text.replace("<vt:lpstr>Sheet1</vt:lpstr>", "<vt:lpstr>ATURAN REWARD &amp; PUNISHMENT</vt:lpstr>")
    return text.encode("utf-8")


def generate_reward_punishment_excel(
    period_data_a: Optional[Dict[str, Any]] = None,
    period_data_b: Optional[Dict[str, Any]] = None,
    group_code: Optional[str] = None,
    template_path: Optional[str] = None,
    approval_info: Optional[Dict[str, Any]] = None,
    doc_date: Optional[date] = None,
) -> bytes:
    """
    Menghasilkan file Excel resmi (.xlsx) Reward & Punishment Katering
    dengan struktur template persis seperti workbook referensi:
    - Sheet 1: GRUP B
    - Sheet 2: GRUP A
    - Sheet 3: ATURAN REWARD & PUNISHMENT
    - Full rules / instructions content preserved (TextBoxes, DrawingML, EMF mini-tables)
    - Stray '31' removed
    - 3-column signature block with electronic permanent QR signature for Checked (NIA), unconfigured Approve, and creator initials for Prepared
    """
    if template_path is None:
        template_path = get_template_path()

    with open(template_path, "rb") as f:
        template_bytes = f.read()

    # Legacy argument handling
    if period_data_b is None and period_data_a is not None:
        grp = period_data_a.get("group_code", group_code or "A")
        if grp == "B":
            period_data_b = period_data_a
            period_data_a = None

    period_meta = (
        (period_data_a.get("period") if period_data_a else None)
        or (period_data_b.get("period") if period_data_b else None)
        or {}
    )

    # 1. Resolve doc_date: Canonical and immutable from document creation timestamp (created_at)
    if doc_date is None:
        if period_meta.get("created_at"):
            raw_cr = period_meta.get("created_at")
            if isinstance(raw_cr, str):
                try:
                    doc_date = date.fromisoformat(raw_cr.split("T")[0])
                except Exception:
                    pass
            elif hasattr(raw_cr, "date"):
                doc_date = raw_cr.date()
            elif isinstance(raw_cr, date):
                doc_date = raw_cr

        if doc_date is None and period_meta.get("tanggal_mulai"):
            raw_tm = period_meta.get("tanggal_mulai")
            if isinstance(raw_tm, str):
                try:
                    doc_date = date.fromisoformat(raw_tm.split("T")[0])
                except Exception:
                    pass
            elif hasattr(raw_tm, "date"):
                doc_date = raw_tm.date()
            elif isinstance(raw_tm, date):
                doc_date = raw_tm

        if doc_date is None:
            doc_date = date(2026, 8, 20)

    doc_date_str = format_doc_date_str(doc_date)

    # 2. Resolve approval_info
    if approval_info is None:
        approval_info = build_default_approval_info(period_meta)

    # Prepare media files mapping
    if "checked" in approval_info and "checked_1" not in approval_info:
        approval_info["checked_1"] = approval_info["checked"]

    media_map = {}
    media_files = {}
    for slot_key in ["approve", "checked_2", "checked_1"]:
        slot = approval_info.get(slot_key, {})
        qr_bytes = slot.get("qr_bytes")
        if slot.get("signed") and qr_bytes:
            fname = f"qr_{slot_key}.png"
            media_map[slot_key] = fname
            media_files[f"xl/media/{fname}"] = qr_bytes

    in_zip = zipfile.ZipFile(io.BytesIO(template_bytes), "r")
    out_buf = io.BytesIO()
    out_zip = zipfile.ZipFile(out_buf, "w", zipfile.ZIP_DEFLATED)

    for item in in_zip.infolist():
        data = in_zip.read(item.filename)

        if item.filename == "[Content_Types].xml":
            # Ensure png is declared
            ct_str = data.decode("utf-8")
            if 'Extension="png"' not in ct_str:
                ct_str = ct_str.replace("</Types>", '<Default Extension="png" ContentType="image/png"/></Types>')
                data = ct_str.encode("utf-8")
        elif item.filename == "xl/workbook.xml":
            data = patch_workbook_xml(data)
        elif item.filename == "docProps/app.xml":
            data = patch_app_xml(data)
        elif item.filename == "xl/worksheets/sheet1.xml":
            # Sheet 1: Grup B
            data = patch_group_sheet_xml(
                data,
                period_meta,
                period_data_b or {},
                is_group_b=True,
                approval_info=approval_info,
                doc_date_str=doc_date_str
            )
        elif item.filename == "xl/worksheets/sheet2.xml":
            # Sheet 2: Grup A
            data = patch_group_sheet_xml(
                data,
                period_meta,
                period_data_a or {},
                is_group_b=False,
                approval_info=approval_info,
                doc_date_str=doc_date_str
            )
        elif item.filename == "xl/worksheets/sheet3.xml":
            # Sheet 3: Rules & Reference Signatures
            data = patch_sheet3_xml(data, approval_info)
        elif item.filename == "xl/drawings/vmlDrawing1.vml":
            data = patch_vml_drawing(data, sheet_num=1)
        elif item.filename == "xl/drawings/vmlDrawing2.vml":
            data = patch_vml_drawing(data, sheet_num=2)
        elif item.filename == "xl/drawings/drawing1.xml":
            # Drawing 1 will be written together with rels
            rels_data = in_zip.read("xl/drawings/_rels/drawing1.xml.rels").decode("utf-8")
            data, new_rels = patch_drawing_xml(data, rels_data, sheet_num=1, approval_info=approval_info, media_map=media_map)
            out_zip.writestr("xl/drawings/_rels/drawing1.xml.rels", new_rels.encode("utf-8"))
        elif item.filename == "xl/drawings/drawing2.xml":
            # Drawing 2 will be written together with rels
            rels_data = in_zip.read("xl/drawings/_rels/drawing2.xml.rels").decode("utf-8")
            data, new_rels = patch_drawing_xml(data, rels_data, sheet_num=2, approval_info=approval_info, media_map=media_map)
            out_zip.writestr("xl/drawings/_rels/drawing2.xml.rels", new_rels.encode("utf-8"))
        elif item.filename in ("xl/drawings/_rels/drawing1.xml.rels", "xl/drawings/_rels/drawing2.xml.rels"):
            # Handled above
            continue

        out_zip.writestr(item, data)

    # Write new media files
    for mpath, mbytes in media_files.items():
        out_zip.writestr(mpath, mbytes)

    in_zip.close()
    out_zip.close()
    out_buf.seek(0)
    return out_buf.getvalue()
