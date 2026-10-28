"""
Modul Pembuatan Workbook Excel Laporan Performance Catering PT JAI.
Mengikuti acuan resmi: PERFORMA CATERING AGUSTUS 2026.xlsx
Hanya menghasilkan:
1. Sheet individual setiap vendor aktif.
2. Sheet RESUME.
3. Sheet STANDAR PENILAIAN (Rev. 04/PK/JAI/IV/2018).

Eksklusi ketat: SIGN, Record Catering, arsip 2015, Sheet1, Sheet13, hidden sheet, vendor placeholder F.
"""
import io
import re
from typing import Dict, Any, List, Optional
import openpyxl
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.utils import get_column_letter

# Styles
FONT_TITLE = Font(name="Calibri", size=13, bold=True, color="1E293B")
FONT_SUBTITLE = Font(name="Calibri", size=10.5, bold=True, color="1E293B")
FONT_HEADER = Font(name="Calibri", size=10, bold=True, color="1E293B")
FONT_DATA = Font(name="Calibri", size=10, bold=False, color="1E293B")
FONT_DATA_BOLD = Font(name="Calibri", size=10, bold=True, color="1E293B")
FONT_GRADE = Font(name="Calibri", size=11, bold=True, color="1E293B")
FONT_SMALL = Font(name="Calibri", size=8.5, italic=True, color="64748B")
FONT_INFO = Font(name="Calibri", size=9.5, bold=True, color="334155")
FONT_ALERT = Font(name="Calibri", size=9.5, bold=True, color="991B1B")
FONT_STATUS_BADGE = Font(name="Calibri", size=8.5, bold=True, color="991B1B")

# Subtle and Harmonious Fills
FILL_HEADER = PatternFill(start_color="F1F5F9", end_color="F1F5F9", fill_type="solid")  # Slate-100
FILL_ALERT = PatternFill(start_color="FEE2E2", end_color="FEE2E2", fill_type="solid")
FILL_TOTAL = PatternFill(start_color="FEF9C3", end_color="FEF9C3", fill_type="solid")   # Soft Pale Yellow
FILL_GRADE = PatternFill(start_color="F8FAFC", end_color="F8FAFC", fill_type="solid")   # Slate-50
FILL_STATUS_BADGE = PatternFill(start_color="FEE2E2", end_color="FEE2E2", fill_type="solid")  # Soft Rose-100

# Professional Borders: Solid Black for maximum clarity, firmness, and printability
SIDE_BLACK_MEDIUM = Side(style="medium", color="000000")
SIDE_BLACK_THIN   = Side(style="thin", color="000000")

SIDE_OUTER = SIDE_BLACK_MEDIUM
SIDE_INNER = SIDE_BLACK_THIN
SIDE_MID   = SIDE_BLACK_MEDIUM

BORDER_BOX = Border(
    left=SIDE_BLACK_THIN,
    right=SIDE_BLACK_THIN,
    top=SIDE_BLACK_THIN,
    bottom=SIDE_BLACK_THIN,
)

ALIGN_CENTER = Alignment(horizontal="center", vertical="center", wrap_text=True)
ALIGN_LEFT = Alignment(horizontal="left", vertical="center", wrap_text=True)
ALIGN_RIGHT = Alignment(horizontal="right", vertical="center", wrap_text=True)


def format_score_val(val: Any) -> Any:
    """Format nilai skor secara konsisten: integer jika bulat, atau 1 decimal."""
    if val is None or val == "":
        return ""
    try:
        f_val = float(val)
        if f_val.is_integer():
            return int(f_val)
        return round(f_val, 1)
    except (ValueError, TypeError):
        return val


def sanitize_sheet_name(name: str, max_len: int = 30) -> str:
    """Membersihkan nama sheet agar valid di Excel (maksimal 31 char, tanpa karakter terlarang)."""
    clean = re.sub(r'[\\/*?:\[\]]', '', name).strip().upper()
    return clean[:max_len] if clean else "VENDOR"


