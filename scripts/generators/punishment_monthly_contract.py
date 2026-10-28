"""
Modul Kontrak Data dan Adapter Laporan Punishment PT JAI.
Neutral module to prevent circular imports between calculation, endpoints, and Excel generator.

Kontrak resmi baru untuk Kalori Minus menggunakan named key:
- minus_tidak_berturut
- minus_2_berturut
- minus_berikutnya
- minus_sidak

Alias lama (kategori_1, kategori_2, kategori_3, kategori_4) dipertahankan
untuk kompatibilitas snapshot lama dengan mapping 1-to-1 identik.
"""

import copy
import math
import re
from datetime import date, datetime
from typing import Any, Callable, Dict, List, Optional, Tuple

MONTH_NAMES_ID = {
    1: "Jan", 2: "Feb", 3: "Mar", 4: "Apr", 5: "Mei", 6: "Jun",
    7: "Jul", 8: "Agu", 9: "Sep", 10: "Okt", 11: "Nov", 12: "Des"
}

KEY_ALIAS_MAPPING: List[Tuple[str, str, str]] = [
    ("minus_tidak_berturut", "kategori_1", "TIDAK BERTURUT-TURUT"),
    ("minus_2_berturut", "kategori_2", "BERTURUT-TURUT (2x)"),
    ("minus_berikutnya", "kategori_3", "SETELAH 2 HARI BERTURUT-TURUT"),
    ("minus_sidak", "kategori_4", "BERTURUT-TURUT SAAT SIDAK"),
]

EMPTY_MINUS_DETAIL: Dict[str, Any] = {
    "nama": "",
    "ada": False,
    "jumlah": 0,
    "tanggal": [],
    "tanggal_display": "",
    "tgl": "-",
    "pengurangan_poin_pagi": 0.0,
    "pengurangan_poin_malam": 0.0,
}

EMPTY_PENCAPAIAN_KALORI: Dict[str, Any] = {
    "jumlah_mencapai": 0,
    "jumlah_tidak_mencapai": 0,
    "jumlah_pengiriman": 0,
    "persentase": 0.0,
    "formatted_persen": "-",
    "score_performance": 0.0,
    "has_data": False,
}


def _safe_float(val: Any, default: float = 0.0) -> float:
    if val is None or isinstance(val, bool):
        return default
    try:
        num = float(val)
        if math.isnan(num) or math.isinf(num):
            return default
        return round(num, 2)
    except (ValueError, TypeError):
        return default


def _safe_int(val: Any, default: int = 0) -> int:
    if val is None or isinstance(val, bool):
        return default
    try:
        num = float(val)
        if math.isnan(num) or math.isinf(num):
            return default
        return int(num)
    except (ValueError, TypeError):
        return default


def _parse_date_to_iso(d_val: Any) -> Optional[Tuple[int, int, int, str]]:
    """
    Mengurai tanggal ke tuple (year, month, day, iso_str) untuk pengurutan kronologis.
    Mendukung date object, ISO format (YYYY-MM-DD), atau format teks umum.
    """
    if isinstance(d_val, (date, datetime)):
        iso_s = d_val.strftime("%Y-%m-%d")
        return (d_val.year, d_val.month, d_val.day, iso_s)
    if not isinstance(d_val, str):
        return None
    s = d_val.strip()
    if not s or s == "-":
        return None

    # Format ISO: YYYY-MM-DD
    m_iso = re.match(r"^(\d{4})-(\d{1,2})-(\d{1,2})$", s)
    if m_iso:
        y, m, d = int(m_iso.group(1)), int(m_iso.group(2)), int(m_iso.group(3))
        return (y, m, d, f"{y:04d}-{m:02d}-{d:02d}")

    # Format teks Indonesia: DD Mon YYYY / DD Month YYYY (misal "10 Jan 2025")
    parts = s.split()
    if len(parts) == 3 and parts[0].isdigit() and parts[2].isdigit():
        d = int(parts[0])
        y = int(parts[2])
        m_str = parts[1].lower()
        m_map = {
            "jan": 1, "januari": 1, "feb": 2, "februari": 2, "mar": 3, "maret": 3,
            "apr": 4, "april": 4, "mei": 5, "may": 5, "jun": 6, "juni": 6,
            "jul": 7, "juli": 7, "agu": 8, "agustus": 8, "aug": 8,
            "sep": 9, "september": 9, "okt": 10, "oktober": 10, "oct": 10,
            "nov": 11, "november": 11, "des": 12, "desember": 12, "dec": 12,
        }
        m = m_map.get(m_str, 1)
        return (y, m, d, f"{y:04d}-{m:02d}-{d:02d}")

    return None


