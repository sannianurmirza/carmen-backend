"""
Modul Selective OOXML Patching untuk Tanda Tangan QR Elektronik pada Workbook Punishment & Excel CARMEN.

Menjaga integritas workbook SpreadsheetML secara non-destruktif:
- 7 sheets lengkap dipertahankan.
- 12 file EMF media kamera asli dipertahankan tanpa perubahan.
- Objek Camera-tool pada RESUM dan VML drawing dipertahankan utuh.
- Urutan elemen SpreadsheetML di workbook.xml (<definedNames> sebelum <calcPr>) tidak disentuh.
- Penempatan QR sesuai slot approval:
    * Sequence 1 -> RESUM!AC4:AC6 (image3.png)
    * Sequence 2 -> RESUM!AB4:AB6 (image2.png)
    * Sequence 3 -> RESUM!AA4:AA6 (image1.png)
- Bersifat inkremental: persetujuan sequence berikutnya melestarikan QR dari sequence sebelumnya.
"""

import io
import os
import re
import zipfile
import xml.etree.ElementTree as ET
import qrcode
from typing import Dict, Any, Optional, Tuple

# Konfigurasi Pemetaan Slot Tanda Tangan Template Punishment
# Sesuai Aturan Bisnis Terkunci:
# - sanianurmirza@gmail.com -> label "Checked 1", initial "NIA", slot AC (AC4:AC6), media image3.png
# - niamirza521@gmail.com -> label "Checked 2", initial "CAN", slot AB (AB4:AB6), media image2.png
# - Approved -> UNCONFIGURED (slot AA4:AA6, media image1.png). Tidak di-stamp sampai user terkonfigurasi.

PUNISHMENT_SIGNATURE_CONFIG: Dict[str, Dict[str, Any]] = {
    "sanianurmirza@gmail.com": {
        "slot_code": "CHECKED_1",
        "role_label": "Checked 1",
        "initial": "NIA",
        "sheet_name": "RESUM",
        "cell_range": "AC4:AC6",
        "label_cell": "AC3",
        "initial_cell": "AC7",
        "col": 28,  # AC (0-indexed)
        "media_target": "image3.png",
        "rel_id": "rId101",
        "pic_id": 101,
        "pic_name": "QR_Signature_1",
        "is_active": True,
    },
    "niamirza521@gmail.com": {
        "slot_code": "CHECKED_2",
        "role_label": "Checked 2",
        "initial": "CAN",
        "sheet_name": "RESUM",
        "cell_range": "AB4:AB6",
        "label_cell": "AB3",
        "initial_cell": "AB7",
        "col": 27,  # AB (0-indexed)
        "media_target": "image2.png",
        "rel_id": "rId102",
        "pic_id": 102,
        "pic_name": "QR_Signature_2",
        "is_active": True,
    },
}

APPROVED_SLOT_CONFIG: Dict[str, Any] = {
    "slot_code": "APPROVED",
    "role_label": "Approved",
    "initial": None,  # Belum dikonfigurasi
    "email": None,    # Belum dikonfigurasi
    "sheet_name": "RESUM",
    "cell_range": "AA4:AA6",
    "label_cell": "AA3",
    "initial_cell": "AA7",
    "col": 26,  # AA (0-indexed)
    "media_target": "image1.png",
    "rel_id": "rId103",
    "pic_id": 103,
    "pic_name": "QR_Signature_3",
    "is_active": False,
}

SLOT_MAPPING: Dict[int, Dict[str, Any]] = {
    1: PUNISHMENT_SIGNATURE_CONFIG["sanianurmirza@gmail.com"],
    2: PUNISHMENT_SIGNATURE_CONFIG["niamirza521@gmail.com"],
    3: APPROVED_SLOT_CONFIG,
}

# Konfigurasi Pemetaan Slot Tanda Tangan Template Reward & Punishment
# Sesuai Aturan Bisnis Terkunci: Checked 1 | Checked 2 | Approved
# Physical layout (left to right): Approved (Col F / 5) | Checked 2 (Col G / 6) | Checked 1 (Col H / 7)
# Sequence 1 -> sanianurmirza@gmail.com (NIA permanent QR, Col H)
# Sequence 2 -> niamirza521@gmail.com (CAN permanent QR, Col G)
# Sequence 3 -> Approved (UNCONFIGURED, Col F)
REWARD_PUNISHMENT_SIGNATURE_CONFIG: Dict[str, Dict[str, Any]] = {
    "sanianurmirza@gmail.com": {
        "slot_code": "CHECKED_1",
        "role_label": "Checked 1",
        "initial": "NIA",
        "sequence": 1,
        "col": 7,  # Col H (0-indexed 7)
        "media_target": "qr_checked_1.png",
        "rel_id": "rId101",
        "pic_id": 101,
        "pic_name": "QR_Signature_1",
        "is_active": True,
    },
    "niamirza521@gmail.com": {
        "slot_code": "CHECKED_2",
        "role_label": "Checked 2",
        "initial": "CAN",
        "sequence": 2,
        "col": 6,  # Col G (0-indexed 6)
        "media_target": "qr_checked_2.png",
        "rel_id": "rId102",
        "pic_id": 102,
        "pic_name": "QR_Signature_2",
        "is_active": True,
    },
}

