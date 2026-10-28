"""
Modul perhitungan dan penataan data untuk Laporan Kalori Bulanan.

Aturan output:
- Total kalori >= 900 kkal -> "+1"
- Total kalori < 900 kkal -> "-1"
- Tidak ada pengiriman/data valid pada tanggal dan shift -> "-"
- Tepat 900 kkal harus menghasilkan "+1".
- Vendor ENERGI tidak dimasukkan.
- Catering tidak aktif tidak dimasukkan.
- Duplikasi catering_id + tanggal + shift: gunakan record terbaru berdasarkan updated_at, lalu id.
"""

from __future__ import annotations
import calendar
from datetime import date, datetime
import logging
from typing import Any, Dict, List, Optional, Tuple

from sqlmodel import Session, select
from models import Catering, KaloriHarian, KaloriHarianBahan
from calculations import hitung_kalori_harian

logger = logging.getLogger(__name__)

NAMA_BULAN_INDONESIA = {
    1: "JANUARI",
    2: "FEBRUARI",
    3: "MARET",
    4: "APRIL",
    5: "MEI",
    6: "JUNI",
    7: "JULI",
    8: "AGUSTUS",
    9: "SEPTEMBER",
    10: "OKTOBER",
    11: "NOVEMBER",
    12: "DESEMBER",
}


def get_calendar_weeks(year: int, month: int) -> List[Tuple[str, List[date]]]:
    """
    Membagi tanggal-tanggal dalam satu bulan menjadi kelompok minggu kalender (W1, W2, ...).
    Aturan kalender referensi:
    - W1: mulai tanggal 1 sampai Minggu pertama bulan tersebut (atau akhir bulan jika sebelum Minggu).
    - W2, W3, dst: mulai Senin sampai Minggu.
    - Minggu terakhir: mulai Senin sampai hari terakhir dalam bulan tersebut.
    """
    _, num_days = calendar.monthrange(year, month)
    weeks: List[Tuple[str, List[date]]] = []
    current_days: List[date] = []
    week_idx = 1

    for day in range(1, num_days + 1):
        d = date(year, month, day)
        current_days.append(d)
        # weekday(): 0 = Senin, 6 = Minggu
        if d.weekday() == 6 or day == num_days:
            weeks.append((f"W{week_idx}", current_days))
            week_idx += 1
            current_days = []

    return weeks


def normalize_catering_name(name: Optional[str]) -> str:
    """Normalisasi nama catering: trim spasi, lowercase/casefold, gabungkan spasi berulang."""
    if not name:
        return ""
    return " ".join((name or "").strip().casefold().split())


def get_active_caterings(session: Session) -> List[Catering]:
    """
    Mengambil catering aktif dan mengecualikan vendor ENERGI (exact normalized match).
    Diurutkan berdasarkan id.
    """
    stmt = select(Catering).where(Catering.is_active == True).order_by(Catering.id)
    all_active = session.exec(stmt).all()
    # Filter vendor ENERGI secara exact normalized, bukan substring (sehingga SINERGI tetap masuk)
    filtered = [
        c for c in all_active
        if normalize_catering_name(c.nama) != "energi"
    ]
    return filtered


def kh_dedup_sort_key(item: KaloriHarian) -> Tuple[datetime, int]:
    """Sort key untuk memilih record terbaru berdasarkan updated_at, kemudian id."""
    dt = getattr(item, "updated_at", None)
    if dt is None:
        dt = getattr(item, "created_at", None) or datetime.min
    return (dt, item.id or 0)


