"""
Generator Excel untuk Laporan Kalori Bulanan.
Menghasilkan workbook dengan tampilan visual, warna, struktur tabel, dan print setup
yang identik dengan file referensi backend/data/SUMMARY WEEKLY SCORE 2026.xlsx.
"""

from __future__ import annotations
import datetime
from io import BytesIO
from typing import Any, Dict, List

import openpyxl
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.styles.colors import Color
from openpyxl.utils import get_column_letter

from kalori_monthly_calc import pack_monthly_report_layout

# Warna dan Style Referensi (SUMMARY WEEKLY SCORE 2026.xlsx)
# Abu-abu malam: theme 0 with tint -0.1499984740745262 -> effective #D9D9D9
FILL_NIGHT = PatternFill(fill_type="solid", fgColor=Color(theme=0, tint=-0.1499984740745262))
# Biru muda ∑ BAGUS: theme 4 with tint 0.5999938962981048 -> effective #B8CCE4
FILL_BAGUS = PatternFill(fill_type="solid", fgColor=Color(theme=4, tint=0.5999938962981048))
# Ungu muda ∑ NOT OK: solid ARGB FFEFD4F8 -> effective #EFD4F8
FILL_NOT_OK = PatternFill(fill_type="solid", fgColor=Color(rgb="FFEFD4F8"))
# Putih / tanpa fill untuk Pagi
FILL_WHITE = PatternFill(fill_type=None)

# Warna font: theme 1 tint 0.0 (hitam default Excel), dan ARGB FFFF0000 (merah)
COLOR_BLACK_THEME = Color(theme=1, tint=0.0)
COLOR_RED_RGB = Color(rgb="FFFF0000")

FONT_COMPANY_HEADER_1 = Font(name="Calibri", size=20.0, bold=True, color=COLOR_BLACK_THEME)
FONT_COMPANY_HEADER_2 = Font(name="Calibri", size=20.0, bold=False, color=COLOR_BLACK_THEME)
FONT_REPORT_TITLE = Font(name="Calibri", size=32.0, bold=True, color=COLOR_BLACK_THEME)
FONT_TABLE_HEADER = Font(name="Calibri", size=22.0, bold=True, color=COLOR_BLACK_THEME)
FONT_DATA_BOLD = Font(name="Calibri", size=22.0, bold=True, color=COLOR_BLACK_THEME)
FONT_DATA_NORMAL = Font(name="Calibri", size=22.0, bold=False, color=COLOR_BLACK_THEME)
FONT_DATA_RED = Font(name="Calibri", size=22.0, bold=False, color=COLOR_RED_RGB)

FONT_SCORE_NORMAL = Font(name="Calibri", size=20.0, bold=False, color=COLOR_BLACK_THEME)
FONT_SCORE_RED = Font(name="Calibri", size=20.0, bold=False, color=COLOR_RED_RGB)

ALIGN_CENTER_CENTER = Alignment(horizontal="center", vertical="center")
ALIGN_CENTER_WRAP = Alignment(horizontal="center", vertical="center", wrap_text=True)
ALIGN_LEFT_CENTER = Alignment(horizontal="left", vertical="center")

BORDER_THIN = Side(border_style="thin", color=Color(indexed=64))
BORDER_MEDIUM = Side(border_style="medium", color=Color(indexed=64))


def apply_cell_border(cell, top=None, bottom=None, left=None, right=None):
    """Menerapkan border pada cell dengan mempertahankan sisi yang sudah ada jika tidak dispesifikasikan."""
    current = cell.border
    new_top = top if top is not None else (current.top if current else Side())
    new_bottom = bottom if bottom is not None else (current.bottom if current else Side())
    new_left = left if left is not None else (current.left if current else Side())
    new_right = right if right is not None else (current.right if current else Side())
    cell.border = Border(top=new_top, bottom=new_bottom, left=new_left, right=new_right)