RP_APPROVED_SLOT_CONFIG: Dict[str, Any] = {
    "slot_code": "APPROVED",
    "role_label": "Approved",
    "initial": None,  # Belum dikonfigurasi
    "sequence": 3,
    "col": 5,  # Col F (0-indexed 5)
    "media_target": "qr_approve.png",
    "rel_id": "rId103",
    "pic_id": 103,
    "pic_name": "QR_Signature_3",
    "is_active": False,
}

RP_SLOT_MAPPING: Dict[int, Dict[str, Any]] = {
    1: REWARD_PUNISHMENT_SIGNATURE_CONFIG["sanianurmirza@gmail.com"],
    2: REWARD_PUNISHMENT_SIGNATURE_CONFIG["niamirza521@gmail.com"],
    3: RP_APPROVED_SLOT_CONFIG,
}


def _find_signature_style_index(styles_xml: str) -> str:
    """
    Mencari style ID yang memiliki border tipis di semua sisi dan horizontal/vertical center alignment.
    Memprioritaskan font bold.
    """
    try:
        st = ET.fromstring(styles_xml)
        ns = {"main": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
        borders = st.find("main:borders", ns).findall("main:border", ns)
        thin_border_ids = set()
        for bi, b in enumerate(borders):
            sides = [b.find("main:" + s, ns) for s in ["left", "right", "top", "bottom"]]
            if all(s is not None and s.attrib.get("style") == "thin" for s in sides):
                thin_border_ids.add(bi)

        fonts = st.find("main:fonts", ns).findall("main:font", ns)
        bold_fids = {fi for fi, font in enumerate(fonts) if font.find("main:b", ns) is not None}

        cell_xfs = st.find("main:cellXfs", ns).findall("main:xf", ns)
        # 1. Cari yang border thin + font bold + center alignment
        for xi, xf in enumerate(cell_xfs):
            bid = int(xf.attrib.get("borderId", -1))
            fid = int(xf.attrib.get("fontId", -1))
            if bid in thin_border_ids and fid in bold_fids:
                al = xf.find("main:alignment", ns)
                if al is not None and al.attrib.get("horizontal") == "center" and al.attrib.get("vertical") == "center":
                    return str(xi)

        # 2. Cari yang border thin + center alignment
        for xi, xf in enumerate(cell_xfs):
            bid = int(xf.attrib.get("borderId", -1))
            if bid in thin_border_ids:
                al = xf.find("main:alignment", ns)
                if al is not None and al.attrib.get("horizontal") == "center" and al.attrib.get("vertical") == "center":
                    return str(xi)

        # 3. Cari yang border thin
        for xi, xf in enumerate(cell_xfs):
            bid = int(xf.attrib.get("borderId", -1))
            if bid in thin_border_ids:
                return str(xi)
    except Exception:
        pass

    return "31"


def _col_to_num(col_str: str) -> int:
    num = 0
    for char in col_str:
        num = num * 26 + (ord(char) - ord("A") + 1)
    return num


def _update_shared_strings(sst_content: str, text: str) -> Tuple[int, str]:
    """
    Mencari atau menyisipkan teks ke dalam xl/sharedStrings.xml secara non-destruktif.
    """
    si_list = re.findall(r'<si>.*?</si>', sst_content, flags=re.DOTALL)
    for idx, si in enumerate(si_list):
        m = re.search(r'<t[^>]*>(.*?)</t>', si, flags=re.DOTALL)
        if m and m.group(1) == text:
            return idx, sst_content
    new_si = f'<si><t>{text}</t></si>'
    new_sst = re.sub(r'</sst>', f'{new_si}</sst>', sst_content)
    m_uc = re.search(r'uniqueCount="(\d+)"', new_sst)
    if m_uc:
        old_uc = int(m_uc.group(1))
        new_sst = new_sst.replace(f'uniqueCount="{old_uc}"', f'uniqueCount="{old_uc + 1}"')
    return len(si_list), new_sst


def _ensure_resum_signature_block(
    files: Dict[str, bytes],
    sheet_path: str,
    sequence: Optional[int] = None,
    initial: Optional[str] = None,
) -> None:
    """
    Memastikan blok tanda tangan RESUM (AA3:AC7) memiliki label (Approved | Checked 2 | Checked 1),
    initial dinamis sesuai status approval, border box, row height, column width,
    dan cell merge yang valid dan konsisten.
    Dilakukan melalui selective OOXML patching agar tidak merusak struktur workbook.
    """
    if sheet_path not in files:
        return

    # 1. Cari style index dengan border thin dan alignment center
    sig_style = "31"
    if "xl/styles.xml" in files:
        styles_str = files["xl/styles.xml"].decode("utf-8")
        sig_style = _find_signature_style_index(styles_str)

    # 2. Perbarui shared strings jika ada
    ns_main = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    ns = {"main": ns_main}
    idx_appr = idx_chk2 = idx_chk1 = idx_can = idx_nia = idx_appr_init = None

    if "xl/sharedStrings.xml" in files:
        sst_str = files["xl/sharedStrings.xml"].decode("utf-8")
        idx_appr, sst_str = _update_shared_strings(sst_str, "Approved")
        idx_chk2, sst_str = _update_shared_strings(sst_str, "Checked 2")
        idx_chk1, sst_str = _update_shared_strings(sst_str, "Checked 1")
        idx_can, sst_str = _update_shared_strings(sst_str, "CAN")
        idx_nia, sst_str = _update_shared_strings(sst_str, "NIA")
        if initial and sequence == 3:
            idx_appr_init, sst_str = _update_shared_strings(sst_str, str(initial).strip())
        files["xl/sharedStrings.xml"] = sst_str.encode("utf-8")

    # 3. Patch worksheet XML secara selektif
    sh = files[sheet_path].decode("utf-8")

    # Ambil atau buat sheetData
    m_sd = re.search(r'(<sheetData>.*?</sheetData>)', sh, flags=re.DOTALL)
    if not m_sd:
        m_sd = re.search(r'(<sheetData\s*/>)', sh)
    if not m_sd:
        return

    orig_sd = m_sd.group(1)
    if orig_sd.endswith('/>'):
        orig_sd_xml = f'<sheetData xmlns="{ns_main}"></sheetData>'
    else:
        orig_sd_xml = re.sub(r'^<sheetData\b', f'<sheetData xmlns="{ns_main}"', orig_sd)

    ET.register_namespace('', ns_main)
    sd_tree = ET.fromstring(orig_sd_xml)

    # Inisial di Row 7 sesuai sequence aktif (tanpa fallback dummy TSU)
    aa7_val = None
    ab7_val = None
    ac7_val = None

    if sequence == 1:
        ac7_val = idx_nia
    elif sequence == 2:
        ac7_val = idx_nia
        ab7_val = idx_can
    elif sequence == 3:
        ac7_val = idx_nia
        ab7_val = idx_can
        aa7_val = idx_appr_init
    elif sequence is None:
        # Periksa apakah sel sudah memiliki nilai sebelumnya
        pass

    row_specs = {
        3: (14.0, {"AA": idx_appr, "AB": idx_chk2, "AC": idx_chk1}),
        4: (31.5, {"AA": None, "AB": None, "AC": None}),
        5: (5.0, {"AA": None, "AB": None, "AC": None}),
        6: (5.0, {"AA": None, "AB": None, "AC": None}),
        7: (12.0, {"AA": aa7_val, "AB": ab7_val, "AC": ac7_val}),
    }

    existing_rows = {int(r.attrib.get('r', 0)): r for r in sd_tree.findall('main:row', ns)}
    # Hapus row duplikat jika ada
    all_rows = sd_tree.findall('main:row', ns)
    seen_r = set()
    for r in list(all_rows):
        r_n = int(r.attrib.get('r', 0))
        if r_n in row_specs:
            if r_n in seen_r:
                sd_tree.remove(r)
            else:
                seen_r.add(r_n)
    existing_rows = {int(r.attrib.get('r', 0)): r for r in sd_tree.findall('main:row', ns)}

    for r_num, (ht, cell_map) in row_specs.items():
        if r_num in existing_rows:
            r_elem = existing_rows[r_num]
            r_elem.attrib['ht'] = str(ht)
            r_elem.attrib['customHeight'] = '1'
        else:
            r_elem = ET.Element(f'{{{ns_main}}}row', {'r': str(r_num), 'ht': str(ht), 'customHeight': '1'})
            inserted = False
            for idx, child in enumerate(list(sd_tree)):
                c_num = int(child.attrib.get('r', 0))
                if c_num > r_num:
                    sd_tree.insert(idx, r_elem)
                    inserted = True
                    break
            if not inserted:
                sd_tree.append(r_elem)
            existing_rows[r_num] = r_elem

        row_cells = {c.attrib.get('r'): c for c in r_elem.findall('main:c', ns)}
        for col, sst_idx in cell_map.items():
            cell_ref = f"{col}{r_num}"
            if cell_ref in row_cells:
                c_elem = row_cells[cell_ref]
            else:
                c_elem = ET.Element(f'{{{ns_main}}}c', {'r': cell_ref})
                c_col_num = _col_to_num(col)
                ins_pos = len(list(r_elem))
                for c_i, child in enumerate(list(r_elem)):
                    other_ref = child.attrib.get('r', '')
                    m_other = re.match(r'([A-Z]+)', other_ref)
                    if m_other and _col_to_num(m_other.group(1)) > c_col_num:
                        ins_pos = c_i
                        break
                r_elem.insert(ins_pos, c_elem)
                row_cells[cell_ref] = c_elem

            c_elem.attrib['s'] = sig_style
            if r_num == 7 and sst_idx is None:
                # Jangan hapus nilai inisial baris 7 yang sudah ada dari approval stage sebelumnya
                existing_v = c_elem.find('main:v', ns)
                if existing_v is not None and existing_v.text:
                    continue

            for v in c_elem.findall('main:v', ns):
                c_elem.remove(v)
            for f in c_elem.findall('main:f', ns):
                c_elem.remove(f)

            if sst_idx is not None:
                c_elem.attrib['t'] = 's'
                v_sub = ET.SubElement(c_elem, f'{{{ns_main}}}v')
                v_sub.text = str(sst_idx)
            else:
                c_elem.attrib.pop('t', None)

    new_sd = ET.tostring(sd_tree, encoding='utf-8').decode('utf-8')
    # Strip namespace xmlns attribute from new_sd to keep sheetData uniform with worksheet root namespace
    new_sd = re.sub(r'\s+xmlns="[^"]*"', '', new_sd, count=1)
    sh = sh.replace(orig_sd, new_sd)

    # 4. Pastikan mergeCells untuk slot QR
    needed_merges = ['AA4:AA6', 'AB4:AB6', 'AC4:AC6']
    m_mc = re.search(r'<mergeCells[^>]*>(.*?)</mergeCells>', sh, flags=re.DOTALL)
    if m_mc:
        mc_body = m_mc.group(1)
        for ref in needed_merges:
            if ref not in mc_body:
                mc_body += f'<mergeCell ref="{ref}"/>'
        total_count = len(re.findall(r'<mergeCell\b', mc_body))
        sh = sh[:m_mc.start()] + f'<mergeCells count="{total_count}">{mc_body}</mergeCells>' + sh[m_mc.end():]
    else:
        mc_xml = f'<mergeCells count="{len(needed_merges)}">' + ''.join(f'<mergeCell ref="{ref}"/>' for ref in needed_merges) + '</mergeCells>'
        sh = sh.replace('</sheetData>', f'</sheetData>{mc_xml}')

    # 5. Pastikan lebar kolom AA, AB, AC sama rata (equal width = 12.0)
    col_widths = {27: '12.0', 28: '12.0', 29: '12.0'}
    m_cols = re.search(r'(<cols>.*?</cols>)', sh, flags=re.DOTALL)
    if m_cols:
        cols_body = m_cols.group(1)
        for c_min, w in col_widths.items():
            m_c = re.search(rf'<col\b[^>]*min="{c_min}"[^>]*/>', cols_body)
            if m_c:
                new_c = f'<col min="{c_min}" max="{c_min}" width="{w}" customWidth="1"/>'
                cols_body = cols_body[:m_c.start()] + new_c + cols_body[m_c.end():]
            else:
                cols_body = cols_body.replace('</cols>', f'<col min="{c_min}" max="{c_min}" width="{w}" customWidth="1"/></cols>')
        sh = sh[:m_cols.start()] + cols_body + sh[m_cols.end():]
    else:
        cols_xml = '<cols>' + ''.join(f'<col min="{c_min}" max="{c_min}" width="{w}" customWidth="1"/>' for c_min, w in col_widths.items()) + '</cols>'
        sh = sh.replace('<sheetData>', f'{cols_xml}<sheetData>')

    files[sheet_path] = sh.encode('utf-8')



def generate_qr_image_bytes(verify_url: str) -> bytes:
    """
    Menghasilkan data binary PNG QR Code untuk tautan verifikasi identitas tanda tangan pengguna.
    Jika dalam lingkungan UAT, tanda tangan diberi penandaan visual 'UAT / SIMULATION' agar tidak dianggap resmi.
    """
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=8,
        border=2,
    )
    qr.add_data(verify_url)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white").convert("RGBA")

    # Watermark khusus UAT agar jelas berbeda dari tanda tangan resmi
    if os.getenv("CARMEN_ENV", "").lower() == "uat" or os.getenv("UAT", "") == "1":
        from PIL import Image as PILImage, ImageDraw
        w, h = img.size
        banner_h = 24
        watermarked = PILImage.new("RGBA", (w, h + banner_h), (255, 230, 0, 255))
        watermarked.paste(img, (0, 0))
        draw = ImageDraw.Draw(watermarked)
        # Tulis teks penanda UAT
        draw.text((w // 2, h + 4), "UAT / SIMULATION", fill=(0, 0, 0, 255), anchor="mt")
        img = watermarked

    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def get_resum_structure(file_bytes: bytes) -> Optional[Dict[str, Any]]:
    """
    Mendeteksi secara struktural apakah data byte merupakan workbook XLSX yang memiliki sheet RESUM.
    Mengembalikan metadata relasi sheet dan drawing tanpa bergantung pada nomor urut sheet historis.
    """
    if not (isinstance(file_bytes, bytes) and file_bytes.startswith(b"PK\x03\x04")):
        return None
    try:
        with zipfile.ZipFile(io.BytesIO(file_bytes), "r") as z:
            names = set(z.namelist())
            if "xl/workbook.xml" not in names or "xl/_rels/workbook.xml.rels" not in names:
                return None

            import xml.etree.ElementTree as ET
            wb_xml = ET.fromstring(z.read("xl/workbook.xml"))
            wb_rels = ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))

            ns_wb = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
            ns_rel = {"pr": "http://schemas.openxmlformats.org/package/2006/relationships"}

            rel_map = {r.attrib.get("Id"): r.attrib.get("Target") for r in wb_rels.findall("pr:Relationship", ns_rel)}

            resum_target = None
            for s in wb_xml.findall("m:sheets/m:sheet", ns_wb):
                s_name = s.attrib.get("name", "")
                r_id = s.attrib.get("{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id")
                if "RESUM" in s_name.upper():
                    resum_target = rel_map.get(r_id)
                    break

            if not resum_target:
                return None

            # Normalisasi path sheet
            clean_target = resum_target.lstrip("/")
            if not clean_target.startswith("xl/"):
                sheet_path = f"xl/{clean_target}"
            else:
                sheet_path = clean_target

            sheet_fname = sheet_path.split("/")[-1]
            sheet_rels_path = f"xl/worksheets/_rels/{sheet_fname}.rels"

            drawing_xml = "xl/drawings/drawing1.xml"
            drawing_rels = "xl/drawings/_rels/drawing1.xml.rels"

            if sheet_rels_path in names:
                srels = ET.fromstring(z.read(sheet_rels_path))
                for r in srels.findall("pr:Relationship", ns_rel):
                    r_type = r.attrib.get("Type", "")
                    if r_type.endswith("/drawing"):
                        d_target = r.attrib.get("Target", "").lstrip("/")
                        d_fname = d_target.split("/")[-1]
                        drawing_xml = f"xl/drawings/{d_fname}"
                        drawing_rels = f"xl/drawings/_rels/{d_fname}.rels"

            return {
                "sheet_path": sheet_path,
                "sheet_rels_path": sheet_rels_path,
                "drawing_xml": drawing_xml,
                "drawing_rels": drawing_rels,
                "names": names,
            }
    except Exception:
        return None


