"""
Generator Excel untuk Laporan Keterlambatan Pengiriman Makanan Katering.
Menghasilkan workbook dengan struktur tabel profesional dan lembar 'ESign' sebagai lembar persetujuan.
"""

from __future__ import annotations
import io
from datetime import date, datetime, timezone
from typing import Dict, Any, List, Optional

import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

from esign_excel_service import attach_esign_to_workbook_bytes


def generate_keterlambatan_excel_bytes(
    items: List[Any],
    caterings_map: Dict[int, str],
    start_date: Optional[date] = None,
    end_date: Optional[date] = None,
    requester_name: str = "Admin Catering",
    approval_request: Optional[Any] = None,
    approvers_status: Optional[List[Dict[str, Any]]] = None,
) -> bytes:
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Keterlambatan"
    ws.views.sheetView[0].showGridLines = True

    # Page setup
    ws.page_setup.orientation = ws.ORIENTATION_LANDSCAPE
    ws.page_setup.paperSize = ws.PAPERSIZE_A4
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.sheet_properties.pageSetUpPr.fitToPage = True

    # Colors & Fonts
    font_company = Font(name="Calibri", size=11, bold=True, color="4B5563")
    font_title = Font(name="Calibri", size=16, bold=True, color="1E3A8A")
    font_meta = Font(name="Calibri", size=10, bold=False, color="374151")
    font_th = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
    font_td = Font(name="Calibri", size=10, bold=False, color="111827")
    font_td_center = Font(name="Calibri", size=10, bold=False, color="111827")
    font_total = Font(name="Calibri", size=10, bold=True, color="111827")
    font_badge_tepat = Font(name="Calibri", size=9.5, bold=True, color="047857")
    font_badge_terlambat = Font(name="Calibri", size=9.5, bold=True, color="B91C1C")

    fill_th = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")
    fill_zebra = PatternFill(start_color="F9FAFB", end_color="F9FAFB", fill_type="solid")
    fill_total = PatternFill(start_color="F3F4F6", end_color="F3F4F6", fill_type="solid")

    thin_border_side = Side(style="thin", color="D1D5DB")
    border_all = Border(left=thin_border_side, right=thin_border_side, top=thin_border_side, bottom=thin_border_side)
    thick_bottom = Border(left=thin_border_side, right=thin_border_side, top=thin_border_side, bottom=Side(style="double", color="111827"))

    align_center = Alignment(horizontal="center", vertical="center")
    align_left = Alignment(horizontal="left", vertical="center")
    align_right = Alignment(horizontal="right", vertical="center")

    # Column Widths
    ws.column_dimensions["A"].width = 6
    ws.column_dimensions["B"].width = 14
    ws.column_dimensions["C"].width = 24
    ws.column_dimensions["D"].width = 12
    ws.column_dimensions["E"].width = 20
    ws.column_dimensions["F"].width = 18
    ws.column_dimensions["G"].width = 28
    ws.column_dimensions["H"].width = 32

    # Title & Header
    ws["A1"] = "PT JAI CATERING MANAGEMENT"
    ws["A1"].font = font_company
    
    ws["A2"] = "LAPORAN KETERLAMBATAN PENGIRIMAN MAKANAN"
    ws["A2"].font = font_title
    
    period_str = f"{start_date.strftime('%d/%m/%Y')} s/d {end_date.strftime('%d/%m/%Y')}" if (start_date and end_date) else "Semua Periode"
    ws["A3"] = f"Periode: {period_str}"
    ws["A3"].font = font_meta
    
    gen_time_str = datetime.now(timezone.utc).strftime("%d/%m/%Y %H:%M UTC")
    ws["A4"] = f"Tanggal Export: {gen_time_str}"
    ws["A4"].font = font_meta

    ws.row_dimensions[1].height = 18
    ws.row_dimensions[2].height = 24
    ws.row_dimensions[3].height = 18
    ws.row_dimensions[4].height = 18
    ws.row_dimensions[5].height = 10

    # Table Header (Row 6)
    table_headers = [
        ("A6", "NO"),
        ("B6", "TANGGAL"),
        ("C6", "KATERING"),
        ("D6", "SHIFT"),
        ("E6", "STATUS PENGIRIMAN"),
        ("F6", "MENIT TERLAMBAT"),
        ("G6", "KATEGORI KETERLAMBATAN"),
        ("H6", "KETERANGAN"),
    ]

    ws.row_dimensions[6].height = 24
    for cell_id, text in table_headers:
        ws[cell_id] = text
        ws[cell_id].font = font_th
        ws[cell_id].fill = fill_th
        ws[cell_id].alignment = align_center
        ws[cell_id].border = border_all

    total_terlambat = 0
    total_menit = 0

    curr_row = 7
    for idx, it in enumerate(items, start=1):
        ws.row_dimensions[curr_row].height = 20
        cat_name = caterings_map.get(it.catering_id, f"Catering #{it.catering_id}")
        tgl_str = it.tanggal.strftime("%d/%m/%Y") if hasattr(it.tanggal, "strftime") else str(it.tanggal)
        status = it.status_pengiriman or "Tepat Waktu"
        is_late = status.lower() == "terlambat"
        menit = getattr(it, "menit_terlambat", None) or getattr(it, "menit_keterlambatan", 0) or 0
        kategori = getattr(it, "kategori", None) or getattr(it, "kategori_keterlambatan", "-") or "-"
        keterangan = getattr(it, "keterangan", "-") or "-"

        if is_late:
            total_terlambat += 1
            total_menit += menit

        ws[f"A{curr_row}"] = idx
        ws[f"A{curr_row}"].alignment = align_center
        
        ws[f"B{curr_row}"] = tgl_str
        ws[f"B{curr_row}"].alignment = align_center
        
        ws[f"C{curr_row}"] = cat_name
        ws[f"C{curr_row}"].alignment = align_left
        
        ws[f"D{curr_row}"] = it.shift or "Pagi"
        ws[f"D{curr_row}"].alignment = align_center
        
        ws[f"E{curr_row}"] = status
        ws[f"E{curr_row}"].alignment = align_center
        ws[f"E{curr_row}"].font = font_badge_terlambat if is_late else font_badge_tepat
        
        ws[f"F{curr_row}"] = f"{menit} menit" if is_late else "-"
        ws[f"F{curr_row}"].alignment = align_center
        
        ws[f"G{curr_row}"] = kategori
        ws[f"G{curr_row}"].alignment = align_left
        
        ws[f"H{curr_row}"] = keterangan
        ws[f"H{curr_row}"].alignment = align_left

        for col_l in ["A", "B", "C", "D", "E", "F", "G", "H"]:
            cell = ws[f"{col_l}{curr_row}"]
            cell.border = border_all
            if col_l != "E":
                cell.font = font_td
            if idx % 2 == 0:
                cell.fill = fill_zebra

        curr_row += 1

    # Total Summary Row
    ws.row_dimensions[curr_row].height = 22
    ws.merge_cells(f"A{curr_row}:D{curr_row}")
    ws[f"A{curr_row}"] = f"TOTAL KEJADIAN: {len(items)} | TERLAMBAT: {total_terlambat} KALI"
    ws[f"A{curr_row}"].font = font_total
    ws[f"A{curr_row}"].alignment = align_center
    ws[f"A{curr_row}"].fill = fill_total

    ws.merge_cells(f"E{curr_row}:F{curr_row}")
    ws[f"E{curr_row}"] = f"TOTAL MENIT: {total_menit} Menit"
    ws[f"E{curr_row}"].font = font_total
    ws[f"E{curr_row}"].alignment = align_center
    ws[f"E{curr_row}"].fill = fill_total

    ws.merge_cells(f"G{curr_row}:H{curr_row}")
    ws[f"G{curr_row}"] = ""
    ws[f"G{curr_row}"].fill = fill_total

    for col_l in ["A", "B", "C", "D", "E", "F", "G", "H"]:
        ws[f"{col_l}{curr_row}"].border = thick_bottom

    ws.print_area = f"'Keterlambatan'!$A$1:$H${curr_row}"

    buf = io.BytesIO()
    wb.save(buf)
    base_bytes = buf.getvalue()

    # Metadata untuk ESign
    doc_meta = {
        "document": "Laporan Keterlambatan Pengiriman",
        "period": period_str,
        "generated_date": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "requester": requester_name,
        "approval_id": f"REQ-{approval_request.id}" if (approval_request and getattr(approval_request, "id", None)) else "-",
        "revision": f"Cycle {getattr(approval_request, 'cycle', 1)} Rev 1" if approval_request else "-",
        "status": getattr(approval_request, "status", "BASE EXPORT"),
    }

    # Pasang lembar ESign sebagai lembar terakhir
    final_bytes = attach_esign_to_workbook_bytes(
        source_bytes=base_bytes,
        metadata=doc_meta,
        approvers_status=approvers_status,
        sheet_name="ESign",
    )

    return final_bytes