def calculate_monthly_kalori_scores(
    session: Session,
    year: int,
    month: int,
    active_caterings: List[Catering],
) -> Dict[str, Any]:
    """
    Menghitung skor kalori per catering, tanggal, dan shift untuk satu bulan.
    Mengembalikan:
      - scores: dict[(catering_id, date_str, shift_norm)] -> "+1" | "-1" | "-"
      - record_digunakan_ids: list[int]
      - record_diabaikan_ids: list[int]
      - duplicate_warnings: list[str]
    """
    _, num_days = calendar.monthrange(year, month)
    start_date = date(year, month, 1)
    end_date = date(year, month, num_days)

    cat_ids = [c.id for c in active_caterings if c.id is not None]

    if not cat_ids:
        return {
            "scores": {},
            "record_digunakan_ids": [],
            "record_diabaikan_ids": [],
            "duplicate_warnings": [],
        }

    stmt = select(KaloriHarian).where(
        KaloriHarian.catering_id.in_(cat_ids),
        KaloriHarian.tanggal >= start_date,
        KaloriHarian.tanggal <= end_date,
    )
    raw_entries = session.exec(stmt).all()

    # Kelompokkan untuk deduplikasi: (catering_id, tanggal_iso, shift_norm)
    grouped: Dict[Tuple[int, str, str], List[KaloriHarian]] = {}
    for entry in raw_entries:
        s_norm = (entry.shift or "").strip().lower()
        if s_norm not in ("pagi", "malam"):
            continue
        tgl_str = entry.tanggal.isoformat() if hasattr(entry.tanggal, "isoformat") else str(entry.tanggal)
        key = (entry.catering_id, tgl_str, s_norm)
        grouped.setdefault(key, []).append(entry)

    record_digunakan_ids: List[int] = []
    record_diabaikan_ids: List[int] = []
    duplicate_warnings: List[str] = []
    chosen_entries: List[KaloriHarian] = []

    for key, group in grouped.items():
        if len(group) > 1:
            group.sort(key=kh_dedup_sort_key, reverse=True)
            chosen = group[0]
            ignored = group[1:]
            if chosen.id is not None:
                record_digunakan_ids.append(chosen.id)
            for ign in ignored:
                if ign.id is not None:
                    record_diabaikan_ids.append(ign.id)
            warn = (
                f"Duplikasi Kalori: catering_id={key[0]} tgl={key[1]} shift={key[2]}: "
                f"record ID #{chosen.id} digunakan, record ID #{[x.id for x in ignored]} diabaikan."
            )
            duplicate_warnings.append(warn)
            logger.info(warn)
            chosen_entries.append(chosen)
        else:
            chosen = group[0]
            if chosen.id is not None:
                record_digunakan_ids.append(chosen.id)
            chosen_entries.append(chosen)

    # Ambil bahan-bahan untuk chosen entries
    scores: Dict[Tuple[int, str, str], str] = {}
    chosen_ids = [e.id for e in chosen_entries if e.id is not None]

    bahans_by_entry: Dict[int, List[KaloriHarianBahan]] = {}
    if chosen_ids:
        b_stmt = select(KaloriHarianBahan).where(KaloriHarianBahan.kalori_harian_id.in_(chosen_ids))
        all_bahans = session.exec(b_stmt).all()
        for b in all_bahans:
            bahans_by_entry.setdefault(b.kalori_harian_id, []).append(b)

    for entry in chosen_entries:
        s_norm = (entry.shift or "").strip().lower()
        tgl_str = entry.tanggal.isoformat() if hasattr(entry.tanggal, "isoformat") else str(entry.tanggal)
        key = (entry.catering_id, tgl_str, s_norm)
        b_list = bahans_by_entry.get(entry.id, [])
        if not b_list:
            # Tidak ada bahan valid -> tampilkan '-'
            scores[key] = "-"
            continue

        calc_res = hitung_kalori_harian(b_list)
        total_kal = calc_res.get("total_kalori", 0.0)
        # Aturan nilai: >= 900 -> +1; < 900 -> -1
        if total_kal >= 900.0:
            scores[key] = "+1"
        else:
            scores[key] = "-1"

    return {
        "scores": scores,
        "record_digunakan_ids": record_digunakan_ids,
        "record_diabaikan_ids": record_diabaikan_ids,
        "duplicate_warnings": duplicate_warnings,
    }


