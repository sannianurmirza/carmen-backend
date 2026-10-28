"""
Layanan Terpusat Lembar Tanda Tangan Elektronik (ESign) untuk Seluruh Komponen Laporan CARMEN.
Mendukung 6 Komponen Laporan Operasional/Evaluasi:
1. Keterlambatan
2. Kelayakan / Menu
3. Kalori
4. Komplain
5. Audit
6. Kebersihan

Prinsip Utama:
- Lembar bernama tepat: 'ESign' (case-sensitive)
- Urutan lembar: Lembar TERAKHIR, Visible
- Format enterprise yang bersih, printable (A4 Portrait, fit-to-page 1x1)
- Susunan fisik visual tanda tangan: Approve | Checked | Checked (kiri ke kanan)
  * Approve (Seq 3) -> UNCONFIGURED (kosong aman)
  * Checked (Seq 2) -> CAN (sanianurmirza@gmail.com / niamirza521@gmail.com)
  * Checked (Seq 1) -> NIA (sanianurmirza@gmail.com)
- Riwayat Approval (Approval History) audit-friendly
- Menggunakan manipulasi selektif OOXML untuk menjaga integritas template asli
  (grafik Chart1, camera tool, macro, drawing, rels, dan formula tidak disentuh)
"""

from __future__ import annotations
import io
import os
import re
import zipfile
import xml.etree.ElementTree as ET
from datetime import datetime
from pathlib import Path
from typing import Dict, Any, List, Optional, Tuple

import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.drawing.image import Image as OpenpyxlImage
import mimetypes

mimetypes.add_type("image/x-wmf", ".wmf")
mimetypes.add_type("image/x-emf", ".emf")


# Konfigurasi approver resmi CARMEN
ESIGN_CONFIRMED_APPROVERS = {
    "sanianurmirza@gmail.com": {
        "sequence": 1,
        "role_label": "Checked 1",
        "initial": "NIA",
        "name": "Sania Nur Mirza",
        "email": "sanianurmirza@gmail.com",
    },
    "niamirza521@gmail.com": {
        "sequence": 2,
        "role_label": "Checked 2",
        "initial": "CAN",
        "name": "Nia Mirza",
        "email": "niamirza521@gmail.com",
    },
}

ESIGN_APPROVED_SLOT = {
    "sequence": 3,
    "role_label": "Approved",
    "initial": "",
    "name": "-",
    "email": "-",
    "is_configured": False,
}


