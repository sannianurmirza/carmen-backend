"""
Module Layanan Export Excel Komplain Katering PT JAI (CARMEN)
Menangani penyusunan file Excel Komplain Katering mingguan dan tunggal (single export)
dengan penataan area foto yang dinamis (Dynamic Photo Column / Row Sizing)
berdasarkan foto terbesar yang ada di laporan mingguan tersebut.

Prinsip Utama:
1. Dynamic Sizing: Ukuran kolom dan tinggi baris menyesuaikan foto terbesar di laporan.
2. Aspect Ratio: Strict aspect ratio preservation (tidak ada stretch, squeeze, distort, atau crop).
3. Safety Bounds: Clamping min/max yang proporsional agar nyaman di Excel desktop dan ramah cetak.
4. Per-Row Adaptation: Tinggi baris menyesuaikan foto pada baris masing-masing (baris tanpa foto tetap kompak).
5. Padding: Padding horizontal & vertikal 8px agar foto tidak menempel pada garis sel.
6. Centering: Foto ditempatkan tepat di tengah sel F secara horizontal dan vertikal.
7. EXIF Orientation: Membaca rotasi EXIF sehingga foto portrait tetap portrait dan landscape tetap landscape.
8. Multiple Photos: Mendukung複数 (multiple) foto per komplain secara horizontal side-by-side.
9. Unit Conversion Helpers: Fungsi konversi piksel ke karakter kolom Excel dan points baris Excel yang terdokumentasi.
"""

from __future__ import annotations

import io
import os
import re
import json
from datetime import date, datetime
from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple

import openpyxl
from openpyxl.styles import Font, Alignment, Border, Side
from openpyxl.drawing.image import Image as XLImage
from openpyxl.drawing.spreadsheet_drawing import AnchorMarker, OneCellAnchor
from openpyxl.drawing.xdr import XDRPositiveSize2D
from openpyxl.utils.units import pixels_to_EMU
from PIL import Image as PILImage, ImageOps


# ==============================================================================
# 1. KONFIGURASI DAN BATASAN KEAMANAN (SAFETY LIMITS & BOUNDS)
# ==============================================================================

# Lebar default kolom F pada template komplain (~21.73 karakter atau ~157px)
DEFAULT_COL_F_WIDTH_CHARS: float = 21.73

# Batas maksimum area foto yang aman untuk tampilan desktop Excel dan print area
# Menghindari foto berukuran ekstrem (misal 4000x3000) membuat kolom/baris absurd.
MAX_PHOTO_AREA_WIDTH_PX: int = 260   # ~36.4 karakter Excel
MAX_PHOTO_AREA_HEIGHT_PX: int = 200  # ~150 points tinggi baris Excel

# Batas minimum area foto yang masuk akal
# Menjamin foto kecil tetap jelas terbaca dan judul kolom "FOTO CATERING" tidak terpotong.
MIN_PHOTO_AREA_WIDTH_PX: int = 140   # ~19.3 karakter Excel
MIN_PHOTO_AREA_HEIGHT_PX: int = 100  # ~75 points tinggi baris Excel

# Padding foto terhadap border sel (dalam piksel)
PADDING_HORIZONTAL_PX: int = 8  # 8px kiri, 8px kanan (total 16px)
PADDING_VERTICAL_PX: int = 8    # 8px atas, 8px bawah (total 16px)

# Gap antar foto jika satu komplain memiliki lebih dari satu foto
MULTI_PHOTO_GAP_PX: int = 6

# Tinggi baris default untuk komplain tanpa foto
DEFAULT_NO_PHOTO_ROW_HEIGHT_PT: float = 45.0
MIN_PHOTO_ROW_HEIGHT_PT: float = 60.0


# ==============================================================================
# 2. HELPER KONVERSI UNIT EXCEL (EXCEL UNIT CONVERSION HELPERS)
# ==============================================================================