def pack_monthly_report_layout(
    year: int,
    month: int,
    active_caterings: List[Catering],
    scores_map: Dict[Tuple[int, str, str], str],
    holiday_dates: set[str],
) -> Dict[str, Any]:
    """
    Menata data mingguan dan harian ke dalam format dua blok (Left Block dan Right Block).
    Left Block = Baris 13-52 (maksimal 40 slot).
    Right Block = Baris 13-52 (maksimal 40 slot).
    Overflow dari baris 52 pada Left Block otomatis pindah ke Right Block mulai baris 13.
    """
    weeks = get_calendar_weeks(year, month)
    month_name = NAMA_BULAN_INDONESIA.get(month, "")

    # Hitung data per minggu
    # week_summaries: list of {
    #   'week_name': str,
    #   'days': list of {
    #       'date': date,
    #       'date_str': str,
    #       'is_holiday': bool,
    #       'pagi_scores': {cat_id: "+1"|"-1"|"-"},
    #       'malam_scores': {cat_id: "+1"|"-1"|"-"},
    #   },
    #   'bagus_counts': {cat_id: int},
    #   'not_ok_counts': {cat_id: int},
    # }
    week_data_list: List[Dict[str, Any]] = []

    for w_name, days in weeks:
        days_data = []
        bagus_counts = {c.id: 0 for c in active_caterings}
        not_ok_counts = {c.id: 0 for c in active_caterings}

        for d in days:
            d_str = d.isoformat()
            is_hol = d_str in holiday_dates

            pagi_scores = {}
            malam_scores = {}

            for c in active_caterings:
                p_score = scores_map.get((c.id, d_str, "pagi"), "-")
                m_score = scores_map.get((c.id, d_str, "malam"), "-")

                pagi_scores[c.id] = p_score
                malam_scores[c.id] = m_score

                if p_score in ("+1", "1", 1):
                    bagus_counts[c.id] += 1
                elif p_score in ("-1", -1):
                    not_ok_counts[c.id] += 1

                if m_score in ("+1", "1", 1):
                    bagus_counts[c.id] += 1
                elif m_score in ("-1", -1):
                    not_ok_counts[c.id] += 1

            days_data.append({
                "date": d,
                "date_str": d_str,
                "is_holiday": is_hol,
                "pagi_scores": pagi_scores,
                "malam_scores": malam_scores,
            })

        week_data_list.append({
            "week_name": w_name,
            "days": days_data,
            "bagus_counts": bagus_counts,
            "not_ok_counts": not_ok_counts,
        })

    # Hitung kumulatif W1 + W2 + ...
    cum_bagus: Dict[int, int] = {c.id: 0 for c in active_caterings}
    cum_not_ok: Dict[int, int] = {c.id: 0 for c in active_caterings}
    cumulative_data_list: List[Dict[str, Any]] = []

    for idx, w_info in enumerate(week_data_list):
        for c in active_caterings:
            cum_bagus[c.id] += w_info["bagus_counts"][c.id]
            cum_not_ok[c.id] += w_info["not_ok_counts"][c.id]

        cum_label = " + ".join([week_data_list[i]["week_name"] for i in range(idx + 1)])
        cumulative_data_list.append({
            "cum_label": cum_label,
            "cum_bagus": dict(cum_bagus),
            "cum_not_ok": dict(cum_not_ok),
        })

    # Hitung kapasitas dinamis
    total_days = sum(len(w_info["days"]) for w_info in week_data_list)
    num_weeks = len(week_data_list)
    total_content_rows = (total_days * 2) + (num_weeks * 2) + ((num_weeks - 1) * 2)
    half_rows = (total_content_rows + 1) // 2
    if half_rows % 2 != 0:
        half_rows += 1
    block_capacity = max(40, half_rows)
    max_row_left = 13 + block_capacity - 1

    # Packing ke Left Block dan Right Block
    left_items: List[Dict[str, Any]] = []
    right_items: List[Dict[str, Any]] = []

    current_block = "LEFT"
    current_row = 13

    for idx, w_info in enumerate(week_data_list):
        w_name = w_info["week_name"]

        # 1. Hari-hari dalam minggu
        for d_info in w_info["days"]:
            if current_block == "LEFT" and current_row + 1 > max_row_left:
                current_block = "RIGHT"
                current_row = 13

            target = left_items if current_block == "LEFT" else right_items
            target.append({
                "type": "DAY",
                "start_row": current_row,
                "end_row": current_row + 1,
                "week_name": w_name,
                "day_data": d_info,
            })
            current_row += 2

        # 2. Baris Ringkasan Mingguan (∑ BAGUS, ∑ NOT OK)
        if current_block == "LEFT" and current_row + 1 > max_row_left:
            current_block = "RIGHT"
            current_row = 13

        target = left_items if current_block == "LEFT" else right_items
        target.append({
            "type": "SUMMARY",
            "start_row": current_row,
            "end_row": current_row + 1,
            "week_name": w_name,
            "bagus_counts": w_info["bagus_counts"],
            "not_ok_counts": w_info["not_ok_counts"],
        })
        current_row += 2

        # 3. Baris Kumulatif (untuk W2 onwards)
        if idx >= 1:
            if current_block == "LEFT" and current_row + 1 > max_row_left:
                current_block = "RIGHT"
                current_row = 13

            target = left_items if current_block == "LEFT" else right_items
            cum_info = cumulative_data_list[idx]
            target.append({
                "type": "CUMULATIVE",
                "start_row": current_row,
                "end_row": current_row + 1,
                "cum_label": cum_info["cum_label"],
                "cum_bagus": cum_info["cum_bagus"],
                "cum_not_ok": cum_info["cum_not_ok"],
            })
            current_row += 2

    # Tentukan max_row untuk print area
    max_left_row = max([it["end_row"] for it in left_items], default=52)
    max_right_row = max([it["end_row"] for it in right_items], default=12)
    total_max_row = max(max_left_row, max_right_row, 52)

    return {
        "year": year,
        "month": month,
        "month_name": month_name,
        "active_caterings": active_caterings,
        "holiday_dates": holiday_dates,
        "left_items": left_items,
        "right_items": right_items,
        "max_row": total_max_row,
        "week_data_list": week_data_list,
        "cumulative_data_list": cumulative_data_list,
    }