def build_standalone_esign_wb(
    metadata: Optional[Dict[str, Any]] = None,
    approvers_status: Optional[List[Dict[str, Any]]] = None,
) -> openpyxl.Workbook:
    """
    Membangun workbook independen yang HANYA berisi lembar 'ESign'
    dengan desain sederhana sesuai referensi pengguna:
    - Hanya tabel tanda tangan 4 kolom: Approved | Checked 2 | Checked 1 | Prepared
    - Plain white worksheet, thin black borders, centered text, NO fill colors
    - Area tanda tangan tengah cukup tinggi untuk gambar QR
    - Baris bawah untuk inisial/nama/tanggal jika disetujui
    - Tanpa blok metadata, riwayat approval, judul besar, atau elemen dekoratif.
    """
    meta = metadata or {}
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "ESign"
    
    # 1. Page Setup & Presentation
    ws.views.sheetView[0].showGridLines = True
    ws.page_setup.orientation = ws.ORIENTATION_PORTRAIT
    ws.page_setup.paperSize = ws.PAPERSIZE_A4
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 1
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    
    ws.page_margins.left = 0.5
    ws.page_margins.right = 0.5
    ws.page_margins.top = 0.6
    ws.page_margins.bottom = 0.6

    # 2. Lebar Kolom
    ws.column_dimensions["A"].width = 3
    ws.column_dimensions["B"].width = 19
    ws.column_dimensions["C"].width = 19
    ws.column_dimensions["D"].width = 19
    ws.column_dimensions["E"].width = 19

    # 3. Gaya Border & Font (Sesuai Referensi Pengguna)
    thin_black = Side(style="thin", color="000000")
    border_box = Border(left=thin_black, right=thin_black, top=thin_black, bottom=thin_black)
    
    font_header = Font(name="Calibri", size=11, bold=False, color="000000")
    font_bottom = Font(name="Calibri", size=10, bold=False, color="000000")
    align_center = Alignment(horizontal="center", vertical="center")

    # 4. Tinggi Baris
    ws.row_dimensions[1].height = 15
    ws.row_dimensions[2].height = 15
    ws.row_dimensions[3].height = 15
    ws.row_dimensions[4].height = 24  # Header row
    for r in range(5, 9):
        ws.row_dimensions[r].height = 20  # Rows 5,6,7,8 -> Total 80 pt
    ws.row_dimensions[9].height = 20  # Bottom row

    # 5. Header Tabel (Susunan Fisik Kiri ke Kanan):
    # B4: Approved (Sequence 3)
    # C4: Checked 2 (Sequence 2 - CAN)
    # D4: Checked 1 (Sequence 1 - NIA)
    # E4: Prepared (Non-approval role / Pembuat laporan)
    headers = [
        ("B4", "Approved"),
        ("C4", "Checked 2"),
        ("D4", "Checked 1"),
        ("E4", "Prepared"),
    ]
    for cell_ref, label in headers:
        cell = ws[cell_ref]
        cell.value = label
        cell.font = font_header
        cell.alignment = align_center
        cell.border = border_box

    # 6. Area Kotak Tanda Tangan (Rows 5-8)
    for r in range(5, 9):
        for col_l in ["B", "C", "D", "E"]:
            ws[f"{col_l}{r}"].border = border_box
            ws[f"{col_l}{r}"].alignment = align_center

    ws.merge_cells("B5:B8")
    ws.merge_cells("C5:C8")
    ws.merge_cells("D5:D8")
    ws.merge_cells("E5:E8")

    # 7. Baris Bawah (Row 9)
    for col_l in ["B", "C", "D", "E"]:
        c = ws[f"{col_l}9"]
        c.border = border_box
        c.alignment = align_center
        c.font = font_bottom
        c.value = ""

    # Preparer identity jika ada sumber valid dari metadata
    preparer_name = meta.get("prepared_by")
    if preparer_name and isinstance(preparer_name, str) and preparer_name.strip() and preparer_name.strip() != "-":
        ws["E9"] = preparer_name.strip()

    # 8. Memproses Data Approver (Inkremental & Berurutan)
    approvers = approvers_status or []
    appr_map = {a.get("sequence"): a for a in approvers}

    # Sequence 1: Checked 1 -> NIA (Kolom D)
    seq1_data = appr_map.get(1)
    if seq1_data and seq1_data.get("status") == "APPROVED":
        ws["D9"] = seq1_data.get("initial") or "NIA"
        qr_bytes = seq1_data.get("qr_png_bytes")
        if qr_bytes:
            img1 = OpenpyxlImage(io.BytesIO(qr_bytes))
            img1.width = 80
            img1.height = 80
            ws.add_image(img1, "D5")

    # Sequence 2: Checked 2 -> CAN (Kolom C)
    seq2_data = appr_map.get(2)
    if seq2_data and seq2_data.get("status") == "APPROVED":
        ws["C9"] = seq2_data.get("initial") or "CAN"
        qr_bytes = seq2_data.get("qr_png_bytes")
        if qr_bytes:
            img2 = OpenpyxlImage(io.BytesIO(qr_bytes))
            img2.width = 80
            img2.height = 80
            ws.add_image(img2, "C5")

    # Sequence 3: Approved -> Kolom B (UNCONFIGURED)
    seq3_data = appr_map.get(3)
    if seq3_data and seq3_data.get("status") == "APPROVED":
        ws["B9"] = seq3_data.get("initial", "")
        qr_bytes = seq3_data.get("qr_png_bytes")
        if qr_bytes:
            img3 = OpenpyxlImage(io.BytesIO(qr_bytes))
            img3.width = 80
            img3.height = 80
            ws.add_image(img3, "B5")

    return wb