def is_reward_punishment_xlsx(file_bytes: bytes) -> bool:
    """
    Memeriksa secara struktural apakah data byte merupakan workbook XLSX Reward & Punishment
    (memiliki sheet GRUP B dan GRUP A atau KELOMPOK JIHAN dan KELOMPOK 1 AKBAR).
    """
    if not (isinstance(file_bytes, bytes) and file_bytes.startswith(b"PK\x03\x04")):
        return False
    try:
        with zipfile.ZipFile(io.BytesIO(file_bytes), "r") as z:
            if "xl/workbook.xml" not in z.namelist():
                return False
            wb_xml = z.read("xl/workbook.xml").decode("utf-8")
            return ("GRUP B" in wb_xml or "KELOMPOK JIHAN" in wb_xml) and ("GRUP A" in wb_xml or "KELOMPOK 1 AKBAR" in wb_xml)
    except Exception:
        return False


def is_performance_xlsx(file_bytes: bytes) -> bool:
    """
    Memeriksa secara struktural apakah data byte merupakan workbook XLSX Performance Catering
    (memiliki sheet STANDAR PENILAIAN dan RESUME).
    """
    if not (isinstance(file_bytes, bytes) and file_bytes.startswith(b"PK\x03\x04")):
        return False
    try:
        with zipfile.ZipFile(io.BytesIO(file_bytes), "r") as z:
            if "xl/workbook.xml" not in z.namelist():
                return False
            wb_xml = z.read("xl/workbook.xml").decode("utf-8")
            return "STANDAR PENILAIAN" in wb_xml and "RESUME" in wb_xml
    except Exception:
        return False


