"""
Modul Pembuatan File Excel Laporan Punishment Bulanan PT JAI.
Format output mengikuti struktur referensi: KETERANGAN PUNISHMENTT JULI 2026.xlsx
Sheet:
1. HASIL AUDIT
2. JUMLAH KOMPLIN
3. JUMPLAH KALORI
4. MENU DAN KELAYAAN
5. PENGIRIMAN TERLAMBAT DATANG
6. RESUM
7. SIGN
"""
import io
import os
import copy
from datetime import date, datetime
from typing import Dict, Any, List, Optional
import openpyxl
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.utils import get_column_letter
from punishment_monthly_calc import percentage_to_excel_ratio
from punishment_monthly_contract import adapt_snapshot_kalori_minus

BULAN_INDONESIA = {
    1: "Januari", 2: "Februari", 3: "Maret", 4: "April",
    5: "Mei", 6: "Juni", 7: "Juli", 8: "Agustus",
    9: "September", 10: "Oktober", 11: "November", 12: "Desember"
}

THIN_BORDER = Border(
    left=Side(style='thin', color='000000'),
    right=Side(style='thin', color='000000'),
    top=Side(style='thin', color='000000'),
    bottom=Side(style='thin', color='000000')
)

HEADER_FILL = PatternFill(start_color="F2F2F2", end_color="F2F2F2", fill_type="solid")
HEADER_FONT = Font(name="Calibri", size=11, bold=True)
DATA_FONT = Font(name="Calibri", size=11, bold=False)
BOLD_DATA_FONT = Font(name="Calibri", size=11, bold=True)
TITLE_FONT = Font(name="Calibri", size=11, bold=True)

CENTER_ALIGN = Alignment(horizontal="center", vertical="center", wrap_text=True)
LEFT_ALIGN = Alignment(horizontal="left", vertical="center", wrap_text=True)
RIGHT_ALIGN = Alignment(horizontal="right", vertical="center", wrap_text=True)
PUNISH_NUM_FMT = "0.##"


def format_periode_str(start_date_str: str, end_date_str: str) -> str:
    try:
        d_start = date.fromisoformat(start_date_str)
        d_end = date.fromisoformat(end_date_str)
        s_day = d_start.day
        s_mon = BULAN_INDONESIA.get(d_start.month, str(d_start.month))
        e_day = d_end.day
        e_mon = BULAN_INDONESIA.get(d_end.month, str(d_end.month))
        e_year = d_end.year
        if d_start.year != d_end.year:
            return f"{s_day} {s_mon} {d_start.year} - {e_day} {e_mon} {e_year}"
        return f"{s_day} {s_mon} - {e_day} {e_mon} {e_year}"
    except Exception:
        return f"{start_date_str} - {end_date_str}"


def style_cell(cell, font=None, alignment=None, fill=None, border=None, number_format=None):
    if font:
        cell.font = font
    if alignment:
        cell.alignment = alignment
    if fill:
        cell.fill = fill
    if border:
        cell.border = border
    if number_format:
        cell.number_format = number_format


def clean_trailing_rows(ws, start_row: int, end_row: Optional[int] = None, min_col: int = 1, max_col: Optional[int] = None):
    """
    Membersihkan baris kosong/sisa template dan unmerge ranges terkait:
    - Meng-unmerge semua merged cell ranges yang berada pada atau bersinggungan dengan area tersebut.
    - Mengosongkan value, border, dan fill pada semua sel di area tersebut.
    """
    if end_row is None:
        end_row = max(ws.max_row, start_row + 15)
    if max_col is None:
        max_col = max(ws.max_column, min_col + 15)

    if start_row > end_row:
        return

    ranges_to_unmerge = []
    for r in list(ws.merged_cells.ranges):
        row_overlap = not (r.max_row < start_row or r.min_row > end_row)
        col_overlap = not (r.max_col < min_col or r.min_col > max_col)
        if row_overlap and col_overlap:
            ranges_to_unmerge.append(r)

    for r in ranges_to_unmerge:
        ws.unmerge_cells(str(r))

    empty_border = Border()
    empty_fill = PatternFill(fill_type=None)
    for r in range(start_row, end_row + 1):
        for c in range(min_col, max_col + 1):
            cell = ws.cell(r, c)
            cell.value = None
            cell.border = empty_border
            cell.fill = empty_fill
            cell.number_format = "General"



