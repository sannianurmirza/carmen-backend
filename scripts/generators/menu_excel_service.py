"""
backend/menu_excel_service.py
Service pembuatan dan penandatanganan berkas Excel Rencana Menu Bulanan PT Jatim Autocomp Indonesia.

Spesifikasi & Kebijakan Bisnis:
1. Header Dokumen Resmi:
   - PT. JATIM AUTOCOMP INDONESIA
   - RENCANA MENU BULANAN — {catering_name.upper()}
   - Periode Berlaku: {effective_menu_period}
2. Dua Sheet Menu Operasional:
   - "SHIFT PAGI" dan "SHIFT MALAM"
   - Kalender matriks dinamis (mendukung 28, 29, 30, dan 31 hari dalam 4-6 minggu kalender)
   - Kolom 1..7 untuk Senin s/d Minggu
   - Base row 15, 23, 31, 39, 47 (dan 55 jika ada minggu ke-6)
   - Tanggal di base, Hari di base + 1, 6 slot komponen menu di base + 2 s/d base + 7
3. Lembar ESign Tiga Kolom:
   - Tepat 3 Kolom: APPROVED (B) | CHECKED 2 (C) | CHECKED 1 (D)
   - TANPA kolom PREPARED (E)
   - Stamping QR permanen dari UserSignatureQR secara inkremental dan non-destruktif
"""

import io
import os
import re
import calendar
import datetime
from pathlib import Path
from typing import List, Dict, Any, Optional

import openpyxl
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.drawing.image import Image as OpenpyxlImage
from sqlmodel import Session, select

from models import (
    MonthlyMenuPlan,
    MonthlyMenuItem,
    Catering,
    User,
    ApprovalRequest,
    ApprovalRequestApprover,
    ApprovalArtifact,
    UserSignatureQR,
)


INDONESIAN_DAYS = ["SENIN", "SELASA", "RABU", "KAMIS", "JUMAT", "SABTU", "MINGGU"]


def build_monthly_menu_workbook(
    plan: MonthlyMenuPlan,
    catering: Catering,
    items: List[MonthlyMenuItem],
    approvers_status: Optional[List[Dict[str, Any]]] = None,
) -> openpyxl.Workbook:
    """
    Membuat workbook Excel Rencana Menu Bulanan lengkap dengan dua sheet menu
    ('SHIFT PAGI', 'SHIFT MALAM') dan sheet tanda tangan ('ESign' 3-kolom).
    """
    wb = openpyxl.Workbook()
    # Hapus sheet default pertama
    default_sheet = wb.active

    # Kelompokkan item berdasarkan shift dan tanggal
    # key: (shift, iso_date_str) -> dict of item_order (1..6) -> menu_name
    pagi_items: Dict[str, Dict[int, str]] = {}
    malam_items: Dict[str, Dict[int, str]] = {}

    for it in items:
        date_str = it.menu_date.isoformat() if isinstance(it.menu_date, (datetime.date, datetime.datetime)) else str(it.menu_date)
        shift_norm = (it.shift or "").strip().lower()
        target_dict = pagi_items if shift_norm == "pagi" else malam_items
        if date_str not in target_dict:
            target_dict[date_str] = {}
        target_dict[date_str][it.item_order] = it.menu_name

    # 1. Bangun sheet SHIFT PAGI
    ws_pagi = wb.create_sheet(title="SHIFT PAGI")
    _populate_menu_sheet(
        ws=ws_pagi,
        shift_title="SHIFT PAGI",
        catering_name=catering.nama,
        plan=plan,
        items_by_date=pagi_items,
    )

    # 2. Bangun sheet SHIFT MALAM
    ws_malam = wb.create_sheet(title="SHIFT MALAM")
    _populate_menu_sheet(
        ws=ws_malam,
        shift_title="SHIFT MALAM",
        catering_name=catering.nama,
        plan=plan,
        items_by_date=malam_items,
    )

    # Hapus sheet bawaan awal
    if default_sheet is not None and default_sheet in wb.worksheets:
        wb.remove(default_sheet)

    # 3. Bangun sheet ESign 3 Kolom
    ws_esign = wb.create_sheet(title="ESign")
    _populate_three_column_esign_sheet(
        ws=ws_esign,
        plan=plan,
        catering_name=catering.nama,
        approvers_status=approvers_status,
    )

    return wb