def pixels_to_excel_col_width(pixels: float) -> float:
    """
    Mengonversi lebar piksel ke satuan lebar karakter kolom Excel (character width units).
    Berdasarkan standar font default Calibri 11pt pada resolusi 96 DPI:
      1 unit kolom Excel = lebar 1 karakter '0' (kira-kira 7 piksel) ditambah padding 5 piksel.
      Rumus: col_width = (pixels - 5) / 7.0
    Nilai dibulatkan ke 2 desimal dengan batas minimal 1.0 karakter.
    """
    if pixels <= 0:
        return 0.0
    return max(round((float(pixels) - 5.0) / 7.0, 2), 1.0)


def excel_col_width_to_pixels(col_width: float) -> int:
    """
    Mengonversi satuan lebar karakter kolom Excel ke piksel layar (96 DPI).
      Rumus: pixels = int(round(col_width * 7.0 + 5.0))
    """
    if col_width <= 0:
        return 0
    return int(round(float(col_width) * 7.0 + 5.0))


def pixels_to_points(pixels: float) -> float:
    """
    Mengonversi ukuran piksel (96 DPI) ke satuan points tinggi baris Excel (72 points per inch).
      1 point = 96 / 72 piksel = 1.333 piksel
      points = pixels * (72 / 96) = pixels * 0.75
    """
    if pixels <= 0:
        return 0.0
    return round(float(pixels) * 72.0 / 96.0, 2)


def points_to_pixels(points: float) -> int:
    """
    Mengonversi satuan points tinggi baris Excel ke piksel layar (96 DPI).
      pixels = points * (96 / 72) = points * 1.333
    """
    if points <= 0:
        return 0
    return int(round(float(points) * 96.0 / 72.0))


# ==============================================================================
# 3. HELPER PARSING & NORMALISASI FOTO (EXIF ORIENTATION & IMAGE EXTRACTION)
# ==============================================================================

def parse_photo_paths(raw_path: Optional[str]) -> List[str]:
    """
    Mengekstrak daftar path file foto dari input foto_path.
    Mendukung format tunggal, string dipisahkan koma/titik koma/newline,
    atau format JSON list string.
    """
    if not raw_path or not str(raw_path).strip():
        return []

    cleaned = str(raw_path).strip()
    # Coba parse sebagai JSON array
    if cleaned.startswith("[") and cleaned.endswith("]"):
        try:
            parsed = json.loads(cleaned)
            if isinstance(parsed, list):
                return [str(p).strip() for p in parsed if p and str(p).strip()]
        except Exception:
            pass

    # Split berdasarkan separator yang umum (, ; \n)
    parts = re.split(r"[,;\n\r]+", cleaned)
    result = []
    for p in parts:
        clean_item = p.strip()
        if clean_item:
            result.append(clean_item)
    return result


def load_normalized_image(
    raw_path_entry: str,
    uploads_dir: Path,
) -> Optional[Dict[str, Any]]:
    """
    Membaca file foto dari direktori uploads, memvalidasi keamanan path,
    menerapkan orientasi EXIF (ImageOps.exif_transpose),
    dan mengembalikan metadata foto beserta buffer gambar siap pakai.

    Returns dict dengan:
      - 'orig_w': lebar alami setelah koreksi orientasi EXIF (px)
      - 'orig_h': tinggi alami setelah koreksi orientasi EXIF (px)
      - 'bytes_io': BytesIO PNG siap untuk openpyxl XLImage
      - 'filename': nama file foto
    atau None jika file tidak ditemukan / rusak.
    """
    if not raw_path_entry or not raw_path_entry.strip():
        return None

    raw_clean = raw_path_entry.strip().replace("\\", "/")
    filename = os.path.basename(raw_clean)

    # Validasi nama file aman (mencegah path traversal)
    if not filename or not re.match(r"^[a-zA-Z0-9_\-\.]+$", filename):
        return None

    target_file = (uploads_dir / filename).resolve()
    try:
        is_safe = target_file.is_relative_to(uploads_dir)
    except AttributeError:
        is_safe = str(target_file).startswith(str(uploads_dir))

    if not is_safe or not target_file.is_file():
        return None

    try:
        with PILImage.open(target_file) as pil_img:
            # Koreksi rotasi orientasi EXIF tag 0x0112 (misal foto smartphone portrait)
            try:
                transposed = ImageOps.exif_transpose(pil_img)
                if transposed is not None:
                    pil_img = transposed
            except Exception:
                pass

            orig_w, orig_h = pil_img.size
            if orig_w <= 0 or orig_h <= 0:
                return None

            # Simpan gambar yang sudah berorientasi benar ke BytesIO dalam format PNG
            img_buffer = io.BytesIO()
            # Konversi RGBA jika gambar memiliki transparansi, atau RGB
            if pil_img.mode in ("RGBA", "LA") or (pil_img.mode == "P" and "transparency" in pil_img.info):
                pil_img.convert("RGBA").save(img_buffer, format="PNG")
            else:
                pil_img.convert("RGB").save(img_buffer, format="PNG")
            img_buffer.seek(0)

            return {
                "orig_w": orig_w,
                "orig_h": orig_h,
                "bytes_io": img_buffer,
                "filename": filename,
            }
    except Exception as err:
        print(f"[WARN komplain_excel] Gagal memproses gambar {target_file}: {err}")
        return None


