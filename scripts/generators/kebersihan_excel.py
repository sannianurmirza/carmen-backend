"""
Generator Excel untuk Laporan Evaluasi Kebersihan Kantin & Disiplin Fisik.
Menghasilkan workbook dengan lembar 'Kebersihan Kantin' dan lembar 'ESign' sebagai lembar persetujuan.
Mematuhi aturan penilaian skor kinerja 10/8/6/4:
- Bersih tanpa pelanggaran = 10.0
- Tidak rapi tanpa pelanggaran = 8.0
- Kotor tanpa pelanggaran = 6.0
- Pelanggaran disiplin = 4.0
"""

from __future__ import annotations
import io
from datetime import date, datetime, timezone
from typing import Dict, Any, List, Optional

import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side

from esign_excel_service import attach_esign_to_workbook_bytes
from models import derive_kebersihan_performance_score


def generate_kebersihan_excel_bytes(
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
    ws.title = "Kebersihan Kantin"
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
    font_th = Font(name="Calibri", size=9.5, bold=True, color="FFFFFF")
    font_td = Font(name="Calibri", size=9.5, bold=False, color="111827")
    font_total = Font(name="Calibri", size=10, bold=True, color="111827")
    
    font_score_10 = Font(name="Calibri", size=10, bold=True, color="047857")
    font_score_8 = Font(name="Calibri", size=10, bold=True, color="1D4ED8")
    font_score_6 = Font(name="Calibri", size=10, bold=True, color="D97706")
    font_score_4 = Font(name="Calibri", size=10, bold=True, color="B91C1C")

    font_status_draft = Font(name="Calibri", size=9.5, bold=True, color="D97706")
    font_status_final = Font(name="Calibri", size=9.5, bold=True, color="047857")
    font_banner_draft = Font(name="Calibri", size=10, bold=True, color="991B1B")
    fill_banner_draft = PatternFill(start_color="FEE2E2", end_color="FEE2E2", fill_type="solid")
    font_banner_final = Font(name="Calibri", size=10, bold=True, color="065F46")
    fill_banner_final = PatternFill(start_color="D1FAE5", end_color="D1FAE5", fill_type="solid")

    fill_th = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")
    fill_zebra = PatternFill(start_color="F9FAFB", end_color="F9FAFB", fill_type="solid")
    fill_total = PatternFill(start_color="F3F4F6", end_color="F3F4F6", fill_type="solid")

    thin_border_side = Side(style="thin", color="D1D5DB")
    border_all = Border(left=thin_border_side, right=thin_border_side, top=thin_border_side, bottom=thin_border_side)
    thick_bottom = Border(left=thin_border_side, right=thin_border_side, top=thin_border_side, bottom=Side(style="double", color="111827"))

    align_center = Alignment(horizontal="center", vertical="center")
    align_left = Alignment(horizontal="left", vertical="center")

    # Column Widths
    ws.column_dimensions["A"].width = 6
    ws.column_dimensions["B"].width = 14
    ws.column_dimensions["C"].width = 24
    ws.column_dimensions["D"].width = 10
    ws.column_dimensions["E"].width = 20
    ws.column_dimensions["F"].width = 20
    ws.column_dimensions["G"].width = 20
    ws.column_dimensions["H"].width = 20
    ws.column_dimensions["I"].width = 14
    ws.column_dimensions["J"].width = 14
    ws.column_dimensions["K"].width = 28

    # Title & Header
    ws["A1"] = "PT JAI CATERING MANAGEMENT"
    ws["A1"].font = font_company
    
    ws["A2"] = "LAPORAN EVALUASI KEBERSIHAN KANTIN & DISIPLIN FISIK"
    ws["A2"].font = font_title
    
    period_str = f"{start_date.strftime('%d/%m/%Y')} s/d {end_date.strftime('%d/%m/%Y')}" if (start_date and end_date) else "Semua Periode"
    ws["A3"] = f"Periode: {period_str}"
    ws["A3"].font = font_meta
    
    gen_time_str = datetime.now(timezone.utc).strftime("%d/%m/%Y %H:%M UTC")
    ws["A4"] = f"Tanggal Export: {gen_time_str}"
    ws["A4"].font = font_meta

    has_draft = any(getattr(it, "status", "").lower() == "draft" for it in items)
    all_final = len(items) > 0 and all(getattr(it, "status", "").lower() == "final" for it in items)

    ws.row_dimensions[1].height = 18
    ws.row_dimensions[2].height = 24
    ws.row_dimensions[3].height = 18
    ws.row_dimensions[4].height = 18
    ws.row_dimensions[5].height = 20

    if has_draft:
        ws.merge_cells("A5:K5")
        ws["A5"] = "STATUS DOKUMEN: MEMUAT DATA DRAFT (BELUM FINAL - TIDAK SAH UNTUK REKAPITULASI RESMI)"
        ws["A5"].font = font_banner_draft
        ws["A5"].fill = fill_banner_draft
        ws["A5"].alignment = align_center
    elif all_final:
        ws.merge_cells("A5:K5")
        ws["A5"] = "STATUS DOKUMEN: SELURUH DATA FINAL (TELAH TERVERIFIKASI RESMI)"
        ws["A5"].font = font_banner_final
        ws["A5"].fill = fill_banner_final
        ws["A5"].alignment = align_center

    # Table Header (Row 6)
    table_headers = [
        ("A6", "NO"),
        ("B6", "TANGGAL"),
        ("C6", "KATERING"),
        ("D6", "SHIFT"),
        ("E6", "KONDISI KEBERSIHAN"),
        ("F6", "RANTANG LUAR MOBIL"),
        ("G6", "BUKA PILAH DI JAI"),
        ("H6", "MEMBERI MAKAN KUCING"),
        ("I6", "SKOR KINERJA"),
        ("J6", "STATUS"),
        ("K6", "CATATAN"),
    ]

    ws.row_dimensions[6].height = 26
    for cell_id, text in table_headers:
        ws[cell_id] = text
        ws[cell_id].font = font_th
        ws[cell_id].fill = fill_th
        ws[cell_id].alignment = align_center
        ws[cell_id].border = border_all

    curr_row = 7
    total_score_sum = 0.0

    for idx, it in enumerate(items, start=1):
        ws.row_dimensions[curr_row].height = 20
        cat_name = caterings_map.get(it.catering_id, f"Catering #{it.catering_id}")
        tgl_str = it.tanggal.strftime("%d/%m/%Y") if hasattr(it.tanggal, "strftime") else str(it.tanggal)

        kondisi = (getattr(it, "kondisi_kebersihan", "") or "bersih").lower()
        p_rantang = bool(getattr(it, "pelanggaran_rantang_tidak_langsung_masuk_mobil", False))
        p_pilah = bool(getattr(it, "pelanggaran_buka_pilah_rantang_sisa_di_jai", False))
        p_kucing = bool(getattr(it, "pelanggaran_memberi_makan_kucing", False))
        catatan = getattr(it, "catatan", "") or "-"
        status_val = (getattr(it, "status", "") or "draft").lower()

        # Hitung skor kinerja resmi
        score = derive_kebersihan_performance_score(
            kondisi,
            (p_rantang, p_pilah, p_kucing)
        )
        total_score_sum += score

        kondisi_label = {
            "bersih": "Bersih & Rapi",
            "tidak_rapi": "Tidak Rapi / Kurang Bersih",
            "kotor": "Kotor",
        }.get(kondisi, kondisi.capitalize())

        ws[f"A{curr_row}"] = idx
        ws[f"A{curr_row}"].alignment = align_center
        
        ws[f"B{curr_row}"] = tgl_str
        ws[f"B{curr_row}"].alignment = align_center
        
        ws[f"C{curr_row}"] = cat_name
        ws[f"C{curr_row}"].alignment = align_left
        
        ws[f"D{curr_row}"] = it.shift or "Pagi"
        ws[f"D{curr_row}"].alignment = align_center
        
        ws[f"E{curr_row}"] = kondisi_label
        ws[f"E{curr_row}"].alignment = align_left
        
        ws[f"F{curr_row}"] = "Ya" if p_rantang else "-"
        ws[f"F{curr_row}"].alignment = align_center
        
        ws[f"G{curr_row}"] = "Ya" if p_pilah else "-"
        ws[f"G{curr_row}"].alignment = align_center
        
        ws[f"H{curr_row}"] = "Ya" if p_kucing else "-"
        ws[f"H{curr_row}"].alignment = align_center
        
        ws[f"I{curr_row}"] = f"{score:.1f}"
        ws[f"I{curr_row}"].alignment = align_center
        if score >= 10.0:
            ws[f"I{curr_row}"].font = font_score_10
        elif score >= 8.0:
            ws[f"I{curr_row}"].font = font_score_8
        elif score >= 6.0:
            ws[f"I{curr_row}"].font = font_score_6
        else:
            ws[f"I{curr_row}"].font = font_score_4

        if status_val == "final":
            ws[f"J{curr_row}"] = "FINAL"
            ws[f"J{curr_row}"].font = font_status_final
        else:
            ws[f"J{curr_row}"] = "DRAFT"
            ws[f"J{curr_row}"].font = font_status_draft
        ws[f"J{curr_row}"].alignment = align_center
            
        ws[f"K{curr_row}"] = catatan
        ws[f"K{curr_row}"].alignment = align_left

        for col_l in ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K"]:
            cell = ws[f"{col_l}{curr_row}"]
            cell.border = border_all
            if col_l not in ("I", "J"):
                cell.font = font_td
            if idx % 2 == 0:
                cell.fill = fill_zebra

        curr_row += 1

    # Total Summary Row
    ws.row_dimensions[curr_row].height = 22
    ws.merge_cells(f"A{curr_row}:D{curr_row}")
    if has_draft:
        ws[f"A{curr_row}"] = f"RATA-RATA SKOR KINERJA KEBERSIHAN ({len(items)} PENILAIAN - MEMUAT DRAFT)"
    else:
        ws[f"A{curr_row}"] = f"RATA-RATA SKOR KINERJA KEBERSIHAN ({len(items)} PENILAIAN FINAL)"
    ws[f"A{curr_row}"].font = font_total
    ws[f"A{curr_row}"].alignment = align_center
    ws[f"A{curr_row}"].fill = fill_total

    ws.merge_cells(f"E{curr_row}:H{curr_row}")
    ws[f"E{curr_row}"] = ""
    ws[f"E{curr_row}"].fill = fill_total

    avg_score = round(total_score_sum / max(len(items), 1), 2)
    ws[f"I{curr_row}"] = f"{avg_score:.2f} / 10.0"
    ws[f"I{curr_row}"].font = font_total
    ws[f"I{curr_row}"].alignment = align_center
    ws[f"I{curr_row}"].fill = fill_total

    ws[f"J{curr_row}"] = "PROVISIONAL" if has_draft else "FINAL"
    ws[f"J{curr_row}"].font = font_status_draft if has_draft else font_status_final
    ws[f"J{curr_row}"].alignment = align_center
    ws[f"J{curr_row}"].fill = fill_total

    ws[f"K{curr_row}"] = ""
    ws[f"K{curr_row}"].fill = fill_total

    for col_l in ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K"]:
        ws[f"{col_l}{curr_row}"].border = thick_bottom

    ws.print_area = f"'Kebersihan Kantin'!$A$1:$K${curr_row}"

    buf = io.BytesIO()
    wb.save(buf)
    base_bytes = buf.getvalue()

    # Metadata untuk ESign
    doc_meta = {
        "document": "Laporan Evaluasi Kebersihan Kantin",
        "period": period_str,
        "generated_date": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "requester": requester_name,
        "approval_id": f"REQ-{approval_request.id}" if (approval_request and getattr(approval_request, "id", None)) else "-",
        "revision": f"Cycle {getattr(approval_request, 'cycle', 1)} Rev 1" if approval_request else "-",
        "status": "DRAFT / PROVISIONAL" if has_draft else getattr(approval_request, "status", "FINAL"),
    }

    # Pasang lembar ESign sebagai lembar terakhir
    final_bytes = attach_esign_to_workbook_bytes(
        source_bytes=base_bytes,
        metadata=doc_meta,
        approvers_status=approvers_status,
        sheet_name="ESign",
    )

    return final_bytes