def _populate_menu_sheet(
    ws: openpyxl.worksheet.worksheet.Worksheet,
    shift_title: str,
    catering_name: str,
    plan: MonthlyMenuPlan,
    items_by_date: Dict[str, Dict[int, str]],
):
    """
    Mengisi sheet menu kalender (SHIFT PAGI / SHIFT MALAM) sesuai template resmi PT JAI.
    """
    ws.views.sheetView[0].showGridLines = True
    ws.page_setup.orientation = ws.ORIENTATION_LANDSCAPE
    ws.page_setup.paperSize = ws.PAPERSIZE_A4
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 1
    ws.sheet_properties.pageSetUpPr.fitToPage = True

    # Styling definitions
    font_company = Font(name="Calibri", size=16, bold=True, color="1F4E79")
    font_title = Font(name="Calibri", size=13, bold=True, color="000000")
    font_subtitle = Font(name="Calibri", size=11, bold=True, color="333333")
    font_day_header = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
    font_date_num = Font(name="Calibri", size=11, bold=True, color="000000")
    font_day_name = Font(name="Calibri", size=9, bold=True, color="555555")
    font_menu_item = Font(name="Calibri", size=9, bold=False, color="000000")

    fill_day_header = PatternFill(start_color="1F4E79", end_color="1F4E79", fill_type="solid")
    fill_date_box = PatternFill(start_color="D9E1F2", end_color="D9E1F2", fill_type="solid")
    fill_weekend_box = PatternFill(start_color="FCE4D6", end_color="FCE4D6", fill_type="solid")
    fill_empty = PatternFill(start_color="F2F2F2", end_color="F2F2F2", fill_type="solid")

    thin_black = Side(style="thin", color="000000")
    thin_gray = Side(style="thin", color="D3D3D3")
    border_cell = Border(left=thin_black, right=thin_black, top=thin_black, bottom=thin_black)
    border_menu = Border(left=thin_black, right=thin_black, top=thin_gray, bottom=thin_gray)

    align_center = Alignment(horizontal="center", vertical="center", wrap_text=True)
    align_left = Alignment(horizontal="left", vertical="center", wrap_text=True)

    # Set column widths for Mon..Sun (Cols B..H = 2..8, Col A as margin)
    ws.column_dimensions["A"].width = 3
    for col_idx in range(1, 8):
        col_letter = openpyxl.utils.get_column_letter(col_idx)
        ws.column_dimensions[col_letter].width = 20

    # Header Baris Dokumen
    ws.row_dimensions[1].height = 24
    ws.row_dimensions[2].height = 20
    ws.row_dimensions[3].height = 18
    ws.row_dimensions[4].height = 12

    ws["A1"] = "PT. JATIM AUTOCOMP INDONESIA"
    ws["A1"].font = font_company
    ws["A1"].alignment = Alignment(horizontal="left", vertical="center")

    ws["A2"] = f"RENCANA MENU BULANAN — {catering_name.upper()} ({shift_title})"
    ws["A2"].font = font_title
    ws["A2"].alignment = Alignment(horizontal="left", vertical="center")

    ws["A3"] = f"Periode Berlaku: {plan.effective_menu_period} | Versi: {plan.version} | Status: {plan.status}"
    ws["A3"].font = font_subtitle
    ws["A3"].alignment = Alignment(horizontal="left", vertical="center")

    # Baris 14: Header Hari (SENIN s/d MINGGU) di Kolom 1..7
    ws.row_dimensions[14].height = 22
    for c_idx, day_name in enumerate(INDONESIAN_DAYS, start=1):
        cell = ws.cell(row=14, column=c_idx)
        cell.value = day_name
        cell.font = font_day_header
        cell.fill = fill_day_header
        cell.alignment = align_center
        cell.border = border_cell

    # Kalender Dinamis
    year = plan.effective_year
    month = plan.effective_month
    cal = calendar.Calendar(firstweekday=0)  # 0 = Monday
    month_weeks = cal.monthdatescalendar(year, month)

    base_rows = [15, 23, 31, 39, 47, 55]

    for w_idx, week_days in enumerate(month_weeks):
        if w_idx >= len(base_rows):
            break
        base = base_rows[w_idx]

        # Atur tinggi baris
        ws.row_dimensions[base].height = 18      # Angka Tanggal
        ws.row_dimensions[base + 1].height = 14  # Nama Hari
        for r_offset in range(2, 8):
            ws.row_dimensions[base + r_offset].height = 17  # 6 komponen menu

        for c_idx, day_date in enumerate(week_days, start=1):
            is_current_month = (day_date.month == month and day_date.year == year)
            date_cell = ws.cell(row=base, column=c_idx)
            day_name_cell = ws.cell(row=base + 1, column=c_idx)

            if is_current_month:
                date_cell.value = day_date.strftime("%d/%m/%Y")
                date_cell.font = font_date_num
                date_cell.alignment = align_center
                date_cell.border = border_cell
                date_cell.fill = fill_weekend_box if c_idx in (6, 7) else fill_date_box

                day_name_cell.value = INDONESIAN_DAYS[c_idx - 1]
                day_name_cell.font = font_day_name
                day_name_cell.alignment = align_center
                day_name_cell.border = border_cell

                # Ambil item menu untuk tanggal ini
                iso_date = day_date.isoformat()
                date_items = items_by_date.get(iso_date, {})

                for item_ord in range(1, 7):
                    row_target = base + 1 + item_ord
                    menu_cell = ws.cell(row=row_target, column=c_idx)
                    val = date_items.get(item_ord, "")
                    menu_cell.value = val
                    menu_cell.font = font_menu_item
                    menu_cell.alignment = align_left
                    # Border khusus: baris terakhir di kotak (order 6) diberi border bottom hitam tebal
                    if item_ord == 6:
                        menu_cell.border = Border(left=thin_black, right=thin_black, top=thin_gray, bottom=thin_black)
                    else:
                        menu_cell.border = border_menu
            else:
                # Tanggal di luar bulan berjalan (kosong & abu-abu)
                date_cell.value = ""
                date_cell.fill = fill_empty
                date_cell.border = border_cell

                day_name_cell.value = ""
                day_name_cell.fill = fill_empty
                day_name_cell.border = border_cell

                for item_ord in range(1, 7):
                    row_target = base + 1 + item_ord
                    menu_cell = ws.cell(row=row_target, column=c_idx)
                    menu_cell.value = ""
                    menu_cell.fill = fill_empty
                    menu_cell.border = border_cell