# ==============================================================================
# 4. ESTIMASI TINGGI TEKS WRAPPED (TEXT WRAPPING LINE ESTIMATION)
# ==============================================================================

def estimate_wrapped_lines(text: Optional[str], col_width_chars: float) -> int:
    """
    Memperkirakan jumlah baris teks jika di-wrap pada kolom dengan lebar col_width_chars.
    Memperhitungkan newline eksplisit dan panjang baris teks.
    """
    if not text:
        return 1
    text_str = str(text)
    total_lines = 0
    safe_width = max(int(col_width_chars), 5)
    for line in text_str.split("\n"):
        line_len = len(line.strip())
        if line_len == 0:
            total_lines += 1
        else:
            total_lines += max(1, (line_len + safe_width - 1) // safe_width)
    return max(1, total_lines)


# ==============================================================================
# 5. KALKULASI UKURAN FOTO DINAMIS (DYNAMIC PHOTO SIZING ENGINE)
# ==============================================================================

def calculate_dynamic_photo_layout(
    items_photo_info: List[List[Dict[str, Any]]],
) -> Tuple[float, int, int]:
    """
    Menghitung ukuran kolom F dan batas acuan foto terbesar untuk satu laporan mingguan.

    Langkah-langkah:
    1. Kumpulkan seluruh foto yang berhasil dibaca.
    2. Jika tidak ada foto sama sekali, kembalikan lebar kolom default.
    3. Tentukan dimensi alami terbesar (max natural width dan max natural height).
    4. Tentukan kotak batas referensi (ref_box_w, ref_box_h) yang di-clamp
       antara batasan minimum (MIN_PHOTO_AREA_*) dan batasan keamanan maksimum (MAX_PHOTO_AREA_*).
    5. Hitung skala render proporsional untuk setiap foto menjaga rasio aspek:
         scale = min(ref_box_w / orig_w, ref_box_h / orig_h)
         rendered_w = orig_w * scale
         rendered_h = orig_h * scale
    6. Tentukan lebar kolom F berdasarkan rendered width terbesar (termasuk layout multiple foto) + padding.

    Returns:
      - col_f_width_chars: float (lebar kolom F dalam satuan karakter Excel)
      - ref_box_w: int (lebar acuan referensi foto dalam piksel)
      - ref_box_h: int (tinggi acuan referensi foto dalam piksel)
    """
    all_photos = [p for row_photos in items_photo_info for p in row_photos]

    if not all_photos:
        # Tidak ada foto di laporan ini: gunakan lebar template default
        return DEFAULT_COL_F_WIDTH_CHARS, 0, 0

    # 1. Cari dimensi natural terbesar
    max_orig_w = max(p["orig_w"] for p in all_photos)
    max_orig_h = max(p["orig_h"] for p in all_photos)

    # 2. Tentukan ukuran acuan area foto dengan min/max clamping proporsional
    ref_box_w = max(MIN_PHOTO_AREA_WIDTH_PX, min(max_orig_w, MAX_PHOTO_AREA_WIDTH_PX))
    ref_box_h = max(MIN_PHOTO_AREA_HEIGHT_PX, min(max_orig_h, MAX_PHOTO_AREA_HEIGHT_PX))

    # 3. Hitung dimensi render tiap baris untuk menentukan lebar kolom yang dibutuhkan
    max_required_col_px = excel_col_width_to_pixels(DEFAULT_COL_F_WIDTH_CHARS)

    for row_photos in items_photo_info:
        if not row_photos:
            continue

        num_photos = len(row_photos)
        if num_photos == 1:
            p = row_photos[0]
            scale = min(ref_box_w / p["orig_w"], ref_box_h / p["orig_h"])
            rend_w = max(1, int(round(p["orig_w"] * scale)))
            p["rendered_w"] = rend_w
            p["rendered_h"] = max(1, int(round(p["orig_h"] * scale)))
            required_w = rend_w + (2 * PADDING_HORIZONTAL_PX)
        else:
            # Multiple photos: susun horizontal berdampingan
            # Skala awal tiap foto ke ref_box
            temp_w_list = []
            temp_h_list = []
            for p in row_photos:
                scale = min(ref_box_w / p["orig_w"], ref_box_h / p["orig_h"])
                rw = max(1, int(round(p["orig_w"] * scale)))
                rh = max(1, int(round(p["orig_h"] * scale)))
                temp_w_list.append(rw)
                temp_h_list.append(rh)

            total_raw_w = sum(temp_w_list) + (MULTI_PHOTO_GAP_PX * (num_photos - 1))
            # Jika total lebar melebihi batas keamanan maksimum, scale down seluruh grup
            if total_raw_w > MAX_PHOTO_AREA_WIDTH_PX:
                group_scale = (MAX_PHOTO_AREA_WIDTH_PX - (MULTI_PHOTO_GAP_PX * (num_photos - 1))) / max(1, sum(temp_w_list))
                for idx, p in enumerate(row_photos):
                    p["rendered_w"] = max(1, int(round(temp_w_list[idx] * group_scale)))
                    p["rendered_h"] = max(1, int(round(temp_h_list[idx] * group_scale)))
                total_w = sum(p["rendered_w"] for p in row_photos) + (MULTI_PHOTO_GAP_PX * (num_photos - 1))
            else:
                for idx, p in enumerate(row_photos):
                    p["rendered_w"] = temp_w_list[idx]
                    p["rendered_h"] = temp_h_list[idx]
                total_w = total_raw_w

            required_w = total_w + (2 * PADDING_HORIZONTAL_PX)

        if required_w > max_required_col_px:
            max_required_col_px = required_w

    # Konversi lebar kolom akhir ke satuan karakter Excel
    col_f_width_chars = pixels_to_excel_col_width(max_required_col_px)
    return col_f_width_chars, ref_box_w, ref_box_h


# ==============================================================================
# 6. PENYUSUNAN WORKBOOK KOMPLAIN MINGGUAN (WEEKLY WORKBOOK BUILDER)
# ==============================================================================

def build_komplain_weekly_excel_workbook(
    items: List[Any],
    caterings_map: Dict[int, str],
    template_path: str,
    uploads_dir: Path,
) -> openpyxl.Workbook:
    """
    Membangun workbook Excel Laporan Komplain Mingguan lengkap:
    - Membaca template asli (komplain.xlsx)
    - Melakukan audit & ekstraksi foto pada setiap baris
    - Mengoreksi rotasi EXIF foto
    - Menghitung acuan foto terbesar dan menetapkan lebar kolom F secara dinamis
    - Menghitung tinggi baris per baris secara adaptif sesuai foto & teks
    - Menempatkan foto tepat di tengah sel F (horizontal & vertical center)
    - Mempertahankan font Calibri 11, border thin, format tanggal, dan print area
    """
    if not os.path.exists(template_path):
        raise FileNotFoundError(f"Template komplain tidak ditemukan: {template_path}")

    wb = openpyxl.load_workbook(template_path)

    # Identifikasi sheet template komplain
    sheet_name = "KETERANGAN MINUS (2)"
    if sheet_name in wb.sheetnames:
        ws = wb[sheet_name]
    elif "KETERANGAN MINUS" in wb.sheetnames:
        ws = wb["KETERANGAN MINUS"]
        ws.title = sheet_name
    elif len(wb.sheetnames) > 0:
        ws = wb.active
        ws.title = sheet_name
    else:
        wb.close()
        raise ValueError("Workbook template tidak memiliki worksheet yang valid")

    # Pastikan judul merge range di baris 4 adalah A4:G4 (sesuai spesifikasi 7 kolom data komplain)
    for rng in list(ws.merged_cells.ranges):
        if "A4" in str(rng):
            ws.unmerge_cells(str(rng))
    ws.merge_cells("A4:G4")

    try:
        ws.sheet_view.view = "normal"
        ws.sheet_view.zoomScale = 100
        ws.sheet_view.showGridLines = True
    except Exception:
        pass

    # Bersihkan foto contoh bawaan dari template
    if hasattr(ws, "_images"):
        ws._images.clear()

    # Deteksi posisi baris header tabel secara dinamis
    header_row_idx = 6
    for r in range(1, 15):
        vals = [str(ws.cell(r, c).value or "").strip().upper() for c in range(1, 8)]
        if "NO" in vals and "TANGGAL" in vals and "MENU" in vals:
            header_row_idx = r
            break

    start_data_row = header_row_idx + 1
    # Bersihkan seluruh baris data contoh lama dari template
    if ws.max_row >= start_data_row:
        ws.delete_rows(start_data_row, ws.max_row - start_data_row + 1)

    # Styling konsisten sesuai spesifikasi
    thin_side = Side(style="thin", color="000000")
    thin_border = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    data_font = Font(name="Calibri", size=11, bold=False)

    # ==========================================================================
    # PASS 1: Kumpulkan seluruh foto & normalisasi orientasi EXIF
    # ==========================================================================
    items_photo_data: List[List[Dict[str, Any]]] = []
    for item in items:
        row_photos: List[Dict[str, Any]] = []
        raw_paths = parse_photo_paths(getattr(item, "foto_path", None))
        for p_str in raw_paths:
            img_info = load_normalized_image(p_str, uploads_dir)
            if img_info is not None:
                row_photos.append(img_info)
        items_photo_data.append(row_photos)

    # ==========================================================================
    # PASS 2: Tentukan acuan foto terbesar & set lebar kolom F dinamis
    # ==========================================================================
    col_f_width_chars, ref_box_w, ref_box_h = calculate_dynamic_photo_layout(items_photo_data)
    ws.column_dimensions["F"].width = col_f_width_chars
    col_f_actual_px = excel_col_width_to_pixels(col_f_width_chars)

    # ==========================================================================
    # PASS 3: Isi baris data, set tinggi baris adaptif, dan pasang foto ter-center
    # ==========================================================================
    for idx, (item, row_photos) in enumerate(zip(items, items_photo_data), start=1):
        curr_row = header_row_idx + idx
        catering_nama = caterings_map.get(item.catering_id, f"Catering #{item.catering_id}")

        # 1. NO
        c1 = ws.cell(row=curr_row, column=1, value=idx)
        c1.font = data_font
        c1.alignment = Alignment(horizontal="center", vertical="center")
        c1.number_format = "General"
        c1.border = thin_border

        # 2. TANGGAL
        t_val = item.tanggal
        if isinstance(t_val, str):
            try:
                t_val = date.fromisoformat(t_val)
            except Exception:
                pass
        if isinstance(t_val, date) and not isinstance(t_val, datetime):
            t_val = datetime(t_val.year, t_val.month, t_val.day)

        c2 = ws.cell(row=curr_row, column=2, value=t_val)
        c2.font = data_font
        c2.alignment = Alignment(horizontal="center", vertical="center")
        c2.number_format = '[$-409]dd\\-mmm\\-yy;@'
        c2.border = thin_border

        # 3. SHIFT
        clean_sh = (item.shift or "Pagi").strip().capitalize()
        c3 = ws.cell(row=curr_row, column=3, value=clean_sh)
        c3.font = data_font
        c3.alignment = Alignment(horizontal="center", vertical="center")
        c3.number_format = "@"
        c3.border = thin_border

        # 4. MENU
        c4 = ws.cell(row=curr_row, column=4, value=item.menu_snapshot or "-")
        c4.font = data_font
        c4.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
        c4.number_format = "General"
        c4.border = thin_border

        # 5. CATERING
        c5 = ws.cell(row=curr_row, column=5, value=catering_nama)
        c5.font = data_font
        c5.alignment = Alignment(horizontal="center", vertical="center")
        c5.number_format = "General"
        c5.border = thin_border

        # 6. FOTO CATERING (sel kosong dengan border, foto di-anchor di atasnya)
        c6 = ws.cell(row=curr_row, column=6, value=None)
        c6.font = data_font
        c6.alignment = Alignment(horizontal="center", vertical="center")
        c6.number_format = "General"
        c6.border = thin_border

        # 7. KETERANGAN
        c7 = ws.cell(row=curr_row, column=7, value=item.keterangan or "-")
        c7.font = data_font
        c7.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
        c7.number_format = "General"
        c7.border = thin_border

        # Estimasi tinggi teks pada baris ini
        menu_lines = estimate_wrapped_lines(item.menu_snapshot, 19.5)
        ket_lines = estimate_wrapped_lines(item.keterangan, 13.0)
        max_text_lines = max(menu_lines, ket_lines, 1)
        text_height_pt = (max_text_lines * 15.0) + 16.0

        # Perhitungan tinggi baris adaptif
        if row_photos:
            max_rendered_h_in_row = max(p["rendered_h"] for p in row_photos)
            photo_height_pt = pixels_to_points(max_rendered_h_in_row + (2 * PADDING_VERTICAL_PX))
            calculated_row_height_pt = max(text_height_pt, photo_height_pt, MIN_PHOTO_ROW_HEIGHT_PT)
        else:
            # Baris tanpa foto tetap rapi dan tidak terlalu tinggi
            calculated_row_height_pt = max(text_height_pt, DEFAULT_NO_PHOTO_ROW_HEIGHT_PT)

        ws.row_dimensions[curr_row].height = calculated_row_height_pt
        actual_row_height_px = points_to_pixels(calculated_row_height_pt)

        # ======================================================================
        # Centering foto secara horizontal & vertikal di sel Kolom F
        # ======================================================================
        if row_photos:
            num_photos = len(row_photos)
            if num_photos == 1:
                p = row_photos[0]
                rend_w = p["rendered_w"]
                rend_h = p["rendered_h"]

                h_offset_px = max(0, int((col_f_actual_px - rend_w) / 2))
                v_offset_px = max(0, int((actual_row_height_px - rend_h) / 2))

                try:
                    xl_img = XLImage(p["bytes_io"])
                    marker = AnchorMarker(
                        col=5,  # Kolom F (0-indexed: A=0, B=1, C=2, D=3, E=4, F=5)
                        colOff=pixels_to_EMU(h_offset_px),
                        row=curr_row - 1,  # 0-indexed row
                        rowOff=pixels_to_EMU(v_offset_px),
                    )
                    xl_img.anchor = OneCellAnchor(
                        _from=marker,
                        ext=XDRPositiveSize2D(pixels_to_EMU(rend_w), pixels_to_EMU(rend_h)),
                    )
                    ws.add_image(xl_img)
                except Exception as err:
                    print(f"[WARN komplain_excel] Gagal embed gambar tunggal baris {curr_row}: {err}")
            else:
                # Multiple photos: susun horizontal berdampingan dengan centering grup
                total_group_w = sum(p["rendered_w"] for p in row_photos) + (MULTI_PHOTO_GAP_PX * (num_photos - 1))
                start_h_offset = max(0, int((col_f_actual_px - total_group_w) / 2))

                curr_h_pos = start_h_offset
                for p in row_photos:
                    rend_w = p["rendered_w"]
                    rend_h = p["rendered_h"]
                    v_offset_px = max(0, int((actual_row_height_px - rend_h) / 2))

                    try:
                        xl_img = XLImage(p["bytes_io"])
                        marker = AnchorMarker(
                            col=5,
                            colOff=pixels_to_EMU(curr_h_pos),
                            row=curr_row - 1,
                            rowOff=pixels_to_EMU(v_offset_px),
                        )
                        xl_img.anchor = OneCellAnchor(
                            _from=marker,
                            ext=XDRPositiveSize2D(pixels_to_EMU(rend_w), pixels_to_EMU(rend_h)),
                        )
                        ws.add_image(xl_img)
                    except Exception as err:
                        print(f"[WARN komplain_excel] Gagal embed multi-gambar baris {curr_row}: {err}")

                    curr_h_pos += rend_w + MULTI_PHOTO_GAP_PX

    last_data_row = header_row_idx + len(items)
    ws.print_area = f"'{ws.title}'!$A$1:$G${last_data_row}"

    return wb


# ==============================================================================
# 7. PENYUSUNAN WORKBOOK KOMPLAIN TUNGGAL (SINGLE WORKBOOK BUILDER)
# ==============================================================================

def build_komplain_single_excel_workbook(
    item: Any,
    catering_nama: str,
    template_path: str,
    uploads_dir: Path,
) -> openpyxl.Workbook:
    """
    Membangun workbook Excel untuk satu laporan komplain (single export).
    Menjaga kompatibilitas dengan template dan layout komplain.
    """
    if not os.path.exists(template_path):
        raise FileNotFoundError(f"Template komplain tidak ditemukan: {template_path}")

    wb = openpyxl.load_workbook(template_path)

    sheet_name = "KETERANGAN MINUS (2)"
    if sheet_name in wb.sheetnames:
        ws = wb[sheet_name]
    elif "KETERANGAN MINUS" in wb.sheetnames:
        ws = wb["KETERANGAN MINUS"]
        ws.title = sheet_name
    elif len(wb.sheetnames) > 0:
        ws = wb.active
        ws.title = sheet_name
    else:
        wb.close()
        raise ValueError("Workbook template tidak memiliki worksheet yang valid")

    # Pastikan merge range judul baris 4 adalah A4:G4
    for rng in list(ws.merged_cells.ranges):
        if "A4" in str(rng):
            ws.unmerge_cells(str(rng))
    ws.merge_cells("A4:G4")

    try:
        ws.sheet_view.view = "normal"
        ws.sheet_view.zoomScale = 100
        ws.sheet_view.showGridLines = True
    except Exception:
        pass

    if hasattr(ws, "_images"):
        ws._images.clear()

    header_row_idx = 6
    for r in range(1, 15):
        vals = [str(ws.cell(r, c).value or "").strip().upper() for c in range(1, 8)]
        if "NO" in vals and "TANGGAL" in vals and "MENU" in vals:
            header_row_idx = r
            break

    data_row_idx = header_row_idx + 1
    if ws.max_row > data_row_idx:
        ws.delete_rows(data_row_idx + 1, ws.max_row - data_row_idx)

    ws.print_area = f"'{ws.title}'!$A$1:$G${data_row_idx}"

    thin_side = Side(style="thin", color="000000")
    thin_border = Border(left=thin_side, right=thin_side, top=thin_side, bottom=thin_side)
    data_font = Font(name="Calibri", size=11, bold=False)

    # 1. NO (selalu 1)
    c1 = ws.cell(row=data_row_idx, column=1, value=1)
    c1.font = data_font
    c1.alignment = Alignment(horizontal="center", vertical="center")
    c1.number_format = "General"
    c1.border = thin_border

    # 2. TANGGAL
    t_val = item.tanggal
    if isinstance(t_val, str):
        try:
            t_val = date.fromisoformat(t_val)
        except Exception:
            pass
    if isinstance(t_val, date) and not isinstance(t_val, datetime):
        t_val = datetime(t_val.year, t_val.month, t_val.day)

    c2 = ws.cell(row=data_row_idx, column=2, value=t_val)
    c2.font = data_font
    c2.alignment = Alignment(horizontal="center", vertical="center")
    c2.number_format = '[$-409]dd\\-mmm\\-yy;@'
    c2.border = thin_border

    # 3. SHIFT
    clean_shift = (item.shift or "Pagi").strip().capitalize()
    c3 = ws.cell(row=data_row_idx, column=3, value=clean_shift)
    c3.font = data_font
    c3.alignment = Alignment(horizontal="center", vertical="center")
    c3.number_format = "@"
    c3.border = thin_border

    # 4. MENU
    c4 = ws.cell(row=data_row_idx, column=4, value=item.menu_snapshot or "-")
    c4.font = data_font
    c4.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
    c4.number_format = "General"
    c4.border = thin_border

    # 5. CATERING
    c5 = ws.cell(row=data_row_idx, column=5, value=catering_nama)
    c5.font = data_font
    c5.alignment = Alignment(horizontal="center", vertical="center")
    c5.number_format = "General"
    c5.border = thin_border

    # 6. FOTO CATERING
    c6 = ws.cell(row=data_row_idx, column=6, value=None)
    c6.font = data_font
    c6.alignment = Alignment(horizontal="center", vertical="center")
    c6.number_format = "General"
    c6.border = thin_border

    # 7. KETERANGAN
    c7 = ws.cell(row=data_row_idx, column=7, value=item.keterangan or "-")
    c7.font = data_font
    c7.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
    c7.number_format = "General"
    c7.border = thin_border

    # Baca foto
    raw_paths = parse_photo_paths(getattr(item, "foto_path", None))
    row_photos = []
    for p_str in raw_paths:
        img_info = load_normalized_image(p_str, uploads_dir)
        if img_info is not None:
            row_photos.append(img_info)

    col_f_width = ws.column_dimensions["F"].width or DEFAULT_COL_F_WIDTH_CHARS
    col_f_px = excel_col_width_to_pixels(col_f_width)

    # Estimasi tinggi teks
    menu_lines = estimate_wrapped_lines(item.menu_snapshot, 19.5)
    ket_lines = estimate_wrapped_lines(item.keterangan, 13.0)
    max_text_lines = max(menu_lines, ket_lines, 1)
    text_height_pt = (max_text_lines * 15.0) + 16.0

    if row_photos:
        p = row_photos[0]
        # Skala foto agar pas di kolom F (~163px)
        scale = min(150.0 / p["orig_w"], 200.0 / p["orig_h"], 1.0)
        img_w = max(1, int(round(p["orig_w"] * scale)))
        img_h = max(1, int(round(p["orig_h"] * scale)))
        photo_height_pt = (img_h * 72.0 / 96.0) + 20.0
        calculated_height_pt = max(text_height_pt, photo_height_pt, 120.0)
        ws.row_dimensions[data_row_idx].height = calculated_height_pt

        row_height_px = points_to_pixels(calculated_height_pt)
        h_offset_px = max(0, int((col_f_px - img_w) / 2))
        v_offset_px = max(0, int((row_height_px - img_h) / 2))

        try:
            xl_img = XLImage(p["bytes_io"])
            marker = AnchorMarker(
                col=5,
                colOff=pixels_to_EMU(h_offset_px),
                row=data_row_idx - 1,
                rowOff=pixels_to_EMU(v_offset_px),
            )
            xl_img.anchor = OneCellAnchor(
                _from=marker,
                ext=XDRPositiveSize2D(pixels_to_EMU(img_w), pixels_to_EMU(img_h)),
            )
            ws.add_image(xl_img)
        except Exception as err:
            print(f"[WARN komplain_excel] Gagal embed gambar single export: {err}")
    else:
        calculated_height_pt = max(text_height_pt, 45.0)
        ws.row_dimensions[data_row_idx].height = calculated_height_pt

    return wb
