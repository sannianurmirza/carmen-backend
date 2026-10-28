import os
import io
import re
import copy
import zipfile
import xml.etree.ElementTree as ET
from datetime import date
from typing import Dict, Any, List, Optional, Union

# Authoritative original template workbook with intact Camera Dashboard & Media
TEMPLATE_PATH = os.path.join(
    os.path.dirname(__file__), "data", "KETERANGAN PUNISHMENTT AGUSTUS 2026.xlsx"
)

BULAN_INDONESIA = {
    1: "JANUARI", 2: "FEBRUARI", 3: "MARET", 4: "APRIL",
    5: "MEI", 6: "JUNI", 7: "JULI", 8: "AGUSTUS",
    9: "SEPTEMBER", 10: "OKTOBER", 11: "NOVEMBER", 12: "DESEMBER"
}

BULAN_INDONESIA_TITLECASE = {
    1: "Januari", 2: "Februari", 3: "Maret", 4: "April",
    5: "Mei", 6: "Juni", 7: "Juli", 8: "Agustus",
    9: "September", 10: "Oktober", 11: "November", 12: "Desember"
}

NS_MAIN = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_MAP = {
    "ns": NS_MAIN,
    "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
}
ET.register_namespace("", NS_MAIN)
ET.register_namespace("r", "http://schemas.openxmlformats.org/officeDocument/2006/relationships")


def format_periode_authoritative(start_date_str: str, end_date_str: str) -> str:
    """
    Format periode sesuai gaya asli:
    Contoh: '20 JULI 19 AGUSTUS 2026' atau cross-year '20 DESEMBER 2025 19 JANUARI 2026'
    Tanpa tanda hubung (-).
    """
    try:
        d_start = date.fromisoformat(start_date_str)
        d_end = date.fromisoformat(end_date_str)
        s_day = d_start.day
        s_mon = BULAN_INDONESIA.get(d_start.month, str(d_start.month))
        e_day = d_end.day
        e_mon = BULAN_INDONESIA.get(d_end.month, str(d_end.month))
        e_year = d_end.year
        if d_start.year != d_end.year:
            return f"{s_day} {s_mon} {d_start.year} {e_day} {e_mon} {e_year}"
        if d_start.month == d_end.month:
            return f"{s_day} {s_mon} {e_day} {e_mon} {e_year}"
        return f"{s_day} {s_mon} {e_day} {e_mon} {e_year}"
    except Exception:
        return f"{start_date_str} {end_date_str}"


class SharedStringsTable:
    """
    Mengelola xl/sharedStrings.xml secara non-destruktif:
    - Melestarikan seluruh entri si yang ada dan index-nya.
    - Menambahkan string baru di akhir jika belum ada.
    - Memperbarui count dan uniqueCount dengan aman.
    """
    def __init__(self, xml_bytes: bytes):
        self.root = ET.fromstring(xml_bytes)
        self.strings: List[str] = []
        self.lookup: Dict[str, int] = {}
        for idx, si in enumerate(self.root.findall("ns:si", NS_MAP)):
            t_elems = si.findall(".//ns:t", NS_MAP)
            text = "".join([t.text or "" for t in t_elems])
            self.strings.append(text)
            if text not in self.lookup:
                self.lookup[text] = idx

    def get_or_add(self, text: str) -> int:
        if text in self.lookup:
            return self.lookup[text]
        idx = len(self.strings)
        self.strings.append(text)
        self.lookup[text] = idx

        si = ET.SubElement(self.root, f"{{{NS_MAIN}}}si")
        t = ET.SubElement(si, f"{{{NS_MAIN}}}t")
        t.text = text

        count = int(self.root.attrib.get("count", str(len(self.strings)))) + 1
        self.root.attrib["count"] = str(count)
        self.root.attrib["uniqueCount"] = str(len(self.strings))
        return idx

    def to_xml_bytes(self) -> bytes:
        return ET.tostring(self.root, encoding="utf-8", xml_declaration=True)


class WorksheetXmlPatcher:
    """
    Melakukan selective in-place patching pada worksheet XML:
    - Melestarikan atribut sel (style index 's', format, dll.)
    - Memperbarui <v>, <f> sesuai tipe data tanpa menghancurkan XML struktur lain.
    - Mendukung ekspansi dinamis baris vendor (> 5) dengan cloning style dan border asli.
    """
    def __init__(self, xml_bytes: bytes, sst: SharedStringsTable):
        self.orig_xml = xml_bytes
        self.root = ET.fromstring(xml_bytes)
        self.sst = sst
        self.cell_map: Dict[str, ET.Element] = {}
        self.row_map: Dict[int, ET.Element] = {}
        sheet_data = self.root.find("ns:sheetData", NS_MAP)
        if sheet_data is not None:
            for row in sheet_data.findall("ns:row", NS_MAP):
                r_num = int(row.attrib.get("r", 0))
                self.row_map[r_num] = row
                for c in row.findall("ns:c", NS_MAP):
                    coord = c.attrib.get("r")
                    if coord:
                        self.cell_map[coord] = c

    def get_row(self, row_num: int) -> Optional[ET.Element]:
        return self.row_map.get(row_num)

    def get_cell(self, coord: str) -> Optional[ET.Element]:
        return self.cell_map.get(coord)

    def clone_row(
        self,
        src_row_num: int,
        target_row_num: int,
        hidden: Optional[str] = None,
        ht: Optional[str] = None,
        clear_values: bool = False
    ) -> ET.Element:
        sheet_data = self.root.find("ns:sheetData", NS_MAP)
        src_row = self.row_map.get(src_row_num)
        if src_row is None:
            raise ValueError(f"Source row {src_row_num} not found in worksheet")

        if target_row_num in self.row_map:
            old_row = self.row_map[target_row_num]
            for c in old_row.findall("ns:c", NS_MAP):
                self.cell_map.pop(c.attrib.get("r"), None)
            sheet_data.remove(old_row)

        new_row = copy.deepcopy(src_row)
        new_row.attrib["r"] = str(target_row_num)
        if hidden is not None:
            new_row.attrib["hidden"] = hidden
        else:
            new_row.attrib.pop("hidden", None)
        if ht is not None:
            new_row.attrib["ht"] = str(ht)

        for c in new_row.findall("ns:c", NS_MAP):
            old_coord = c.attrib.get("r", "")
            m = re.match(r"([A-Z]+)", old_coord)
            if m:
                col_letter = m.group(1)
                new_coord = f"{col_letter}{target_row_num}"
                c.attrib["r"] = new_coord
                self.cell_map[new_coord] = c
                if clear_values:
                    c.attrib.pop("t", None)
                    for child in list(c):
                        c.remove(child)

        sheet_data.append(new_row)
        self.row_map[target_row_num] = new_row
        return new_row

    def create_row_from_element(
        self,
        src_elem: ET.Element,
        target_row_num: int,
        hidden: Optional[str] = None,
        ht: Optional[str] = None,
        clear_values: bool = False
    ) -> ET.Element:
        sheet_data = self.root.find("ns:sheetData", NS_MAP)
        if target_row_num in self.row_map:
            old_row = self.row_map[target_row_num]
            for c in old_row.findall("ns:c", NS_MAP):
                self.cell_map.pop(c.attrib.get("r"), None)
            sheet_data.remove(old_row)

        new_row = copy.deepcopy(src_elem)
        new_row.attrib["r"] = str(target_row_num)
        if hidden is not None:
            new_row.attrib["hidden"] = hidden
        else:
            new_row.attrib.pop("hidden", None)
        if ht is not None:
            new_row.attrib["ht"] = str(ht)

        for c in new_row.findall("ns:c", NS_MAP):
            old_coord = c.attrib.get("r", "")
            m = re.match(r"([A-Z]+)", old_coord)
            if m:
                col_letter = m.group(1)
                new_coord = f"{col_letter}{target_row_num}"
                c.attrib["r"] = new_coord
                self.cell_map[new_coord] = c
                if clear_values:
                    c.attrib.pop("t", None)
                    for child in list(c):
                        c.remove(child)

        sheet_data.append(new_row)
        self.row_map[target_row_num] = new_row
        return new_row

    def sort_rows(self):
        sheet_data = self.root.find("ns:sheetData", NS_MAP)
        if sheet_data is None:
            return
        rows = sheet_data.findall("ns:row", NS_MAP)
        rows_sorted = sorted(rows, key=lambda r: int(r.attrib.get("r", 0)))
        for r in list(sheet_data):
            sheet_data.remove(r)
        for r in rows_sorted:
            sheet_data.append(r)

    def set_string(self, coord: str, text: str):
        c = self.get_cell(coord)
        if c is None:
            return
        idx = self.sst.get_or_add(text)
        c.attrib["t"] = "s"
        for child in list(c):
            c.remove(child)
        v = ET.SubElement(c, f"{{{NS_MAIN}}}v")
        v.text = str(idx)

    def set_number(self, coord: str, val: Union[int, float]):
        c = self.get_cell(coord)
        if c is None:
            return
        c.attrib.pop("t", None)
        for child in list(c):
            c.remove(child)
        v = ET.SubElement(c, f"{{{NS_MAIN}}}v")
        if isinstance(val, float) and val.is_integer():
            v.text = str(int(val))
        elif isinstance(val, float):
            v.text = str(round(val, 6))
        else:
            v.text = str(val)

    def set_formula(self, coord: str, formula: str, cached_val: Optional[Union[int, float, str]] = None):
        c = self.get_cell(coord)
        if c is None:
            return
        c.attrib.pop("t", None)
        for child in list(c):
            c.remove(child)
        f = ET.SubElement(c, f"{{{NS_MAIN}}}f")
        f.text = formula.lstrip("=")
        if cached_val is not None:
            v = ET.SubElement(c, f"{{{NS_MAIN}}}v")
            if isinstance(cached_val, float) and cached_val.is_integer():
                v.text = str(int(cached_val))
            elif isinstance(cached_val, float):
                v.text = str(round(cached_val, 6))
            else:
                v.text = str(cached_val)

    def set_dash(self, coord: str):
        self.set_string(coord, "-")

    def clear_cell(self, coord: str):
        c = self.get_cell(coord)
        if c is None:
            return
        c.attrib.pop("t", None)
        for child in list(c):
            c.remove(child)

    def update_dimension(self):
        dim = self.root.find("ns:dimension", NS_MAP)
        if dim is None or not self.cell_map:
            return
        max_col_idx = 1
        max_row_idx = 1
        for coord in self.cell_map.keys():
            m = re.match(r"^([A-Z]+)(\d+)$", coord)
            if m:
                col_str, row_str = m.groups()
                row_num = int(row_str)
                col_num = 0
                for char in col_str:
                    col_num = col_num * 26 + (ord(char) - ord('A') + 1)
                if col_num > max_col_idx:
                    max_col_idx = col_num
                if row_num > max_row_idx:
                    max_row_idx = row_num
        col_letters = ""
        n = max_col_idx
        while n > 0:
            n, rem = divmod(n - 1, 26)
            col_letters = chr(65 + rem) + col_letters
        dim.attrib["ref"] = f"A1:{col_letters}{max_row_idx}"

    def to_xml_bytes(self) -> bytes:
        self.sort_rows()
        self.update_dimension()
        ET.register_namespace("", NS_MAIN)
        ET.register_namespace("r", "http://schemas.openxmlformats.org/officeDocument/2006/relationships")
        ET.register_namespace("mc", "http://schemas.openxmlformats.org/markup-compatibility/2006")
        ET.register_namespace("x14ac", "http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac")
        raw_bytes = ET.tostring(self.root, encoding="utf-8", xml_declaration=True)
        # Restore exact <worksheet ...> root tag and namespaces from orig_xml
        m_orig = re.search(rb"<worksheet\b[^>]*>", self.orig_xml)
        m_cur = re.search(rb"<[a-zA-Z0-9_:]*worksheet\b[^>]*>", raw_bytes)
        if m_orig and m_cur:
            raw_bytes = raw_bytes[:m_cur.start()] + m_orig.group(0) + raw_bytes[m_cur.end():]
        raw_bytes = re.sub(rb"</[a-zA-Z0-9_:]*worksheet>", b"</worksheet>", raw_bytes)
        return raw_bytes