def format_iso_dates_to_display(iso_list: List[str]) -> str:
    """Format daftar tanggal ISO menjadi string display 'DD Mon YYYY, ...'."""
    if not iso_list:
        return ""
    formatted = []
    for iso_s in iso_list:
        m = re.match(r"^(\d{4})-(\d{2})-(\d{2})$", iso_s)
        if m:
            y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
            mon_name = MONTH_NAMES_ID.get(mo, str(mo))
            formatted.append(f"{d:02d} {mon_name} {y}")
        else:
            formatted.append(iso_s)
    return ", ".join(formatted)


def normalize_minus_detail(
    named_val: Any,
    alias_val: Any,
    default_name: str,
    on_conflict: Optional[Callable[[str, Any, Any], None]] = None,
) -> Dict[str, Any]:
    """
    Normalisasi single kategori minus kalori.
    Aturan:
    1. Jika named_val valid (dict), gunakan named_val sebagai sumber utama.
    2. Jika named_val tidak tersedia, gunakan alias_val.
    3. Jika keduanya tidak tersedia, gunakan default kosong.
    4. Jika named_val dan alias_val keduanya ada dan berbeda nilai, named_val menang dan catat konflik.
    5. Output selalu memiliki nilai yang aman (tidak pernah None, NaN, inf, atau crash).
    """
    has_named = isinstance(named_val, dict)
    has_alias = isinstance(alias_val, dict)

    if has_named and has_alias and named_val != alias_val:
        if on_conflict:
            on_conflict(default_name, named_val, alias_val)

    src = named_val if has_named else (alias_val if has_alias else {})
    if not isinstance(src, dict):
        src = {}

    nama = str(src.get("nama") or default_name)

    # Tanggal: kumpulkan dari array 'tanggal' atau parsing dari string 'tanggal_display'/'tgl'
    raw_dates = src.get("tanggal")
    date_tuples: List[Tuple[int, int, int, str]] = []

    if isinstance(raw_dates, list):
        for item in raw_dates:
            parsed = _parse_date_to_iso(item)
            if parsed:
                date_tuples.append(parsed)
    elif isinstance(raw_dates, (str, date, datetime)):
        parsed = _parse_date_to_iso(raw_dates)
        if parsed:
            date_tuples.append(parsed)

    # Jika array tanggal kosong, coba ekstrak dari string display / tgl
    if not date_tuples:
        str_dates = src.get("tanggal_display") or src.get("tgl")
        if isinstance(str_dates, str) and str_dates.strip() and str_dates.strip() != "-":
            for chunk in str_dates.split(","):
                parsed = _parse_date_to_iso(chunk.strip())
                if parsed:
                    date_tuples.append(parsed)

    # Urutkan kronologis dan hilangkan duplikat
    seen_iso = set()
    sorted_iso: List[str] = []
    for item in sorted(date_tuples, key=lambda x: (x[0], x[1], x[2])):
        iso_str = item[3]
        if iso_str not in seen_iso:
            seen_iso.add(iso_str)
            sorted_iso.append(iso_str)

    # Format display
    raw_disp = src.get("tanggal_display")
    if isinstance(raw_disp, str) and raw_disp.strip() and raw_disp.strip() != "-":
        tanggal_display = raw_disp.strip()
    elif sorted_iso:
        tanggal_display = format_iso_dates_to_display(sorted_iso)
    else:
        tanggal_display = ""

    tgl_compat = tanggal_display if tanggal_display else "-"

    jumlah = _safe_int(src.get("jumlah"), 0)
    # Jika jumlah belum diisi namun tanggal ada, atau ada=True
    if jumlah == 0 and sorted_iso:
        jumlah = len(sorted_iso)

    ada_raw = src.get("ada")
    if ada_raw is True:
        ada = True
    elif ada_raw is False:
        ada = False
    else:
        ada = (jumlah > 0)

    p_pagi = _safe_float(src.get("pengurangan_poin_pagi"), 0.0)
    p_malam = _safe_float(src.get("pengurangan_poin_malam"), 0.0)

    return {
        "nama": nama,
        "ada": ada,
        "jumlah": jumlah,
        "tanggal": sorted_iso,
        "tanggal_display": tanggal_display,
        "tgl": tgl_compat,
        "pengurangan_poin_pagi": p_pagi,
        "pengurangan_poin_malam": p_malam,
    }


