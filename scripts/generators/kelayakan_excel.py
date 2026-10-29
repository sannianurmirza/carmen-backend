"""
Generator Excel untuk Laporan Menu & Kelayakan Makanan Katering.
Menghasilkan workbook dengan lembar 'Rekapitulasi' dan lembar 'ESign' sebagai lembar persetujuan.
"""

from __future__ import annotations
import io
from datetime import date, datetime, timezone
from typing import Dict, Any, List, Optional

import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side

from esign_excel_service import attach_esign_to_workbook_bytes


def generate_kelayakan_excel_bytes(
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
    ws.title = "Rekapitulasi"
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
    font_total = Font(name="Calibri", size=10, bold=True, color="111827")

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
    ws.column_dimensions["E"].width = 10
    ws.column_dimensions["F"].width = 10
    ws.column_dimensions["G"].width = 10
    ws.column_dimensions["H"].width = 10
    ws.column_dimensions["I"].width = 10
    ws.column_dimensions["J"].width = 10
    ws.column_dimensions["K"].width = 14
    ws.column_dimensions["L"].width = 16
    ws.column_dimensions["M"].width = 28

    # Title & Header
    ws["A1"] = "PT JAI CATERING MANAGEMENT"
    ws["A1"].font = font_company
    
    ws["A2"] = "LAPORAN REKAPITULASI KELAYAKAN & MENU MAKANAN"
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
        ("E6", "NASI"),
        ("F6", "LAUK"),
        ("G6", "SAYUR"),
        ("H6", "SAMBAL"),
        ("I6", "KERUPUK"),
        ("J6", "BUAH"),
        ("K6", "TOTAL NILAI"),
        ("L6", "PERSENTASE (%)"),
        ("M6", "CATATAN"),
    ]

    ws.row_dimensions[6].height = 24
    for cell_id, text in table_headers:
        ws[cell_id] = text
        ws[cell_id].font = font_th
        ws[cell_id].fill = fill_th
        ws[cell_id].alignment = align_center
        ws[cell_id].border = border_all

    curr_row = 7
    total_score_sum = 0
    total_pct_sum = 0

    for idx, it in enumerate(items, start=1):
        ws.row_dimensions[curr_row].height = 20
        cat_name = caterings_map.get(it.catering_id, f"Catering #{it.catering_id}")
        tgl_str = it.tanggal.strftime("%d/%m/%Y") if hasattr(it.tanggal, "strftime") else str(it.tanggal)

        # Nilai kelayakan (skor bintang 1 - 5)
        nasi = getattr(it, "nasi_kelayakan", 5)
        lauk = getattr(it, "lauk_kelayakan", 5)
        sayur = getattr(it, "sayur_kelayakan", 5)
        sambal = getattr(it, "sambal_kelayakan", 5)
        kerupuk = getattr(it, "kerupuk_kelayakan", 5)
        buah = getattr(it, "buah_kelayakan", 5)

        total_nilai = getattr(it, "total_nilai", None)
        if total_nilai is None:
            all_13 = [
                getattr(it, "nasi_kelayakan", 0), getattr(it, "nasi_rasa", 0),
                getattr(it, "lauk_kelayakan", 0), getattr(it, "lauk_rasa", 0),
                getattr(it, "sayur_kelayakan", 0), getattr(it, "sayur_rasa", 0),
                getattr(it, "sambal_kelayakan", 0), getattr(it, "sambal_rasa", 0),
                getattr(it, "kerupuk_kelayakan", 0), getattr(it, "kerupuk_rasa", 0),
                getattr(it, "buah_kelayakan", 0), getattr(it, "buah_rasa", 0),
                getattr(it, "kondisi_rantang", 0),
            ]
            sum_13 = sum(all_13)
            total_nilai = sum_13 if sum_13 > 0 else (nasi + lauk + sayur + sambal + kerupuk + buah)

        pct = getattr(it, "score_presentase", None)
        if pct is None:
            pct = (total_nilai / 65.0) * 100.0

        total_score_sum += total_nilai
        total_pct_sum += pct
        catatan = getattr(it, "catatan", "") or "-"

        ws[f"A{curr_row}"] = idx
        ws[f"A{curr_row}"].alignment = align_center
        
        ws[f"B{curr_row}"] = tgl_str
        ws[f"B{curr_row}"].alignment = align_center
        
        ws[f"C{curr_row}"] = cat_name
        ws[f"C{curr_row}"].alignment = align_left
        
        ws[f"D{curr_row}"] = it.shift or "Pagi"
        ws[f"D{curr_row}"].alignment = align_center
        
        ws[f"E{curr_row}"] = nasi
        ws[f"E{curr_row}"].alignment = align_center
        
        ws[f"F{curr_row}"] = lauk
        ws[f"F{curr_row}"].alignment = align_center
        
        ws[f"G{curr_row}"] = sayur
        ws[f"G{curr_row}"].alignment = align_center
        
        ws[f"H{curr_row}"] = sambal
        ws[f"H{curr_row}"].alignment = align_center
        
        ws[f"I{curr_row}"] = kerupuk
        ws[f"I{curr_row}"].alignment = align_center
        
        ws[f"J{curr_row}"] = buah
        ws[f"J{curr_row}"].alignment = align_center
        
        ws[f"K{curr_row}"] = round(total_nilai, 1)
        ws[f"K{curr_row}"].alignment = align_center
        
        ws[f"L{curr_row}"] = f"{round(pct, 1)}%"
        ws[f"L{curr_row}"].alignment = align_center
        
        ws[f"M{curr_row}"] = catatan
        ws[f"M{curr_row}"].alignment = align_left

        for col_l in ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M"]:
            cell = ws[f"{col_l}{curr_row}"]
            cell.border = border_all
            cell.font = font_td
            if idx % 2 == 0:
                cell.fill = fill_zebra

        curr_row += 1

    # Total Summary Row
    ws.row_dimensions[curr_row].height = 22
    ws.merge_cells(f"A{curr_row}:D{curr_row}")
    ws[f"A{curr_row}"] = f"RATA-RATA PENCAPAIAN KELAYAKAN ({len(items)} PENILAIAN)"
    ws[f"A{curr_row}"].font = font_total
    ws[f"A{curr_row}"].alignment = align_center
    ws[f"A{curr_row}"].fill = fill_total

    ws.merge_cells(f"E{curr_row}:J{curr_row}")
    ws[f"E{curr_row}"] = ""
    ws[f"E{curr_row}"].fill = fill_total

    avg_score = round(total_score_sum / max(len(items), 1), 2)
    avg_pct = round(total_pct_sum / max(len(items), 1), 1)

    ws[f"K{curr_row}"] = avg_score
    ws[f"K{curr_row}"].font = font_total
    ws[f"K{curr_row}"].alignment = align_center
    ws[f"K{curr_row}"].fill = fill_total

    ws[f"L{curr_row}"] = f"{avg_pct}%"
    ws[f"L{curr_row}"].font = font_total
    ws[f"L{curr_row}"].alignment = align_center
    ws[f"L{curr_row}"].fill = fill_total

    ws[f"M{curr_row}"] = ""
    ws[f"M{curr_row}"].fill = fill_total

    for col_l in ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M"]:
        ws[f"{col_l}{curr_row}"].border = thick_bottom

    ws.print_area = f"'Rekapitulasi'!$A$1:$M${curr_row}"

    buf = io.BytesIO()
    wb.save(buf)
    base_bytes = buf.getvalue()

    # Metadata untuk ESign
    doc_meta = {
        "document": "Laporan Menu & Kelayakan Makanan",
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