def _populate_three_column_esign_sheet(
    ws: openpyxl.worksheet.worksheet.Worksheet,
    plan: MonthlyMenuPlan,
    catering_name: str,
    approvers_status: Optional[List[Dict[str, Any]]] = None,
):
    """
    Membangun sheet ESign dengan desain 3 Kolom resmi:
    - B4: Approved (Sequence 3)
    - C4: Checked 2 (Sequence 2)
    - D4: Checked 1 (Sequence 1)
    - TANPA KOLOM PREPARED (Kolom E Ditiadakan).
    - Rows 5-8 dimerge untuk area tanda tangan QR (80x80 pt).
    - Row 9 untuk tanggal approval / inisial / status.
    """
    ws.views.sheetView[0].showGridLines = True
    ws.page_setup.orientation = ws.ORIENTATION_PORTRAIT
    ws.page_setup.paperSize = ws.PAPERSIZE_A4
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 1
    ws.sheet_properties.pageSetUpPr.fitToPage = True

    ws.page_margins.left = 0.6
    ws.page_margins.right = 0.6
    ws.page_margins.top = 0.6
    ws.page_margins.bottom = 0.6

    # Lebar kolom: A sebagai margin, B, C, D untuk 3 kolom tanda tangan
    ws.column_dimensions["A"].width = 4
    ws.column_dimensions["B"].width = 22
    ws.column_dimensions["C"].width = 22
    ws.column_dimensions["D"].width = 22
    ws.column_dimensions["E"].width = 4

    # Tinggi baris
    ws.row_dimensions[1].height = 20
    ws.row_dimensions[2].height = 18
    ws.row_dimensions[3].height = 15
    ws.row_dimensions[4].height = 26  # Header tabel persetujuan
    for r in range(5, 9):
        ws.row_dimensions[r].height = 22  # Total 88 pt untuk gambar QR
    ws.row_dimensions[9].height = 22  # Inisial / Nama / Tanggal
    ws.row_dimensions[10].height = 18

    # Title Dokumen di Sheet ESign
    font_company = Font(name="Calibri", size=13, bold=True, color="1F4E79")
    font_doc_title = Font(name="Calibri", size=11, bold=True, color="000000")
    font_header = Font(name="Calibri", size=11, bold=True, color="000000")
    font_bottom = Font(name="Calibri", size=9, bold=False, color="000000")
    align_center = Alignment(horizontal="center", vertical="center")

    thin_black = Side(style="thin", color="000000")
    border_box = Border(left=thin_black, right=thin_black, top=thin_black, bottom=thin_black)

    ws["B1"] = "PT. JATIM AUTOCOMP INDONESIA"
    ws["B1"].font = font_company
    ws["B2"] = f"LEMBAR PENGESAHAN RENCANA MENU — {catering_name.upper()}"
    ws["B2"].font = font_doc_title
    ws["B3"] = f"Periode: {plan.effective_menu_period} | Versi: {plan.version}"
    ws["B3"].font = font_bottom

    # Header 3 Kolom Fisik (Kiri ke Kanan: Approved, Checked 2, Checked 1)
    headers = [
        ("B4", "Approved"),
        ("C4", "Checked 2"),
        ("D4", "Checked 1"),
    ]
    for cell_ref, label in headers:
        cell = ws[cell_ref]
        cell.value = label
        cell.font = font_header
        cell.alignment = align_center
        cell.border = border_box

    # Area Kotak Tanda Tangan (Rows 5-8)
    for col_l in ["B", "C", "D"]:
        for r in range(5, 9):
            ws[f"{col_l}{r}"].border = border_box
            ws[f"{col_l}{r}"].alignment = align_center
        ws.merge_cells(f"{col_l}5:{col_l}8")

    # Baris 9: Informasi Approver / Tanggal Persetujuan
    for col_l in ["B", "C", "D"]:
        c = ws[f"{col_l}9"]
        c.border = border_box
        c.alignment = align_center
        c.font = font_bottom
        c.value = ""

    # Map status approver
    approvers = approvers_status or []
    appr_map = {a.get("sequence"): a for a in approvers}

    # Sequence 1: Checked 1 -> Kolom D
    seq1 = appr_map.get(1)
    if seq1 and seq1.get("status") == "APPROVED":
        ws["D9"] = seq1.get("approved_at") or seq1.get("initial") or "Approved"
        qr_bytes = seq1.get("qr_png_bytes")
        if qr_bytes:
            img1 = OpenpyxlImage(io.BytesIO(qr_bytes))
            img1.width = 80
            img1.height = 80
            ws.add_image(img1, "D5")

    # Sequence 2: Checked 2 -> Kolom C
    seq2 = appr_map.get(2)
    if seq2 and seq2.get("status") == "APPROVED":
        ws["C9"] = seq2.get("approved_at") or seq2.get("initial") or "Approved"
        qr_bytes = seq2.get("qr_png_bytes")
        if qr_bytes:
            img2 = OpenpyxlImage(io.BytesIO(qr_bytes))
            img2.width = 80
            img2.height = 80
            ws.add_image(img2, "C5")

    # Sequence 3: Approved -> Kolom B
    seq3 = appr_map.get(3)
    if seq3 and seq3.get("status") == "APPROVED":
        ws["B9"] = seq3.get("approved_at") or seq3.get("initial") or "Approved"
        qr_bytes = seq3.get("qr_png_bytes")
        if qr_bytes:
            img3 = OpenpyxlImage(io.BytesIO(qr_bytes))
            img3.width = 80
            img3.height = 80
            ws.add_image(img3, "B5")