def generate_standalone_esign_bytes(
    metadata: Dict[str, Any],
    approvers_status: Optional[List[Dict[str, Any]]] = None,
) -> bytes:
    """Helper untuk membuat byte stream XLSX dari lembar ESign."""
    wb = build_standalone_esign_wb(metadata, approvers_status)
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


def attach_esign_to_workbook_bytes(
    source_bytes: bytes,
    metadata: Dict[str, Any],
    approvers_status: Optional[List[Dict[str, Any]]] = None,
    sheet_name: str = "ESign",
) -> bytes:
    """
    Menyuntikkan lembar 'ESign' secara non-destruktif ke dalam workbook target via OOXML.
    - Menjamin lembar 'ESign' berada pada posisi paling AKHIR (LAST sheet).
    - Mempertahankan seluruh lembar bisnis, rumus, grafik Chart1, camera tools, dan EMF.
    - Jika lembar 'ESign' sudah ada sebelumnya, akan digantikan secara bersih dengan versi terbarukan.
    """
    if not (isinstance(source_bytes, bytes) and source_bytes.startswith(b"PK\x03\x04")):
        raise ValueError("Data sumber bukan file ZIP/XLSX yang valid.")

    esign_wb_bytes = generate_standalone_esign_bytes(metadata, approvers_status)
    
    with zipfile.ZipFile(io.BytesIO(source_bytes), "r") as z_tgt:
        tgt_files = {name: z_tgt.read(name) for name in z_tgt.namelist()}
        
    with zipfile.ZipFile(io.BytesIO(esign_wb_bytes), "r") as z_es:
        es_files = {name: z_es.read(name) for name in z_es.namelist()}

    wb_xml = tgt_files["xl/workbook.xml"].decode("utf-8")
    wb_rels_xml = tgt_files["xl/_rels/workbook.xml.rels"].decode("utf-8")
    ct_xml = tgt_files["[Content_Types].xml"].decode("utf-8")

    # Jika lembar ESign sudah ada sebelumnya di workbook target, hapus entitas lamanya terlebih dahulu
    existing_esign_match = re.search(r'<sheet\s+name="' + re.escape(sheet_name) + r'"\s+sheetId="(\d+)"(?:\s+state="[^"]*")?\s+r:id="([^"]+)"\s*/>', wb_xml)
    if not existing_esign_match:
        # Coba format atribut terbalik r:id sebelum sheetId
        existing_esign_match = re.search(r'<sheet\s+name="' + re.escape(sheet_name) + r'"(?:\s+state="[^"]*")?\s+r:id="([^"]+)"\s+sheetId="(\d+)"\s*/>', wb_xml)
        if existing_esign_match:
            old_rid = existing_esign_match.group(1)
            old_sheet_id = existing_esign_match.group(2)
        else:
            old_rid = None
            old_sheet_id = None
    else:
        old_sheet_id = existing_esign_match.group(1)
        old_rid = existing_esign_match.group(2)

    if existing_esign_match:
        # Hapus deklarasi sheet dari workbook.xml
        wb_xml = wb_xml.replace(existing_esign_match.group(0), "")
        
        # Temukan file worksheet target dari old_rid
        old_rel_match = re.search(r'<Relationship\s+Id="' + re.escape(old_rid) + r'"[^>]*Target="([^"]+)"', wb_rels_xml)
        if old_rel_match:
            old_target = old_rel_match.group(1)
            old_sheet_file_path = f"xl/{old_target}" if not old_target.startswith("xl/") else old_target
            if old_sheet_file_path in tgt_files:
                del tgt_files[old_sheet_file_path]
            # Hapus rels worksheet jika ada
            sh_base = os.path.basename(old_sheet_file_path)
            sh_rels_path = f"xl/worksheets/_rels/{sh_base}.rels"
            if sh_rels_path in tgt_files:
                del tgt_files[sh_rels_path]
            # Hapus dari [Content_Types].xml
            ct_xml = re.sub(r'<Override\s+PartName="/?' + re.escape(old_sheet_file_path) + r'"[^>]*/>', "", ct_xml)
        
        # Hapus relasi dari wb_rels_xml
        wb_rels_xml = re.sub(r'<Relationship\s+Id="' + re.escape(old_rid) + r'"[^>]*/>', "", wb_rels_xml)

    # Hitung alokasi nomor baru untuk file worksheet ESign
    existing_sheet_files = [n for n in tgt_files.keys() if n.startswith("xl/worksheets/sheet") and n.endswith(".xml")]
    sheet_nums = []
    for s in existing_sheet_files:
        m = re.search(r'sheet(\d+)\.xml', s)
        if m:
            sheet_nums.append(int(m.group(1)))
    next_sheet_num = max(sheet_nums, default=0) + 1
    new_sheet_file = f"sheet{next_sheet_num}.xml"
    new_sheet_path = f"xl/worksheets/{new_sheet_file}"
    new_sheet_rels_path = f"xl/worksheets/_rels/{new_sheet_file}.rels"

    # Hitung alokasi nomor baru untuk drawing ESign
    existing_drawings = [n for n in tgt_files.keys() if n.startswith("xl/drawings/drawing") and n.endswith(".xml")]
    drawing_nums = []
    for d in existing_drawings:
        m = re.search(r'drawing(\d+)\.xml', d)
        if m:
            drawing_nums.append(int(m.group(1)))
    next_drawing_num = max(drawing_nums, default=0) + 1
    new_drawing_file = f"drawing{next_drawing_num}.xml"
    new_drawing_path = f"xl/drawings/{new_drawing_file}"
    new_drawing_rels_path = f"xl/drawings/_rels/{new_drawing_file}.rels"

    # Hitung alokasi penamaan media gambar ESign
    existing_media = [n for n in tgt_files.keys() if n.startswith("xl/media/")]
    media_nums = []
    for m in existing_media:
        m_match = re.search(r'image(\d+)\.', m)
        if m_match:
            media_nums.append(int(m_match.group(1)))
    next_media_num = max(media_nums, default=0) + 1

    # Cari nomor rId yang belum terpakai di workbook.xml.rels
    existing_rids = [int(m) for m in re.findall(r'rId(\d+)', wb_rels_xml)]
    next_rid_num = max(existing_rids, default=0) + 1
    new_sheet_rid = f"rId{next_rid_num}"

    # Cari sheetId integer unik
    existing_sheet_ids = [int(m) for m in re.findall(r'sheetId="(\d+)"', wb_xml)]
    next_sheet_id = max(existing_sheet_ids, default=0) + 1

    # Ekstrak data XML lembar ESign dari es_files
    es_sheet_xml = es_files["xl/worksheets/sheet1.xml"].decode("utf-8")
    has_drawings = "xl/drawings/drawing1.xml" in es_files

    if has_drawings:
        es_drawing_xml = es_files["xl/drawings/drawing1.xml"].decode("utf-8")
        es_drawing_rels_xml = es_files.get("xl/drawings/_rels/drawing1.xml.rels", b"").decode("utf-8")
        
        # Salin dan remap media
        media_map = {}
        es_media_files = [n for n in es_files.keys() if n.startswith("xl/media/")]
        for mf in sorted(es_media_files):
            ext = mf.split(".")[-1]
            new_mf_name = f"image{next_media_num}.{ext}"
            tgt_files[f"xl/media/{new_mf_name}"] = es_files[mf]
            old_mf_base = os.path.basename(mf)
            media_map[old_mf_base] = new_mf_name
            next_media_num += 1
            
        # Perbarui tautan relasi media di drawing rels secara akurat
        for old_m, new_m in media_map.items():
            pattern = r'Target="(?:\.\./|/xl/)?media/' + re.escape(old_m) + r'"'
            es_drawing_rels_xml = re.sub(pattern, f'Target="../media/{new_m}"', es_drawing_rels_xml)
            
        tgt_files[new_drawing_path] = es_drawing_xml.encode("utf-8")
        tgt_files[new_drawing_rels_path] = es_drawing_rels_xml.encode("utf-8")
        
        # Buat worksheet rels untuk drawing ESign
        sheet_drawing_rid = "rId1"
        sheet_rels_xml = (
            f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
            f'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            f'<Relationship Id="{sheet_drawing_rid}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/{new_drawing_file}"/>'
            f'</Relationships>'
        )
        tgt_files[new_sheet_rels_path] = sheet_rels_xml.encode("utf-8")
        
        # Pastikan tag drawing ada di dalam es_sheet_xml
        if "<drawing" not in es_sheet_xml:
            es_sheet_xml = es_sheet_xml.replace("</worksheet>", f'<drawing r:id="{sheet_drawing_rid}"/>\n</worksheet>')

    tgt_files[new_sheet_path] = es_sheet_xml.encode("utf-8")

    # Pastikan tipe konten PNG terdaftar
    if 'Extension="png"' not in ct_xml:
        ct_xml = ct_xml.replace("</Types>", '<Default Extension="png" ContentType="image/png"/></Types>')

    # Daftarkan part worksheet baru ke [Content_Types].xml
    sheet_part = f"/xl/worksheets/{new_sheet_file}"
    if f'PartName="{sheet_part}"' not in ct_xml:
        ct_xml = ct_xml.replace("</Types>", f'<Override PartName="{sheet_part}" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')

    # Daftarkan drawing baru jika ada
    if has_drawings:
        draw_part = f"/xl/drawings/{new_drawing_file}"
        if f'PartName="{draw_part}"' not in ct_xml:
            ct_xml = ct_xml.replace("</Types>", f'<Override PartName="{draw_part}" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>')

    tgt_files["[Content_Types].xml"] = ct_xml.encode("utf-8")

    # Daftarkan ke xl/_rels/workbook.xml.rels
    rel_entry = f'<Relationship Id="{new_sheet_rid}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/{new_sheet_file}"/>'
    wb_rels_xml = wb_rels_xml.replace("</Relationships>", f"{rel_entry}</Relationships>")
    tgt_files["xl/_rels/workbook.xml.rels"] = wb_rels_xml.encode("utf-8")

    # Sisipkan sebagai LEMBAR TERAKHIR di dalam <sheets> pada xl/workbook.xml
    sheet_entry = f'<sheet name="{sheet_name}" sheetId="{next_sheet_id}" r:id="{new_sheet_rid}"/>'
    wb_xml = wb_xml.replace("</sheets>", f"{sheet_entry}</sheets>")
    tgt_files["xl/workbook.xml"] = wb_xml.encode("utf-8")

    # Pastikan xl/styles.xml memiliki jumlah cellXfs yang cukup untuk menampung style ESign (mencegah IndexError)
    if "xl/styles.xml" in tgt_files:
        st_xml = tgt_files["xl/styles.xml"].decode("utf-8")
        m_count = re.search(r'<cellXfs count="(\d+)">', st_xml)
        if m_count:
            cur_count = int(m_count.group(1))
            if cur_count < 35:
                needed = 35 - cur_count
                pad_xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' * needed
                st_xml = st_xml.replace(f'<cellXfs count="{cur_count}">', f'<cellXfs count="{cur_count + needed}">')
                st_xml = st_xml.replace("</cellXfs>", f"{pad_xfs}</cellXfs>")
                tgt_files["xl/styles.xml"] = st_xml.encode("utf-8")

    # Kemas kembali ke zip buffer
    out_buf = io.BytesIO()
    with zipfile.ZipFile(out_buf, "w", zipfile.ZIP_DEFLATED) as zout:
        for name, data in tgt_files.items():
            zout.writestr(name, data)

    return out_buf.getvalue()