def normalize_pencapaian_kalori(val: Any) -> Dict[str, Any]:
    """Normalisasi struktur pencapaian kalori (>=900 kkal)."""
    if not isinstance(val, dict):
        val = {}

    mencapai = _safe_int(val.get("jumlah_mencapai"), 0)
    tidak_mencapai = _safe_int(val.get("jumlah_tidak_mencapai"), 0)
    pengiriman = _safe_int(val.get("jumlah_pengiriman"), mencapai + tidak_mencapai)
    persentase = _safe_float(val.get("persentase"), 0.0)
    score = _safe_float(val.get("score_performance"), 0.0)

    has_data = val.get("has_data")
    if has_data is True:
        has_data_bool = True
    elif has_data is False:
        has_data_bool = False
    else:
        has_data_bool = pengiriman > 0

    fmt_p = val.get("formatted_persen")
    if isinstance(fmt_p, str) and fmt_p.strip():
        formatted_persen = fmt_p.strip()
    elif pengiriman > 0:
        formatted_persen = f"{persentase:.2f}%"
    else:
        formatted_persen = "-"

    return {
        "jumlah_mencapai": mencapai,
        "jumlah_tidak_mencapai": tidak_mencapai,
        "jumlah_pengiriman": pengiriman,
        "persentase": persentase,
        "formatted_persen": formatted_persen,
        "score_performance": score,
        "has_data": has_data_bool,
    }


def adapt_snapshot_kalori_minus(
    snapshot: Any,
    on_conflict: Optional[Callable[[str, Any, Any], None]] = None,
) -> Dict[str, Any]:
    """
    Adapter non-destruktif dan IDEMPOTEN untuk melengkapi struktur Minus Kalori.
    - Menjamin input snapshot TIDAK termutasi (menggunakan copy.deepcopy).
    - Memenuhi sifat idempoten: adapt(adapt(s)) == adapt(s).
    - Memastikan setiap catering memiliki named key dan alias legacy dengan nilai identik.
    - Tidak menyentuh atau menulis kembali ke database.
    """
    if not isinstance(snapshot, dict):
        return {}

    adapted = copy.deepcopy(snapshot)
    caterings = adapted.get("caterings")
    if not isinstance(caterings, list):
        adapted["caterings"] = []
        return adapted

    for c in caterings:
        if not isinstance(c, dict):
            continue

        # Ambil atau inisialisasi objek kalori
        kalori = c.get("jumlah_kalori")
        if kalori is None:
            kalori = c.get("kalori")
        if not isinstance(kalori, dict):
            kalori = {}

        # Pastikan dua referensi nama tersedia
        c["jumlah_kalori"] = kalori
        c["kalori"] = kalori

        pelanggaran = kalori.get("pelanggaran")
        if not isinstance(pelanggaran, dict):
            pelanggaran = {}
        kalori["pelanggaran"] = pelanggaran

        # Normalisasi ke-4 kategori dengan mapping identik
        tot_p_pagi = 0.0
        tot_p_malam = 0.0

        for named_key, alias_key, def_name in KEY_ALIAS_MAPPING:
            named_val = pelanggaran.get(named_key)
            alias_val = pelanggaran.get(alias_key)

            normalized_item = normalize_minus_detail(
                named_val=named_val,
                alias_val=alias_val,
                default_name=def_name,
                on_conflict=on_conflict,
            )

            # Assign identik ke named key dan alias
            pelanggaran[named_key] = normalized_item
            pelanggaran[alias_key] = normalized_item

            tot_p_pagi += normalized_item["pengurangan_poin_pagi"]
            tot_p_malam += normalized_item["pengurangan_poin_malam"]

        # Akumulasi poin pelanggaran
        raw_tot_pagi = pelanggaran.get("total_pengurangan_poin_pagi")
        raw_tot_malam = pelanggaran.get("total_pengurangan_poin_malam")

        pelanggaran["total_pengurangan_poin_pagi"] = (
            _safe_float(raw_tot_pagi) if raw_tot_pagi is not None else round(tot_p_pagi, 2)
        )
        pelanggaran["total_pengurangan_poin_malam"] = (
            _safe_float(raw_tot_malam) if raw_tot_malam is not None else round(tot_p_malam, 2)
        )

        kalori["pengurangan_poin_pagi"] = pelanggaran["total_pengurangan_poin_pagi"]
        kalori["pengurangan_poin_malam"] = pelanggaran["total_pengurangan_poin_malam"]

        # Normalisasi pencapaian
        pencapaian = normalize_pencapaian_kalori(kalori.get("pencapaian"))
        kalori["pencapaian"] = pencapaian

        # Score performance kalori
        if "score_performance" in kalori and kalori["score_performance"] is not None:
            kalori["score_performance"] = _safe_float(kalori["score_performance"])
        else:
            kalori["score_performance"] = pencapaian["score_performance"]

    return adapted
