"""
backend-laravel/scripts/generators/minus_excel.py
Standalone Minus Kalori Excel Generator for CARMEN (Stage 9B Dependency Closure).

Generates official Minus Kalori Excel workbook without importing backend/main.py or FastAPI.
Maintains 100% parity with official formatting, font styles, borders, column widths,
image centering, and date ranges.
"""

from __future__ import annotations

import os
import re
import math
from datetime import date, datetime
from io import BytesIO
from typing import Any, Dict, List, Optional
from urllib.parse import quote

import openpyxl
from openpyxl.styles import Font, Alignment, Border, Side
from openpyxl.drawing.image import Image as XLImage
from openpyxl.drawing.spreadsheet_drawing import OneCellAnchor, AnchorMarker
from openpyxl.drawing.xdr import XDRPositiveSize2D
from openpyxl.utils.units import pixels_to_EMU

INDONESIAN_MONTHS = {
    1: "Januari", 2: "Februari", 3: "Maret", 4: "April",
    5: "Mei", 6: "Juni", 7: "Juli", 8: "Agustus",
    9: "September", 10: "Oktober", 11: "November", 12: "Desember"
}


def estimate_wrapped_lines(value: Any, column_width: float) -> int:
    """Estimates how many wrapped lines text will occupy."""
    if value is None or str(value).strip() == "":
        return 1
    text = str(value)
    estimated_chars_per_line = max(1, int(column_width * 1.1))
    total_lines = 0
    for explicit_line in text.splitlines() or [""]:
        total_lines += max(1, math.ceil(len(explicit_line) / estimated_chars_per_line))
    return total_lines


def format_indonesian_date_range(start_date: date, end_date: date) -> str:
    """Formats date range string in official Indonesian notation."""
    if start_date == end_date:
        return f"{start_date.day} {INDONESIAN_MONTHS[start_date.month]} {start_date.year}"

    if start_date.year == end_date.year and start_date.month == end_date.month:
        return f"{start_date.day} - {end_date.day} {INDONESIAN_MONTHS[end_date.month]} {end_date.year}"

    if start_date.year == end_date.year:
        return (
            f"{start_date.day} {INDONESIAN_MONTHS[start_date.month]} - "
            f"{end_date.day} {INDONESIAN_MONTHS[end_date.month]} {end_date.year}"
        )

    return (
        f"{start_date.day} {INDONESIAN_MONTHS[start_date.month]} {start_date.year} - "
        f"{end_date.day} {INDONESIAN_MONTHS[end_date.month]} {end_date.year}"
    )


def build_export_filename(entries: List[Any], caterings_map: Dict[int, str]) -> str:
    """Builds official filename for Minus Kalori export."""
    if not entries:
        return "Kalori Minus.xlsx"

    if len(entries) == 1:
        entry = entries[0]
        cat_id = getattr(entry, "catering_id", 0)
        cat_name = caterings_map.get(cat_id, f"Catering #{cat_id}")
        clean_cat = re.sub(r'[<>:"/\\|?*]', "", cat_name)
        clean_cat = re.sub(r'\s+', " ", clean_cat).strip()
        t_date = getattr(entry, "tanggal", None)
        if isinstance(t_date, str):
            t_date = date.fromisoformat(t_date[:10])
        elif isinstance(t_date, datetime):
            t_date = t_date.date()
        date_str = f"{t_date.day} {INDONESIAN_MONTHS[t_date.month]} {t_date.year}" if t_date else "Laporan"
        return f"Kalori Minus_{clean_cat}_{date_str}.xlsx"

    date_objs: List[date] = []
    for e in entries:
        d_val = getattr(e, "tanggal", None)
        if isinstance(d_val, str):
            d_val = date.fromisoformat(d_val[:10])
        elif isinstance(d_val, datetime):
            d_val = d_val.date()
        if d_val:
            date_objs.append(d_val)

    if date_objs:
        start_date = min(date_objs)
        end_date = max(date_objs)
        range_str = format_indonesian_date_range(start_date, end_date)
        return f"Kalori Minus_{range_str}.xlsx"
    return "Kalori Minus.xlsx"