def stamp_performance_approval_qr(
    source_bytes: bytes,
    sequence: int,
    qr_png_bytes: bytes,
    approver_email: Optional[str] = None,
) -> bytes:
    """
    Menyuntikkan tanda tangan QR elektronik ke dalam sheet RESUME pada workbook Performance Catering:
    - Sequence 1 / sanianurmirza@gmail.com -> Slot Checked 1 (Col F4:F6), inisial NIA di F7
    - Sequence 2 / niamirza521@gmail.com   -> Slot Checked 2 (Col E4:E6), inisial CAN di E7
    - Sequence 3 / Approved                -> Slot Approved (Col D4:D6), inisial di D7 (Unconfigured)
    """
    clean_email = (approver_email or "").strip().lower()
    if clean_email in REWARD_PUNISHMENT_SIGNATURE_CONFIG:
        slot = REWARD_PUNISHMENT_SIGNATURE_CONFIG[clean_email]
        seq = slot["sequence"]
        initial = slot["initial"]
    elif not clean_email:
        if sequence == 1:
            seq = 1
            initial = "NIA"
        elif sequence == 2:
            seq = 2
            initial = "CAN"
        elif sequence == 3:
            raise ValueError("User untuk slot Approved belum dikonfigurasi.")
        else:
            raise ValueError("Mapping tanda tangan approver belum dikonfigurasi.")
    else:
        if sequence == 3:
            raise ValueError("User untuk slot Approved belum dikonfigurasi.")
        else:
            raise ValueError("Mapping tanda tangan approver belum dikonfigurasi.")

    import openpyxl
    from openpyxl.drawing.image import Image

    wb = openpyxl.load_workbook(io.BytesIO(source_bytes), data_only=False)
    if "RESUME" not in wb.sheetnames:
        return source_bytes
    ws = wb["RESUME"]

    col_map = {1: "F", 2: "E", 3: "D"}
    target_col = col_map[seq]

    img = Image(io.BytesIO(qr_png_bytes))
    img.width = 95
    img.height = 95
    ws.add_image(img, f"{target_col}4")

    ws[f"{target_col}7"].value = initial

    out = io.BytesIO()
    wb.save(out)
    return out.getvalue()