def prune_empty_cells(ws):
    """
    Membersihkan sel-sel kosong tak terpakai dari internal sheet
    sehingga max_row dan max_column mencerminkan data aktual secara akurat.
    """
    to_delete = []
    for coord, cell in list(ws._cells.items()):
        b = cell.border
        has_b = b and (
            (b.left and b.left.style) or
            (b.right and b.right.style) or
            (b.top and b.top.style) or
            (b.bottom and b.bottom.style)
        )
        has_f = cell.fill and cell.fill.fill_type
        if cell.value is None and not has_b and not has_f and cell.comment is None:
            to_delete.append(coord)

    for coord in to_delete:
        del ws._cells[coord]

    for r in list(ws.row_dimensions.keys()):
        if r > ws.max_row:
            del ws.row_dimensions[r]

def generate_punishment_monthly_excel(report_snapshot: Dict[str, Any], config: Optional[Dict[str, Any]] = None) -> bytes:
    """
    Menghasilkan file Excel byte stream mengikuti template KETERANGAN PUNISHMENTT JULI 2026.xlsx
    """
    config = config or {}
    report_snapshot = adapt_snapshot_kalori_minus(report_snapshot)
    clean_path = os.path.join(os.path.dirname(__file__), "data", "template_punishment_bulanan_clean.xlsx")
    orig_path = os.path.join(os.path.dirname(__file__), "data", "KETERANGAN PUNISHMENTT JULI 2026.xlsx")
    template_path = clean_path if os.path.exists(clean_path) else orig_path

    # Load template workbook
    if os.path.exists(template_path):
        wb = openpyxl.load_workbook(template_path)
    else:
        # Fallback jika template tidak ada: buat workbook baru dengan 7 sheet
        wb = openpyxl.Workbook()
        wb.active.title = "HASIL AUDIT"
        for sname in ["JUMLAH KOMPLIN", "JUMPLAH KALORI", "MENU DAN KELAYAAN", "PENGIRIMAN TERLAMBAT DATANG", "RESUM", "SIGN"]:
            wb.create_sheet(sname)

    # Bersihkan metadata author
    wb.properties.creator = "Catering Management System"
    wb.properties.lastModifiedBy = "Catering Management System"

    periode_obj = report_snapshot.get("periode", {})
    start_date_str = report_snapshot.get("start_date") or periode_obj.get("start_date", "")
    end_date_str = report_snapshot.get("end_date") or periode_obj.get("end_date", "")
    periode_label = format_periode_str(start_date_str, end_date_str).upper()

    caterings_data = report_snapshot.get("caterings", [])
    num_caterings = len(caterings_data)

    # =========================================================================
    # SHEET 1: HASIL AUDIT
    # =========================================================================
    if "HASIL AUDIT" in wb.sheetnames:
        ws = wb["HASIL AUDIT"]
        ws["A5"] = f"HASIL AUDIT PERIODE  {periode_label}"
        # Bersihkan duplicate grade legend pada baris 14 ke atas jika ada di template
        for r_clear in range(14, 25):
            c_i = ws.cell(r_clear, 9)
            c_j = ws.cell(r_clear, 10)
            c_i.value = None
            c_j.value = None
            c_i.fill = PatternFill(fill_type=None)
            c_j.fill = PatternFill(fill_type=None)
            c_i.border = Border()
            c_j.border = Border()
        # Mulai dari baris 10
        start_row = 10
        for i, c in enumerate(caterings_data):
            row = start_row + i
            audit = c.get("hasil_audit", {})
            score = audit.get("score_performance", 0.0)
            nilai_raw = audit.get("nilai_audit_persen") if audit.get("nilai_audit_persen") is not None else audit.get("nilai_audit_raw")
            nilai_val = percentage_to_excel_ratio(nilai_raw) if (nilai_raw is not None) else None
            grade = audit.get("grade", "-")
            p_pagi = audit.get("pengurangan_poin_pagi", 0.0)
            p_malam = audit.get("pengurangan_poin_malam", 0.0)

            c_no = ws.cell(row, 1, i + 1)
            c_no.number_format = "0"
            ws.cell(row, 2, c.get("catering_nama", ""))

            cell_c = ws.cell(row, 3, nilai_val if nilai_val is not None else "-")
            if nilai_val is not None:
                cell_c.number_format = "0.00%"

            ws.cell(row, 4, grade)
            c_pagi = ws.cell(row, 5, p_pagi if p_pagi > 0 else "-")
            if isinstance(c_pagi.value, (int, float)):
                c_pagi.number_format = "0" if c_pagi.value == int(c_pagi.value) else "0.##"
            c_malam = ws.cell(row, 6, p_malam if p_malam > 0 else "-")
            if isinstance(c_malam.value, (int, float)):
                c_malam.number_format = "0" if c_malam.value == int(c_malam.value) else "0.##"
            c_score = ws.cell(row, 7, int(score) if isinstance(score, (int, float)) and score == int(score) else score)
            c_score.number_format = "0"

            for col in range(1, 8):
                cell = ws.cell(row, col)
                cell.border = THIN_BORDER
                cell.font = DATA_FONT
                cell.alignment = LEFT_ALIGN if col == 2 else CENTER_ALIGN

        # Bersihkan baris sisa jika katering kurang dari template
        clean_trailing_rows(ws, start_row=start_row + num_caterings, end_row=max(ws.max_row, 35), min_col=1, max_col=max(ws.max_column, 10))

    # =========================================================================
    # SHEET 2: JUMLAH KOMPLIN
    # =========================================================================
    if "JUMLAH KOMPLIN" in wb.sheetnames:
        ws = wb["JUMLAH KOMPLIN"]
        ws["A5"] = f"JUMLAH KOMPLAIN   {periode_label}"
        start_row = 10
        for i, c in enumerate(caterings_data):
            row = start_row + i
            komplain = c.get("jumlah_komplain", {})
            cnt = komplain.get("jumlah_komplain", 0)
            ringkasan = komplain.get("ringkasan_jenis", "-")
            p_pagi = komplain.get("pengurangan_poin_pagi", 0.0)
            p_malam = komplain.get("pengurangan_poin_malam", 0.0)
            score = komplain.get("score_performance", 15.0)

            c_no = ws.cell(row, 1, i + 1)
            c_no.number_format = "0"
            ws.cell(row, 2, c.get("catering_nama", ""))
            c_cnt = ws.cell(row, 3, int(cnt))
            c_cnt.number_format = "0"
            ws.cell(row, 4, ringkasan if ringkasan else "-")
            c_pagi = ws.cell(row, 5, p_pagi)
            if isinstance(c_pagi.value, (int, float)):
                c_pagi.number_format = "0" if c_pagi.value == int(c_pagi.value) else "0.##"
            c_malam = ws.cell(row, 6, p_malam)
            if isinstance(c_malam.value, (int, float)):
                c_malam.number_format = "0" if c_malam.value == int(c_malam.value) else "0.##"
            c_score = ws.cell(row, 7, int(score) if isinstance(score, (int, float)) and score == int(score) else score)
            c_score.number_format = "0"

            for col in range(1, 8):
                cell = ws.cell(row, col)
                cell.border = THIN_BORDER
                cell.font = DATA_FONT
                cell.alignment = LEFT_ALIGN if col in (2, 4) else CENTER_ALIGN

        clean_trailing_rows(ws, start_row=start_row + num_caterings, end_row=max(ws.max_row, 25), min_col=1, max_col=max(ws.max_column, 10))

    # =========================================================================
    # SHEET 3: JUMPLAH KALORI
    # =========================================================================
    if "JUMPLAH KALORI" in wb.sheetnames:
        ws = wb["JUMPLAH KALORI"]
        ws["A6"] = f"JUMLAH KALORI PERIODE   {periode_label}"
        ws["A19"] = f"PENCAPAIAN KALORI PERIODE   {periode_label}"

        # Tabel 1: Punishment Kalori Minus (baris 13)
        start_row_minus = 13
        for i, c in enumerate(caterings_data):
            row = start_row_minus + i
            kalori = c.get("jumlah_kalori") or c.get("kalori") or {}
            pelanggaran = kalori.get("pelanggaran") or {}
            g_single = pelanggaran.get("minus_tidak_berturut") or pelanggaran.get("kategori_1") or {}
            g_streak2 = pelanggaran.get("minus_2_berturut") or pelanggaran.get("kategori_2") or {}
            g_streak_next = pelanggaran.get("minus_berikutnya") or pelanggaran.get("kategori_3") or {}
            g_sidak = pelanggaran.get("minus_sidak") or pelanggaran.get("kategori_4") or {}

            ws.cell(row, 1, i + 1).number_format = "0"
            ws.cell(row, 2, c.get("catering_nama", ""))

            # Group A: Tidak berturut-turut
            tgl_single = g_single.get("tanggal_display") or g_single.get("tgl") or "-"
            ws.cell(row, 3, "Ada" if g_single.get("ada") else "Tidak ada")
            ws.cell(row, 4, tgl_single if tgl_single != "" else "-")
            ws.cell(row, 5, int(g_single.get("jumlah", 0))).number_format = "0"
            ws.cell(row, 6, g_single.get("pengurangan_poin_pagi", 0.0)).number_format = "0"
            ws.cell(row, 7, g_single.get("pengurangan_poin_malam", 0.0)).number_format = "0"

            # Group B: 2x berturut-turut
            tgl_streak2 = g_streak2.get("tanggal_display") or g_streak2.get("tgl") or "-"
            ws.cell(row, 8, "Ada" if g_streak2.get("ada") else "Tidak ada")
            ws.cell(row, 9, tgl_streak2 if tgl_streak2 != "" else "-")
            ws.cell(row, 10, int(g_streak2.get("jumlah", 0))).number_format = "0"
            ws.cell(row, 11, g_streak2.get("pengurangan_poin_pagi", 0.0)).number_format = "0"
            ws.cell(row, 12, g_streak2.get("pengurangan_poin_malam", 0.0)).number_format = "0"

            # Group C: Berikutnya setelah 2 hari
            tgl_streak_next = g_streak_next.get("tanggal_display") or g_streak_next.get("tgl") or "-"
            ws.cell(row, 13, "Ada" if g_streak_next.get("ada") else "-")
            ws.cell(row, 14, tgl_streak_next if tgl_streak_next != "" else "-")
            ws.cell(row, 15, int(g_streak_next.get("jumlah", 0))).number_format = "0"
            ws.cell(row, 16, g_streak_next.get("pengurangan_poin_pagi", 0.0)).number_format = "0"
            ws.cell(row, 17, g_streak_next.get("pengurangan_poin_malam", 0.0)).number_format = "0"

            # Group D: Sidak
            tgl_sidak = g_sidak.get("tanggal_display") or g_sidak.get("tgl") or "-"
            ws.cell(row, 18, "Ada" if g_sidak.get("ada") else "-")
            ws.cell(row, 19, tgl_sidak if tgl_sidak != "" else "-")
            ws.cell(row, 20, int(g_sidak.get("jumlah", 0))).number_format = "0"
            ws.cell(row, 21, g_sidak.get("pengurangan_poin_pagi", 0.0)).number_format = "0"
            ws.cell(row, 22, g_sidak.get("pengurangan_poin_malam", 0.0)).number_format = "0"

            # Formula Total Reward & Punishment: (A+B+C+D)
            ws.cell(row, 23, f"=F{row}+K{row}+P{row}+U{row}").number_format = "0"
            ws.cell(row, 24, f"=G{row}+L{row}+Q{row}+V{row}").number_format = "0"

            for col in range(1, 25):
                cell = ws.cell(row, col)
                cell.border = THIN_BORDER
                cell.font = DATA_FONT
                cell.alignment = LEFT_ALIGN if col == 2 else CENTER_ALIGN

        # Bersihkan sisa baris Tabel 1 (Kalori Minus)
        clean_trailing_rows(ws, start_row=start_row_minus + num_caterings, end_row=18, min_col=1, max_col=24)

        # Tabel 2: Pencapaian Kalori (baris 22)
        start_row_pencapaian = 22
        for i, c in enumerate(caterings_data):
            row = start_row_pencapaian + i
            kalori = c.get("jumlah_kalori") or c.get("kalori") or {}
            pencapaian = kalori.get("pencapaian") or {}
            mencapai = pencapaian.get("jumlah_mencapai", 0)
            tidak_mencapai = pencapaian.get("jumlah_tidak_mencapai", 0)
            score = kalori.get("score_performance", 0.0)

            c_no = ws.cell(row, 1, i + 1)
            c_no.number_format = "0"
            ws.cell(row, 2, c.get("catering_nama", ""))
            c_mencapai = ws.cell(row, 3, int(mencapai))
            c_mencapai.number_format = "0"
            c_tidak = ws.cell(row, 5, int(tidak_mencapai))
            c_tidak.number_format = "0"
            c_tot = ws.cell(row, 7, f"=C{row}+E{row}")
            c_tot.number_format = "0"

            cell_i = ws.cell(row, 9, f"=IF(G{row}>0, C{row}/G{row}, 0)")
            cell_i.number_format = "0.00%"
            c_score = ws.cell(row, 11, int(score) if isinstance(score, (int, float)) and score == int(score) else score)
            c_score.number_format = "0"

            # Pastikan merged cells horizontal Tabel 2 dibuat rapi
            existing_ranges = [str(r) for r in ws.merged_cells.ranges]
            for col_s, col_e in [(3, 4), (5, 6), (7, 8), (9, 10), (11, 12)]:
                r_str = f"{get_column_letter(col_s)}{row}:{get_column_letter(col_e)}{row}"
                if r_str not in existing_ranges:
                    ws.merge_cells(r_str)

            for col in range(1, 13):
                cell = ws.cell(row, col)
                cell.border = THIN_BORDER
                cell.font = DATA_FONT
                cell.alignment = LEFT_ALIGN if col == 2 else CENTER_ALIGN

        # Bersihkan sisa baris Tabel 2 (Pencapaian Kalori)
        clean_trailing_rows(ws, start_row=start_row_pencapaian + num_caterings, end_row=max(ws.max_row, 30), min_col=1, max_col=13)

        # =====================================================================
        # OPTIONAL DETAIL SECTIONS: DETAIL KETERANGAN KALORI MINUS (Cols P..T)
        # =====================================================================
        # Bersihkan seluruh area template statis cols N..X dari baris 21 ke bawah
        clean_trailing_rows(ws, start_row=21, end_row=max(ws.max_row, 50), min_col=14, max_col=max(ws.max_column, 25))



    # =========================================================================
    # SHEET 4: MENU DAN KELAYAAN
    # =========================================================================
    if "MENU DAN KELAYAAN" in wb.sheetnames:
        ws = wb["MENU DAN KELAYAAN"]
        ws["A6"] = f"MENU DAN KELAYAKAN PERIODE   {periode_label}"
        start_row = 11
        for i, c in enumerate(caterings_data):
            row = start_row + i
            kelayakan = c.get("menu_kelayakan", {})
            nilai_raw = kelayakan.get("nilai_persen") if kelayakan.get("nilai_persen") is not None else kelayakan.get("nilai_kelayakan_raw")
            nilai_val = percentage_to_excel_ratio(nilai_raw) if (nilai_raw is not None) else None
            p_pagi = kelayakan.get("pengurangan_poin_pagi", 0.0)
            score = kelayakan.get("score_performance", 0.0)

            c_no = ws.cell(row, 1, i + 1)
            c_no.number_format = "0"
            ws.cell(row, 2, c.get("catering_nama", ""))

            cell_c = ws.cell(row, 3, nilai_val if nilai_val is not None else "-")
            if nilai_val is not None:
                cell_c.number_format = "0.00%"

            c_pagi = ws.cell(row, 4, p_pagi if p_pagi > 0 else "-")
            if isinstance(c_pagi.value, (int, float)):
                c_pagi.number_format = "0" if c_pagi.value == int(c_pagi.value) else "0.##"
            c_score = ws.cell(row, 5, int(score) if isinstance(score, (int, float)) and score == int(score) else score)
            c_score.number_format = "0"

            for col in range(1, 6):
                cell = ws.cell(row, col)
                cell.border = THIN_BORDER
                cell.font = DATA_FONT
                cell.alignment = LEFT_ALIGN if col == 2 else CENTER_ALIGN

        clean_trailing_rows(ws, start_row=start_row + num_caterings, end_row=max(ws.max_row, 25), min_col=1, max_col=max(ws.max_column, 8))

    # =========================================================================
    # SHEET 5: PENGIRIMAN TERLAMBAT DATANG
    # =========================================================================
    if "PENGIRIMAN TERLAMBAT DATANG" in wb.sheetnames:
        ws = wb["PENGIRIMAN TERLAMBAT DATANG"]
        ws["A5"] = f"PENGIRIMAN TERLAMBAT PERIODE   {periode_label}"
        start_row = 12
        for i, c in enumerate(caterings_data):
            row = start_row + i
            terlambat = c.get("keterlambatan", {})
            lamanya = terlambat.get("lamanya_ringkasan", "-")
            kejadian = terlambat.get("jumlah_kejadian", 0)
            p_pagi = terlambat.get("pengurangan_poin_pagi", 0.0)
            p_malam = terlambat.get("pengurangan_poin_malam", 0.0)
            score = terlambat.get("score_performance", 30.0)

            c_no = ws.cell(row, 1, i + 1)
            c_no.number_format = "0"
            ws.cell(row, 2, c.get("catering_nama", ""))
            c_lama = ws.cell(row, 3, lamanya if lamanya else "-")
            if isinstance(c_lama.value, (int, float)) and c_lama.value == int(c_lama.value):
                c_lama.number_format = "0"
            c_jml = ws.cell(row, 4, int(kejadian) if kejadian > 0 else "-")
            c_jml.number_format = "0"
            c_pagi = ws.cell(row, 5, p_pagi if p_pagi > 0 else "-")
            if isinstance(c_pagi.value, (int, float)):
                c_pagi.number_format = "0" if c_pagi.value == int(c_pagi.value) else "0.##"
            c_malam = ws.cell(row, 6, p_malam if p_malam > 0 else "-")
            if isinstance(c_malam.value, (int, float)):
                c_malam.number_format = "0" if c_malam.value == int(c_malam.value) else "0.##"
            c_score = ws.cell(row, 7, int(score) if isinstance(score, (int, float)) and score == int(score) else score)
            c_score.number_format = "0"

            for col in range(1, 8):
                cell = ws.cell(row, col)
                cell.border = THIN_BORDER
                cell.font = DATA_FONT
                cell.alignment = LEFT_ALIGN if col in (2, 3) else CENTER_ALIGN

        clean_trailing_rows(ws, start_row=start_row + num_caterings, end_row=max(ws.max_row, 25), min_col=1, max_col=max(ws.max_column, 12))

    # =========================================================================
    # SHEET 6: RESUM
    # =========================================================================
    if "RESUM" in wb.sheetnames:
        ws = wb["RESUM"]
        ws["A4"] = f"KETERANGAN PUNISMENT CATERING PERIODE  {periode_label}"

        # Sub-tabel 1: Pengiriman Terlambat (Baris 10)
        ws["A8"] = "1.PENGIRIMAN TERLAMBAT"
        headers_1 = ["NO", "NAMA KATERING", "LAMANYA", "JUMLAH", "PENGURANGAN POINT PAGI", "PENGURANGAN POINT MALAM", "SCORE PERFORMANCE"]
        for c_idx, h in enumerate(headers_1, 1):
            cell = ws.cell(10, c_idx, h)
            style_cell(cell, font=HEADER_FONT, alignment=CENTER_ALIGN, fill=HEADER_FILL, border=THIN_BORDER)

        for i, c in enumerate(caterings_data):
            row = 11 + i
            src_row = 12 + i
            ws.cell(row, 1, f"='PENGIRIMAN TERLAMBAT DATANG'!A{src_row}").number_format = "0"
            ws.cell(row, 2, f"='PENGIRIMAN TERLAMBAT DATANG'!B{src_row}")
            ws.cell(row, 3, f"='PENGIRIMAN TERLAMBAT DATANG'!C{src_row}")
            ws.cell(row, 4, f"='PENGIRIMAN TERLAMBAT DATANG'!D{src_row}").number_format = "0"
            ws.cell(row, 5, f"='PENGIRIMAN TERLAMBAT DATANG'!E{src_row}").number_format = "0"
            ws.cell(row, 6, f"='PENGIRIMAN TERLAMBAT DATANG'!F{src_row}").number_format = "0"
            ws.cell(row, 7, f"='PENGIRIMAN TERLAMBAT DATANG'!G{src_row}").number_format = "0"
            for col in range(1, 8):
                fmt = "0" if col in (1, 4, 5, 6, 7) else None
                style_cell(ws.cell(row, col), font=DATA_FONT, alignment=LEFT_ALIGN if col in (2, 3) else CENTER_ALIGN, border=THIN_BORDER, number_format=fmt)

        # Sub-tabel 4A: Jumlah Kalori A (Punishment Minus) (Baris 10, Col N to U)
        ws["N8"] = "4A. JUMLAH KALORI A"
        headers_4a = ["NO", "NAMA KATERING", "TIDAK BERTURUT2", "BERTURUT2", "SETELAH 2 HARI", "SIDAK", "PENGURANGAN PAGI", "PENGURANGAN MALAM"]
        for c_idx, h in enumerate(headers_4a, 14):
            cell = ws.cell(10, c_idx, h)
            style_cell(cell, font=HEADER_FONT, alignment=CENTER_ALIGN, fill=HEADER_FILL, border=THIN_BORDER)

        for i, c in enumerate(caterings_data):
            row = 11 + i
            src_row = 13 + i
            ws.cell(row, 14, f"='JUMPLAH KALORI'!A{src_row}")
            ws.cell(row, 15, f"='JUMPLAH KALORI'!B{src_row}")
            ws.cell(row, 16, f"='JUMPLAH KALORI'!C{src_row}")
            ws.cell(row, 17, f"='JUMPLAH KALORI'!H{src_row}")
            ws.cell(row, 18, f"='JUMPLAH KALORI'!M{src_row}")
            ws.cell(row, 19, f"='JUMPLAH KALORI'!R{src_row}")
            ws.cell(row, 20, f"='JUMPLAH KALORI'!W{src_row}")
            ws.cell(row, 21, f"='JUMPLAH KALORI'!X{src_row}")
            for col in range(14, 22):
                style_cell(ws.cell(row, col), font=DATA_FONT, alignment=LEFT_ALIGN if col == 15 else CENTER_ALIGN, border=THIN_BORDER)

        # Sub-tabel 2: Menu dan Kelayakan (Baris 27, Col A to E)
        ws["A26"] = "2. MENU DAN KELAYAKAN"
        headers_2 = ["NO", "NAMA KATERING", "NILAI", "PENGURANGAN POINT", "SCORE PERFORMANCE"]
        for c_idx, h in enumerate(headers_2, 1):
            cell = ws.cell(27, c_idx, h)
            style_cell(cell, font=HEADER_FONT, alignment=CENTER_ALIGN, fill=HEADER_FILL, border=THIN_BORDER)

        for i, c in enumerate(caterings_data):
            row = 28 + i
            src_row = 11 + i
            ws.cell(row, 1, f"='MENU DAN KELAYAAN'!A{src_row}")
            ws.cell(row, 2, f"='MENU DAN KELAYAAN'!B{src_row}")
            cell_c = ws.cell(row, 3, f"='MENU DAN KELAYAAN'!C{src_row}")
            cell_c.number_format = "0.00%"
            ws.cell(row, 4, f"='MENU DAN KELAYAAN'!D{src_row}")
            ws.cell(row, 5, f"='MENU DAN KELAYAAN'!E{src_row}")
            for col in range(1, 6):
                style_cell(ws.cell(row, col), font=DATA_FONT, alignment=LEFT_ALIGN if col == 2 else CENTER_ALIGN, border=THIN_BORDER)

        # Sub-tabel 4B: Jumlah Kalori B (Pencapaian) (Baris 27, Col N to T)
        ws["N26"] = "4B. JUMLAH KALORI B"
        headers_4b = ["NO", "NAMA KATERING", "MENCAPAI", "TDK MENCAPAI", "PENGIRIMAN", "PERSENTASE", "SCORE PERFORMANCE"]
        for c_idx, h in enumerate(headers_4b, 14):
            cell = ws.cell(27, c_idx, h)
            style_cell(cell, font=HEADER_FONT, alignment=CENTER_ALIGN, fill=HEADER_FILL, border=THIN_BORDER)

        for i, c in enumerate(caterings_data):
            row = 28 + i
            src_row = 22 + i
            ws.cell(row, 14, f"='JUMPLAH KALORI'!A{src_row}")
            ws.cell(row, 15, f"='JUMPLAH KALORI'!B{src_row}")
            ws.cell(row, 16, f"='JUMPLAH KALORI'!C{src_row}")
            ws.cell(row, 17, f"='JUMPLAH KALORI'!E{src_row}")
            ws.cell(row, 18, f"='JUMPLAH KALORI'!G{src_row}")
            cell_s = ws.cell(row, 19, f"='JUMPLAH KALORI'!I{src_row}")
            cell_s.number_format = "0.00%"
            ws.cell(row, 20, f"='JUMPLAH KALORI'!K{src_row}")
            for col in range(14, 21):
                style_cell(ws.cell(row, col), font=DATA_FONT, alignment=LEFT_ALIGN if col == 15 else CENTER_ALIGN, border=THIN_BORDER)

        # Sub-tabel 3: Hasil Audit (Baris 44, Col A to G)
        ws["A43"] = "3. HASIL AUDIT"
        headers_3 = ["NO", "NAMA KATERING", "NILAI AUDIT", "GRADE", "PENGURANGAN PAGI", "PENGURANGAN MALAM", "SCORE PERFORMANCE"]
        for c_idx, h in enumerate(headers_3, 1):
            cell = ws.cell(44, c_idx, h)
            style_cell(cell, font=HEADER_FONT, alignment=CENTER_ALIGN, fill=HEADER_FILL, border=THIN_BORDER)

        for i, c in enumerate(caterings_data):
            row = 45 + i
            src_row = 10 + i
            ws.cell(row, 1, f"='HASIL AUDIT'!A{src_row}")
            ws.cell(row, 2, f"='HASIL AUDIT'!B{src_row}")
            cell_c = ws.cell(row, 3, f"='HASIL AUDIT'!C{src_row}")
            cell_c.number_format = "0.00%"
            ws.cell(row, 4, f"='HASIL AUDIT'!D{src_row}")
            ws.cell(row, 5, f"='HASIL AUDIT'!E{src_row}")
            ws.cell(row, 6, f"='HASIL AUDIT'!F{src_row}")
            ws.cell(row, 7, f"='HASIL AUDIT'!G{src_row}")
            for col in range(1, 8):
                style_cell(ws.cell(row, col), font=DATA_FONT, alignment=LEFT_ALIGN if col == 2 else CENTER_ALIGN, border=THIN_BORDER)

        # Sub-tabel 5: Jumlah Komplain (Baris 44, Col N to T)
        ws["N43"] = "5. JUMLAH KOMPLAIN"
        headers_5 = ["NO", "NAMA KATERING", "JUMLAH", "JENIS KOMPLAIN", "PENGURANGAN PAGI", "PENGURANGAN MALAM", "SCORE PERFORMANCE"]
        for c_idx, h in enumerate(headers_5, 14):
            cell = ws.cell(44, c_idx, h)
            style_cell(cell, font=HEADER_FONT, alignment=CENTER_ALIGN, fill=HEADER_FILL, border=THIN_BORDER)

        for i, c in enumerate(caterings_data):
            row = 45 + i
            src_row = 10 + i
            ws.cell(row, 14, f"='JUMLAH KOMPLIN'!A{src_row}")
            ws.cell(row, 15, f"='JUMLAH KOMPLIN'!B{src_row}")
            ws.cell(row, 16, f"='JUMLAH KOMPLIN'!C{src_row}")
            ws.cell(row, 17, f"='JUMLAH KOMPLIN'!D{src_row}")
            ws.cell(row, 18, f"='JUMLAH KOMPLIN'!E{src_row}")
            ws.cell(row, 19, f"='JUMLAH KOMPLIN'!F{src_row}")
            ws.cell(row, 20, f"='JUMLAH KOMPLIN'!G{src_row}")
            for col in range(14, 21):
                style_cell(ws.cell(row, col), font=DATA_FONT, alignment=LEFT_ALIGN if col in (15, 17) else CENTER_ALIGN, border=THIN_BORDER)


        # Set width untuk RESUM
        resum_col_widths = {
            'A': 5, 'B': 22, 'C': 15, 'D': 15, 'E': 18, 'F': 18, 'G': 18,
            'H': 15, 'I': 14, 'J': 10, 'K': 25, 'L': 18, 'M': 18,
            'N': 5, 'O': 22, 'P': 15, 'Q': 15, 'R': 15, 'S': 15, 'T': 18, 'U': 18
        }
        for col_letter, width in resum_col_widths.items():
            ws.column_dimensions[col_letter].width = width

    # =========================================================================
    # SHEET 7: SIGN
    # =========================================================================
    if "SIGN" in wb.sheetnames:
        ws = wb["SIGN"]
        appr = config.get("approved_by")
        chk = config.get("checked_by")
        prep = config.get("prepared_by")
        ws["H13"] = str(appr if isinstance(appr, str) else getattr(appr, "default", "TSU") or "TSU")
        ws["I13"] = str(chk if isinstance(chk, str) else getattr(chk, "default", "NIA") or "NIA")
        ws["J13"] = str(prep if isinstance(prep, str) else getattr(prep, "default", "IRO") or "IRO")

    for sname in wb.sheetnames:
        prune_empty_cells(wb[sname])

    output = io.BytesIO()
    wb.save(output)
    output.seek(0)
    return output.read()