def generate_monthly_menu_excel_bytes(
    plan: MonthlyMenuPlan,
    catering: Catering,
    items: List[MonthlyMenuItem],
    approvers_status: Optional[List[Dict[str, Any]]] = None,
) -> bytes:
    """
    Menghasilkan byte stream berkas Excel resmi Rencana Menu Bulanan (.xlsx).
    """
    wb = build_monthly_menu_workbook(plan, catering, items, approvers_status)
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


def is_monthly_menu_plan_request(approval_request: Any, db: Any = None) -> bool:
    """
    Memeriksa apakah ApprovalRequest terkait dengan MonthlyMenuPlan.
    """
    if not approval_request:
        return False

    orig_name = (getattr(approval_request, "original_filename", "") or "").lower()
    if "rencana_menu" in orig_name or "menu_bulanan" in orig_name:
        return True

    if db is not None and getattr(approval_request, "id", None):
        plan = db.exec(
            select(MonthlyMenuPlan).where(MonthlyMenuPlan.approval_request_id == approval_request.id)
        ).first()
        if plan:
            return True

    return False


def stamp_monthly_menu_approval_qr(
    source_bytes: bytes,
    approval_request: Any,
    sequence: int,
    cycle: int,
    revision_number: int,
    approver_user: Any,
    qr_png_bytes: bytes,
    now: datetime.datetime,
    db: Any = None,
) -> bytes:
    """
    Menyuntikkan tanda tangan QR approver secara bertahap pada lembar ESign 3-kolom
    workbook Rencana Menu Bulanan.
    Menjaga immutability data menu dan melestarikan tanda tangan tahap sebelumnya.
    """
    if not (isinstance(source_bytes, bytes) and source_bytes.startswith(b"PK\x03\x04")):
        raise ValueError("File sumber bukan berkas Excel (.xlsx) yang valid.")

    wb = openpyxl.load_workbook(io.BytesIO(source_bytes))
    if "ESign" not in wb.sheetnames:
        raise ValueError("Lembar pengesahan 'ESign' tidak ditemukan di dalam berkas rencana menu.")

    ws = wb["ESign"]

    # Ambil catatan approvers dari database
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

    # Kumpulkan QR bytes untuk sequence 1, 2, dan 3
    # Sequence 1 -> Kolom D (Checked 1)
    if sequence == 1:
        img1 = OpenpyxlImage(io.BytesIO(qr_png_bytes))
        img1.width = 80
        img1.height = 80
        ws.add_image(img1, "D5")
        ws["D9"] = now.strftime("%Y-%m-%d %H:%M")
    elif sequence > 1:
        # Coba muat QR sequence 1 jika belum ada
        if db is not None:
            art1 = db.exec(
                select(ApprovalArtifact).where(
                    ApprovalArtifact.approval_request_id == approval_request.id,
                    ApprovalArtifact.cycle == cycle,
                    ApprovalArtifact.sequence == 1,
                )
            ).first()
            if art1:
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
                            img1 = OpenpyxlImage(io.BytesIO(qf.read()))
                            img1.width = 80
                            img1.height = 80
                            ws.add_image(img1, "D5")
                        ws["D9"] = art1.approved_at.strftime("%Y-%m-%d %H:%M")

    # Sequence 2 -> Kolom C (Checked 2)
    if sequence == 2:
        img2 = OpenpyxlImage(io.BytesIO(qr_png_bytes))
        img2.width = 80
        img2.height = 80
        ws.add_image(img2, "C5")
        ws["C9"] = now.strftime("%Y-%m-%d %H:%M")
    elif sequence > 2:
        if db is not None:
            art2 = db.exec(
                select(ApprovalArtifact).where(
                    ApprovalArtifact.approval_request_id == approval_request.id,
                    ApprovalArtifact.cycle == cycle,
                    ApprovalArtifact.sequence == 2,
                )
            ).first()
            if art2:
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
                            img2 = OpenpyxlImage(io.BytesIO(qf.read()))
                            img2.width = 80
                            img2.height = 80
                            ws.add_image(img2, "C5")
                        ws["C9"] = art2.approved_at.strftime("%Y-%m-%d %H:%M")

    # Sequence 3 -> Kolom B (Approved)
    if sequence == 3:
        img3 = OpenpyxlImage(io.BytesIO(qr_png_bytes))
        img3.width = 80
        img3.height = 80
        ws.add_image(img3, "B5")
        ws["B9"] = now.strftime("%Y-%m-%d %H:%M")

    out_buf = io.BytesIO()
    wb.save(out_buf)
    return out_buf.getvalue()