def is_punishment_xlsx(file_bytes: bytes) -> bool:
    """
    Memeriksa secara struktural apakah data byte merupakan workbook XLSX Punishment (RESUM),
    Reward & Punishment (GRUP A/B), atau Performance Catering (RESUME).
    """
    return (
        is_performance_xlsx(file_bytes)
        or is_reward_punishment_xlsx(file_bytes)
        or get_resum_structure(file_bytes) is not None
    )


def _ensure_rp_signature_block(files: Dict[str, bytes], sequence: int, initial: str) -> None:
    """
    Memastikan struktur blok tanda tangan Reward & Punishment (Approved | Checked 2 | Checked 1)
    pada sheet1 (GRUP B), sheet2 (GRUP A), dan sheet3 (ATURAN REWARD & PUNISHMENT) konsisten:
    - Labels: F=Approved, G=Checked 2, H=Checked 1
    - Sequence 1: Slot Checked 1 (Col H) mendapatkan inisial NIA (H44/H46/H57)
    - Sequence 2: Slot Checked 2 (Col G) mendapatkan inisial CAN (G44/G46/G57), H tetap dipertahankan
    - Sequence 3: Slot Approved (Col F) mendapatkan inisial (bila terkonfigurasi)
    - Row heights: header=14.0, box=31.5/5.0, initial=12.0
    - Styles: label & initial=51, box=153
    - Merged cells: F, G, H untuk rentang baris kotak QR
    """
    from reward_punishment_excel import set_cell_value, NS_MAP, NS_MAIN

    sheets_config = [
        ("xl/worksheets/sheet1.xml", 39, [40, 41, 42, 43], 44),
        ("xl/worksheets/sheet2.xml", 40, [41, 42, 43, 44, 45], 46),
        ("xl/worksheets/sheet3.xml", 52, [53, 54, 55, 56], 57),
    ]

    for sheet_path, row_lbl, box_rows, row_init in sheets_config:
        if sheet_path not in files:
            continue
        root = ET.fromstring(files[sheet_path])

        # Pastikan Label F=Approved, G=Checked 2, H=Checked 1
        set_cell_value(root, f"F{row_lbl}", "Approved")
        set_cell_value(root, f"G{row_lbl}", "Checked 2")
        set_cell_value(root, f"H{row_lbl}", "Checked 1")

        # Set inisial sesuai sequence
        if sequence == 1:
            set_cell_value(root, f"H{row_init}", initial or "NIA")
        elif sequence == 2:
            set_cell_value(root, f"G{row_init}", initial or "CAN")
            # Pastikan H tetap NIA jika belum terisi
            sd = root.find("ns:sheetData", NS_MAP)
            if sd is not None:
                r_elem = sd.find(f"ns:row[@r='{row_init}']", NS_MAP)
                h_cell = r_elem.find("ns:c[@r='H" + str(row_init) + "']", NS_MAP) if r_elem is not None else None
                if h_cell is None or not "".join(h_cell.itertext()).strip():
                    set_cell_value(root, f"H{row_init}", "NIA")
        elif sequence == 3:
            set_cell_value(root, f"F{row_init}", initial)

        # Pastikan styles dan row heights
        sd = root.find("ns:sheetData", NS_MAP)
        if sd is not None:
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

        # Merged cells
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

        # Ensure sheet views are Normal (no pageBreakPreview mode to eliminate Page 1 watermark)
        svs = root.find("ns:sheetViews", NS_MAP)
        if svs is not None:
            for sv in svs.findall("ns:sheetView", NS_MAP):
                if sv.attrib.get("view") == "pageBreakPreview":
                    del sv.attrib["view"]

        files[sheet_path] = ET.tostring(root, encoding="utf-8", xml_declaration=True)