def style_range(ws, start_col: int, start_row: int, end_col: int, end_row: int,
                top=None, bottom=None, left=None, right=None):
    """Menerapkan border luar dan dalam untuk rentang cell tertentu."""
    for r in range(start_row, end_row + 1):
        for c in range(start_col, end_col + 1):
            cell = ws.cell(r, c)
            c_top = top if r == start_row and top is not None else None
            c_bottom = bottom if r == end_row and bottom is not None else None
            c_left = left if c == start_col and left is not None else None
            c_right = right if c == end_col and right is not None else None
            apply_cell_border(cell, top=c_top, bottom=c_bottom, left=c_left, right=c_right)


def generate_monthly_kalori_workbook(report_data: Dict[str, Any]) -> openpyxl.Workbook:
    """
    Membuat openpyxl Workbook sesuai spesifikasi referensi dari report_data.
    """
    wb = openpyxl.Workbook()
    ws = wb.active

    month_name = report_data["month_name"]
    year = report_data["year"]
    active_caterings = report_data["active_caterings"]
    num_caterings = len(active_caterings)
    max_row = report_data["max_row"]

    ws.title = month_name

    # 1. Page Setup & Print Properties (Persis dengan referensi SUMMARY WEEKLY SCORE 2026.xlsx)
    ws.page_setup.orientation = ws.ORIENTATION_LANDSCAPE
    ws.page_setup.paperSize = ws.PAPERSIZE_A4
    ws.page_setup.fitToWidth = 2
    ws.page_setup.fitToHeight = 2
    ws.page_setup.scale = 27
    ws.sheet_properties.pageSetUpPr.fitToPage = None

    ws.page_margins.left = 0.0
    ws.page_margins.right = 0.0
    ws.page_margins.top = 0.0
    ws.page_margins.bottom = 0.0
    ws.page_margins.header = 0.11811023622047245
    ws.page_margins.footer = 0.11811023622047245

    # 2. Definisikan pemetaan kolom Left Block dan Right Block
    # Left Block:
    # Col A: Week
    # Col B: Date
    # Col C: Shift
    # Col D to (3 + N): Caterings
    # Col (4 + N): Spacer
    # Right Block:
    # Col (5 + N): Week
    # Col (6 + N): Date
    # Col (7 + N): Shift
    # Col (8 + N) to (7 + 2*N): Caterings
    left_week_col = 1
    left_date_col = 2
    left_shift_col = 3
    left_cat_start_col = 4
    left_cat_end_col = 3 + num_caterings

    spacer_col = left_cat_end_col + 1

    right_week_col = spacer_col + 1
    right_date_col = right_week_col + 1
    right_shift_col = right_date_col + 1
    right_cat_start_col = right_shift_col + 1
    right_cat_end_col = right_shift_col + num_caterings

    total_cols = right_cat_end_col

    REFERENCE_VENDOR_WIDTH = 27.85546875
    REFERENCE_SHIFT_WIDTH = 14.0

    # 3. Column Widths (A=40.0, B=26.85546875, Shift cols=14.0, vendor cols=27.85546875, I=23.42578125, J=49.0, K=26.85546875)
    ws.column_dimensions[get_column_letter(left_week_col)].width = 40.0
    ws.column_dimensions[get_column_letter(left_date_col)].width = 26.85546875
    ws.column_dimensions[get_column_letter(left_shift_col)].width = REFERENCE_SHIFT_WIDTH

    # Seluruh kolom vendor blok kiri (D, E, F, G, H)
    for col_idx in range(left_cat_start_col, left_cat_end_col + 1):
        ws.column_dimensions[get_column_letter(col_idx)].width = REFERENCE_VENDOR_WIDTH

    ws.column_dimensions[get_column_letter(spacer_col)].width = 23.42578125

    ws.column_dimensions[get_column_letter(right_week_col)].width = 49.0
    ws.column_dimensions[get_column_letter(right_date_col)].width = 26.85546875
    ws.column_dimensions[get_column_letter(right_shift_col)].width = REFERENCE_SHIFT_WIDTH

    # Seluruh kolom vendor blok kanan (M, N, O, P, Q)
    for col_idx in range(right_cat_start_col, right_cat_end_col + 1):
        ws.column_dimensions[get_column_letter(col_idx)].width = REFERENCE_VENDOR_WIDTH

    # 4. Row Heights
    ws.row_dimensions[1].height = 26.25
    ws.row_dimensions[2].height = 26.25
    ws.row_dimensions[8].height = 50.1
    ws.row_dimensions[9].height = 50.1
    ws.row_dimensions[10].height = 15.75

    for r in range(11, max_row + 1):
        ws.row_dimensions[r].height = 42.95

    # 5. Header Perusahaan
    c_a1 = ws.cell(1, 1, value="PT. JATIM AUTOCOMP INDONESIA")
    c_a1.font = FONT_COMPANY_HEADER_1
    c_a1.alignment = ALIGN_LEFT_CENTER

    c_a2 = ws.cell(2, 1, value="INDUSTRIAL RELATION SECTION")
    c_a2.font = FONT_COMPANY_HEADER_2
    c_a2.alignment = ALIGN_LEFT_CENTER

    # 6. Judul Laporan (Baris 8 dan 9, merge A sampai total_cols)
    ws.merge_cells(start_row=8, start_column=1, end_row=8, end_column=total_cols)
    c_title = ws.cell(8, 1, value="SUMMARY  WEEKLY SCORE MAKAN PADA MASING - MASING CATERING")
    c_title.font = FONT_REPORT_TITLE
    c_title.alignment = ALIGN_CENTER_CENTER

    ws.merge_cells(start_row=9, start_column=1, end_row=9, end_column=total_cols)
    c_subtitle = ws.cell(9, 1, value=f"BULAN {month_name} {year}")
    c_subtitle.font = FONT_REPORT_TITLE
    c_subtitle.alignment = ALIGN_CENTER_CENTER

    # 7. Table Headers (Baris 11 dan 12)
    # Left Block Header
    ws.merge_cells(start_row=11, start_column=left_week_col, end_row=12, end_column=left_week_col)
    c_lw = ws.cell(11, left_week_col, value="WEEK ( W )")
    c_lw.font = FONT_TABLE_HEADER
    c_lw.alignment = ALIGN_CENTER_WRAP

    ws.merge_cells(start_row=11, start_column=left_date_col, end_row=12, end_column=left_shift_col)
    c_ld = ws.cell(11, left_date_col, value="DATE")
    c_ld.font = FONT_TABLE_HEADER
    c_ld.alignment = ALIGN_CENTER_CENTER

    ws.merge_cells(start_row=11, start_column=left_cat_start_col, end_row=11, end_column=left_cat_end_col)
    c_lc = ws.cell(11, left_cat_start_col, value="CATERING")
    c_lc.font = FONT_TABLE_HEADER
    c_lc.alignment = ALIGN_CENTER_CENTER

    for idx, cat in enumerate(active_caterings):
        cell_c = ws.cell(12, left_cat_start_col + idx, value=cat.nama.upper())
        cell_c.font = FONT_TABLE_HEADER
        cell_c.alignment = ALIGN_CENTER_WRAP

    # Right Block Header
    ws.merge_cells(start_row=11, start_column=right_week_col, end_row=12, end_column=right_week_col)
    c_rw = ws.cell(11, right_week_col, value="WEEK ( W )")
    c_rw.font = FONT_TABLE_HEADER
    c_rw.alignment = ALIGN_CENTER_WRAP

    ws.merge_cells(start_row=11, start_column=right_date_col, end_row=12, end_column=right_shift_col)
    c_rd = ws.cell(11, right_date_col, value="DATE")
    c_rd.font = FONT_TABLE_HEADER
    c_rd.alignment = ALIGN_CENTER_CENTER

    ws.merge_cells(start_row=11, start_column=right_cat_start_col, end_row=11, end_column=right_cat_end_col)
    c_rc = ws.cell(11, right_cat_start_col, value="CATERING")
    c_rc.font = FONT_TABLE_HEADER
    c_rc.alignment = ALIGN_CENTER_CENTER

    for idx, cat in enumerate(active_caterings):
        cell_c = ws.cell(12, right_cat_start_col + idx, value=cat.nama.upper())
        cell_c.font = FONT_TABLE_HEADER
        cell_c.alignment = ALIGN_CENTER_WRAP

    # Style Header Borders (Baris 11-12)
    for c in range(1, total_cols + 1):
        if c == spacer_col:
            continue
        for r in (11, 12):
            cell = ws.cell(r, c)
            top_b = BORDER_MEDIUM if r == 11 else BORDER_THIN
            bot_b = BORDER_MEDIUM if r == 12 else BORDER_THIN
            apply_cell_border(cell, top=top_b, bottom=bot_b, left=BORDER_THIN, right=BORDER_THIN)

    # 8. Render Data Items (Left Block & Right Block)
    def render_block_items(items: List[Dict[str, Any]], is_right: bool):
        w_col = right_week_col if is_right else left_week_col
        d_col = right_date_col if is_right else left_date_col
        s_col = right_shift_col if is_right else left_shift_col
        cat_start = right_cat_start_col if is_right else left_cat_start_col
        cat_end = right_cat_end_col if is_right else left_cat_end_col

        # Lacak rentang minggu untuk merge kolom Week
        week_segments: Dict[str, Dict[str, int]] = {}

        for item in items:
            item_type = item["type"]
            r_start = item["start_row"]
            r_end = item["end_row"]

            if item_type == "DAY":
                w_name = item["week_name"]
                if w_name not in week_segments:
                    week_segments[w_name] = {"start": r_start, "end": r_end}
                else:
                    week_segments[w_name]["end"] = r_end

                d_data = item["day_data"]
                d_obj = d_data["date"]
                is_hol = d_data["is_holiday"]
                is_weekend = d_obj.weekday() in (5, 6)

                # Tanggal: merge r_start:r_end pada d_col
                ws.merge_cells(start_row=r_start, start_column=d_col, end_row=r_end, end_column=d_col)
                date_cell = ws.cell(r_start, d_col, value=datetime.datetime(d_obj.year, d_obj.month, d_obj.day))
                date_cell.number_format = "[$-409]dd\\-mmm\\-yy;@"
                date_cell.alignment = ALIGN_CENTER_CENTER
                date_cell.font = FONT_DATA_RED if (is_hol or is_weekend) else FONT_DATA_NORMAL

                # Shift Pagi (r_start)
                c_pagi = ws.cell(r_start, s_col, value="Pagi")
                c_pagi.font = FONT_DATA_NORMAL
                c_pagi.alignment = ALIGN_LEFT_CENTER

                # Shift Malam (r_end)
                c_malam = ws.cell(r_end, s_col, value="Malam")
                c_malam.font = FONT_DATA_NORMAL
                c_malam.alignment = ALIGN_LEFT_CENTER
                c_malam.fill = FILL_NIGHT

                # Scores
                for c_idx, cat in enumerate(active_caterings):
                    col_idx = cat_start + c_idx

                    # Score Pagi
                    p_val = d_data["pagi_scores"].get(cat.id, "-")
                    if p_val in ("+1", "1", 1):
                        cell_val_p = 1
                        cell_font_p = FONT_SCORE_NORMAL
                        cell_fmt_p = "0"
                    elif p_val in ("-1", -1):
                        cell_val_p = -1
                        cell_font_p = FONT_SCORE_RED
                        cell_fmt_p = "0"
                    else:  # "-"
                        cell_val_p = "-"
                        cell_font_p = FONT_SCORE_RED if is_hol else FONT_SCORE_NORMAL
                        cell_fmt_p = "@"

                    cell_p = ws.cell(r_start, col_idx, value=cell_val_p)
                    cell_p.alignment = ALIGN_CENTER_CENTER
                    cell_p.number_format = cell_fmt_p
                    cell_p.font = cell_font_p

                    # Score Malam
                    m_val = d_data["malam_scores"].get(cat.id, "-")
                    if m_val in ("+1", "1", 1):
                        cell_val_m = 1
                        cell_font_m = FONT_SCORE_NORMAL
                        cell_fmt_m = "0"
                    elif m_val in ("-1", -1):
                        cell_val_m = -1
                        cell_font_m = FONT_SCORE_RED
                        cell_fmt_m = "0"
                    else:  # "-"
                        cell_val_m = "-"
                        cell_font_m = FONT_SCORE_RED if is_hol else FONT_SCORE_NORMAL
                        cell_fmt_m = "@"

                    cell_m = ws.cell(r_end, col_idx, value=cell_val_m)
                    cell_m.alignment = ALIGN_CENTER_CENTER
                    cell_m.fill = FILL_NIGHT
                    cell_m.number_format = cell_fmt_m
                    cell_m.font = cell_font_m

                # Thin borders untuk baris data
                for r in (r_start, r_end):
                    for c in range(w_col, cat_end + 1):
                        apply_cell_border(ws.cell(r, c), top=BORDER_THIN, bottom=BORDER_THIN,
                                          left=BORDER_THIN, right=BORDER_THIN)

            elif item_type == "SUMMARY":
                # Baris 1: ∑ BAGUS (r_start)
                ws.merge_cells(start_row=r_start, start_column=d_col, end_row=r_start, end_column=s_col)
                lbl_b = ws.cell(r_start, d_col, value="∑ BAGUS")
                lbl_b.font = FONT_DATA_BOLD
                lbl_b.alignment = ALIGN_CENTER_CENTER
                lbl_b.fill = FILL_BAGUS
                ws.cell(r_start, s_col).fill = FILL_BAGUS

                for c_idx, cat in enumerate(active_caterings):
                    col_idx = cat_start + c_idx
                    val = item["bagus_counts"].get(cat.id, 0)
                    cell = ws.cell(r_start, col_idx, value=val)
                    cell.font = FONT_DATA_NORMAL
                    cell.alignment = ALIGN_CENTER_CENTER
                    cell.fill = FILL_BAGUS
                    cell.number_format = "#,##0_);[Red](#,##0)"

                # Baris 2: ∑ NOT OK (r_end)
                ws.merge_cells(start_row=r_end, start_column=d_col, end_row=r_end, end_column=s_col)
                lbl_no = ws.cell(r_end, d_col, value="∑ NOT OK")
                lbl_no.font = FONT_DATA_BOLD
                lbl_no.alignment = ALIGN_CENTER_CENTER
                lbl_no.fill = FILL_NOT_OK
                ws.cell(r_end, s_col).fill = FILL_NOT_OK

                for c_idx, cat in enumerate(active_caterings):
                    col_idx = cat_start + c_idx
                    val = item["not_ok_counts"].get(cat.id, 0)
                    cell = ws.cell(r_end, col_idx, value=val)
                    cell.font = FONT_DATA_RED if val > 0 else FONT_DATA_NORMAL
                    cell.alignment = ALIGN_CENTER_CENTER
                    cell.fill = FILL_NOT_OK
                    cell.number_format = "#,##0_);[Red](#,##0)"

                # Border untuk baris summary
                for r in (r_start, r_end):
                    for c in range(w_col, cat_end + 1):
                        apply_cell_border(ws.cell(r, c), top=BORDER_THIN, bottom=BORDER_THIN,
                                          left=BORDER_THIN, right=BORDER_THIN)

            elif item_type == "CUMULATIVE":
                # Baris 1: JUMLAH :  | ∑ BAGUS (r_start)
                c_j = ws.cell(r_start, w_col, value="JUMLAH : ")
                c_j.font = FONT_DATA_BOLD
                c_j.alignment = ALIGN_LEFT_CENTER
                c_j.fill = FILL_NIGHT

                ws.merge_cells(start_row=r_start, start_column=d_col, end_row=r_start, end_column=s_col)
                lbl_cb = ws.cell(r_start, d_col, value="∑ BAGUS")
                lbl_cb.font = FONT_DATA_BOLD
                lbl_cb.alignment = ALIGN_CENTER_CENTER
                lbl_cb.fill = FILL_BAGUS
                ws.cell(r_start, s_col).fill = FILL_BAGUS

                for c_idx, cat in enumerate(active_caterings):
                    col_idx = cat_start + c_idx
                    val = item["cum_bagus"].get(cat.id, 0)
                    cell = ws.cell(r_start, col_idx, value=val)
                    cell.font = FONT_DATA_NORMAL
                    cell.alignment = ALIGN_CENTER_CENTER
                    cell.fill = FILL_BAGUS
                    cell.number_format = "#,##0_);[Red](#,##0)"

                # Baris 2: W1 + W2 ... | ∑ NOT OK (r_end)
                c_w = ws.cell(r_end, w_col, value=item["cum_label"])
                c_w.font = FONT_DATA_BOLD
                c_w.alignment = ALIGN_LEFT_CENTER
                c_w.fill = FILL_NIGHT

                ws.merge_cells(start_row=r_end, start_column=d_col, end_row=r_end, end_column=s_col)
                lbl_cno = ws.cell(r_end, d_col, value="∑ NOT OK")
                lbl_cno.font = FONT_DATA_BOLD
                lbl_cno.alignment = ALIGN_CENTER_CENTER
                lbl_cno.fill = FILL_NOT_OK
                ws.cell(r_end, s_col).fill = FILL_NOT_OK

                for c_idx, cat in enumerate(active_caterings):
                    col_idx = cat_start + c_idx
                    val = item["cum_not_ok"].get(cat.id, 0)
                    cell = ws.cell(r_end, col_idx, value=val)
                    cell.font = FONT_DATA_RED if val > 0 else FONT_DATA_NORMAL
                    cell.alignment = ALIGN_CENTER_CENTER
                    cell.fill = FILL_NOT_OK
                    cell.number_format = "#,##0_);[Red](#,##0)"

                # Border untuk baris cumulative
                for r in (r_start, r_end):
                    for c in range(w_col, cat_end + 1):
                        apply_cell_border(ws.cell(r, c), top=BORDER_THIN, bottom=BORDER_THIN,
                                          left=BORDER_THIN, right=BORDER_THIN)

        # Merge cells kolom Week untuk setiap minggu
        for w_name, seg in week_segments.items():
            s_r = seg["start"]
            e_r = seg["end"]
            if s_r < e_r:
                ws.merge_cells(start_row=s_r, start_column=w_col, end_row=e_r, end_column=w_col)
            c_week = ws.cell(s_r, w_col, value=w_name)
            c_week.font = FONT_DATA_BOLD
            c_week.alignment = ALIGN_CENTER_CENTER

        # Terapkan border medium untuk batas kelompok dan batas luar
        # 1. Kolom vertikal tebal (medium)
        for r in range(13, (max_row + 1) if not is_right else (items[-1]["end_row"] + 1 if items else 13)):
            # Garis kiri week col
            apply_cell_border(ws.cell(r, w_col), left=BORDER_MEDIUM)
            # Garis antara week col dan date col
            apply_cell_border(ws.cell(r, w_col), right=BORDER_MEDIUM)
            apply_cell_border(ws.cell(r, d_col), left=BORDER_MEDIUM)
            # Garis antara shift col dan catering start col
            apply_cell_border(ws.cell(r, s_col), right=BORDER_MEDIUM)
            apply_cell_border(ws.cell(r, cat_start), left=BORDER_MEDIUM)
            # Garis kanan catering end col
            apply_cell_border(ws.cell(r, cat_end), right=BORDER_MEDIUM)

        # 2. Garis horizontal medium di bawah setiap grup minggu (setelah ∑ NOT OK atau cumulative)
        for item in items:
            if item["type"] in ("SUMMARY", "CUMULATIVE"):
                r_bot = item["end_row"]
                for c in range(w_col, cat_end + 1):
                    apply_cell_border(ws.cell(r_bot, c), bottom=BORDER_MEDIUM)

    render_block_items(report_data["left_items"], is_right=False)
    render_block_items(report_data["right_items"], is_right=True)

    # 9. Print Area & Clean Grid
    last_col_letter = get_column_letter(total_cols)
    ws.print_area = f"'{month_name}'!$A$1:${last_col_letter}${max_row}"

    # Pastikan grid lines ditampilkan
    ws.views.sheetView[0].showGridLines = True

    # Hapus seluruh freeze pane / split pane agar sheet dapat discroll bebas
    ws.freeze_panes = None
    ws.sheet_view.pane = None

    return wb


def generate_monthly_kalori_excel_bytes(report_data: Dict[str, Any]) -> bytes:
    """Helper untuk menghasilkan buffer bytes file Excel."""
    wb = generate_monthly_kalori_workbook(report_data)
    stream = BytesIO()
    wb.save(stream)
    stream.seek(0)
    return stream.getvalue()