def generate_minus_excel_bytes(
    entries: List[Any],
    caterings_map: Dict[int, str],
    template_path: Optional[str] = None,
    uploads_dir: Optional[str] = None,
) -> bytes:
    """
    Authoritative generator for Minus Kalori Excel workbook.
    Returns raw bytes of the resulting .xlsx file.
    """
    if template_path and os.path.exists(template_path):
        wb = openpyxl.load_workbook(template_path)
        ws = wb["KETERANGAN MINUS"] if "KETERANGAN MINUS" in wb.sheetnames else wb.active
    else:
        # Fallback to local default template paths
        script_dir = os.path.dirname(os.path.abspath(__file__))
        cand_paths = [
            os.path.join(script_dir, "..", "..", "backend", "data", "minus.xlsx"),
            os.path.join(script_dir, "..", "..", "backend", "excel_templates", "template_minus_kalori.xlsx"),
            os.path.join(script_dir, "..", "resources", "excel_templates", "template_minus_kalori.xlsx"),
        ]
        found_template = None
        for cp in cand_paths:
            if os.path.exists(cp):
                found_template = cp
                break

        if found_template:
            wb = openpyxl.load_workbook(found_template)
            ws = wb["KETERANGAN MINUS"] if "KETERANGAN MINUS" in wb.sheetnames else wb.active
        else:
            wb = openpyxl.Workbook()
            ws = wb.active
            ws.title = "KETERANGAN MINUS"

    # Set normal sheet view
    try:
        ws.sheet_view.view = "normal"
        ws.sheet_view.zoomScale = 100
        ws.sheet_view.showGridLines = True
    except Exception:
        pass

    # Clear template sample images
    if hasattr(ws, "_images"):
        ws._images.clear()

    # Clear sample data rows starting from row 7
    max_existing = max(ws.max_row, 20)
    for r in range(7, max_existing + 1):
        for c in range(1, 9):
            ws.cell(row=r, column=c).value = None

    # Document Header rows 1-4
    ws["A1"] = "PT JATIM AUTOCOMP INDONESIA"
    ws["A1"].font = Font(name="Calibri", size=9, bold=False)
    ws["A1"].alignment = Alignment(horizontal="left", vertical="center")

    ws["A2"] = "INDUSTRIAL RELATION SECTION"
    ws["A2"].font = Font(name="Calibri", size=9, bold=False)
    ws["A2"].alignment = Alignment(horizontal="left", vertical="center")

    ws["A4"] = "KETERANGAN KALORI MINUS"
    ws["A4"].font = Font(name="Calibri", size=12, bold=True)
    ws["A4"].alignment = Alignment(horizontal="center", vertical="center")

    try:
        ws.merge_cells("A4:H4")
    except Exception:
        pass

    # Header Row 6
    headers = ["NO", "TANGGAL", "MENU", "CATERING", "FOTO CATERING", "HASIL KALORI", "PROBLEM", "PERBAIKAN"]
    thin_side = Side(style="thin", color="000000")
    thin_border = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    header_font = Font(name="Calibri", size=11, bold=True)
    header_align = Alignment(horizontal="center", vertical="center", wrap_text=True)

    for col_idx, h_text in enumerate(headers, start=1):
        cell = ws.cell(row=6, column=col_idx, value=h_text)
        cell.font = header_font
        cell.alignment = header_align
        cell.border = thin_border

    # Column Widths
    col_widths = {
        "A": 4.3,
        "B": 12.5,
        "C": 19.5,
        "D": 9.8,
        "E": 21.7,
        "F": 10.7,
        "G": 21.7,
        "H": 37.8,
    }
    for col_letter, width in col_widths.items():
        ws.column_dimensions[col_letter].width = width

    # Data Rows
    data_font = Font(name="Calibri", size=11, bold=False)
    center_align = Alignment(horizontal="center", vertical="center", wrap_text=True)

    if not uploads_dir:
        uploads_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "backend", "uploads"))

    for export_idx, entry in enumerate(entries, start=1):
        row_num = export_idx + 6

        cat_id = getattr(entry, "catering_id", 0)
        catering_nama = caterings_map.get(cat_id, f"Catering #{cat_id}")

        # Cell A: NO
        cell_a = ws.cell(row=row_num, column=1, value=export_idx)
        cell_a.font = data_font
        cell_a.alignment = center_align
        cell_a.border = thin_border

        # Cell B: TANGGAL
        t_val = getattr(entry, "tanggal", None)
        if isinstance(t_val, str):
            t_val = date.fromisoformat(t_val[:10])
        cell_b = ws.cell(row=row_num, column=2, value=t_val)
        cell_b.font = data_font
        cell_b.alignment = center_align
        cell_b.number_format = "dd/mm/yyyy"
        cell_b.border = thin_border

        # Cell C: MENU
        cell_c = ws.cell(row=row_num, column=3, value=getattr(entry, "menu", ""))
        cell_c.font = data_font
        cell_c.alignment = center_align
        cell_c.border = thin_border

        # Cell D: CATERING
        cell_d = ws.cell(row=row_num, column=4, value=catering_nama)
        cell_d.font = data_font
        cell_d.alignment = center_align
        cell_d.border = thin_border

        # Cell E: FOTO CATERING
        cell_e = ws.cell(row=row_num, column=5)
        cell_e.font = data_font
        cell_e.alignment = center_align
        cell_e.border = thin_border

        # Cell F: HASIL KALORI
        hk_val = getattr(entry, "hasil_kalori", 0.0)
        try:
            hk_val = float(hk_val)
        except Exception:
            hk_val = 0.0
        cell_f = ws.cell(row=row_num, column=6, value=hk_val)
        cell_f.font = data_font
        cell_f.alignment = center_align
        cell_f.number_format = "#,##0.00"
        cell_f.border = thin_border

        # Cell G: PROBLEM
        cell_g = ws.cell(row=row_num, column=7, value=getattr(entry, "problem", ""))
        cell_g.font = data_font
        cell_g.alignment = center_align
        cell_g.border = thin_border

        # Cell H: PERBAIKAN
        cell_h = ws.cell(row=row_num, column=8, value=getattr(entry, "perbaikan", ""))
        cell_h.font = data_font
        cell_h.alignment = center_align
        cell_h.border = thin_border

        # Height calculation
        menu_lines = estimate_wrapped_lines(getattr(entry, "menu", ""), 19.5)
        problem_lines = estimate_wrapped_lines(getattr(entry, "problem", ""), 21.7)
        perbaikan_lines = estimate_wrapped_lines(getattr(entry, "perbaikan", ""), 37.8)
        max_text_lines = max(menu_lines, problem_lines, perbaikan_lines, 1)
        text_height_points = (max_text_lines * 15) + 10

        photo_embedded = False
        target_img_obj = None
        img_w = 0
        img_h = 0

        foto_url = getattr(entry, "foto_url", None)
        if foto_url and str(foto_url).strip():
            raw_path = str(foto_url).strip().lstrip("/")
            if raw_path.startswith("uploads/"):
                raw_path = raw_path[len("uploads/"):]
            filename = os.path.basename(raw_path)
            target_image_path = os.path.abspath(os.path.join(uploads_dir, filename))

            if os.path.exists(target_image_path):
                try:
                    img = XLImage(target_image_path)
                    orig_w, orig_h = img.width, img.height
                    scale = min(140 / max(1, orig_w), 100 / max(1, orig_h), 1.0)
                    img_w = max(1, int(orig_w * scale))
                    img_h = max(1, int(orig_h * scale))
                    target_img_obj = img
                    photo_embedded = True
                except Exception:
                    photo_embedded = False
                    cell_e.value = "[Foto tidak ditemukan]"
                    cell_e.font = Font(name="Calibri", size=9, bold=False, color="7F7F7F")
            else:
                photo_embedded = False
                cell_e.value = "[Foto tidak ditemukan]"
                cell_e.font = Font(name="Calibri", size=9, bold=False, color="7F7F7F")

        photo_height_points = (img_h * 72 / 96) + 10 if photo_embedded else 0
        calculated_height = max(text_height_points, photo_height_points, 25)
        ws.row_dimensions[row_num].height = calculated_height

        if photo_embedded and target_img_obj:
            try:
                col_e_width_px = 160
                row_height_px = int(calculated_height * 96 / 72)
                h_offset_px = max(0, int((col_e_width_px - img_w) / 2))
                v_offset_px = max(0, int((row_height_px - img_h) / 2))

                marker = AnchorMarker(
                    col=4,
                    colOff=pixels_to_EMU(h_offset_px),
                    row=row_num - 1,
                    rowOff=pixels_to_EMU(v_offset_px),
                )
                target_img_obj.anchor = OneCellAnchor(
                    _from=marker,
                    ext=XDRPositiveSize2D(pixels_to_EMU(img_w), pixels_to_EMU(img_h)),
                )
                ws.add_image(target_img_obj)
            except Exception:
                pass

    # Delete extra unused rows
    last_data_row = len(entries) + 6
    if ws.max_row > last_data_row:
        ws.delete_rows(last_data_row + 1, ws.max_row - last_data_row)

    output = BytesIO()
    wb.save(output)
    return output.getvalue()