def stamp_reward_punishment_approval_qr(
    source_bytes: bytes,
    sequence: int,
    qr_png_bytes: bytes,
    approver_email: Optional[str] = None,
) -> bytes:
    """
    Menyuntikkan tanda tangan QR elektronik ke dalam workbook Reward & Punishment (Sheet GRUP B dan GRUP A):
    - Table structure: Checked | Checked | Approve (matching Punishment)
    - Physical layout (left to right): Approve (Col F / 5) | Checked 2 (Col G / 6) | Checked 1 (Col H / 7)
    - Sequence 1 / sanianurmirza@gmail.com -> Slot Checked 1 (Col H / 7), inisial NIA
    - Sequence 2 / niamirza521@gmail.com   -> Slot Checked 2 (Col G / 6), inisial CAN
    - Sequence 3 / Approve                 -> UNCONFIGURED (Ditolak aman dengan ValueError sampai email resmi dikonfigurasi)
    """
    clean_email = (approver_email or "").strip().lower()

    if clean_email in REWARD_PUNISHMENT_SIGNATURE_CONFIG:
        slot = REWARD_PUNISHMENT_SIGNATURE_CONFIG[clean_email]
        slot_key = slot["slot_code"].lower()
        initial = slot["initial"]
        col = slot["col"]
        rel_id = slot["rel_id"]
        pic_id = slot["pic_id"]
        pic_name = slot["pic_name"]
        media_name = slot["media_target"]
    elif not clean_email:
        if sequence == 1:
            slot = REWARD_PUNISHMENT_SIGNATURE_CONFIG["sanianurmirza@gmail.com"]
            slot_key = slot["slot_code"].lower()
            initial = slot["initial"]
            col = slot["col"]
            rel_id = slot["rel_id"]
            pic_id = slot["pic_id"]
            pic_name = slot["pic_name"]
            media_name = slot["media_target"]
        elif sequence == 2:
            slot = REWARD_PUNISHMENT_SIGNATURE_CONFIG["niamirza521@gmail.com"]
            slot_key = slot["slot_code"].lower()
            initial = slot["initial"]
            col = slot["col"]
            rel_id = slot["rel_id"]
            pic_id = slot["pic_id"]
            pic_name = slot["pic_name"]
            media_name = slot["media_target"]
        elif sequence == 3:
            raise ValueError("User untuk slot Approved belum dikonfigurasi.")
        else:
            raise ValueError("Mapping tanda tangan approver belum dikonfigurasi.")
    else:
        if sequence == 3:
            raise ValueError("User untuk slot Approved belum dikonfigurasi.")
        else:
            raise ValueError("Mapping tanda tangan approver belum dikonfigurasi.")

    with zipfile.ZipFile(io.BytesIO(source_bytes), "r") as zin:
        files = {name: zin.read(name) for name in zin.namelist()}

    # 1. Content types: add png
    if "[Content_Types].xml" in files:
        ct_xml = files["[Content_Types].xml"].decode("utf-8")
        if 'Extension="png"' not in ct_xml:
            ct_xml = ct_xml.replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>')
            files["[Content_Types].xml"] = ct_xml.encode("utf-8")

    # 2. Media
    files[f"xl/media/{media_name}"] = qr_png_bytes

    # 3. Clean VML Camera Tool shapes
    if "xl/drawings/vmlDrawing1.vml" in files:
        v1 = files["xl/drawings/vmlDrawing1.vml"].decode("utf-8", errors="ignore")
        v1 = re.sub(r'<v:shape\s+id="Picture_x0020_6"[^>]*>.*?</v:shape>', '', v1, flags=re.DOTALL)
        files["xl/drawings/vmlDrawing1.vml"] = v1.encode("utf-8")

    if "xl/drawings/vmlDrawing2.vml" in files:
        v2 = files["xl/drawings/vmlDrawing2.vml"].decode("utf-8", errors="ignore")
        v2 = re.sub(r'<v:shape\s+id="Picture_x0020_8"[^>]*>.*?</v:shape>', '', v2, flags=re.DOTALL)
        files["xl/drawings/vmlDrawing2.vml"] = v2.encode("utf-8")

    # 4. Patch drawing XMLs
    for s_idx, d_path, r_path, r_start, r_end in [
        (1, "xl/drawings/drawing1.xml", "xl/drawings/_rels/drawing1.xml.rels", 39, 43),
        (2, "xl/drawings/drawing2.xml", "xl/drawings/_rels/drawing2.xml.rels", 40, 45),
    ]:
        if d_path in files:
            d_xml = files[d_path].decode("utf-8")
            # Remove legacy camera tool if still present
            d_root = ET.fromstring(d_xml.encode("utf-8"))
            to_remove = []
            for child in d_root:
                ct = child.find('.//{http://schemas.microsoft.com/office/drawing/2010/main}cameraTool')
                if ct is not None and "F$52" in ct.attrib.get("cellRange", ""):
                    to_remove.append(child)
            for elem in to_remove:
                d_root.remove(elem)

            d_xml = ET.tostring(d_root, encoding="utf-8").decode("utf-8")

            # Add relationship
            if r_path in files:
                r_xml = files[r_path].decode("utf-8")
                rel_elem = f'<Relationship Id="{rel_id}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/{media_name}"/>'
                if f'Id="{rel_id}"' not in r_xml:
                    r_xml = r_xml.replace('</Relationships>', f'{rel_elem}</Relationships>')
                    files[r_path] = r_xml.encode("utf-8")

            # Add twoCellAnchor
            anchor_xml = (
                f'<xdr:twoCellAnchor editAs="oneCell" '
                f'xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
                f'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                f'<xdr:from><xdr:col>{col}</xdr:col><xdr:colOff>15000</xdr:colOff><xdr:row>{r_start}</xdr:row><xdr:rowOff>15000</xdr:rowOff></xdr:from>'
                f'<xdr:to><xdr:col>{col + 1}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{r_end}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                f'<xdr:pic>'
                f'<xdr:nvPicPr>'
                f'<xdr:cNvPr id="{pic_id + (s_idx * 10)}" name="{pic_name}"/>'
                f'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr>'
                f'</xdr:nvPicPr>'
                f'<xdr:blipFill>'
                f'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="{rel_id}"/>'
                f'<a:stretch><a:fillRect/></a:stretch>'
                f'</xdr:blipFill>'
                f'<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                f'</xdr:pic>'
                f'<xdr:clientData/>'
                f'</xdr:twoCellAnchor>'
            )
            if f'name="{pic_name}"' not in d_xml:
                d_xml = d_xml.replace('</xdr:wsDr>', f'{anchor_xml}</xdr:wsDr>')
            files[d_path] = d_xml.encode("utf-8")

    # 5. Ensure worksheet cells, initials, and formatting
    _ensure_rp_signature_block(files, sequence, initial)

    out_buf = io.BytesIO()
    with zipfile.ZipFile(out_buf, "w", zipfile.ZIP_DEFLATED) as zout:
        for name, data in files.items():
            zout.writestr(name, data)

    return out_buf.getvalue()