def generate_performance_excel(snapshot: Dict[str, Any], is_draft: bool = True) -> io.BytesIO:
    """
    Menghasilkan workbook Excel multi-sheet resmi dari data snapshot performance.
    """
    wb = openpyxl.Workbook()
    # Hapus sheet default pertama
    default_sheet = wb.active

    nama_periode = snapshot.get("nama_periode", "")
    caterings = snapshot.get("caterings", [])

    # Tracking nama sheet vendor yang unik
    used_sheet_names = set()
    vendor_sheets = []

    # --------------------------------------------------------------------------
    # 1. SHEET INDIVIDUAL PER KATERING AKTIF
    # --------------------------------------------------------------------------
    for c in caterings:
        raw_name = c.get("catering_nama", f"Catering {c.get('catering_id')}")
        base_name = sanitize_sheet_name(raw_name)
        sheet_name = base_name
        counter = 1
        while sheet_name in used_sheet_names:
            sheet_name = f"{base_name[:26]}_{counter}"
            counter += 1
        used_sheet_names.add(sheet_name)
        vendor_sheets.append((sheet_name, c))

        ws = wb.create_sheet(title=sheet_name)
        ws.page_setup.orientation = ws.ORIENTATION_PORTRAIT
        ws.page_setup.fitToPage = True
        ws.page_setup.fitToWidth = 1
        ws.page_setup.fitToHeight = 0
        ws.sheet_properties.pageSetUpPr.fitToPage = True

        # Header Perusahaan
        ws.cell(1, 2, "PT. JATIM AUTOCOMP INDONESIA").font = FONT_SUBTITLE
        ws.cell(2, 2, "Wirring Harness Manufacturer").font = FONT_DATA
        ws.cell(4, 2, f"CATERING {c.get('catering_nama', '').upper()} PERFORMANCE").font = FONT_TITLE
        ws.cell(5, 2, f"PERIODE {nama_periode}").font = FONT_SUBTITLE

        # Penanda DRAFT jika kebersihan belum tersedia & Subtotal Info Row 7
        kebersihan_incomplete = not c.get("kebersihan", {}).get("is_complete", False)
        ws.row_dimensions[6].height = 18
        ws.row_dimensions[7].height = 20

        if is_draft:
            if kebersihan_incomplete:
                ws.cell(6, 2, "DRAFT — DATA KEBERSIHAN BELUM TERSEDIA").font = FONT_ALERT
                subtotal_str = f"Subtotal sementara: {c.get('subtotal_5_komponen', 0.0):.1f} / 90"
                c_sub = ws.cell(7, 2, subtotal_str)
                c_sub.font = FONT_INFO
                c_sub.alignment = Alignment(horizontal="left", vertical="center")

                # Option A: Small structured status badge above/right of the table (cols E-F)
                ws.merge_cells("E7:F7")
                c_badge = ws.cell(7, 5, "Menunggu konfirmasi")
                c_badge.font = FONT_STATUS_BADGE
                c_badge.fill = FILL_STATUS_BADGE
                c_badge.alignment = ALIGN_CENTER
                c_badge.border = Border(top=SIDE_INNER, bottom=SIDE_INNER, left=SIDE_INNER, right=SIDE_INNER)
                c_badge_f = ws.cell(7, 6)
                c_badge_f.fill = FILL_STATUS_BADGE
                c_badge_f.border = Border(top=SIDE_INNER, bottom=SIDE_INNER, left=SIDE_INNER, right=SIDE_INNER)
            else:
                ws.cell(6, 2, "DRAFT — LAPORAN PERFORMANCE BULANAN").font = FONT_SUBTITLE
                if c.get("total_score") is not None:
                    total_str = f"Total skor: {c.get('total_score', 0.0):.1f} / 100"
                    c_tot_hdr = ws.cell(7, 2, total_str)
                    c_tot_hdr.font = FONT_INFO
                    c_tot_hdr.alignment = Alignment(horizontal="left", vertical="center")
        else:
            if c.get("total_score") is not None:
                total_str = f"Total skor: {c.get('total_score', 0.0):.1f} / 100"
                c_tot_hdr = ws.cell(7, 2, total_str)
                c_tot_hdr.font = FONT_INFO
                c_tot_hdr.alignment = Alignment(horizontal="left", vertical="center")

        # Header Tabel (Row 8)
        ws.row_dimensions[8].height = 26

        # Col 2: NO
        c_h_no = ws.cell(8, 2, "NO")
        c_h_no.font = FONT_HEADER
        c_h_no.fill = FILL_HEADER
        c_h_no.alignment = ALIGN_CENTER
        c_h_no.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_OUTER, right=SIDE_INNER)

        # Col 3-4 (Merged): ITEM
        ws.merge_cells("C8:D8")
        c_h_it = ws.cell(8, 3, "ITEM")
        c_h_it.font = FONT_HEADER
        c_h_it.fill = FILL_HEADER
        c_h_it.alignment = ALIGN_CENTER
        c_h_it.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_INNER)
        c_h_it_d = ws.cell(8, 4)
        c_h_it_d.fill = FILL_HEADER
        c_h_it_d.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_INNER)

        # Col 5: BULAN SEKARANG
        c_h_curr = ws.cell(8, 5, "BULAN SEKARANG")
        c_h_curr.font = FONT_HEADER
        c_h_curr.fill = FILL_HEADER
        c_h_curr.alignment = ALIGN_CENTER
        c_h_curr.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_INNER)

        # Col 6: BULAN LALU
        c_h_prev = ws.cell(8, 6, "BULAN LALU")
        c_h_prev.font = FONT_HEADER
        c_h_prev.fill = FILL_HEADER
        c_h_prev.alignment = ALIGN_CENTER
        c_h_prev.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_OUTER)

        # Item Penilaian (Rows 9-14)
        pl = c.get("periode_lalu", {})
        rows_data = [
            (9, 1, "Terlambat datang saat pengiriman", c.get("terlambat", {}).get("score"), pl.get("score_terlambat")),
            (10, 2, "Menu & Kelayakan", c.get("kelayakan", {}).get("score"), pl.get("score_kelayakan")),
            (11, 3, "Pencapaian kalori (\u2265 900 kalori)", c.get("kalori", {}).get("score"), pl.get("score_kalori")),
            (12, 4, "Komplain", c.get("komplain", {}).get("score"), pl.get("score_komplain")),
            (13, 5, "Hasil audit dapur supplier katering", c.get("audit", {}).get("score"), pl.get("score_audit")),
            (14, 6, "Kebersihan kantin serta perlengkapan makan", c.get("kebersihan", {}).get("score"), pl.get("score_kebersihan")),
        ]

        for r_idx, no, label, val_curr, val_prev in rows_data:
            ws.row_dimensions[r_idx].height = 24
            is_last_item = (r_idx == 14)
            b_bottom = SIDE_OUTER if is_last_item else SIDE_INNER

            # Col 2: No
            c_no = ws.cell(r_idx, 2, no)
            c_no.font = FONT_DATA
            c_no.alignment = ALIGN_CENTER
            c_no.border = Border(top=SIDE_INNER, bottom=b_bottom, left=SIDE_OUTER, right=SIDE_INNER)

            # Col 3-4: Label (Merged)
            ws.merge_cells(start_row=r_idx, start_column=3, end_row=r_idx, end_column=4)
            c_lbl = ws.cell(r_idx, 3, label)
            c_lbl.font = FONT_DATA
            c_lbl.alignment = ALIGN_LEFT
            c_lbl.border = Border(top=SIDE_INNER, bottom=b_bottom, left=SIDE_INNER, right=SIDE_INNER)
            c_lbl_d = ws.cell(r_idx, 4)
            c_lbl_d.border = Border(top=SIDE_INNER, bottom=b_bottom, left=SIDE_INNER, right=SIDE_INNER)

            # Col 5: Score Sekarang
            c_sc = ws.cell(r_idx, 5, format_score_val(val_curr))
            c_sc.font = FONT_DATA
            c_sc.alignment = ALIGN_CENTER
            c_sc.border = Border(top=SIDE_INNER, bottom=b_bottom, left=SIDE_INNER, right=SIDE_INNER)

            # Col 6: Score Lalu
            c_sp = ws.cell(r_idx, 6, format_score_val(val_prev))
            c_sp.font = FONT_DATA
            c_sp.alignment = ALIGN_CENTER
            c_sp.border = Border(top=SIDE_INNER, bottom=b_bottom, left=SIDE_INNER, right=SIDE_OUTER)

        # Row 15: TOTAL (Formula pemeriksaan kelengkapan 6 cell)
        ws.row_dimensions[15].height = 25
        ws.merge_cells("B15:D15")
        c_tot_lbl = ws.cell(15, 2, "TOTAL  ")
        c_tot_lbl.font = FONT_DATA_BOLD
        c_tot_lbl.fill = FILL_TOTAL
        c_tot_lbl.alignment = ALIGN_RIGHT
        c_tot_lbl.border = Border(top=SIDE_OUTER, bottom=SIDE_INNER, left=SIDE_OUTER, right=SIDE_INNER)
        for col_i in (3, 4):
            ws.cell(15, col_i).border = Border(top=SIDE_OUTER, bottom=SIDE_INNER, left=SIDE_INNER, right=SIDE_INNER)
            ws.cell(15, col_i).fill = FILL_TOTAL

        # Formula =IF(COUNT(E9:E14)=6,SUM(E9:E14),"")
        c_tot_curr = ws.cell(15, 5, '=IF(COUNT(E9:E14)=6,SUM(E9:E14),"")')
        c_tot_curr.font = FONT_DATA_BOLD
        c_tot_curr.fill = FILL_TOTAL
        c_tot_curr.alignment = ALIGN_CENTER
        c_tot_curr.border = Border(top=SIDE_OUTER, bottom=SIDE_INNER, left=SIDE_INNER, right=SIDE_INNER)

        c_tot_prev = ws.cell(15, 6, '=IF(COUNT(F9:F14)=6,SUM(F9:F14),"")')
        c_tot_prev.font = FONT_DATA_BOLD
        c_tot_prev.fill = FILL_TOTAL
        c_tot_prev.alignment = ALIGN_CENTER
        c_tot_prev.border = Border(top=SIDE_OUTER, bottom=SIDE_INNER, left=SIDE_INNER, right=SIDE_OUTER)

        # Row 16: GRADE
        ws.row_dimensions[16].height = 25
        ws.merge_cells("B16:D16")
        c_grd_lbl = ws.cell(16, 2, "GRADE  ")
        c_grd_lbl.font = FONT_DATA_BOLD
        c_grd_lbl.fill = FILL_GRADE
        c_grd_lbl.alignment = ALIGN_RIGHT
        c_grd_lbl.border = Border(top=SIDE_INNER, bottom=SIDE_OUTER, left=SIDE_OUTER, right=SIDE_INNER)
        for col_i in (3, 4):
            ws.cell(16, col_i).border = Border(top=SIDE_INNER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_INNER)
            ws.cell(16, col_i).fill = FILL_GRADE

        # Grade values
        c_grd_curr = ws.cell(16, 5, c.get("grade") or "")
        c_grd_curr.font = FONT_GRADE
        c_grd_curr.fill = FILL_GRADE
        c_grd_curr.alignment = ALIGN_CENTER
        c_grd_curr.border = Border(top=SIDE_INNER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_INNER)

        c_grd_prev = ws.cell(16, 6, pl.get("grade") or "")
        c_grd_prev.font = FONT_GRADE
        c_grd_prev.fill = FILL_GRADE
        c_grd_prev.alignment = ALIGN_CENTER
        c_grd_prev.border = Border(top=SIDE_INNER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_OUTER)

        # Row 17: Structured Status Note if Kebersihan is Incomplete
        ws.row_dimensions[17].height = 18
        if is_draft and kebersihan_incomplete:
            ws.merge_cells("B17:F17")
            c_note = ws.cell(17, 2, "* Kebersihan kantin serta perlengkapan makan: Menunggu konfirmasi")
            c_note.font = Font(name="Calibri", size=9, italic=True, color="991B1B")
            c_note.alignment = Alignment(horizontal="left", vertical="center")

        # Standar Grade & Action (Rows 19-23)
        ws.row_dimensions[18].height = 8  # breathing spacer
        ws.row_dimensions[19].height = 20
        ws.merge_cells("B19:C19")
        c_ga_h1 = ws.cell(19, 2, "GRADE")
        c_ga_h1.font = FONT_HEADER
        c_ga_h1.alignment = ALIGN_CENTER
        c_ga_h1.fill = FILL_HEADER
        c_ga_h1.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_OUTER, right=SIDE_INNER)
        ws.cell(19, 3).border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_INNER)
        ws.cell(19, 3).fill = FILL_HEADER

        ws.merge_cells("D19:F19")
        c_ga_h2 = ws.cell(19, 4, "ACTION")
        c_ga_h2.font = FONT_HEADER
        c_ga_h2.alignment = ALIGN_CENTER
        c_ga_h2.fill = FILL_HEADER
        c_ga_h2.border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_OUTER)
        for col_i in (5, 6):
            ws.cell(19, col_i).border = Border(top=SIDE_OUTER, bottom=SIDE_OUTER, left=SIDE_INNER, right=SIDE_OUTER if col_i == 6 else SIDE_INNER)
            ws.cell(19, col_i).fill = FILL_HEADER

        grade_actions = [
            (20, "A", "Total Score 95 - 100", "Good"),
            (21, "B", "Total Score 81 - 94", "Standard"),
            (22, "C", "Total Score 66 - 80", "Diberikan surat teguran, Evaluasi Kontrak katering jika 2 bulan berturut - turut"),
            (23, "D", "Total Score 0 - 65", "Evaluasi Kontrak Katering"),
        ]
        for r_ga, g_code, g_range, g_act in grade_actions:
            ws.row_dimensions[r_ga].height = 20
            is_last_ga = (r_ga == 23)
            ga_bottom = SIDE_OUTER if is_last_ga else SIDE_INNER

            ws.cell(r_ga, 2, g_code).font = FONT_DATA_BOLD
            ws.cell(r_ga, 2).alignment = ALIGN_CENTER
            ws.cell(r_ga, 2).border = Border(top=SIDE_INNER, bottom=ga_bottom, left=SIDE_OUTER, right=SIDE_INNER)

            ws.cell(r_ga, 3, g_range).font = FONT_DATA
            ws.cell(r_ga, 3).alignment = ALIGN_LEFT
            ws.cell(r_ga, 3).border = Border(top=SIDE_INNER, bottom=ga_bottom, left=SIDE_INNER, right=SIDE_INNER)

            ws.merge_cells(f"D{r_ga}:F{r_ga}")
            c_ga = ws.cell(r_ga, 4, g_act)
            c_ga.font = FONT_DATA
            c_ga.alignment = ALIGN_LEFT
            c_ga.border = Border(top=SIDE_INNER, bottom=ga_bottom, left=SIDE_INNER, right=SIDE_INNER)
            ws.cell(r_ga, 5).border = Border(top=SIDE_INNER, bottom=ga_bottom, left=SIDE_INNER, right=SIDE_INNER)
            ws.cell(r_ga, 6).border = Border(top=SIDE_INNER, bottom=ga_bottom, left=SIDE_INNER, right=SIDE_OUTER)

        # Catatan Perhatian (Rows 25-27)
        ws.row_dimensions[24].height = 8  # breathing spacer
        ws.cell(25, 2, "Perhatian :").font = FONT_DATA_BOLD
        ws.cell(26, 2, "Total Order dipengaruhi dari Performance ( Penilaian ) Catering dan pemberlakuan").font = FONT_DATA
        ws.cell(27, 2, "sanksi sesuai ketentuan yang ada").font = FONT_DATA

        # Lebar Kolom Seimbang & Proporsional (~7% NO, ~57% ITEM, ~18% BULAN SEKARANG, ~18% BULAN LALU)
        ws.column_dimensions["A"].width = 3.5
        ws.column_dimensions["B"].width = 5.5
        ws.column_dimensions["C"].width = 20.0
        ws.column_dimensions["D"].width = 24.5
        ws.column_dimensions["E"].width = 14.0
        ws.column_dimensions["F"].width = 14.0

        ws.print_area = "$A$1:$F$48"

    # --------------------------------------------------------------------------
    # 2. SHEET RESUME
    # --------------------------------------------------------------------------
    ws_res = wb.create_sheet(title="RESUME")
    ws_res.page_setup.orientation = ws_res.ORIENTATION_LANDSCAPE
    ws_res.page_setup.fitToPage = True
    ws_res.page_setup.fitToWidth = 1
    ws_res.page_setup.fitToHeight = 0
    ws_res.sheet_properties.pageSetUpPr.fitToPage = True

    # Header RESUME
    ws_res.cell(1, 1, "PT. JATIM AUTOCOMP INDONESIA").font = FONT_SUBTITLE
    ws_res.cell(2, 1, "Industrial Relation Section").font = FONT_DATA
    ws_res.cell(4, 1, "CATERING PERFORMANCE").font = FONT_SUBTITLE
    ws_res.cell(5, 1, f"PERIODE {nama_periode}").font = FONT_SUBTITLE

    if is_draft:
        has_incomplete_kb = any(not c_data.get("kebersihan", {}).get("is_complete", False) for _, c_data in vendor_sheets)
        if has_incomplete_kb:
            ws_res.cell(6, 1, "DRAFT — DATA KEBERSIHAN BELUM TERSEDIA").font = FONT_ALERT
            ws_res.cell(7, 1, "Subtotal sementara (maks. 90)").font = FONT_DATA_BOLD
        else:
            ws_res.cell(6, 1, "DRAFT — LAPORAN PERFORMANCE BULANAN").font = FONT_SUBTITLE
            ws_res.cell(7, 1, "Total skor resmi (maks. 100)").font = FONT_DATA_BOLD

    # Header Tabel RESUME (Row 10)
    ws_res.row_dimensions[10].height = 28
    c_item_hdr = ws_res.cell(10, 1, "ITEM")
    c_item_hdr.font = FONT_HEADER
    c_item_hdr.fill = FILL_HEADER
    c_item_hdr.alignment = ALIGN_CENTER
    c_item_hdr.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)

    # Kolom Vendor Dinamis (Mulai Kolom 2 / B)
    col_idx = 2
    vendor_cols = {}
    for s_name, c_data in vendor_sheets:
        c_hdr = ws_res.cell(10, col_idx, c_data.get("catering_nama", "").upper())
        c_hdr.font = FONT_HEADER
        c_hdr.fill = FILL_HEADER
        c_hdr.alignment = ALIGN_CENTER
        vendor_cols[col_idx] = (s_name, c_data)
        col_idx += 1

    last_vendor_col = col_idx - 1
    for c_col in range(2, last_vendor_col + 1):
        is_last = (c_col == last_vendor_col)
        ws_res.cell(10, c_col).border = Border(
            top=SIDE_BLACK_MEDIUM,
            bottom=SIDE_BLACK_MEDIUM,
            left=SIDE_BLACK_THIN,
            right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
        )

    # Baris Item RESUME (Rows 11-16)
    resume_items = [
        (11, "1. Terlambat datang saat pengiriman", 9),
        (12, "2. Menu & Kelayakan", 10),
        (13, "3. Pencapaian kalori ( \u2265 900 kalori )", 11),
        (14, "4. Komplain", 12),
        (15, "5. Hasil audit dapur supplier katering", 13),
        (16, "6. Kebersihan kantin serta perlengkapan makan", 14),
    ]

    for r_idx, label, v_row in resume_items:
        ws_res.row_dimensions[r_idx].height = 20
        is_last_item = (r_idx == 16)
        b_bot = SIDE_BLACK_MEDIUM if is_last_item else SIDE_BLACK_THIN

        c_lbl = ws_res.cell(r_idx, 1, label)
        c_lbl.font = FONT_DATA
        c_lbl.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
        c_lbl.alignment = ALIGN_LEFT

        for c_col, (s_name, c_data) in vendor_cols.items():
            cell = ws_res.cell(r_idx, c_col)
            is_last = (c_col == last_vendor_col)
            cell.border = Border(
                top=SIDE_BLACK_THIN,
                bottom=b_bot,
                left=SIDE_BLACK_THIN,
                right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
            )
            cell.alignment = ALIGN_CENTER
            # Formula referensi ke sheet vendor: ='AKBAR'!E9 dsb
            cell.value = f"='{s_name}'!E{v_row}"
            cell.font = FONT_DATA

    # Row 17: TOTAL BULAN SEKARANG
    ws_res.row_dimensions[17].height = 22
    c_tot_lbl = ws_res.cell(17, 1, "TOTAL BULAN SEKARANG")
    c_tot_lbl.font = FONT_DATA_BOLD
    c_tot_lbl.fill = FILL_TOTAL
    c_tot_lbl.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_THIN, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
    c_tot_lbl.alignment = ALIGN_LEFT

    for c_col in range(2, last_vendor_col + 1):
        col_letter = get_column_letter(c_col)
        is_last = (c_col == last_vendor_col)
        c_cell = ws_res.cell(17, c_col)
        c_cell.value = f'=IF(COUNT({col_letter}11:{col_letter}16)=6,SUM({col_letter}11:{col_letter}16),"")'
        c_cell.font = FONT_DATA_BOLD
        c_cell.fill = FILL_TOTAL
        c_cell.border = Border(
            top=SIDE_BLACK_MEDIUM,
            bottom=SIDE_BLACK_THIN,
            left=SIDE_BLACK_THIN,
            right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
        )
        c_cell.alignment = ALIGN_CENTER

    # Row 18: GRADE BULAN SEKARANG
    ws_res.row_dimensions[18].height = 22
    c_grd_lbl = ws_res.cell(18, 1, "GRADE BULAN SEKARANG")
    c_grd_lbl.font = FONT_DATA_BOLD
    c_grd_lbl.fill = FILL_GRADE
    c_grd_lbl.border = Border(top=SIDE_BLACK_THIN, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
    c_grd_lbl.alignment = ALIGN_LEFT

    for c_col, (s_name, c_data) in vendor_cols.items():
        is_last = (c_col == last_vendor_col)
        c_cell = ws_res.cell(18, c_col, f"='{s_name}'!E16")
        c_cell.font = FONT_DATA_BOLD
        c_cell.fill = FILL_GRADE
        c_cell.border = Border(
            top=SIDE_BLACK_THIN,
            bottom=SIDE_BLACK_MEDIUM,
            left=SIDE_BLACK_THIN,
            right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
        )
        c_cell.alignment = ALIGN_CENTER

    # Row 19: TOTAL BULAN LALU
    ws_res.row_dimensions[19].height = 20
    c_tot_lalu_lbl = ws_res.cell(19, 1, "TOTAL BULAN LALU")
    c_tot_lalu_lbl.font = FONT_DATA
    c_tot_lalu_lbl.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_THIN, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
    c_tot_lalu_lbl.alignment = ALIGN_LEFT

    for c_col, (s_name, c_data) in vendor_cols.items():
        is_last = (c_col == last_vendor_col)
        c_cell = ws_res.cell(19, c_col, f"='{s_name}'!F15")
        c_cell.font = FONT_DATA
        c_cell.border = Border(
            top=SIDE_BLACK_MEDIUM,
            bottom=SIDE_BLACK_THIN,
            left=SIDE_BLACK_THIN,
            right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
        )
        c_cell.alignment = ALIGN_CENTER

    # Row 20: GRADE BULAN LALU
    ws_res.row_dimensions[20].height = 20
    c_grd_lalu_lbl = ws_res.cell(20, 1, "GRADE BULAN LALU")
    c_grd_lalu_lbl.font = FONT_DATA
    c_grd_lalu_lbl.border = Border(top=SIDE_BLACK_THIN, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
    c_grd_lalu_lbl.alignment = ALIGN_LEFT

    for c_col, (s_name, c_data) in vendor_cols.items():
        is_last = (c_col == last_vendor_col)
        c_cell_grd = ws_res.cell(20, c_col, f"='{s_name}'!F16")
        c_cell_grd.font = FONT_DATA
        c_cell_grd.border = Border(
            top=SIDE_BLACK_THIN,
            bottom=SIDE_BLACK_MEDIUM,
            left=SIDE_BLACK_THIN,
            right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
        )
        c_cell_grd.alignment = ALIGN_CENTER

    # Standar Grade & Action di RESUME (Rows 22-26)
    ws_res.row_dimensions[22].height = 22
    ws_res.merge_cells("A22:B22")
    c_res_g = ws_res.cell(22, 1, "GRADE")
    c_res_g.font = FONT_HEADER
    c_res_g.fill = FILL_HEADER
    c_res_g.alignment = ALIGN_CENTER
    c_res_g.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
    ws_res.cell(22, 2).fill = FILL_HEADER
    ws_res.cell(22, 2).border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

    c_res_a = ws_res.cell(22, 3, "ACTION")
    c_res_a.font = FONT_HEADER
    c_res_a.fill = FILL_HEADER
    c_res_a.alignment = ALIGN_CENTER
    c_res_a.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)

    grade_actions_res = [
        (23, "A", "Total Score 95 - 100", "Good"),
        (24, "B", "Total Score 81 - 94", "Standard"),
        (25, "C", "Total Score 66 - 80", "Diberikan surat teguran, Evaluasi Kontrak katering jika 2 bulan berturut - turut"),
        (26, "D", "Total Score 0 - 65", "Evaluasi Kontrak Katering"),
    ]
    for r_ga, g_code, g_range, g_act in grade_actions_res:
        ws_res.row_dimensions[r_ga].height = 20
        is_last = (r_ga == 26)
        b_bot = SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN

        c_c = ws_res.cell(r_ga, 1, g_code)
        c_c.font = FONT_DATA_BOLD
        c_c.alignment = ALIGN_CENTER
        c_c.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)

        c_r = ws_res.cell(r_ga, 2, g_range)
        c_r.font = FONT_DATA
        c_r.alignment = ALIGN_LEFT
        c_r.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

        c_a = ws_res.cell(r_ga, 3, g_act)
        c_a.font = FONT_DATA
        c_a.alignment = ALIGN_LEFT
        c_a.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)

    # Perhatian (Rows 28-30)
    ws_res.cell(28, 1, "Perhatian :").font = FONT_DATA_BOLD
    ws_res.cell(29, 1, "Total Order dipengaruhi dari Performance ( Penilaian ) Catering dan pemberlakuan").font = FONT_DATA
    ws_res.cell(30, 1, "sanksi sesuai ketentuan yang ada").font = FONT_DATA

    # Lebar Kolom RESUME
    ws_res.column_dimensions["A"].width = 42
    for c_col in range(2, max(last_vendor_col, 6) + 1):
        ws_res.column_dimensions[get_column_letter(c_col)].width = 20

    # --------------------------------------------------------------------------
    # E-Sign Table Standardized 3-Column: Approved (D) | Checked 2 (E) | Checked 1 (F)
    # Rows 3: Header, Rows 4-6: QR Box, Row 7: Initial
    # Placed at top-right corner of RESUME sheet without clashing with data/formulas
    # --------------------------------------------------------------------------
    ws_res.row_dimensions[3].height = 16
    ws_res.row_dimensions[4].height = 24
    ws_res.row_dimensions[5].height = 24
    ws_res.row_dimensions[6].height = 24
    ws_res.row_dimensions[7].height = 14

    esign_headers = [("D3", "Approved"), ("E3", "Checked 2"), ("F3", "Checked 1")]
    for coord, txt in esign_headers:
        c_h = ws_res[coord]
        c_h.value = txt
        c_h.font = FONT_DATA_BOLD
        c_h.alignment = ALIGN_CENTER
        c_h.border = Border(top=SIDE_BLACK_THIN, bottom=SIDE_BLACK_THIN, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

    for col_l in ["D", "E", "F"]:
        for r_box in range(4, 7):
            c_b = ws_res[f"{col_l}{r_box}"]
            c_b.border = Border(top=SIDE_BLACK_THIN, bottom=SIDE_BLACK_THIN, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)
        ws_res.merge_cells(f"{col_l}4:{col_l}6")

        c_init = ws_res[f"{col_l}7"]
        c_init.border = Border(top=SIDE_BLACK_THIN, bottom=SIDE_BLACK_THIN, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)
        c_init.alignment = ALIGN_CENTER
        c_init.font = FONT_DATA

    ws_res.print_area = f"$A$1:${get_column_letter(max(last_vendor_col, 6))}$48"

    # --------------------------------------------------------------------------
    # 3. SHEET STANDAR PENILAIAN
    # --------------------------------------------------------------------------
    ws_std = wb.create_sheet(title="STANDAR PENILAIAN")
    ws_std.page_setup.orientation = ws_std.ORIENTATION_PORTRAIT
    ws_std.page_setup.fitToPage = True
    ws_std.page_setup.fitToWidth = 1
    ws_std.page_setup.fitToHeight = 0
    ws_std.sheet_properties.pageSetUpPr.fitToPage = True

    ws_std.cell(1, 2, "PT. JATIM AUTOCOMP INDONESIA").font = FONT_SUBTITLE
    ws_std.cell(2, 2, "Wirring Harness Manufacturer").font = FONT_DATA
    ws_std.cell(4, 2, "STANDAR PENILAIAN SUPPLIER KATERING").font = FONT_TITLE
    ws_std.cell(5, 5, "Rev : 04/PK/JAI/IV/2018").font = FONT_SMALL

    # Header Tabel Standar (Row 6)
    std_headers = [(2, "NO"), (3, "ITEM PENILAIAN"), (4, "SCORE"), (5, "KRITERIA")]
    ws_std.row_dimensions[6].height = 26
    for col, txt in std_headers:
        is_first = (col == 2)
        is_last = (col == 5)
        c_h = ws_std.cell(6, col, txt)
        c_h.font = FONT_HEADER
        c_h.fill = FILL_HEADER
        c_h.alignment = ALIGN_CENTER
        c_h.border = Border(
            top=SIDE_BLACK_MEDIUM,
            bottom=SIDE_BLACK_MEDIUM,
            left=SIDE_BLACK_MEDIUM if is_first else SIDE_BLACK_THIN,
            right=SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN,
        )

    # 6 Scoring Reference Blocks (Rows 7-29 contiguous, fully bordered, merged item cells)
    criteria_blocks = [
        {
            "no": 1,
            "item": "Terlambat datang saat pengiriman",
            "rows": [
                (30, "Tidak pernah terlambat"),
                (20, "1 kali terlambat"),
                (10, "2-3 kali terlambat"),
                (0, "> 3 kali terlambat"),
            ],
        },
        {
            "no": 2,
            "item": "Menu & Kelayakan",
            "rows": [
                (20, "Kesesuaian > 80 %"),
                (10, "Kesesuaian \u2265 65 %"),
                (5, "Kesesuaian < 65 %"),
            ],
        },
        {
            "no": 3,
            "item": "Pencapaian kalori ( \u2265 900 kalori )",
            "rows": [
                (15, "100 % mencapai \u2265 900 kalori"),
                (12, "80 % - 99 % mencapai \u2265 900 kalori"),
                (8, "60 % - 79 % mencapai \u2265 900 kalori"),
                (5, "0 - 59 % mencapai \u2265 900 kalori"),
            ],
        },
        {
            "no": 4,
            "item": "Komplain",
            "rows": [
                (15, "Tidak ada komplain"),
                (12, "1-2 komplain"),
                (8, "3-4 komplain"),
                (5, "\u2265 5 komplain"),
            ],
        },
        {
            "no": 5,
            "item": "Hasil audit dapur supplier katering",
            "rows": [
                (10, "Nilai 95 - 100 %"),
                (8, "Nilai 81 - 94 %"),
                (6, "Nilai 70 - 80 %"),
                (4, "Nilai 0 - 69 %"),
            ],
        },
        {
            "no": 6,
            "item": "Kebersihan kantin serta perlengkapan makan",
            "rows": [
                (10, "Kantin bersih & rapi, serta tidak melanggar item kedisiplinan"),
                (8, "Kantin tidak rapi, serta tidak melanggar item kedisiplinan"),
                (6, "Kantin kotor, serta tidak melanggar item kedisiplinan"),
                (4, "Melanggar salah satu item kedisiplinan"),
            ],
        },
    ]

    curr_r = 7
    for block in criteria_blocks:
        start_r = curr_r
        n_rows = len(block["rows"])
        end_r = curr_r + n_rows - 1

        # Isi value hanya pada top cell dari merged block
        ws_std.cell(start_r, 2, block["no"])
        ws_std.cell(start_r, 3, block["item"])

        # Merge NO dan ITEM PENILAIAN secara vertikal
        ws_std.merge_cells(start_row=start_r, start_column=2, end_row=end_r, end_column=2)
        ws_std.merge_cells(start_row=start_r, start_column=3, end_row=end_r, end_column=3)

        for r_idx in range(start_r, end_r + 1):
            ws_std.row_dimensions[r_idx].height = 20
            idx_in_block = r_idx - start_r
            score_val, crit_text = block["rows"][idx_in_block]

            is_sec_top = (r_idx == start_r)
            is_sec_bot = (r_idx == end_r)

            b_top = SIDE_BLACK_MEDIUM if is_sec_top else SIDE_BLACK_THIN
            b_bot = SIDE_BLACK_MEDIUM if is_sec_bot else SIDE_BLACK_THIN

            # Col 2: NO (Merged perimeter explicit styling)
            c_no = ws_std.cell(r_idx, 2)
            c_no.font = FONT_DATA_BOLD
            c_no.alignment = ALIGN_CENTER
            c_no.border = Border(top=b_top, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)

            # Col 3: ITEM PENILAIAN (Merged perimeter explicit styling)
            c_it = ws_std.cell(r_idx, 3)
            c_it.font = FONT_DATA_BOLD
            c_it.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
            c_it.border = Border(top=b_top, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

            # Col 4: SCORE (Setiap baris kriteria memiliki border pemisah jelas)
            c_sc = ws_std.cell(r_idx, 4, score_val)
            c_sc.font = FONT_DATA
            c_sc.alignment = ALIGN_CENTER
            c_sc.border = Border(top=b_top, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

            # Col 5: KRITERIA (Setiap baris kriteria memiliki border pemisah jelas)
            c_cr = ws_std.cell(r_idx, 5, crit_text)
            c_cr.font = FONT_DATA
            c_cr.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
            c_cr.border = Border(top=b_top, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)

        curr_r = end_r + 1

    # Standar Grade & Action (Rows 31-35)
    ws_std.row_dimensions[30].height = 12  # Spacer
    ws_std.row_dimensions[31].height = 22

    ws_std.merge_cells("B31:C31")
    c_std_g = ws_std.cell(31, 2, "GRADE")
    c_std_g.font = FONT_HEADER
    c_std_g.fill = FILL_HEADER
    c_std_g.alignment = ALIGN_CENTER
    c_std_g.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)
    ws_std.cell(31, 3).fill = FILL_HEADER
    ws_std.cell(31, 3).border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

    ws_std.merge_cells("D31:E31")
    c_std_a = ws_std.cell(31, 4, "ACTION")
    c_std_a.font = FONT_HEADER
    c_std_a.fill = FILL_HEADER
    c_std_a.alignment = ALIGN_CENTER
    c_std_a.border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)
    ws_std.cell(31, 5).fill = FILL_HEADER
    ws_std.cell(31, 5).border = Border(top=SIDE_BLACK_MEDIUM, bottom=SIDE_BLACK_MEDIUM, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)

    std_ga = [
        (32, "A", "Total Score 95 - 100", "Good"),
        (33, "B", "Total Score 81 - 94", "Standard"),
        (34, "C", "Total Score 66 - 80", "Diberikan surat teguran, Evaluasi Kontrak katering jika 2 bulan berturut - turut"),
        (35, "D", "Total Score 0 - 65", "Evaluasi Kontrak Katering"),
    ]
    for r_ga, g_code, g_range, g_act in std_ga:
        ws_std.row_dimensions[r_ga].height = 20
        is_last = (r_ga == 35)
        b_bot = SIDE_BLACK_MEDIUM if is_last else SIDE_BLACK_THIN

        c_c = ws_std.cell(r_ga, 2, g_code)
        c_c.font = FONT_DATA_BOLD
        c_c.alignment = ALIGN_CENTER
        c_c.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_MEDIUM, right=SIDE_BLACK_THIN)

        c_r = ws_std.cell(r_ga, 3, g_range)
        c_r.font = FONT_DATA
        c_r.alignment = ALIGN_LEFT
        c_r.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_THIN)

        ws_std.merge_cells(f"D{r_ga}:E{r_ga}")
        c_a = ws_std.cell(r_ga, 4, g_act)
        c_a.font = FONT_DATA
        c_a.alignment = ALIGN_LEFT
        c_a.border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)

        ws_std.cell(r_ga, 5).border = Border(top=SIDE_BLACK_THIN, bottom=b_bot, left=SIDE_BLACK_THIN, right=SIDE_BLACK_MEDIUM)

    # Catatan Perhatian & Kedisiplinan (Rows 37-44)
    ws_std.row_dimensions[36].height = 10  # Spacer
    ws_std.cell(37, 2, "Perhatian :").font = FONT_DATA_BOLD
    notes = [
        (38, "● Total Order dipengaruhi dari Performance ( Penilaian ) Catering dan pemberlakuan sanksi sesuai ketentuan yang ada"),
        (39, "● Batas waktu kedatangan pengiriman catering sampai di tempat penurunan rantang maksimal jam 11.00 wib"),
        (40, "● Pengiriman Catering tidak boleh lebih dari satu kali jalan, apabila melebihi dianggap terlambat"),
        (41, "● Item Kedisiplinan Catering :"),
        (42, "   1. Rantang kosong & rantang sisa setelah selesai langsung dimasukkan mobil."),
        (43, "   2. Dilarang membuka rantang sisa dan memilah-milahnya di dalam area JAI."),
        (44, "   3. Dilarang memberi makan kucing."),
    ]
    for r_n, n_txt in notes:
        ws_std.cell(r_n, 2, n_txt).font = FONT_DATA

    # Lebar Kolom STANDAR
    ws_std.column_dimensions["A"].width = 3.5
    ws_std.column_dimensions["B"].width = 6.0
    ws_std.column_dimensions["C"].width = 36.0
    ws_std.column_dimensions["D"].width = 10.0
    ws_std.column_dimensions["E"].width = 54.0

    ws_std.print_area = "$A$1:$E$46"

    # Hapus sheet default bawaan openpyxl
    if default_sheet in wb.worksheets:
        wb.remove(default_sheet)

    # Calculation property agar Excel melakukan auto recalculation saat dibuka
    wb.calculation.calcMode = 'auto'
    wb.calculation.fullCalcOnLoad = True

    stream = io.BytesIO()
    wb.save(stream)
    stream.seek(0)
    return stream