def is_three_stage_esign_request(approval_request: Any, db: Any = None) -> bool:
    """
    Memeriksa apakah pengajuan approval ini menggunakan lembar kontrak ESign 3-tahap
    (Keterlambatan, Kelayakan, Kalori, Komplain, Audit, Kebersihan).
    Laporan-laporan ini memiliki lembar 'ESign' dengan layout 4 kolom:
    Approved (Seq 3) | Checked 2 (Seq 2) | Checked 1 (Seq 1) | Prepared
    dan mensyaratkan tepat 3 sekuens persetujuan sebelum status dokumen dapat menjadi APPROVED.
    """
    if not approval_request:
        return False

    # 1. Cek dari file yang tersimpan di disk
    stored_path = getattr(approval_request, "stored_file_path", None)
    if stored_path:
        base_dir = Path(__file__).resolve().parent
        candidate = (base_dir / "uploads" / stored_path).resolve()
        if candidate.exists():
            try:
                file_bytes = candidate.read_bytes()
                from approval_excel_qr import is_punishment_xlsx
                if is_punishment_xlsx(file_bytes):
                    return False
                if file_bytes.startswith(b"PK\x03\x04"):
                    with zipfile.ZipFile(io.BytesIO(file_bytes), "r") as z:
                        if "xl/workbook.xml" in z.namelist():
                            wb_xml = z.read("xl/workbook.xml").decode("utf-8", errors="ignore")
                            if re.search(r'name=["\']ESign["\']', wb_xml):
                                return True
            except Exception:
                pass

    # 2. Cek nama file jika file fisik belum dapat dibaca
    orig_name = (getattr(approval_request, "original_filename", "") or "").lower()
    esign_keywords = ["keterlambatan", "kelayakan", "kalori", "komplain", "audit", "kebersihan"]
    if any(k in orig_name for k in esign_keywords) and orig_name.endswith(".xlsx"):
        return True

    return False