def generate_punishment_monthly_excel_ooxml(
    report_snapshot: Dict[str, Any],
    config: Optional[Dict[str, Any]] = None,
    template_path: Optional[str] = None
) -> bytes:
    """
    Ekspor Excel Laporan Punishment Bulanan menggunakan teknik OOXML Selective Patching:
    - Melestarikan paket OOXML asli (Camera dashboard di RESUM, Drawing, EMF, Style, PageSetup).
    - Mendukung kapasitas dinamis tak terbatas (1, 2, 5, 6, 7, 10, ... vendor operasional).
    - Mengambil vendor dinamis dari report_snapshot['caterings'].
    - Memperluas print area dan cellRange Camera Tool secara dinamis.
    - Menjaga baris referensi ENERGI dan formula tanpa membatasi jumlah vendor operasional.
    """
    config = config or {}
    tpl_file = template_path or TEMPLATE_PATH
    if not os.path.exists(tpl_file):
        raise FileNotFoundError(f"Authoritative template Excel tidak ditemukan: {tpl_file}")

    caterings: List[Dict[str, Any]] = report_snapshot.get("caterings") or report_snapshot.get("katering_details") or []
    num_vendors = len(caterings)

    # Tanggal dan Periode Label
    periode_obj = report_snapshot.get("periode", {})
    start_date_str = report_snapshot.get("start_date") or periode_obj.get("start_date", "")
    end_date_str = report_snapshot.get("end_date") or periode_obj.get("end_date", "")
    periode_label = format_periode_authoritative(start_date_str, end_date_str)

    # Konfigurasi Penandatangan (Sheet ESign)
    # Physical order: Approved | Checked 2 | Checked 1 | Prepared
    # Base state: semua slot kosong (atau Prepared dari user valid). Dilarang dummy TSU/IRO/admin.
    approved_by = config.get("approved_by") or ""
    checked_2_by = config.get("checked_2_by") or ""
    checked_1_by = config.get("checked_1_by") or config.get("checked_by") or ""
    prepared_by = config.get("prepared_by") or ""

    # Sanitize forbidden dummy / fake approvers / generic role fallbacks
    forbidden_tokens = ["tsu", "iro", "admin", "administrator", "manager", "supervisor", "dummy"]
    if any(tok in str(approved_by).strip().lower() for tok in forbidden_tokens):
        approved_by = ""
    if any(tok in str(checked_2_by).strip().lower() for tok in forbidden_tokens):
        checked_2_by = ""
    if any(tok in str(checked_1_by).strip().lower() for tok in forbidden_tokens):
        checked_1_by = ""
    if any(tok in str(prepared_by).strip().lower() for tok in forbidden_tokens):
        prepared_by = ""

    # Buka ZIP template asli secara read-only di memori
    with open(tpl_file, "rb") as f:
        tpl_bytes = f.read()

    in_zip = zipfile.ZipFile(io.BytesIO(tpl_bytes), "r")
    out_buf = io.BytesIO()
    out_zip = zipfile.ZipFile(out_buf, "w", compression=zipfile.ZIP_DEFLATED)

    sst = SharedStringsTable(in_zip.read("xl/sharedStrings.xml"))

    # Mapping sheet name ke ZIP path
    # Sheet 1: HASIL AUDIT
    # Sheet 2: JUMLAH KOMPLIN
    # Sheet 3: JUMPLAH KALORI
    # Sheet 4: MENU DAN KELAYAAN
    # Sheet 5: PENGIRIMAN TERLAMBAT DATANG
    # Sheet 6: RESUM
    # Sheet 7: ESign (migrasi dari legacy SIGN)

    patcher_s1 = WorksheetXmlPatcher(in_zip.read("xl/worksheets/sheet1.xml"), sst)
    patcher_s2 = WorksheetXmlPatcher(in_zip.read("xl/worksheets/sheet2.xml"), sst)
    patcher_s3 = WorksheetXmlPatcher(in_zip.read("xl/worksheets/sheet3.xml"), sst)
    patcher_s4 = WorksheetXmlPatcher(in_zip.read("xl/worksheets/sheet4.xml"), sst)
    patcher_s5 = WorksheetXmlPatcher(in_zip.read("xl/worksheets/sheet5.xml"), sst)
    patcher_s6 = WorksheetXmlPatcher(in_zip.read("xl/worksheets/sheet6.xml"), sst)

    # -------------------------------------------------------------------------
    # 1. SHEET 6: RESUM
    # Dynamic period title ONLY at A4. Do NOT generate synthetic cell table!
    # Spacing pattern: 'KETERANGAN PUNISMENT CATERING PERIODE   ' (3 spaces)
    # -------------------------------------------------------------------------
    patcher_s6.set_string("A4", f"KETERANGAN PUNISMENT CATERING PERIODE   {periode_label}")

    # -------------------------------------------------------------------------
    # 3. SHEET 5: PENGIRIMAN TERLAMBAT DATANG
    # Headers at rows 9:11, vendors start at row 12
    # Title at A5: 'PENGIRIMAN TERLAMBAT PERIODE  ' (2 spaces)
    # -------------------------------------------------------------------------
    patcher_s5.set_string("A5", f"PENGIRIMAN TERLAMBAT PERIODE  {periode_label}")
    loop_count_s5 = max(5, num_vendors)
    for idx in range(loop_count_s5):
        row = 12 + idx
        if idx >= 5:
            patcher_s5.clone_row(src_row_num=16, target_row_num=row, hidden=None, ht="30.75", clear_values=True)

        r_elem = patcher_s5.get_row(row)
        if idx < num_vendors:
            if r_elem is not None:
                r_elem.attrib.pop("hidden", None)
            c = caterings[idx]
            name = c.get("catering_nama", "")
            terlambat = c.get("keterlambatan") or c.get("terlambat") or {}
            lamanya = terlambat.get("lamanya_ringkasan") or terlambat.get("lamanya") or "-"
            kejadian = terlambat.get("jumlah_kejadian", 0)
            p_pagi = terlambat.get("pengurangan_poin_pagi") or terlambat.get("punishment_pagi", 0.0)
            p_malam = terlambat.get("pengurangan_poin_malam") or terlambat.get("punishment_malam", 0.0)
            score = terlambat.get("score_performance", 30.0)

            patcher_s5.set_number(f"A{row}", idx + 1)
            patcher_s5.set_string(f"B{row}", name)
            if lamanya and lamanya != "-":
                patcher_s5.set_string(f"C{row}", str(lamanya))
            else:
                patcher_s5.set_dash(f"C{row}")

            if kejadian and int(kejadian) > 0:
                patcher_s5.set_number(f"D{row}", int(kejadian))
            else:
                patcher_s5.set_dash(f"D{row}")

            if p_pagi and float(p_pagi) > 0:
                patcher_s5.set_number(f"E{row}", float(p_pagi))
            else:
                patcher_s5.set_dash(f"E{row}")

            if p_malam and float(p_malam) > 0:
                patcher_s5.set_number(f"F{row}", float(p_malam))
            else:
                patcher_s5.clear_cell(f"F{row}")

            patcher_s5.set_number(f"G{row}", int(score) if isinstance(score, (int, float)) and score == int(score) else score)
        else:
            # Kosongkan baris template sisa jika vendor < 5
            if r_elem is not None:
                r_elem.attrib["hidden"] = "1"
            for col_l in ["A", "B", "C", "D", "E", "F", "G"]:
                patcher_s5.clear_cell(f"{col_l}{row}")

    ref_row_s5 = 12 + loop_count_s5
    if num_vendors > 5:
        patcher_s5.clone_row(src_row_num=17, target_row_num=ref_row_s5, hidden="1", ht="30")
    else:
        r17 = patcher_s5.get_row(17)
        if r17 is not None:
            r17.attrib["hidden"] = "1"

    # -------------------------------------------------------------------------
    # 4. SHEET 4: MENU DAN KELAYAAN
    # Headers at rows 8:10, vendors start at row 11
    # Title at A6: 'MENU DAN KELAYAKAN PERIODE  ' (2 spaces)
    # -------------------------------------------------------------------------
    patcher_s4.set_string("A6", f"MENU DAN KELAYAKAN PERIODE  {periode_label}")
    loop_count_s4 = max(5, num_vendors)
    for idx in range(loop_count_s4):
        row = 11 + idx
        if idx >= 5:
            patcher_s4.clone_row(src_row_num=15, target_row_num=row, hidden=None, ht="29.25", clear_values=True)

        r_elem = patcher_s4.get_row(row)
        if idx < num_vendors:
            if r_elem is not None:
                r_elem.attrib.pop("hidden", None)
            c = caterings[idx]
            name = c.get("catering_nama", "")
            kelayakan = c.get("menu_kelayakan") or c.get("kelayakan") or {}
            nilai_persen = kelayakan.get("nilai_persen")
            if nilai_persen is None:
                nilai_persen = kelayakan.get("nilai_kelayakan_raw")
            nilai_val = None
            if nilai_persen is not None:
                nilai_val = round(float(nilai_persen) / 100.0, 4) if float(nilai_persen) > 1.0 else round(float(nilai_persen), 4)

            p_pagi = kelayakan.get("pengurangan_poin_pagi") or kelayakan.get("punishment_pagi", 0.0)
            score = kelayakan.get("score_performance", 20.0)

            patcher_s4.set_number(f"A{row}", idx + 1)
            patcher_s4.set_string(f"B{row}", name)
            if nilai_val is not None:
                patcher_s4.set_number(f"C{row}", nilai_val)
            else:
                patcher_s4.set_dash(f"C{row}")

            if p_pagi and float(p_pagi) > 0:
                patcher_s4.set_number(f"D{row}", float(p_pagi))
            else:
                patcher_s4.set_dash(f"D{row}")

            patcher_s4.set_number(f"E{row}", int(score) if isinstance(score, (int, float)) and score == int(score) else score)
        else:
            if r_elem is not None:
                r_elem.attrib["hidden"] = "1"
            for col_l in ["A", "B", "C", "D", "E"]:
                patcher_s4.clear_cell(f"{col_l}{row}")

    ref_row_s4 = 11 + loop_count_s4
    if num_vendors > 5:
        patcher_s4.clone_row(src_row_num=16, target_row_num=ref_row_s4, hidden="1", ht="30.75")
    else:
        r16 = patcher_s4.get_row(16)
        if r16 is not None:
            r16.attrib["hidden"] = "1"

    # -------------------------------------------------------------------------
    # 5. SHEET 1: HASIL AUDIT
    # Headers at rows 8:9, vendors start at row 10
    # Title at A5: 'HASIL AUDIT PERIODE  ' (2 spaces)
    # -------------------------------------------------------------------------
    patcher_s1.set_string("A5", f"HASIL AUDIT PERIODE  {periode_label}")

    # Ensure grade legend table in columns I:J strictly contains rows 10-13 (A, B, C, D).
    # Strip any duplicate cells in columns I and J for row 14 and above.
    for r_check in range(14, 35):
        r_el = patcher_s1.get_row(r_check)
        if r_el is not None:
            for cell_el in list(r_el):
                coord = cell_el.attrib.get("r", "")
                if coord.startswith("I") or coord.startswith("J"):
                    r_el.remove(cell_el)
                    patcher_s1.cell_map.pop(coord, None)

    loop_count_s1 = max(5, num_vendors)
    for idx in range(loop_count_s1):
        row = 10 + idx
        if idx >= 5:
            patcher_s1.clone_row(src_row_num=14, target_row_num=row, hidden=None, ht="42.75", clear_values=True)

        r_elem = patcher_s1.get_row(row)
        if idx < num_vendors:
            if r_elem is not None:
                r_elem.attrib.pop("hidden", None)
            c = caterings[idx]
            name = c.get("catering_nama", "")
            audit = c.get("hasil_audit") or c.get("audit") or {}
            nilai_raw = audit.get("nilai_audit_persen") if audit.get("nilai_audit_persen") is not None else audit.get("nilai_audit_raw")
            nilai_val = None
            if nilai_raw is not None:
                nilai_val = round(float(nilai_raw) / 100.0, 4) if float(nilai_raw) > 1.0 else round(float(nilai_raw), 4)
            grade = audit.get("grade", "-")
            p_pagi = audit.get("pengurangan_poin_pagi") or audit.get("punishment_pagi", 0.0)
            p_malam = audit.get("pengurangan_poin_malam") or audit.get("punishment_malam", 0.0)
            score = audit.get("score_performance", 10.0)

            patcher_s1.set_number(f"A{row}", idx + 1)
            patcher_s1.set_string(f"B{row}", name)
            if nilai_val is not None:
                patcher_s1.set_number(f"C{row}", nilai_val)
            else:
                patcher_s1.set_dash(f"C{row}")

            patcher_s1.set_string(f"D{row}", str(grade))

            if p_pagi and float(p_pagi) > 0:
                patcher_s1.set_number(f"E{row}", float(p_pagi))
            else:
                patcher_s1.clear_cell(f"E{row}")

            if p_malam and float(p_malam) > 0:
                patcher_s1.set_number(f"F{row}", float(p_malam))
            else:
                patcher_s1.clear_cell(f"F{row}")

            patcher_s1.set_number(f"G{row}", int(score) if isinstance(score, (int, float)) and score == int(score) else score)
        else:
            if r_elem is not None:
                r_elem.attrib["hidden"] = "1"
            for col_l in ["A", "B", "C", "D", "E", "F", "G"]:
                patcher_s1.clear_cell(f"{col_l}{row}")

    ref_row_s1 = 10 + loop_count_s1
    if num_vendors > 5:
        patcher_s1.clone_row(src_row_num=15, target_row_num=ref_row_s1, hidden="1", ht="46.5")
    else:
        r15 = patcher_s1.get_row(15)
        if r15 is not None:
            r15.attrib["hidden"] = "1"

    # -------------------------------------------------------------------------
    # 6. SHEET 2: JUMLAH KOMPLIN
    # Headers at rows 8:9, vendors start at row 10
    # Title at A5: 'JUMLAH KOMPLAIN   ' (3 spaces)
    # -------------------------------------------------------------------------
    patcher_s2.set_string("A5", f"JUMLAH KOMPLAIN   {periode_label}")
    loop_count_s2 = max(5, num_vendors)
    for idx in range(loop_count_s2):
        row = 10 + idx
        if idx >= 5:
            patcher_s2.clone_row(src_row_num=14, target_row_num=row, hidden=None, ht="33", clear_values=True)

        r_elem = patcher_s2.get_row(row)
        if idx < num_vendors:
            if r_elem is not None:
                r_elem.attrib.pop("hidden", None)
            c = caterings[idx]
            name = c.get("catering_nama", "")
            komplain = c.get("jumlah_komplain") or c.get("komplain") or {}
            cnt = komplain.get("jumlah_komplain", 0)
            ringkasan = komplain.get("ringkasan_jenis") or komplain.get("jenis_komplain") or "-"
            p_pagi = komplain.get("pengurangan_poin_pagi") or komplain.get("punishment_pagi", 0.0)
            p_malam = komplain.get("pengurangan_poin_malam") or komplain.get("punishment_malam", 0.0)
            score = komplain.get("score_performance", 15.0)

            patcher_s2.set_number(f"A{row}", idx + 1)
            patcher_s2.set_string(f"B{row}", name)
            patcher_s2.set_number(f"C{row}", int(cnt) if cnt is not None else 0)
            patcher_s2.set_string(f"D{row}", str(ringkasan) if ringkasan else "-")

            patcher_s2.set_number(f"E{row}", float(p_pagi) if p_pagi else 0)
            patcher_s2.set_number(f"F{row}", float(p_malam) if p_malam else 0)
            patcher_s2.set_number(f"G{row}", int(score) if isinstance(score, (int, float)) and score == int(score) else score)
        else:
            if r_elem is not None:
                r_elem.attrib["hidden"] = "1"
            for col_l in ["A", "B", "C", "D", "E", "F", "G"]:
                patcher_s2.clear_cell(f"{col_l}{row}")

    ref_row_s2 = 10 + loop_count_s2
    if num_vendors > 5:
        patcher_s2.clone_row(src_row_num=15, target_row_num=ref_row_s2, hidden="1", ht="33")
    else:
        r15_s2 = patcher_s2.get_row(15)
        if r15_s2 is not None:
            r15_s2.attrib["hidden"] = "1"

    # -------------------------------------------------------------------------
    # 7. SHEET 3: JUMPLAH KALORI
    # Subtabel 4A: Punishment Minus (Starts at row 13)
    # Subtabel 4B: Pencapaian Kalori (Starts at row 22 for <=5 vendors; shifts for >5)
    # -------------------------------------------------------------------------
    patcher_s3.set_string("A6", f"JUMLAH KALORI PERIODE  {periode_label}")

    if num_vendors <= 5:
        patcher_s3.set_string("A19", f"PENCAPAIAN KALORI PERIODE  {periode_label}")
        for idx in range(5):
            row_4a = 13 + idx
            row_4b = 22 + idx
            r_4a = patcher_s3.get_row(row_4a)
            r_4b = patcher_s3.get_row(row_4b)

            if idx < num_vendors:
                if r_4a is not None:
                    r_4a.attrib.pop("hidden", None)
                if r_4b is not None:
                    r_4b.attrib.pop("hidden", None)
                c = caterings[idx]
                name = c.get("catering_nama", "")
                kalori = c.get("jumlah_kalori") or c.get("kalori") or {}
                pelanggaran = kalori.get("pelanggaran") or {}
                pencapaian = kalori.get("pencapaian") or {}

                g1 = pelanggaran.get("minus_tidak_berturut") or pelanggaran.get("kategori_1") or {}
                g2 = pelanggaran.get("minus_2_berturut") or pelanggaran.get("kategori_2") or {}
                g3 = pelanggaran.get("minus_berikutnya") or pelanggaran.get("kategori_3") or {}
                g4 = pelanggaran.get("minus_sidak") or pelanggaran.get("kategori_4") or {}

                order_pagi = float(c.get("jumlah_order_pagi") or c.get("order_pagi") or 0.0)
                order_malam = float(c.get("jumlah_order_malam") or c.get("order_malam") or 0.0)

                patcher_s3.set_number(f"A{row_4a}", idx + 1)
                patcher_s3.set_string(f"B{row_4a}", name)

                has_g1 = bool(g1.get("ada") or (g1.get("jumlah", 0) > 0))
                patcher_s3.set_string(f"C{row_4a}", "Ada" if has_g1 else "Tidak ada ")
                tgl1 = g1.get("tanggal_display") or g1.get("tgl") or "-"
                if tgl1 and tgl1 != "-":
                    patcher_s3.set_string(f"D{row_4a}", str(tgl1))
                else:
                    patcher_s3.set_dash(f"D{row_4a}")
                patcher_s3.set_number(f"E{row_4a}", int(g1.get("jumlah", 0)))

                punish_pagi_1 = float(g1.get("pengurangan_poin_pagi", 0.0))
                punish_malam_1 = float(g1.get("pengurangan_poin_malam", 0.0))
                if has_g1 and (order_pagi > 0 or order_malam > 0):
                    op_int = int(round(order_pagi))
                    om_int = int(round(order_malam))
                    patcher_s3.set_formula(f"F{row_4a}", f"={op_int}*3%", cached_val=round(punish_pagi_1, 2))
                    patcher_s3.set_formula(f"G{row_4a}", f"={om_int}*3%", cached_val=round(punish_malam_1, 2))
                elif has_g1 and (punish_pagi_1 > 0 or punish_malam_1 > 0):
                    patcher_s3.set_number(f"F{row_4a}", round(punish_pagi_1, 2))
                    patcher_s3.set_number(f"G{row_4a}", round(punish_malam_1, 2))
                else:
                    patcher_s3.set_number(f"F{row_4a}", 0)
                    patcher_s3.set_number(f"G{row_4a}", 0)

                has_g2 = bool(g2.get("ada") or (g2.get("jumlah", 0) > 0))
                patcher_s3.set_string(f"H{row_4a}", "ADA " if has_g2 else "Tidak ada ")
                tgl2 = g2.get("tanggal_display") or g2.get("tgl") or "-"
                if tgl2 and tgl2 != "-":
                    patcher_s3.set_string(f"I{row_4a}", str(tgl2))
                else:
                    patcher_s3.set_dash(f"I{row_4a}")
                patcher_s3.set_number(f"J{row_4a}", int(g2.get("jumlah", 0)))
                patcher_s3.set_number(f"K{row_4a}", float(g2.get("pengurangan_poin_pagi", 0.0)))
                patcher_s3.set_number(f"L{row_4a}", float(g2.get("pengurangan_poin_malam", 0.0)))

                has_g3 = bool(g3.get("ada") or (g3.get("jumlah", 0) > 0))
                if has_g3:
                    patcher_s3.set_string(f"M{row_4a}", "ADA")
                    tgl3 = g3.get("tanggal_display") or g3.get("tgl") or "-"
                    patcher_s3.set_string(f"N{row_4a}", str(tgl3))
                    patcher_s3.set_number(f"O{row_4a}", int(g3.get("jumlah", 0)))
                    patcher_s3.set_number(f"P{row_4a}", float(g3.get("pengurangan_poin_pagi", 0.0)))
                    patcher_s3.set_number(f"Q{row_4a}", float(g3.get("pengurangan_poin_malam", 0.0)))
                else:
                    patcher_s3.set_dash(f"M{row_4a}")
                    patcher_s3.set_dash(f"N{row_4a}")
                    patcher_s3.set_dash(f"O{row_4a}")
                    patcher_s3.clear_cell(f"P{row_4a}")
                    patcher_s3.clear_cell(f"Q{row_4a}")

                has_g4 = bool(g4.get("ada") or (g4.get("jumlah", 0) > 0))
                if has_g4:
                    patcher_s3.set_string(f"R{row_4a}", "ADA")
                    tgl4 = g4.get("tanggal_display") or g4.get("tgl") or "-"
                    patcher_s3.set_string(f"S{row_4a}", str(tgl4))
                    patcher_s3.set_number(f"T{row_4a}", int(g4.get("jumlah", 0)))
                    patcher_s3.set_number(f"U{row_4a}", float(g4.get("pengurangan_poin_pagi", 0.0)))
                    patcher_s3.set_number(f"V{row_4a}", float(g4.get("pengurangan_poin_malam", 0.0)))
                else:
                    patcher_s3.set_dash(f"R{row_4a}")
                    patcher_s3.set_dash(f"S{row_4a}")
                    patcher_s3.set_dash(f"T{row_4a}")
                    patcher_s3.clear_cell(f"U{row_4a}")
                    patcher_s3.clear_cell(f"V{row_4a}")

                tot_p_pagi = (
                    float(g1.get("pengurangan_poin_pagi", 0.0)) +
                    float(g2.get("pengurangan_poin_pagi", 0.0)) +
                    float(g3.get("pengurangan_poin_pagi", 0.0)) +
                    float(g4.get("pengurangan_poin_pagi", 0.0))
                )
                tot_p_malam = (
                    float(g1.get("pengurangan_poin_malam", 0.0)) +
                    float(g2.get("pengurangan_poin_malam", 0.0)) +
                    float(g3.get("pengurangan_poin_malam", 0.0)) +
                    float(g4.get("pengurangan_poin_malam", 0.0))
                )
                patcher_s3.set_formula(
                    f"W{row_4a}",
                    f"=F{row_4a}+K{row_4a}+P{row_4a}+U{row_4a}",
                    cached_val=round(tot_p_pagi, 2)
                )
                patcher_s3.set_formula(
                    f"X{row_4a}",
                    f"=G{row_4a}+L{row_4a}+Q{row_4a}+V{row_4a}",
                    cached_val=round(tot_p_malam, 2)
                )

                mencapai = int(pencapaian.get("jumlah_mencapai", 0))
                tidak_mencapai = int(pencapaian.get("jumlah_tidak_mencapai", 0))
                tot_kirim = mencapai + tidak_mencapai
                persen_ratio = (mencapai / tot_kirim) if tot_kirim > 0 else 0.0
                score_kal = kalori.get("score_performance", 15.0)

                patcher_s3.set_number(f"A{row_4b}", idx + 1)
                patcher_s3.set_string(f"B{row_4b}", name)
                patcher_s3.set_number(f"C{row_4b}", mencapai)
                patcher_s3.set_number(f"E{row_4b}", tidak_mencapai)
                patcher_s3.set_formula(f"G{row_4b}", f"=SUM(C{row_4b}:F{row_4b})", cached_val=tot_kirim)
                patcher_s3.set_formula(f"I{row_4b}", f"=C{row_4b}/G{row_4b}*100%", cached_val=round(persen_ratio, 6))
                patcher_s3.set_number(f"K{row_4b}", int(score_kal) if isinstance(score_kal, (int, float)) and score_kal == int(score_kal) else score_kal)
            else:
                if r_4a is not None:
                    r_4a.attrib["hidden"] = "1"
                for col_l in ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V", "W", "X"]:
                    patcher_s3.clear_cell(f"{col_l}{row_4a}")

                if r_4b is not None:
                    r_4b.attrib["hidden"] = "1"
                for col_l in ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L"]:
                    patcher_s3.clear_cell(f"{col_l}{row_4b}")

        r18 = patcher_s3.get_row(18)
        if r18 is not None:
            r18.attrib["hidden"] = "1"
        patcher_s3.set_formula("W18", "=F18+K18+P18+U18", cached_val=30)
        patcher_s3.set_formula("X18", "=G18+L18+Q18+V18", cached_val=30)

        r27 = patcher_s3.get_row(27)
        if r27 is not None:
            r27.attrib["hidden"] = "1"
        patcher_s3.set_formula("G27", "=SUM(C27:F27)", cached_val=21)
        patcher_s3.set_formula("I27", "=C27/G27*100%", cached_val=0.61904761904761907)

    else:
        # Dynamic layout for num_vendors > 5
        row_17_copy = copy.deepcopy(patcher_s3.get_row(17))
        row_18_copy = copy.deepcopy(patcher_s3.get_row(18))
        row_19_copy = copy.deepcopy(patcher_s3.get_row(19))
        row_20_copy = copy.deepcopy(patcher_s3.get_row(20))
        row_21_copy = copy.deepcopy(patcher_s3.get_row(21))
        rows_22_to_26_copy = [copy.deepcopy(patcher_s3.get_row(r)) for r in range(22, 27)]
        row_27_copy = copy.deepcopy(patcher_s3.get_row(27))
        trailing_blank_rows = [copy.deepcopy(patcher_s3.get_row(r)) for r in range(28, 34) if patcher_s3.get_row(r) is not None]

        s3_sheet_data = patcher_s3.root.find("ns:sheetData", NS_MAP)
        for r_elem in list(s3_sheet_data):
            r_num = int(r_elem.attrib.get("r", 0))
            if r_num >= 18:
                for c in r_elem.findall("ns:c", NS_MAP):
                    patcher_s3.cell_map.pop(c.attrib.get("r"), None)
                patcher_s3.row_map.pop(r_num, None)
                s3_sheet_data.remove(r_elem)

        # Populate 4A rows
        for idx in range(num_vendors):
            row_4a = 13 + idx
            if idx >= 5:
                patcher_s3.create_row_from_element(row_17_copy, row_4a, hidden=None, ht="47.25", clear_values=True)
            else:
                r_elem = patcher_s3.get_row(row_4a)
                if r_elem is not None:
                    r_elem.attrib.pop("hidden", None)

            c = caterings[idx]
            name = c.get("catering_nama", "")
            kalori = c.get("jumlah_kalori") or c.get("kalori") or {}
            pelanggaran = kalori.get("pelanggaran") or {}
            pencapaian = kalori.get("pencapaian") or {}

            g1 = pelanggaran.get("minus_tidak_berturut") or pelanggaran.get("kategori_1") or {}
            g2 = pelanggaran.get("minus_2_berturut") or pelanggaran.get("kategori_2") or {}
            g3 = pelanggaran.get("minus_berikutnya") or pelanggaran.get("kategori_3") or {}
            g4 = pelanggaran.get("minus_sidak") or pelanggaran.get("kategori_4") or {}

            order_pagi = float(c.get("jumlah_order_pagi") or c.get("order_pagi") or 0.0)
            order_malam = float(c.get("jumlah_order_malam") or c.get("order_malam") or 0.0)

            patcher_s3.set_number(f"A{row_4a}", idx + 1)
            patcher_s3.set_string(f"B{row_4a}", name)

            has_g1 = bool(g1.get("ada") or (g1.get("jumlah", 0) > 0))
            patcher_s3.set_string(f"C{row_4a}", "Ada" if has_g1 else "Tidak ada ")
            tgl1 = g1.get("tanggal_display") or g1.get("tgl") or "-"
            if tgl1 and tgl1 != "-":
                patcher_s3.set_string(f"D{row_4a}", str(tgl1))
            else:
                patcher_s3.set_dash(f"D{row_4a}")
            patcher_s3.set_number(f"E{row_4a}", int(g1.get("jumlah", 0)))

            punish_pagi_1 = float(g1.get("pengurangan_poin_pagi", 0.0))
            punish_malam_1 = float(g1.get("pengurangan_poin_malam", 0.0))
            if has_g1 and (order_pagi > 0 or order_malam > 0):
                op_int = int(round(order_pagi))
                om_int = int(round(order_malam))
                patcher_s3.set_formula(f"F{row_4a}", f"={op_int}*3%", cached_val=round(punish_pagi_1, 2))
                patcher_s3.set_formula(f"G{row_4a}", f"={om_int}*3%", cached_val=round(punish_malam_1, 2))
            elif has_g1 and (punish_pagi_1 > 0 or punish_malam_1 > 0):
                patcher_s3.set_number(f"F{row_4a}", round(punish_pagi_1, 2))
                patcher_s3.set_number(f"G{row_4a}", round(punish_malam_1, 2))
            else:
                patcher_s3.set_number(f"F{row_4a}", 0)
                patcher_s3.set_number(f"G{row_4a}", 0)

            has_g2 = bool(g2.get("ada") or (g2.get("jumlah", 0) > 0))
            patcher_s3.set_string(f"H{row_4a}", "ADA " if has_g2 else "Tidak ada ")
            tgl2 = g2.get("tanggal_display") or g2.get("tgl") or "-"
            if tgl2 and tgl2 != "-":
                patcher_s3.set_string(f"I{row_4a}", str(tgl2))
            else:
                patcher_s3.set_dash(f"I{row_4a}")
            patcher_s3.set_number(f"J{row_4a}", int(g2.get("jumlah", 0)))
            patcher_s3.set_number(f"K{row_4a}", float(g2.get("pengurangan_poin_pagi", 0.0)))
            patcher_s3.set_number(f"L{row_4a}", float(g2.get("pengurangan_poin_malam", 0.0)))

            has_g3 = bool(g3.get("ada") or (g3.get("jumlah", 0) > 0))
            if has_g3:
                patcher_s3.set_string(f"M{row_4a}", "ADA")
                tgl3 = g3.get("tanggal_display") or g3.get("tgl") or "-"
                patcher_s3.set_string(f"N{row_4a}", str(tgl3))
                patcher_s3.set_number(f"O{row_4a}", int(g3.get("jumlah", 0)))
                patcher_s3.set_number(f"P{row_4a}", float(g3.get("pengurangan_poin_pagi", 0.0)))
                patcher_s3.set_number(f"Q{row_4a}", float(g3.get("pengurangan_poin_malam", 0.0)))
            else:
                patcher_s3.set_dash(f"M{row_4a}")
                patcher_s3.set_dash(f"N{row_4a}")
                patcher_s3.set_dash(f"O{row_4a}")
                patcher_s3.clear_cell(f"P{row_4a}")
                patcher_s3.clear_cell(f"Q{row_4a}")

            has_g4 = bool(g4.get("ada") or (g4.get("jumlah", 0) > 0))
            if has_g4:
                patcher_s3.set_string(f"R{row_4a}", "ADA")
                tgl4 = g4.get("tanggal_display") or g4.get("tgl") or "-"
                patcher_s3.set_string(f"S{row_4a}", str(tgl4))
                patcher_s3.set_number(f"T{row_4a}", int(g4.get("jumlah", 0)))
                patcher_s3.set_number(f"U{row_4a}", float(g4.get("pengurangan_poin_pagi", 0.0)))
                patcher_s3.set_number(f"V{row_4a}", float(g4.get("pengurangan_poin_malam", 0.0)))
            else:
                patcher_s3.set_dash(f"R{row_4a}")
                patcher_s3.set_dash(f"S{row_4a}")
                patcher_s3.set_dash(f"T{row_4a}")
                patcher_s3.clear_cell(f"U{row_4a}")
                patcher_s3.clear_cell(f"V{row_4a}")

            tot_p_pagi = (
                float(g1.get("pengurangan_poin_pagi", 0.0)) +
                float(g2.get("pengurangan_poin_pagi", 0.0)) +
                float(g3.get("pengurangan_poin_pagi", 0.0)) +
                float(g4.get("pengurangan_poin_pagi", 0.0))
            )
            tot_p_malam = (
                float(g1.get("pengurangan_poin_malam", 0.0)) +
                float(g2.get("pengurangan_poin_malam", 0.0)) +
                float(g3.get("pengurangan_poin_malam", 0.0)) +
                float(g4.get("pengurangan_poin_malam", 0.0))
            )
            patcher_s3.set_formula(f"W{row_4a}", f"=F{row_4a}+K{row_4a}+P{row_4a}+U{row_4a}", cached_val=round(tot_p_pagi, 2))
            patcher_s3.set_formula(f"X{row_4a}", f"=G{row_4a}+L{row_4a}+Q{row_4a}+V{row_4a}", cached_val=round(tot_p_malam, 2))

        # 4A Reference row
        ref_row_4a = 13 + num_vendors
        patcher_s3.create_row_from_element(row_18_copy, ref_row_4a, hidden="1", ht="47.25")
        patcher_s3.set_formula(f"W{ref_row_4a}", f"=F{ref_row_4a}+K{ref_row_4a}+P{ref_row_4a}+U{ref_row_4a}", cached_val=30)
        patcher_s3.set_formula(f"X{ref_row_4a}", f"=G{ref_row_4a}+L{ref_row_4a}+Q{ref_row_4a}+V{ref_row_4a}", cached_val=30)

        # 4B Title
        title_row_4b = 14 + num_vendors
        patcher_s3.create_row_from_element(row_19_copy, title_row_4b, hidden=None)
        patcher_s3.set_string(f"A{title_row_4b}", f"PENCAPAIAN KALORI PERIODE  {periode_label}")

        # 4B Spacing
        spacing_row_4b = 15 + num_vendors
        patcher_s3.create_row_from_element(row_20_copy, spacing_row_4b, hidden=None)

        # 4B Header
        header_row_4b = 16 + num_vendors
        patcher_s3.create_row_from_element(row_21_copy, header_row_4b, hidden=None)

        # 4B Vendors
        start_4b = 17 + num_vendors
        for idx in range(num_vendors):
            row_4b = start_4b + idx
            src_t = rows_22_to_26_copy[idx] if idx < 5 else rows_22_to_26_copy[-1]
            patcher_s3.create_row_from_element(src_t, row_4b, hidden=None, ht="28.5", clear_values=True)

            c = caterings[idx]
            name = c.get("catering_nama", "")
            kalori = c.get("jumlah_kalori") or c.get("kalori") or {}
            pencapaian = kalori.get("pencapaian") or {}
            mencapai = int(pencapaian.get("jumlah_mencapai", 0))
            tidak_mencapai = int(pencapaian.get("jumlah_tidak_mencapai", 0))
            tot_kirim = mencapai + tidak_mencapai
            persen_ratio = (mencapai / tot_kirim) if tot_kirim > 0 else 0.0
            score_kal = kalori.get("score_performance", 15.0)

            patcher_s3.set_number(f"A{row_4b}", idx + 1)
            patcher_s3.set_string(f"B{row_4b}", name)
            patcher_s3.set_number(f"C{row_4b}", mencapai)
            patcher_s3.set_number(f"E{row_4b}", tidak_mencapai)
            patcher_s3.set_formula(f"G{row_4b}", f"=SUM(C{row_4b}:F{row_4b})", cached_val=tot_kirim)
            patcher_s3.set_formula(f"I{row_4b}", f"=C{row_4b}/G{row_4b}*100%", cached_val=round(persen_ratio, 6))
            patcher_s3.set_number(f"K{row_4b}", int(score_kal) if isinstance(score_kal, (int, float)) and score_kal == int(score_kal) else score_kal)

        # 4B Reference row
        ref_row_4b = start_4b + num_vendors
        patcher_s3.create_row_from_element(row_27_copy, ref_row_4b, hidden="1", ht="28.5")
        patcher_s3.set_formula(f"G{ref_row_4b}", f"=SUM(C{ref_row_4b}:F{ref_row_4b})", cached_val=21)
        patcher_s3.set_formula(f"I{ref_row_4b}", f"=C{ref_row_4b}/G{ref_row_4b}*100%", cached_val=0.61904761904761907)

        # Trailing rows
        for b_idx, b_elem in enumerate(trailing_blank_rows):
            tr_num = ref_row_4b + 1 + b_idx
            patcher_s3.create_row_from_element(b_elem, tr_num, hidden=None)

        # Update merged cells in Sheet 3
        mc_elem = patcher_s3.root.find("ns:mergeCells", NS_MAP)
        if mc_elem is not None:
            for m in list(mc_elem):
                ref = m.attrib.get("ref", "")
                rows_in_ref = [int(n) for n in re.findall(r"\d+", ref)]
                if any(r >= 21 for r in rows_in_ref):
                    mc_elem.remove(m)

            for col_pair in [("C", "D"), ("E", "F"), ("G", "H"), ("I", "J"), ("K", "L")]:
                m_sub = ET.SubElement(mc_elem, f"{{{NS_MAIN}}}mergeCell")
                m_sub.attrib["ref"] = f"{col_pair[0]}{header_row_4b}:{col_pair[1]}{header_row_4b}"

            for r in range(start_4b, ref_row_4b + 1):
                for col_pair in [("C", "D"), ("E", "F"), ("G", "H"), ("I", "J"), ("K", "L")]:
                    m_sub = ET.SubElement(mc_elem, f"{{{NS_MAIN}}}mergeCell")
                    m_sub.attrib["ref"] = f"{col_pair[0]}{r}:{col_pair[1]}{r}"

            mc_elem.attrib["count"] = str(len(list(mc_elem)))

    # -------------------------------------------------------------------------
    # RESUM (Sheet 6) Visual Compactness
    # -------------------------------------------------------------------------
    # 1. Set column M (Col 13) narrow width in sheet6.xml to reduce oversized center gutter
    s6_xml_bytes = patcher_s6.to_xml_bytes()
    pos_sd = s6_xml_bytes.find(b"<sheetData>")
    if pos_sd != -1 and b"<cols>" not in s6_xml_bytes:
        s6_xml_bytes = (
            s6_xml_bytes[:pos_sd] +
            b'<cols><col min="13" max="13" width="2.0" customWidth="1"/></cols>' +
            s6_xml_bytes[pos_sd:]
        )

    # 2. Preserve sheet6 XML structure
    s6_final_bytes = s6_xml_bytes

    # -------------------------------------------------------------------------
    # Dynamic Camera Ranges & Geometry in Drawing1
    # -------------------------------------------------------------------------
    end_s5 = 11 + max(5, num_vendors) + 1
    end_s4 = 10 + max(5, num_vendors) + 1
    end_s1 = 9 + max(5, num_vendors) + 1
    end_s2 = 9 + max(5, num_vendors) + 1

    if num_vendors <= 5:
        end_s3_4a = 18
        header_4b = 21
        end_s3_4b = 27
    else:
        end_s3_4a = 13 + num_vendors
        header_4b = 16 + num_vendors
        end_s3_4b = 17 + 2 * num_vendors

    cam_map = {
        "Picture 17": f"'PENGIRIMAN TERLAMBAT DATANG'!$A$9:$G${end_s5}",
        "Picture 15": f"'MENU DAN KELAYAAN'!$A$8:$E${end_s4}",
        "Picture 16": f"'HASIL AUDIT'!$A$8:$G${end_s1}",
        "Picture 19": f"'JUMLAH KOMPLIN'!$A$8:$G${end_s2}",
        "Picture 11": f"'JUMPLAH KALORI'!$A$9:$X${end_s3_4a}",
        "Picture 9":  f"'JUMPLAH KALORI'!$A${header_4b}:$L${end_s3_4b}",
    }

    d1_raw = in_zip.read("xl/drawings/drawing1.xml").decode("utf-8")
    d1_blocks = re.findall(r'<mc:AlternateContent.*?</mc:AlternateContent>', d1_raw, re.DOTALL)
    d1_new_blocks = []
    for b in d1_blocks:
        name_m = re.search(r'name="([^"]+)"', b)
        name = name_m.group(1) if name_m else ""
        b_cur = b
        if name in cam_map:
            b_cur = re.sub(r'cellRange="[^"]+"', f'cellRange="{cam_map[name]}"', b_cur)

        if name in ("Picture 17", "Picture 16"):
            b_mod = re.sub(r'<xdr:from><xdr:col>\d+</xdr:col><xdr:colOff>\d+</xdr:colOff>', '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>52052</xdr:colOff>', b_cur)
            d1_new_blocks.append(b_mod)
        elif name in ("Picture 9", "Picture 19"):
            b_mod = re.sub(r'<xdr:from><xdr:col>\d+</xdr:col><xdr:colOff>\d+</xdr:colOff>', '<xdr:from><xdr:col>13</xdr:col><xdr:colOff>2</xdr:colOff>', b_cur)
            d1_new_blocks.append(b_mod)
        else:
            d1_new_blocks.append(b_cur)

    d1_header = d1_raw[:d1_raw.find("<mc:AlternateContent")]
    d1_footer = d1_raw[d1_raw.rfind("</mc:AlternateContent>") + 22:]
    d1_final_bytes = (d1_header + "".join(d1_new_blocks) + d1_footer).encode("utf-8")

    # -------------------------------------------------------------------------
    # 7. GENERATE DEDICATED ESign SHEET (Standardized 3-Column: Approved | Checked 2 | Checked 1)
    # Layout: Approved (B4) | Checked 2 (C4) | Checked 1 (D4) - NO Prepared column
    # Merged Boxes B5:B8, C5:C8, D5:D8
    # Bottom Row 9: B9, C9, D9
    # -------------------------------------------------------------------------
    approvers_status = config.get("approvers_status") or []
    qr_images = config.get("qr_images") or {}

    qr_slots = []
    if approvers_status:
        for app_rec in approvers_status:
            seq = app_rec.get("sequence")
            qr_b = app_rec.get("qr_png_bytes")
            if qr_b:
                if seq == 1:
                    qr_slots.append({"col": 3, "qr_bytes": qr_b, "name": "QR_Checked_1"})
                    if not checked_1_by:
                        checked_1_by = app_rec.get("initial") or "NIA"
                elif seq == 2:
                    qr_slots.append({"col": 2, "qr_bytes": qr_b, "name": "QR_Checked_2"})
                    if not checked_2_by:
                        checked_2_by = app_rec.get("initial") or "CAN"
                elif seq == 3:
                    qr_slots.append({"col": 1, "qr_bytes": qr_b, "name": "QR_Approved"})
                    if not approved_by:
                        approved_by = app_rec.get("initial") or ""
    elif qr_images:
        for slot_k, slot_v in qr_images.items():
            if slot_k in ("Checked 1", "checked_1", 1) and slot_v:
                qr_slots.append({"col": 3, "qr_bytes": slot_v, "name": "QR_Checked_1"})
            elif slot_k in ("Checked 2", "checked_2", 2) and slot_v:
                qr_slots.append({"col": 2, "qr_bytes": slot_v, "name": "QR_Checked_2"})
            elif slot_k in ("Approved", "approved", 3) and slot_v:
                qr_slots.append({"col": 1, "qr_bytes": slot_v, "name": "QR_Approved"})

    drawing_tag = '<drawing r:id="rId2"/>' if qr_slots else ''

    s7_xml = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac" '
        'xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac">'
        '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/><pageSetUpPr fitToPage="1"/></sheetPr>'
        '<dimension ref="B4:D9"/>'
        '<sheetViews><sheetView showGridLines="1" workbookViewId="0"><selection activeCell="A1" sqref="A1"/></sheetView></sheetViews>'
        '<sheetFormatPr defaultRowHeight="15" x14ac:dyDescent="0.25"/>'
        '<cols>'
        '<col min="1" max="1" width="3" customWidth="1"/>'
        '<col min="2" max="2" width="19" customWidth="1"/>'
        '<col min="3" max="3" width="19" customWidth="1"/>'
        '<col min="4" max="4" width="19" customWidth="1"/>'
        '</cols>'
        '<sheetData>'
        '<row r="1" ht="15" customHeight="1"/>'
        '<row r="2" ht="15" customHeight="1"/>'
        '<row r="3" ht="15" customHeight="1"/>'
        '<row r="4" ht="24" customHeight="1">'
        '<c r="B4" s="38" t="inlineStr"><is><t>Approved</t></is></c>'
        '<c r="C4" s="38" t="inlineStr"><is><t>Checked 2</t></is></c>'
        '<c r="D4" s="38" t="inlineStr"><is><t>Checked 1</t></is></c>'
        '</row>'
        '<row r="5" ht="20" customHeight="1"><c r="B5" s="38"/><c r="C5" s="38"/><c r="D5" s="38"/></row>'
        '<row r="6" ht="20" customHeight="1"><c r="B6" s="38"/><c r="C6" s="38"/><c r="D6" s="38"/></row>'
        '<row r="7" ht="20" customHeight="1"><c r="B7" s="38"/><c r="C7" s="38"/><c r="D7" s="38"/></row>'
        '<row r="8" ht="20" customHeight="1"><c r="B8" s="38"/><c r="C8" s="38"/><c r="D8" s="38"/></row>'
        '<row r="9" ht="20" customHeight="1">'
        f'<c r="B9" s="38" t="inlineStr"><is><t>{approved_by}</t></is></c>'
        f'<c r="C9" s="38" t="inlineStr"><is><t>{checked_2_by}</t></is></c>'
        f'<c r="D9" s="38" t="inlineStr"><is><t>{checked_1_by}</t></is></c>'
        '</row>'
        '</sheetData>'
        '<mergeCells count="3">'
        '<mergeCell ref="B5:B8"/>'
        '<mergeCell ref="C5:C8"/>'
        '<mergeCell ref="D5:D8"/>'
        '</mergeCells>'
        '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.5" footer="0.5"/>'
        '<pageSetup orientation="portrait" paperSize="9" fitToWidth="1" fitToHeight="1"/>'
        f'{drawing_tag}'
        '</worksheet>'
    )
    s7_final_bytes = s7_xml.encode("utf-8")

    # -------------------------------------------------------------------------
    # DefinedNames for Dynamic Print Area in xl/workbook.xml
    # -------------------------------------------------------------------------
    wb_raw = in_zip.read("xl/workbook.xml").decode("utf-8")
    wb_raw = wb_raw.replace('name="SIGN"', 'name="ESign"')
    print_areas = [
        f'<definedName name="_xlnm.Print_Area" localSheetId="0">\'HASIL AUDIT\'!$A$1:$G${end_s1}</definedName>',
        f'<definedName name="_xlnm.Print_Area" localSheetId="1">\'JUMLAH KOMPLIN\'!$A$1:$G${end_s2}</definedName>',
        f'<definedName name="_xlnm.Print_Area" localSheetId="2">\'JUMPLAH KALORI\'!$A$1:$X${end_s3_4b}</definedName>',
        f'<definedName name="_xlnm.Print_Area" localSheetId="3">\'MENU DAN KELAYAAN\'!$A$1:$E${end_s4}</definedName>',
        f'<definedName name="_xlnm.Print_Area" localSheetId="4">\'PENGIRIMAN TERLAMBAT DATANG\'!$A$1:$G${end_s5}</definedName>',
        f'<definedName name="_xlnm.Print_Area" localSheetId="5">\'RESUM\'!$A$1:$AD$60</definedName>',
        f'<definedName name="_xlnm.Print_Area" localSheetId="6">\'ESign\'!$A$1:$D$15</definedName>',
    ]
    def_names_xml = f'<definedNames>{"".join(print_areas)}</definedNames>'

    # OpenXML SpreadsheetML schema sequence (ECMA-376 Part 4 §3.2.29):
    # definedNames MUST precede calcPr in xl/workbook.xml.
    # Strip any preexisting definedNames (wherever located) to prevent duplicates or stale positions
    wb_clean = re.sub(r'<definedNames>.*?</definedNames>', '', wb_raw, flags=re.DOTALL)
    if "<calcPr" in wb_clean:
        wb_patched = wb_clean.replace("<calcPr", f"{def_names_xml}<calcPr")
    else:
        wb_patched = wb_clean.replace("</workbook>", f"{def_names_xml}</workbook>")

    # Regression assertion & failsafe: definedNames MUST be sequenced before calcPr
    idx_dn = wb_patched.find("<definedNames")
    idx_calc = wb_patched.find("<calcPr")
    if idx_dn != -1 and idx_calc != -1 and idx_dn > idx_calc:
        calc_match = re.search(r'<calcPr[^>]*/>', wb_patched)
        dn_match = re.search(r'<definedNames>.*?</definedNames>', wb_patched, flags=re.DOTALL)
        if calc_match and dn_match:
            calc_tag = calc_match.group(0)
            dn_tag = dn_match.group(0)
            wb_patched = wb_patched.replace(calc_tag, "").replace(dn_tag, f"{dn_tag}{calc_tag}")
        idx_dn = wb_patched.find("<definedNames")
        idx_calc = wb_patched.find("<calcPr")

    if idx_dn != -1 and idx_calc != -1:
        assert idx_dn < idx_calc, "Schema violation: definedNames MUST be before calcPr in xl/workbook.xml"

    wb_final_bytes = wb_patched.encode("utf-8")

    # docProps/app.xml update: SIGN -> ESign
    app_raw = in_zip.read("docProps/app.xml").decode("utf-8")
    app_patched = app_raw.replace("<vt:lpstr>SIGN</vt:lpstr>", "<vt:lpstr>ESign</vt:lpstr>").encode("utf-8")

    # -------------------------------------------------------------------------
    # Reassemble OOXML ZIP Package
    # Salin seluruh entri byte-for-byte; timpa hanya yang di-patch.
    # Exclude xl/calcChain.xml: Excel regenerates calculation dependency trees automatically on open.
    # -------------------------------------------------------------------------
    modified_xmls = {
        "xl/sharedStrings.xml": sst.to_xml_bytes(),
        "xl/workbook.xml": wb_final_bytes,
        "docProps/app.xml": app_patched,
        "xl/worksheets/sheet1.xml": patcher_s1.to_xml_bytes(),
        "xl/worksheets/sheet2.xml": patcher_s2.to_xml_bytes(),
        "xl/worksheets/sheet3.xml": patcher_s3.to_xml_bytes(),
        "xl/worksheets/sheet4.xml": patcher_s4.to_xml_bytes(),
        "xl/worksheets/sheet5.xml": patcher_s5.to_xml_bytes(),
        "xl/worksheets/sheet6.xml": s6_final_bytes,
        "xl/worksheets/sheet7.xml": s7_final_bytes,
        "xl/drawings/drawing1.xml": d1_final_bytes,
    }

    # Patch [Content_Types].xml and xl/_rels/workbook.xml.rels to cleanly remove calcChain
    ct_raw = in_zip.read("[Content_Types].xml").decode("utf-8")
    ct_patched = ct_raw.replace(
        '<Override PartName="/xl/calcChain.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.calcChain+xml"/>',
        ''
    )

    # Optional QR drawing files
    if qr_slots:
        anchors = []
        d2_rels = []
        for i, s in enumerate(qr_slots):
            r_id = f"rId{i+1}"
            img_name = f"qr_esign_{i+1}.png"
            modified_xmls[f"xl/media/{img_name}"] = s["qr_bytes"]
            d2_rels.append(f'<Relationship Id="{r_id}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/{img_name}"/>')
            anchors.append(
                f'<oneCellAnchor>'
                f'<from><col>{s["col"]}</col><colOff>200000</colOff><row>4</row><rowOff>100000</rowOff></from>'
                f'<ext cx="650000" cy="650000"/>'
                f'<pic>'
                f'<nvPicPr><cNvPr id="{i+100}" name="{s["name"]}" descr="Signature QR"/><cNvPicPr/></nvPicPr>'
                f'<blipFill><a:blip cstate="print" r:embed="{r_id}"/><a:stretch><a:fillRect/></a:stretch></blipFill>'
                f'<spPr><a:prstGeom prst="rect"/></spPr>'
                f'</pic>'
                f'<clientData/>'
                f'</oneCellAnchor>'
            )

        d2_xml = (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<wsDr xmlns="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            + "".join(anchors) +
            '</wsDr>'
        )
        d2_rels_xml = (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            + "".join(d2_rels) +
            '</Relationships>'
        )
        modified_xmls["xl/drawings/drawing2.xml"] = d2_xml.encode("utf-8")
        modified_xmls["xl/drawings/_rels/drawing2.xml.rels"] = d2_rels_xml.encode("utf-8")

        s7_rels_xml = (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="../printerSettings/printerSettings7.bin"/>'
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing2.xml"/>'
            '</Relationships>'
        )
        modified_xmls["xl/worksheets/_rels/sheet7.xml.rels"] = s7_rels_xml.encode("utf-8")

        if 'Extension="png"' not in ct_patched:
            ct_patched = ct_patched.replace("</Types>", '<Default Extension="png" ContentType="image/png"/></Types>')
        if 'PartName="/xl/drawings/drawing2.xml"' not in ct_patched:
            ct_patched = ct_patched.replace("</Types>", '<Override PartName="/xl/drawings/drawing2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>')

    modified_xmls["[Content_Types].xml"] = ct_patched.encode("utf-8")

    wb_rels_raw = in_zip.read("xl/_rels/workbook.xml.rels").decode("utf-8")
    wb_rels_patched = re.sub(r'<Relationship[^>]*Target="calcChain\.xml"[^>]*/>', '', wb_rels_raw).encode("utf-8")
    modified_xmls["xl/_rels/workbook.xml.rels"] = wb_rels_patched

    for item in in_zip.infolist():
        fname = item.filename
        if fname == "xl/calcChain.xml":
            continue
        if fname in modified_xmls:
            out_zip.writestr(item, modified_xmls[fname])
        else:
            out_zip.writestr(item, in_zip.read(fname))

    # Add newly created parts that did not exist in in_zip (e.g. drawing2 when QR is present)
    for fname, data in modified_xmls.items():
        if fname not in in_zip.namelist():
            out_zip.writestr(fname, data)

    in_zip.close()
    out_zip.close()
    return out_buf.getvalue()