def stamp_punishment_approval_qr(
    source_bytes: bytes,
    sequence: int,
    qr_png_bytes: bytes,
    approver_email: Optional[str] = None,
) -> bytes:
    """
    Menyuntikkan tanda tangan QR elektronik ke dalam slot RESUM yang ditentukan secara selektif via OOXML:
    - User sanianurmirza@gmail.com -> Slot AC4:AC6 (image3.png), AC3=Checked, AC7=NIA
    - User niamirza521@gmail.com   -> Slot AB4:AB6 (image2.png), AB3=Checked, AB7=CAN
    - Slot Approved (AA4:AA6, image1.png) -> Belum dikonfigurasi (ditolak aman dengan pengecualian eksplisit)

    Jika workbook adalah Reward & Punishment, otomatis dialihkan ke stamp_reward_punishment_approval_qr.
    Jika workbook bukan file XLSX dengan RESUM drawing, byte asli dikembalikan tanpa modifikasi.
    """
    if is_performance_xlsx(source_bytes):
        return stamp_performance_approval_qr(
            source_bytes=source_bytes,
            sequence=sequence,
            qr_png_bytes=qr_png_bytes,
            approver_email=approver_email,
        )

    if is_reward_punishment_xlsx(source_bytes):
        return stamp_reward_punishment_approval_qr(
            source_bytes=source_bytes,
            sequence=sequence,
            qr_png_bytes=qr_png_bytes,
            approver_email=approver_email,
        )

    resum_info = get_resum_structure(source_bytes)
    if not resum_info:
        return source_bytes

    clean_email = (approver_email or "").strip().lower()

    if clean_email in PUNISHMENT_SIGNATURE_CONFIG:
        slot = PUNISHMENT_SIGNATURE_CONFIG[clean_email]
    elif not clean_email:
        # Fallback berdasarkan urutan jika email tidak dilewatkan secara eksplisit
        if sequence == 1:
            slot = PUNISHMENT_SIGNATURE_CONFIG["sanianurmirza@gmail.com"]
        elif sequence == 2:
            slot = PUNISHMENT_SIGNATURE_CONFIG["niamirza521@gmail.com"]
        elif sequence == 3:
            raise ValueError("User untuk slot Approved belum dikonfigurasi.")
        else:
            raise ValueError("Mapping tanda tangan approver belum dikonfigurasi.")
    else:
        # Email dilewatkan tapi tidak ditemukan di konfigurasi
        if sequence == 3:
            raise ValueError("User untuk slot Approved belum dikonfigurasi.")
        else:
            raise ValueError("Mapping tanda tangan approver belum dikonfigurasi.")

    col = slot["col"]
    media_name = slot["media_target"]
    rel_id = slot["rel_id"]
    pic_id = slot["pic_id"]
    pic_name = slot["pic_name"]
    label_cell = slot.get("label_cell")
    role_label = slot.get("role_label")
    initial_cell = slot.get("initial_cell")
    initial = slot.get("initial")

    drawing_xml_path = resum_info["drawing_xml"]
    drawing_rels_path = resum_info["drawing_rels"]
    sheet_path = resum_info["sheet_path"]

    with zipfile.ZipFile(io.BytesIO(source_bytes), "r") as zin:
        files = {name: zin.read(name) for name in zin.namelist()}

    # 1. Pastikan [Content_Types].xml memiliki deklarasi ekstensi PNG
    if "[Content_Types].xml" in files:
        ct_xml = files["[Content_Types].xml"].decode("utf-8")
        if 'Extension="png"' not in ct_xml:
            ct_xml = ct_xml.replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>')
            files["[Content_Types].xml"] = ct_xml.encode("utf-8")

    # 2. Simpan file gambar QR ke xl/media/{media_name} (juga memperbarui in-cell richData image jika ada)
    files[f"xl/media/{media_name}"] = qr_png_bytes

    # 3. Perbarui relasi drawing
    if drawing_rels_path in files:
        d_rels = files[drawing_rels_path].decode("utf-8")
        rel_elem = f'<Relationship Id="{rel_id}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/{media_name}"/>'
        if f'Id="{rel_id}"' not in d_rels:
            d_rels = d_rels.replace('</Relationships>', f'{rel_elem}</Relationships>')
            files[drawing_rels_path] = d_rels.encode("utf-8")
    elif "xl/drawings/_rels/drawing1.xml.rels" in files:
        d_rels = files["xl/drawings/_rels/drawing1.xml.rels"].decode("utf-8")
        rel_elem = f'<Relationship Id="{rel_id}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/{media_name}"/>'
        if f'Id="{rel_id}"' not in d_rels:
            d_rels = d_rels.replace('</Relationships>', f'{rel_elem}</Relationships>')
            files["xl/drawings/_rels/drawing1.xml.rels"] = d_rels.encode("utf-8")

    # 4. Sisipkan anchor shape ke dalam drawing xml
    # Koordinat sel: row 4 hingga row 6 adalah 0-indexed row 3 hingga row 6
    anchor_xml = (
        f'<xdr:twoCellAnchor editAs="oneCell" '
        f'xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
        f'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        f'<xdr:from><xdr:col>{col}</xdr:col><xdr:colOff>15000</xdr:colOff><xdr:row>3</xdr:row><xdr:rowOff>15000</xdr:rowOff></xdr:from>'
        f'<xdr:to><xdr:col>{col + 1}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>6</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
        f'<xdr:pic>'
        f'<xdr:nvPicPr>'
        f'<xdr:cNvPr id="{pic_id}" name="{pic_name}"/>'
        f'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr>'
        f'</xdr:nvPicPr>'
        f'<xdr:blipFill>'
        f'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="{rel_id}"/>'
        f'<a:stretch><a:fillRect/></a:stretch>'
        f'</xdr:blipFill>'
        f'<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
        f'</xdr:pic>'
        f'<xdr:clientData/>'
        f'</xdr:twoCellAnchor>'
    )

    target_draw_xml = drawing_xml_path if drawing_xml_path in files else "xl/drawings/drawing1.xml"
    if target_draw_xml in files:
        d_xml = files[target_draw_xml].decode("utf-8")
        if f'name="{pic_name}"' not in d_xml:
            d_xml = d_xml.replace('</xdr:wsDr>', f'{anchor_xml}</xdr:wsDr>')
            files[target_draw_xml] = d_xml.encode("utf-8")

    # 5. Pastikan seluruh struktur visual blok tanda tangan RESUM (AA3:AC7) lengkap,
    # memiliki label 'Approved | Checked 2 | Checked 1', inisial dinamis, border box terlihat, row height, dan cell merge.
    _ensure_resum_signature_block(files, sheet_path, sequence=sequence, initial=initial)


    # 6. Tulis kembali ke zip buffer dengan kompresi standar
    out_buf = io.BytesIO()
    with zipfile.ZipFile(out_buf, "w", zipfile.ZIP_DEFLATED) as zout:
        for name, data in files.items():
            zout.writestr(name, data)

    return out_buf.getvalue()