def stamp_esign_approval_qr(
    source_bytes: bytes,
    approval_request: Any,
    sequence: int,
    cycle: int,
    revision_number: int,
    approver_user: Any,
    qr_png_bytes: bytes,
    now: datetime,
    db: Any = None,
) -> bytes:
    """
    Menyuntikkan tanda tangan QR permanen approver ke lembar ESign pada workbook laporan.
    Menegakkan aturan bisnis:
    - Sequence 1: sanianurmirza@gmail.com -> Checked 1 -> NIA
    - Sequence 2: niamirza521@gmail.com -> Checked 2 -> CAN
    - Sequence 3: Approve -> UNCONFIGURED (hanya dapat di-stamp jika user approver terdaftar & sah)
    - Bersifat inkremental: Sequence 2 melestarikan Sequence 1, Sequence 3 melestarikan Sequence 1 & 2.
    """
    clean_email = (approver_user.email or "").strip().lower() if approver_user else ""
    
    # Validasi sequence 3 jika belum terkonfigurasi atau tidak sah
    if sequence == 3:
        if not approver_user or not getattr(approver_user, "is_active", False) or getattr(approver_user, "role", None) != "approver":
            raise ValueError("User untuk slot Approved belum dikonfigurasi atau tidak berwenang.")
    elif sequence > 3:
        raise ValueError(f"Sequence {sequence} tidak didukung pada lembar ESign.")
        
    # Ambil status seluruh approver untuk siklus & revisi ini
    from models import ApprovalRequestApprover, ApprovalArtifact, UserSignatureQR
    from sqlmodel import select
    
    approvers_records = []
    if db is not None:
        approvers_records = db.exec(
            select(ApprovalRequestApprover)
            .where(
                ApprovalRequestApprover.approval_request_id == approval_request.id,
                ApprovalRequestApprover.cycle == cycle,
            )
            .order_by(ApprovalRequestApprover.sequence.asc())
        ).all()

    # Susun list status approver
    approvers_status: List[Dict[str, Any]] = []
    
    # Sequence 1
    seq1_record = next((a for a in approvers_records if a.sequence == 1), None)
    seq1_approved = (sequence >= 1) or (seq1_record and seq1_record.status == "APPROVED")
    
    # Ambil QR seq 1 jika sudah disetujui sebelumnya
    seq1_qr_bytes = None
    if seq1_approved:
        if sequence == 1:
            seq1_qr_bytes = qr_png_bytes
            seq1_at_str = now.strftime("%Y-%m-%d %H:%M UTC")
        else:
            seq1_at_str = "-"
            # 1. Coba ekstrak QR gambar sequence 1 dari media source_bytes yang sudah ada
            try:
                with zipfile.ZipFile(io.BytesIO(source_bytes), "r") as z_src:
                    media_candidates = [m for m in sorted(z_src.namelist()) if m.startswith("xl/media/") and m.lower().endswith(".png")]
                    if media_candidates:
                        seq1_qr_bytes = z_src.read(media_candidates[-1])
            except Exception:
                seq1_qr_bytes = None

            # 2. Ambil dari artefak sequence 1 jika db tersedia
            if db is not None:
                art1 = db.exec(
                    select(ApprovalArtifact).where(
                        ApprovalArtifact.approval_request_id == approval_request.id,
                        ApprovalArtifact.cycle == cycle,
                        ApprovalArtifact.sequence == 1,
                    )
                ).first()
                if art1:
                    seq1_at_str = art1.approved_at.strftime("%Y-%m-%d %H:%M UTC")
                    if not seq1_qr_bytes:
                        sig1 = db.exec(
                            select(UserSignatureQR).where(
                                UserSignatureQR.user_id == art1.approver_user_id,
                                UserSignatureQR.is_active == True,
                            )
                        ).first()
                        if sig1 and sig1.qr_image_path:
                            q_path = os.path.join(os.path.dirname(__file__), "uploads", sig1.qr_image_path)
                            if os.path.exists(q_path):
                                with open(q_path, "rb") as qf:
                                    seq1_qr_bytes = qf.read()
            if seq1_at_str == "-":
                seq1_at_str = "Approved (Cycle 1)"

        approvers_status.append({
            "sequence": 1,
            "role_label": "Checked 1",
            "initial": "NIA",
            "name": seq1_record.name if seq1_record else "Sania Nur Mirza",
            "email": "sanianurmirza@gmail.com",
            "status": "APPROVED",
            "approved_at": seq1_at_str,
            "qr_png_bytes": seq1_qr_bytes,
        })
    else:
        approvers_status.append({
            "sequence": 1,
            "role_label": "Checked 1",
            "initial": "NIA",
            "name": seq1_record.name if seq1_record else "Sania Nur Mirza",
            "email": "sanianurmirza@gmail.com",
            "status": seq1_record.status if seq1_record else "PENDING",
            "approved_at": "-",
            "qr_png_bytes": None,
        })

    # Sequence 2
    seq2_record = next((a for a in approvers_records if a.sequence == 2), None)
    seq2_approved = (sequence >= 2) or (seq2_record and seq2_record.status == "APPROVED")
    
    if seq2_approved:
        if sequence == 2:
            seq2_qr_bytes = qr_png_bytes
            seq2_at_str = now.strftime("%Y-%m-%d %H:%M UTC")
        else:
            # sequence == 3: preserve Seq 2 QR
            seq2_at_str = "-"
            seq2_qr_bytes = None
            if db is not None:
                art2 = db.exec(
                    select(ApprovalArtifact).where(
                        ApprovalArtifact.approval_request_id == approval_request.id,
                        ApprovalArtifact.cycle == cycle,
                        ApprovalArtifact.sequence == 2,
                    )
                ).first()
                if art2:
                    seq2_at_str = art2.approved_at.strftime("%Y-%m-%d %H:%M UTC")
                    sig2 = db.exec(
                        select(UserSignatureQR).where(
                            UserSignatureQR.user_id == art2.approver_user_id,
                            UserSignatureQR.is_active == True,
                        )
                    ).first()
                    if sig2 and sig2.qr_image_path:
                        q_path = os.path.join(os.path.dirname(__file__), "uploads", sig2.qr_image_path)
                        if os.path.exists(q_path):
                            with open(q_path, "rb") as qf:
                                seq2_qr_bytes = qf.read()
            if seq2_at_str == "-":
                seq2_at_str = "Approved (Cycle 1)"

        approvers_status.append({
            "sequence": 2,
            "role_label": "Checked 2",
            "initial": "CAN",
            "name": seq2_record.name if seq2_record else "Nia Mirza",
            "email": "niamirza521@gmail.com",
            "status": "APPROVED",
            "approved_at": seq2_at_str,
            "qr_png_bytes": seq2_qr_bytes,
        })
    else:
        approvers_status.append({
            "sequence": 2,
            "role_label": "Checked 2",
            "initial": "CAN",
            "name": seq2_record.name if seq2_record else "Nia Mirza",
            "email": "niamirza521@gmail.com",
            "status": seq2_record.status if seq2_record else "WAITING",
            "approved_at": "-",
            "qr_png_bytes": None,
        })

    # Sequence 3
    seq3_record = next((a for a in approvers_records if a.sequence == 3), None)
    seq3_approved = (sequence == 3) or (seq3_record and seq3_record.status == "APPROVED")
    
    if seq3_approved:
        seq3_qr_bytes = qr_png_bytes if sequence == 3 else None
        if not seq3_qr_bytes and db is not None:
            art3 = db.exec(
                select(ApprovalArtifact).where(
                    ApprovalArtifact.approval_request_id == approval_request.id,
                    ApprovalArtifact.cycle == cycle,
                    ApprovalArtifact.sequence == 3,
                )
            ).first()
            if art3:
                sig3 = db.exec(
                    select(UserSignatureQR).where(
                        UserSignatureQR.user_id == art3.approver_user_id,
                        UserSignatureQR.is_active == True,
                    )
                ).first()
                if sig3 and sig3.qr_image_path:
                    q_path = os.path.join(os.path.dirname(__file__), "uploads", sig3.qr_image_path)
                    if os.path.exists(q_path):
                        with open(q_path, "rb") as qf:
                            seq3_qr_bytes = qf.read()

        seq3_at_str = now.strftime("%Y-%m-%d %H:%M UTC") if sequence == 3 else "-"
        seq3_initial = ""
        u_for_initial = approver_user if sequence == 3 else None
        if not u_for_initial and seq3_record and seq3_record.matched_user_id and db is not None:
            from models import User
            u_for_initial = db.get(User, seq3_record.matched_user_id)

        if u_for_initial and getattr(u_for_initial, "nama", None):
            parts = u_for_initial.nama.strip().split()
            seq3_initial = "".join(p[0].upper() for p in parts[:3])
        elif seq3_record and seq3_record.name:
            parts = seq3_record.name.strip().split()
            seq3_initial = "".join(p[0].upper() for p in parts[:3])

        approvers_status.append({
            "sequence": 3,
            "role_label": "Approved",
            "initial": seq3_initial,
            "name": seq3_record.name if seq3_record else (approver_user.nama if approver_user else "-"),
            "email": seq3_record.email if seq3_record else (approver_user.email if approver_user else "-"),
            "status": "APPROVED",
            "approved_at": seq3_at_str,
            "qr_png_bytes": seq3_qr_bytes,
        })
    else:
        approvers_status.append({
            "sequence": 3,
            "role_label": "Approved",
            "initial": "",
            "name": seq3_record.name if seq3_record else "-",
            "email": seq3_record.email if seq3_record else "-",
            "status": seq3_record.status if seq3_record else "UNCONFIGURED",
            "approved_at": "-",
            "qr_png_bytes": None,
        })

    # Status keseluruhan dokumen dalam metadata
    if is_three_stage_esign_request(approval_request, db):
        if seq1_approved and seq2_approved and seq3_approved:
            doc_status = "APPROVED"
        else:
            doc_status = f"APPROVAL_{sequence}"
    else:
        total_seqs = len(approvers_records) if approvers_records else 2
        if sequence >= total_seqs:
            doc_status = "APPROVED"
        else:
            doc_status = f"APPROVAL_{sequence}"

    # Metadata dokumen
    req_date = approval_request.requested_at if hasattr(approval_request, "requested_at") and approval_request.requested_at else now
    doc_metadata = {
        "document": Path(approval_request.original_filename).stem if hasattr(approval_request, "original_filename") else "Laporan Operasional",
        "period": getattr(approval_request, "note", None) or "Periode Berjalan",
        "generated_date": req_date.strftime("%Y-%m-%d %H:%M UTC") if hasattr(req_date, "strftime") else str(req_date),
        "requester": getattr(approval_request, "requester_name", "-"),
        "approval_id": f"REQ-{approval_request.id}" if hasattr(approval_request, "id") and approval_request.id else "-",
        "revision": f"Cycle {cycle} Rev {revision_number}",
        "status": doc_status,
    }

    # Pasang lembar ESign yang telah diperbarui ke workbook
    updated_bytes = attach_esign_to_workbook_bytes(
        source_bytes=source_bytes,
        metadata=doc_metadata,
        approvers_status=approvers_status,
        sheet_name="ESign",
    )

    return updated_bytes
