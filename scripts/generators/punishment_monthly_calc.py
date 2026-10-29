"""
Modul Kalkulasi dan Agregasi Laporan Punishment Bulanan PT JAI.
Sumber Kebenaran (Single Source of Truth) untuk:
1. Score Performance: Standar Penilaian Supplier Catering Rev. 04/PK/JAI/IV/2018 (Max 100)
2. Aturan Punishment: Perubahan Aturan Punishment Catering tanggal 2 Oktober 2025
"""
import copy
import math
from datetime import date, timedelta, datetime, timezone
from typing import Optional, Any
from sqlmodel import Session, select, text
from audit_calc import compute_audit_entry_score, get_audit_entries_compatible
from audit_completeness import check_audit_completeness
from models import (
    Catering, Keterlambatan, KelayakanEntry, AuditEntry, AuditKriteria,
    AuditEntryItem, KaloriHarian, KaloriHarianBahan, MinusEntry, KomplainCatering,
    CateringOrderQuota, LaporanPunishmentBulanan, LaporanPunishmentCateringOrder,
    CateringDailyOrder
)
from calculations import hitung_energi_bahan, AMBANG_KALORI_MENCAPAI
from punishment_monthly_contract import (
    adapt_snapshot_kalori_minus,
    normalize_minus_detail,
    normalize_pencapaian_kalori,
    EMPTY_MINUS_DETAIL,
    EMPTY_PENCAPAIAN_KALORI,
)


def validate_punishment_percentage(val: Any) -> float:
    """
    Validasi persentase punishment pada kontrak backend resmi (skala 0–100):
    - 0 <= punish_pct <= 100
    - Menolak None / nilai kosong
    - Menolak boolean (karena bool adalah subclass int)
    - Menolak tipe non-numerik (str, dict, list, dll.)
    - Menolak NaN
    - Menolak positive / negative infinity
    - Menolak nilai < 0 atau > 100
    """
    if val is None:
        raise ValueError("Persentase punishment wajib diisi (tidak boleh kosong/None).")
    if isinstance(val, bool):
        raise TypeError("Tipe boolean tidak valid untuk persentase punishment.")
    if not isinstance(val, (int, float)):
        raise TypeError(f"Tipe non-numerik ({type(val).__name__}) tidak valid untuk persentase punishment.")

    num = float(val)
    if math.isnan(num):
        raise ValueError("Nilai persentase punishment NaN ditolak.")
    if math.isinf(num):
        raise ValueError("Nilai persentase punishment infinity ditolak.")
    if num < 0.0 or num > 100.0:
        raise ValueError(f"Nilai persentase punishment {num} di luar rentang valid 0–100.")
    return num


def validate_backend_percentage(value: Optional[float]) -> Optional[float]:
    """
    Validasi persentase kontrak backend (skala 0 - 100):
    - None -> None
    - nilai < 0 atau nilai > 100 -> raise ValueError
    - NaN / Inf -> raise ValueError
    - Non-numerik / bool -> raise TypeError
    - 0 -> 0.0%
    - 0.65 -> 0.65% (tetap 0.65%, bukan 65%)
    - 1 -> 1.0% (tetap 1%, bukan 100%)
    - 65 -> 65.0%
    - 80 -> 80.0%
    - 96.25 -> 96.25%
    - 100 -> 100.0%
    """
    if value is None:
        return None
    if isinstance(value, bool):
        raise TypeError("Tipe boolean tidak valid untuk persentase.")
    if not isinstance(value, (int, float)):
        raise TypeError(f"Tipe non-numerik ({type(value).__name__}) tidak valid untuk persentase.")
    val = float(value)
    if math.isnan(val):
        raise ValueError("Nilai persentase NaN ditolak.")
    if math.isinf(val):
        raise ValueError("Nilai persentase infinity ditolak.")
    if val < 0.0 or val > 100.0:
        raise ValueError(f"Nilai persentase di luar rentang valid 0-100: {val}")
    return round(val, 4)


def validate_order_value(value: Any) -> float:
    """
    Validasi nilai jumlah order (skala non-negatif):
    - menerima int / float finite >= 0
    - menolak bool (karena bool subclass int di Python)
    - menolak string
    - menolak NaN, +Infinity, -Infinity
    - menolak nilai negatif (< 0)
    - menolak None / null
    """
    if value is None:
        raise ValueError("Jumlah order tidak boleh kosong/null")
    if isinstance(value, bool):
        raise ValueError("Tipe boolean tidak valid untuk jumlah order")
    if isinstance(value, str):
        raise ValueError("Tipe string tidak valid untuk jumlah order (harus numerik)")
    if not isinstance(value, (int, float)):
        raise ValueError(f"Tipe non-numerik ({type(value).__name__}) tidak valid untuk jumlah order")

    val = float(value)
    if math.isnan(val):
        raise ValueError("Nilai order NaN tidak valid")
    if math.isinf(val):
        raise ValueError("Nilai order infinity tidak valid")
    if val < 0.0:
        raise ValueError("Jumlah order tidak boleh negatif (harus >= 0)")
    return val



def percentage_to_excel_ratio(value: Optional[float]) -> Optional[float]:
    """
    Konversi persen backend (skala 0-100) menjadi rasio Excel (skala 0-1) untuk format sel numerik.
    Contoh: 96.25% -> 0.9625, 1% -> 0.01, 0.65% -> 0.0065.
    Hanya digunakan saat menulis sel ke workbook Excel.
    """
    if value is None:
        return None
    val = validate_backend_percentage(value)
    return round(val / 100.0, 6)


def legacy_ratio_to_percentage(value: Optional[float]) -> Optional[float]:
    """
    Konversi rasio legacy (skala 0-1) ke persentase backend (skala 0-100).
    Hanya digunakan jika ada field/sumber legacy yang secara eksplisit terdokumentasi berskala 0-1.
    Jangan dipanggil berdasarkan tebakan nilai.
    """
    if value is None:
        return None
    val = float(value)
    if val < 0.0 or val > 1.0:
        raise ValueError(f"Nilai rasio legacy di luar rentang 0-1: {val}")
    return round(val * 100.0, 4)


VALID_SHIFT_BASIS = {"pagi", "malam", "keduanya", "total_order", "tidak_diterapkan"}


def format_punishment_display(val: Optional[float]) -> str:
    """
    Format angka punishment desimal maksimal 2 digit:
    15 -> '15'
    15.5 -> '15.5'
    15.25 -> '15.25'
    """
    if val is None or val == 0:
        return "0"
    v = round(float(val), 2)
    if v.is_integer():
        return str(int(v))
    s = f"{v:.2f}".rstrip("0").rstrip(".")
    return s


def hitung_potongan_shift(
    order_pagi: float,
    order_malam: float,
    punish_pct: float,
    shift_basis: Optional[str],
) -> tuple[float, float, str]:
    """
    Menghitung pemotongan order pagi dan malam secara terpusat berdasarkan aturan shift eksplisit.
    Kontrak backend resmi: skala 0–100.
    rate = punish_pct / 100.0
    Aturan final SHIFT_BASIS = total_order:
    - Kelayakan dan Audit dibebankan satu kali pada total_order = order_pagi + order_malam (Kebersihan bukan domain Punishment).
    - Pembagian proporsional berdasarkan jumlah order:
      total_order = round(order_pagi + order_malam, 2)
      total_punishment = round(total_order * rate, 2)
      punishment_pagi = round(order_pagi * rate, 2)
      punishment_malam = round(total_punishment - punishment_pagi, 2)
    - Jika order pagi 0, seluruh punishment masuk malam.
    - Jika order malam 0, seluruh punishment masuk pagi.
    - Jika total order 0, seluruh punishment harus 0.
    """
    pct = validate_punishment_percentage(punish_pct)
    rate = pct / 100.0

    basis = (shift_basis or "").strip().lower()
    if basis == "total_order":
        total_order = round(order_pagi + order_malam, 2)
        if total_order <= 0.0:
            total_punishment = 0.0
            punishment_pagi = 0.0
            punishment_malam = 0.0
            return punishment_pagi, punishment_malam, "Terkonfirmasi (Total Order)"

        total_punishment = round(total_order * rate, 2)
        punishment_pagi = round(order_pagi * rate, 2)
        punishment_malam = round(total_punishment - punishment_pagi, 2)

        return punishment_pagi, punishment_malam, "Terkonfirmasi (Total Order)"
    elif basis == "pagi":
        return round(order_pagi * rate, 2), 0.0, "Terkonfirmasi (Pagi)"
    elif basis == "malam":
        return 0.0, round(order_malam * rate, 2), "Terkonfirmasi (Malam)"
    elif basis == "keduanya":
        return round(order_pagi * rate, 2), round(order_malam * rate, 2), "Terkonfirmasi (Keduanya)"
    elif basis == "tidak_diterapkan":
        return 0.0, 0.0, "Terkonfirmasi (Tidak Diterapkan)"
    else:
        # Belum dikonfirmasi pemilik proses: JANGAN diam-diam potong kedua shift!
        return 0.0, 0.0, "Butuh Konfirmasi Aturan Shift"


# ==============================================================================
# 1. SCORE PERFORMANCE FUNCTIONS (Standar Rev. 04/PK/JAI/IV/2018)
# ==============================================================================

def score_performance_keterlambatan(kejadian: int) -> float:
    if kejadian == 0:
        return 30.0
    elif kejadian == 1:
        return 20.0
    elif 2 <= kejadian <= 3:
        return 10.0
    else:
        return 0.0


def score_performance_kelayakan(persen: Optional[float]) -> float:
    if persen is None:
        return 0.0
    p = validate_backend_percentage(persen)
    # Nilai tepat 80.0% masuk kelompok score 10
    if p > 80.0:
        return 20.0
    elif p >= 65.0:
        return 10.0
    else:
        return 5.0


def score_performance_kalori(persen_mencapai: Optional[float]) -> float:
    if persen_mencapai is None:
        return 0.0
    p = validate_backend_percentage(persen_mencapai)
    if p >= 100.0:
        return 15.0
    elif p >= 80.0:
        return 12.0
    elif p >= 60.0:
        return 8.0
    else:
        return 5.0


def score_performance_komplain(jumlah: int) -> float:
    if jumlah == 0:
        return 15.0
    elif 1 <= jumlah <= 2:
        return 12.0
    elif 3 <= jumlah <= 4:
        return 8.0
    else:
        # Dokumen resmi Rev. 04/PK/JAI/IV/2018 secara eksplisit menulis '≥ 5 komplain' (Score 5)
        return 5.0


def score_performance_audit(persen: Optional[float]) -> float:
    if persen is None:
        return 0.0
    p = validate_backend_percentage(persen)
    if p >= 95.0:
        return 10.0
    elif p >= 81.0:
        return 8.0
    elif p >= 70.0:
        return 6.0
    else:
        return 4.0


CANONICAL_PUNISHMENT_RULE_VERSIONS = {"2024", "2025-10"}

PUNISHMENT_RULE_ALIASES = {
    "2025_v1": "2025-10",
    "2025": "2025-10",
    "2025_10": "2025-10",
    "oktober_2025": "2025-10",
    "2025_oktober": "2025-10",
}

ACTIVE_PUNISHMENT_RULE_VERSION = "2025-10"


def get_active_rule_version() -> str:
    """Sumber kebenaran tunggal untuk versi aturan punishment aktif."""
    return ACTIVE_PUNISHMENT_RULE_VERSION


RULE_VERSION_CONFIGS = {
    "2024": {
        "rule_version": "2024",
        "status": "DISETUJUI",
        "shift_basis": "total_order",
        "multi_audit_aggregation": "arithmetic_mean",
        "punishment_rounding": "decimal_2",
        "complaint_rule": "gte_5_score_5",
        "complaint_score_rule": "gte_5_score_5",
        "is_approved": True,
        "notes": "Perubahan aturan punishment 2024: Kalori 1%/5%/3%, Sidak 5% aktif, Kelayakan <90%=6%, Audit 86-95%=4%.",
        "audit": {
            "tier_gt_95": 0.0,
            "tier_86_95": 4.0,
            "tier_76_85": 6.0,
            "tier_66_75": 8.0,
            "tier_0_65": 10.0,
        },
        "kalori": {
            "kat1_pct": 1.0,
            "kat2_pct": 5.0,
            "kat3_pct": 3.0,
            "sidak_active": True,
            "kat4_sidak_pct": 5.0,
        },
        "kelayakan": {
            "tier_gte_90": 0.0,
            "tier_lt_90": 6.0,
        },
    },
    "2025-10": {
        "rule_version": "2025-10",
        "status": "DISETUJUI",
        "shift_basis": "total_order",
        "multi_audit_aggregation": "arithmetic_mean",
        "punishment_rounding": "decimal_2",
        "complaint_rule": "gte_5_score_5",
        "complaint_score_rule": "gte_5_score_5",
        "is_approved": True,
        "notes": "Perubahan aturan punishment catering Oktober 2025: Audit 86-95%=4%, Kalori 3%/7%/5%, Sidak tidak berlaku, Kelayakan 85-90%=6% / <85%=10%.",
        "audit": {
            "tier_gt_95": 0.0,
            "tier_86_95": 4.0,
            "tier_76_85": 6.0,
            "tier_66_75": 8.0,
            "tier_0_65": 10.0,
        },
        "kalori": {
            "kat1_pct": 3.0,
            "kat2_pct": 7.0,
            "kat3_pct": 5.0,
            "sidak_active": False,
            "kat4_sidak_pct": 0.0,
        },
        "kelayakan": {
            "tier_gt_90": 0.0,
            "tier_85_90": 6.0,
            "tier_lt_85": 10.0,
        },
    },
    "2025_draft": {
        "rule_version": "2025_draft",
        "status": "MENUNGGU_KONFIRMASI",
        "shift_basis": "belum_dikonfirmasi",
        "multi_audit_aggregation": "arithmetic_mean",
        "punishment_rounding": "decimal_2",
        "complaint_rule": "gte_5_score_5",
        "complaint_score_rule": "gte_5_score_5",
        "is_approved": False,
        "notes": "Draft aturan belum disahkan pemilik proses.",
        "audit": {
            "tier_gt_95": 0.0,
            "tier_86_95": 4.0,
            "tier_76_85": 6.0,
            "tier_66_75": 8.0,
            "tier_0_65": 10.0,
        },
        "kalori": {
            "kat1_pct": 3.0,
            "kat2_pct": 7.0,
            "kat3_pct": 5.0,
            "sidak_active": False,
            "kat4_sidak_pct": 0.0,
        },
        "kelayakan": {
            "tier_gt_90": 0.0,
            "tier_85_90": 6.0,
            "tier_lt_85": 10.0,
        },
    },
}

# Compatibility aliases: deepcopy independen agar mutasi test pada alias tidak merusak canonical
for _alias, _canonical in PUNISHMENT_RULE_ALIASES.items():
    if _canonical in RULE_VERSION_CONFIGS and _alias not in RULE_VERSION_CONFIGS:
        RULE_VERSION_CONFIGS[_alias] = copy.deepcopy(RULE_VERSION_CONFIGS[_canonical])
        RULE_VERSION_CONFIGS[_alias]["rule_version"] = _alias


def resolve_rule_version(rule_version: Optional[str]) -> str:
    """
    Resolve dan validasi string rule_version:
    - None atau "" -> default ke ACTIVE_PUNISHMENT_RULE_VERSION ("2025-10")
    - "2024" -> "2024"
    - "2025-10" -> "2025-10"
    - Legacy alias ("2025_v1", "2025", dll.) -> "2025-10"
    - "2025_draft" -> "2025_draft" (unapproved status untuk pengujian)
    - Explicit invalid / unsupported ("2018", "abc", "2026", dll.) -> raise ValueError
    """
    if rule_version is None:
        return get_active_rule_version()
    v = str(rule_version).strip()
    if not v:
        return get_active_rule_version()

    if v == "2025_draft":
        return "2025_draft"

    if v in CANONICAL_PUNISHMENT_RULE_VERSIONS:
        return v

    if v in PUNISHMENT_RULE_ALIASES:
        return PUNISHMENT_RULE_ALIASES[v]
    v_lower = v.lower()
    if v_lower in PUNISHMENT_RULE_ALIASES:
        return PUNISHMENT_RULE_ALIASES[v_lower]

    # Cek apakah ada di RULE_VERSION_CONFIGS (misal dynamic injection saat testing seperti 2026_v1)
    if v in RULE_VERSION_CONFIGS:
        return v

    supported_list = sorted(list(CANONICAL_PUNISHMENT_RULE_VERSIONS))
    raise ValueError(
        f"Rule version '{rule_version}' tidak valid / tidak berlaku pada periode ini. Nilai yang didukung: {', '.join(supported_list)}."
    )


def is_2024_rule_version(rule_version: Optional[str]) -> bool:
    """Mengecek apakah versi aturan termasuk era 2024."""
    if not rule_version:
        return False
    try:
        resolved = resolve_rule_version(rule_version)
        return resolved == "2024"
    except ValueError:
        return False


def get_rule_config(rule_version: Optional[str] = None) -> dict:
    """Mengambil dict konfigurasi aturan canonical berdasarkan rule_version atau active default."""
    canonical = resolve_rule_version(rule_version)
    return RULE_VERSION_CONFIGS.get(canonical, RULE_VERSION_CONFIGS[ACTIVE_PUNISHMENT_RULE_VERSION])

ATURAN_ASUMSI_BISNIS = {
    "shift_basis": {
        "kode": "total_order",
        "status": "DISETUJUI",
        "dasar": "Kelayakan dan Audit dibebankan satu kali pada total_order = order_pagi + order_malam (Kebersihan bukan domain Punishment). Tidak ada pemotongan ganda.",
    },
    "multi_audit_aggregation": {
        "kode": "arithmetic_mean",
        "status": "DISETUJUI",
        "dasar": "Rata-rata aritmatika seluruh persentase audit valid dalam periode untuk katering terkait.",
    },
    "punishment_rounding": {
        "kode": "decimal_2",
        "status": "DISETUJUI",
        "dasar": "Presisi maksimal 2 angka desimal numerik tanpa pembulatan integer (ceiling/floor).",
    },
    "complaint_score": {
        "kode": "gte_5_score_5",
        "status": "DISETUJUI",
        "dasar": "Dokumen Rev. 04/PK/JAI/IV/2018 terverifikasi: 0=15, 1-2=12, 3-4=8, >=5=5.",
    },
}
KETENTUAN_PERHITUNGAN_AKTIF = ATURAN_ASUMSI_BISNIS


def score_performance_kebersihan(kondisi: Optional[str], custom_score: Optional[float] = None) -> Optional[float]:
    if custom_score is not None:
        if custom_score in (4.0, 6.0, 8.0, 10.0, 4, 6, 8, 10):
            return float(custom_score)
        return None
    kondisi_clean = (kondisi or "").lower().strip()
    if kondisi_clean in ("bersih", "bersih_rapi"):
        return 10.0
    elif kondisi_clean == "tidak_rapi":
        return 8.0
    elif kondisi_clean == "kotor":
        return 6.0
    elif kondisi_clean == "melanggar":
        return 4.0
    return None


def hitung_grade_total(total_score: Optional[float]) -> tuple[str, str]:
    """
    Grade:
    95-100 = A / Good
    81-94.99 = B / Standard
    66-80.99 = C / Surat teguran dan evaluasi kontrak jika terjadi 2 bulan
    0-65.99 = D / Evaluasi kontrak Catering
    None = - / Belum Lengkap (Kebersihan belum dinilai)
    """
    if total_score is None:
        return "-", "Belum Lengkap (Kebersihan belum dinilai)"
    total_rounded = round(total_score, 2)
    if total_rounded >= 95.0:
        return "A", "Good"
    elif total_rounded >= 81.0:
        return "B", "Standard"
    elif total_rounded >= 66.0:
        return "C", "Surat teguran & evaluasi kontrak jika terjadi 2 bulan"
    else:
        return "D", "Evaluasi kontrak Catering"


# ==============================================================================
# 2. PUNISHMENT RULES (Aturan Aktif 2 Oktober 2025)
# ==============================================================================

def get_punishment_pct_keterlambatan(kategori: str, menit: Optional[int] = None, status: str = "terlambat") -> float:
    if status == "tidak_dikirim":
        return 50.0

    kat = (kategori or "").strip().lower()
    if kat == "1-5" or kat == "1–5":
        return 5.0
    elif kat == "6-10" or kat == "6–10":
        return 10.0
    elif kat == "11-30" or kat == "11–30":
        return 25.0
    elif kat == "31-60" or kat == "31–60":
        return 35.0
    elif kat in (">60", "lebih_dari_60"):
        return 50.0

    # Fallback jika hanya menit yang ada
    if menit is not None:
        if 1 <= menit <= 5:
            return 5.0
        elif 6 <= menit <= 10:
            return 10.0
        elif 11 <= menit <= 30:
            return 25.0
        elif 31 <= menit <= 60:
            return 35.0
        elif menit > 60:
            return 50.0
    return 0.0


def get_punishment_pct_kelayakan(nilai_persen: Optional[float], rule_version: Optional[str] = None) -> float:
    if nilai_persen is None:
        return 0.0
    p = validate_backend_percentage(nilai_persen)
    if is_2024_rule_version(rule_version):
        # Aturan 2024: >=90% = 0%, <90% = 6%
        if p >= 90.0:
            return 0.00
        else:
            return 6.00
    else:
        # Aturan 2025: >90% = 0%, 85-90% = 6% (tepat 85% masuk 6%), 0-84.99% = 10%
        if p > 90.0:
            return 0.00
        elif p >= 85.0:
            return 6.00
        else:
            return 10.00


def get_punishment_pct_audit(nilai_persen: Optional[float], rule_version: Optional[str] = None) -> float:
    if nilai_persen is None:
        return 0.0
    p = validate_backend_percentage(nilai_persen)
    # Aturan Audit: >95% = 0%, 86-95% = 4%, 76-85% = 6%, 66-75% = 8%, 0-65% = 10%
    if p > 95.0:
        return 0.00
    elif p >= 86.0:
        return 4.00
    elif p >= 76.0:
        return 6.00
    elif p >= 66.0:
        return 8.00
    else:
        return 10.00


# ==============================================================================
# 3. AGGREGASI DATA PER KATEGORI UNTUK SATU KATERING
# ==============================================================================

def agregasi_keterlambatan(
    session: Session,
    catering_id: int,
    start_date: date,
    end_date: date,
    order_pagi: float,
    order_malam: float,
    rule_version: str = "2025"
) -> dict:
    records = session.exec(
        select(Keterlambatan)
        .where(
            Keterlambatan.catering_id == catering_id,
            Keterlambatan.tanggal >= start_date,
            Keterlambatan.tanggal <= end_date,
            (Keterlambatan.is_terlambat == True) | (Keterlambatan.status_pengiriman.in_(["terlambat", "tidak_dikirim"]))
        )
        .order_by(Keterlambatan.tanggal.asc())
    ).all()

    jumlah_kejadian = len(records)
    score = score_performance_keterlambatan(jumlah_kejadian)

    rincian = []
    total_punish_pagi = 0.0
    total_punish_malam = 0.0

    lamanya_list = []
    for r in records:
        shift_norm = (r.shift or "pagi").lower().strip()
        base_order = order_pagi if shift_norm == "pagi" else order_malam
        pct = get_punishment_pct_keterlambatan(r.kategori, r.menit_terlambat, r.status_pengiriman)
        rate = pct / 100.0
        punish_amt = round(base_order * rate, 2)

        if shift_norm == "pagi":
            total_punish_pagi += punish_amt
        else:
            total_punish_malam += punish_amt

        lama_str = f"{r.menit_terlambat} menit" if r.menit_terlambat else (r.kategori or r.status_pengiriman)
        lamanya_list.append(lama_str)

        rincian.append({
            "tanggal": r.tanggal.isoformat(),
            "shift": r.shift,
            "status": r.status_pengiriman,
            "menit": r.menit_terlambat,
            "kategori": r.kategori,
            "persentase": pct,
            "base_order": base_order,
            "punishment": punish_amt,
        })

    lamanya_summary = ", ".join(lamanya_list) if lamanya_list else "-"

    return {
        "jumlah_kejadian": jumlah_kejadian,
        "lamanya": lamanya_summary,
        "pengurangan_poin_pagi": round(total_punish_pagi, 2),
        "pengurangan_poin_malam": round(total_punish_malam, 2),
        "score_performance": score,
        "rincian": rincian,
        "has_data": jumlah_kejadian > 0,
    }


def agregasi_kelayakan(
    session: Session,
    catering_id: int,
    start_date: date,
    end_date: date,
    order_pagi: float,
    order_malam: float,
    rule_version: str = "2025",
    shift_basis: Optional[str] = None,
) -> dict:
    entries = session.exec(
        select(KelayakanEntry)
        .where(
            KelayakanEntry.catering_id == catering_id,
            KelayakanEntry.tanggal >= start_date,
            KelayakanEntry.tanggal <= end_date,
        )
    ).all()

    if not entries:
        return {
            "nilai_persen": None,
            "nilai_kelayakan_raw": None,
            "formatted_persen": "-",
            "score_performance": 0.0,
            "persentase_punishment": 0.0,
            "shift_basis": shift_basis or "belum_dikonfirmasi",
            "status_aturan_shift": "Tidak Ada Data",
            "order_pagi": order_pagi,
            "order_malam": order_malam,
            "total_order": round(order_pagi + order_malam, 2),
            "pengurangan_poin_pagi": 0.0,
            "pengurangan_poin_malam": 0.0,
            "punishment_pagi": 0.0,
            "punishment_malam": 0.0,
            "total_punishment": 0.0,
            "has_data": False,
            "total_laporan": 0,
        }

    scores = []
    for e in entries:
        tot = (
            e.nasi_kelayakan + e.nasi_rasa +
            e.lauk_kelayakan + e.lauk_rasa +
            e.sayur_kelayakan + e.sayur_rasa +
            e.sambal_kelayakan + e.sambal_rasa +
            e.kerupuk_kelayakan + e.kerupuk_rasa +
            e.buah_kelayakan + e.buah_rasa +
            e.kondisi_rantang
        )
        scores.append(round(tot / 65.0 * 100.0, 2))

    avg_persen = validate_backend_percentage(round(sum(scores) / len(scores), 2))
    score = score_performance_kelayakan(avg_persen)
    punish_pct = get_punishment_pct_kelayakan(avg_persen, rule_version=rule_version)

    punish_pagi, punish_malam, status_shift = hitung_potongan_shift(
        order_pagi, order_malam, punish_pct, shift_basis
    )

    return {
        "nilai_persen": avg_persen,
        "nilai_kelayakan_raw": avg_persen,
        "formatted_persen": f"{avg_persen:.2f}%",
        "score_performance": score,
        "persentase_punishment": punish_pct,
        "shift_basis": shift_basis or "belum_dikonfirmasi",
        "status_aturan_shift": status_shift,
        "order_pagi": order_pagi,
        "order_malam": order_malam,
        "total_order": round(order_pagi + order_malam, 2),
        "pengurangan_poin_pagi": punish_pagi,
        "pengurangan_poin_malam": punish_malam,
        "punishment_pagi": punish_pagi,
        "punishment_malam": punish_malam,
        "total_punishment": round(punish_pagi + punish_malam, 2),
        "has_data": True,
        "total_laporan": len(entries),
    }


def agregasi_audit(
    session: Session,
    catering_id: int,
    start_date: date,
    end_date: date,
    order_pagi: float,
    order_malam: float,
    rule_version: str = "2025",
    shift_basis: Optional[str] = None,
) -> dict:
    entries = get_audit_entries_compatible(
        session,
        catering_id=catering_id,
        start_date=start_date,
        end_date=end_date,
        status_final_only=True,
    )

    if not entries:
        return {
            "nilai_audit_persen": None,
            "nilai_audit_raw": None,
            "formatted_persen": "-",
            "grade": "-",
            "score_performance": 0.0,
            "persentase_punishment": 0.0,
            "shift_basis": shift_basis or "belum_dikonfirmasi",
            "status_aturan_shift": "Tidak Ada Data",
            "order_pagi": order_pagi,
            "order_malam": order_malam,
            "total_order": round(order_pagi + order_malam, 2),
            "pengurangan_poin_pagi": 0.0,
            "pengurangan_poin_malam": 0.0,
            "punishment_pagi": 0.0,
            "punishment_malam": 0.0,
            "total_punishment": 0.0,
            "has_data": False,
            "total_audit": 0,
        }

    audit_scores = []
    for e in entries:
        calc_res = compute_audit_entry_score(session, e)
        audit_scores.append(calc_res["nilai_audit"])

    avg_persen = validate_backend_percentage(round(sum(audit_scores) / len(audit_scores), 2))

    # Tentukan Grade
    if avg_persen > 95.0:
        grade = "A"
    elif avg_persen > 85.0:
        grade = "B"
    elif avg_persen > 70.0:
        grade = "C"
    else:
        grade = "D"

    score = score_performance_audit(avg_persen)
    punish_pct = get_punishment_pct_audit(avg_persen, rule_version=rule_version)

    punish_pagi, punish_malam, status_shift = hitung_potongan_shift(
        order_pagi, order_malam, punish_pct, shift_basis
    )

    return {
        "nilai_audit_persen": avg_persen,
        "nilai_audit_raw": avg_persen,
        "formatted_persen": f"{avg_persen:.2f}%",
        "grade": grade,
        "score_performance": score,
        "persentase_punishment": punish_pct,
        "shift_basis": shift_basis or "belum_dikonfirmasi",
        "status_aturan_shift": status_shift,
        "order_pagi": order_pagi,
        "order_malam": order_malam,
        "total_order": round(order_pagi + order_malam, 2),
        "pengurangan_poin_pagi": punish_pagi,
        "pengurangan_poin_malam": punish_malam,
        "punishment_pagi": punish_pagi,
        "punishment_malam": punish_malam,
        "total_punishment": round(punish_pagi + punish_malam, 2),
        "has_data": True,
        "total_audit": len(entries),
    }


def agregasi_komplain(
    session: Session,
    catering_id: int,
    start_date: date,
    end_date: date,
    order_pagi: float,
    order_malam: float,
    rule_version: str = "2025"
) -> dict:
    records = session.exec(
        select(KomplainCatering)
        .where(
            KomplainCatering.catering_id == catering_id,
            KomplainCatering.tanggal >= start_date,
            KomplainCatering.tanggal <= end_date,
        )
        .order_by(KomplainCatering.tanggal.asc())
    ).all()

    pagi_count = sum(1 for r in records if (r.shift or "").lower() == "pagi")
    malam_count = sum(1 for r in records if (r.shift or "").lower() == "malam")
    total_count = len(records)

    # 1 komplain = 5% order shift tersebut
    punish_pagi = round(order_pagi * 0.05 * pagi_count, 2)
    punish_malam = round(order_malam * 0.05 * malam_count, 2)

    score = score_performance_komplain(total_count)

    # Keterangan ringkasan
    ket_set = []
    for r in records:
        txt = (r.keterangan or "").strip()
        if txt and txt not in ket_set:
            ket_set.append(txt)
    jenis_summary = ", ".join(ket_set) if ket_set else "-"

    return {
        "jumlah_komplain": total_count,
        "komplain_pagi": pagi_count,
        "komplain_malam": malam_count,
        "jenis_komplain": jenis_summary,
        "pengurangan_poin_pagi": punish_pagi,
        "pengurangan_poin_malam": punish_malam,
        "score_performance": score,
        "has_data": total_count > 0,
    }


def agregasi_kalori(
    session: Session,
    catering_id: int,
    start_date: date,
    end_date: date,
    order_pagi: float,
    order_malam: float,
    rule_version: str = "2025"
) -> dict:
    effective_ver = resolve_rule_version(rule_version)
    cfg = RULE_VERSION_CONFIGS.get(effective_ver, RULE_VERSION_CONFIGS.get(get_active_rule_version(), {}))
    kalori_cfg = cfg.get("kalori", {})

    is_2024 = is_2024_rule_version(effective_ver)
    sidak_active = kalori_cfg.get("sidak_active", True if is_2024 else False)

    # Rates persentase
    rate_kat1 = (kalori_cfg.get("kat1_pct", 1.0 if is_2024 else 3.0)) / 100.0
    rate_kat2 = (kalori_cfg.get("kat2_pct", 5.0 if is_2024 else 7.0)) / 100.0
    rate_kat3 = (kalori_cfg.get("kat3_pct", 3.0 if is_2024 else 5.0)) / 100.0
    rate_kat4 = (kalori_cfg.get("kat4_sidak_pct", 5.0 if is_2024 else 0.0)) / 100.0

    # --------------------------------------------------------------------------
    # Bagian A: Punishment Kalori Minus
    # --------------------------------------------------------------------------
    minus_records = session.exec(
        select(MinusEntry)
        .where(
            MinusEntry.catering_id == catering_id,
            MinusEntry.tanggal >= start_date,
            MinusEntry.tanggal <= end_date,
            MinusEntry.is_active == True,
        )
        .order_by(MinusEntry.tanggal.asc())
    ).all()

    def process_shift_minus(shift_target: str, base_order: float):
        shift_entries = [m for m in minus_records if (m.shift or "Pagi").lower() == shift_target.lower()]
        unique_dates = sorted(list({m.tanggal for m in shift_entries}))

        # Deteksi rangkaian hari berturut-turut
        runs: list[list[date]] = []
        for d in unique_dates:
            if not runs:
                runs.append([d])
            else:
                last_run = runs[-1]
                if d == last_run[-1] + timedelta(days=1):
                    last_run.append(d)
                else:
                    runs.append([d])

        kat1_dates, kat2_dates, kat3_dates, kat4_dates = [], [], [], []
        kat1_count, kat2_count, kat3_count, kat4_count = 0, 0, 0, 0

        for run in runs:
            # Periksa apakah ada sidak
            run_sidak = any(
                "sidak" in ((m.problem or "") + (m.perbaikan or "")).lower()
                for m in shift_entries if m.tanggal in run
            )

            # Sidak hanya aktif di versi 2024. Di versi 2025-10, sidak nonaktif (tidak berlaku)
            if sidak_active and run_sidak and len(run) >= 2:
                kat4_count += 1
                kat4_dates.extend(run)
            elif len(run) == 1:
                kat1_count += 1
                kat1_dates.append(run[0])
            elif len(run) == 2:
                kat2_count += 1
                kat2_dates.extend(run)
            else:
                # 3 atau lebih hari berturut-turut:
                # 2 hari pertama masuk kategori 2
                kat2_count += 1
                kat2_dates.extend(run[:2])
                # Hari ke-3 dan seterusnya masuk kategori 3
                extra_days = len(run) - 2
                kat3_count += extra_days
                kat3_dates.extend(run[2:])

        punish_kat1 = round(base_order * rate_kat1 * kat1_count, 2)
        punish_kat2 = round(base_order * rate_kat2 * kat2_count, 2)
        punish_kat3 = round(base_order * rate_kat3 * kat3_count, 2)
        punish_kat4 = round(base_order * rate_kat4 * kat4_count, 2) if sidak_active else 0.0

        return {
            "kat1": {"count": kat1_count, "dates": kat1_dates, "punish": punish_kat1},
            "kat2": {"count": kat2_count, "dates": kat2_dates, "punish": punish_kat2},
            "kat3": {"count": kat3_count, "dates": kat3_dates, "punish": punish_kat3},
            "kat4": {"count": kat4_count, "dates": kat4_dates, "punish": punish_kat4},
            "total_punish": round(punish_kat1 + punish_kat2 + punish_kat3 + punish_kat4, 2),
            "total_count": len(shift_entries),
        }

    res_pagi = process_shift_minus("Pagi", order_pagi)
    res_malam = process_shift_minus("Malam", order_malam)

    def to_iso_dates(dates_pagi: list[date], dates_malam: list[date]) -> list[str]:
        all_d = sorted(list(set(dates_pagi + dates_malam)))
        return [d.isoformat() for d in all_d]

    def format_date_list(dates_pagi: list[date], dates_malam: list[date]) -> str:
        all_d = sorted(list(set(dates_pagi + dates_malam)))
        if not all_d:
            return ""
        return ", ".join(d.strftime("%d %b %Y") for d in all_d)

    d1_iso = to_iso_dates(res_pagi["kat1"]["dates"], res_malam["kat1"]["dates"])
    d1_disp = format_date_list(res_pagi["kat1"]["dates"], res_malam["kat1"]["dates"])
    kat1_dict = {
        "nama": "TIDAK BERTURUT-TURUT",
        "ada": (res_pagi["kat1"]["count"] + res_malam["kat1"]["count"]) > 0,
        "jumlah": res_pagi["kat1"]["count"] + res_malam["kat1"]["count"],
        "tanggal": d1_iso,
        "tanggal_display": d1_disp,
        "tgl": d1_disp if d1_disp else "-",
        "pengurangan_poin_pagi": res_pagi["kat1"]["punish"],
        "pengurangan_poin_malam": res_malam["kat1"]["punish"],
    }

    d2_iso = to_iso_dates(res_pagi["kat2"]["dates"], res_malam["kat2"]["dates"])
    d2_disp = format_date_list(res_pagi["kat2"]["dates"], res_malam["kat2"]["dates"])
    kat2_dict = {
        "nama": "BERTURUT-TURUT (2x)",
        "ada": (res_pagi["kat2"]["count"] + res_malam["kat2"]["count"]) > 0,
        "jumlah": res_pagi["kat2"]["count"] + res_malam["kat2"]["count"],
        "tanggal": d2_iso,
        "tanggal_display": d2_disp,
        "tgl": d2_disp if d2_disp else "-",
        "pengurangan_poin_pagi": res_pagi["kat2"]["punish"],
        "pengurangan_poin_malam": res_malam["kat2"]["punish"],
    }

    d3_iso = to_iso_dates(res_pagi["kat3"]["dates"], res_malam["kat3"]["dates"])
    d3_disp = format_date_list(res_pagi["kat3"]["dates"], res_malam["kat3"]["dates"])
    kat3_dict = {
        "nama": "SETELAH 2 HARI BERTURUT-TURUT",
        "ada": (res_pagi["kat3"]["count"] + res_malam["kat3"]["count"]) > 0,
        "jumlah": res_pagi["kat3"]["count"] + res_malam["kat3"]["count"],
        "tanggal": d3_iso,
        "tanggal_display": d3_disp,
        "tgl": d3_disp if d3_disp else "-",
        "pengurangan_poin_pagi": res_pagi["kat3"]["punish"],
        "pengurangan_poin_malam": res_malam["kat3"]["punish"],
    }

    d4_iso = to_iso_dates(res_pagi["kat4"]["dates"], res_malam["kat4"]["dates"])
    d4_disp = format_date_list(res_pagi["kat4"]["dates"], res_malam["kat4"]["dates"])
    kat4_dict = {
        "nama": "BERTURUT-TURUT SAAT SIDAK",
        "ada": (res_pagi["kat4"]["count"] + res_malam["kat4"]["count"]) > 0,
        "jumlah": res_pagi["kat4"]["count"] + res_malam["kat4"]["count"],
        "tanggal": d4_iso,
        "tanggal_display": d4_disp,
        "tgl": d4_disp if d4_disp else "-",
        "pengurangan_poin_pagi": res_pagi["kat4"]["punish"],
        "pengurangan_poin_malam": res_malam["kat4"]["punish"],
    }

    pelanggaran_data = {
        "minus_tidak_berturut": kat1_dict,
        "minus_2_berturut": kat2_dict,
        "minus_berikutnya": kat3_dict,
        "minus_sidak": kat4_dict,
        "kategori_1": kat1_dict,
        "kategori_2": kat2_dict,
        "kategori_3": kat3_dict,
        "kategori_4": kat4_dict,
        "total_pengurangan_poin_pagi": res_pagi["total_punish"],
        "total_pengurangan_poin_malam": res_malam["total_punish"],
    }

    # --------------------------------------------------------------------------
    # Bagian B: Pencapaian Kalori (>=900 kkal)
    # --------------------------------------------------------------------------
    kh_entries = session.exec(
        select(KaloriHarian)
        .where(
            KaloriHarian.catering_id == catering_id,
            KaloriHarian.tanggal >= start_date,
            KaloriHarian.tanggal <= end_date,
        )
    ).all()

    mencapai_count = 0
    tidak_mencapai_count = 0

    for kh in kh_entries:
        bahans = session.exec(
            select(KaloriHarianBahan).where(KaloriHarianBahan.kalori_harian_id == kh.id)
        ).all()
        tot_kalori = sum(
            hitung_energi_bahan(b.standar_kalori, b.berat_aktual, getattr(b, "standar_berat", None))
            for b in bahans
        )
        if round(tot_kalori, 2) >= AMBANG_KALORI_MENCAPAI:
            mencapai_count += 1
        else:
            tidak_mencapai_count += 1

    total_pengiriman = mencapai_count + tidak_mencapai_count
    persentase_mencapai = (
        validate_backend_percentage(round(mencapai_count / total_pengiriman * 100.0, 2))
        if total_pengiriman > 0
        else 0.0
    )

    score_kalori = score_performance_kalori(persentase_mencapai if total_pengiriman > 0 else None)

    pencapaian_data = {
        "jumlah_mencapai": mencapai_count,
        "jumlah_tidak_mencapai": tidak_mencapai_count,
        "jumlah_pengiriman": total_pengiriman,
        "persentase": persentase_mencapai,
        "formatted_persen": f"{persentase_mencapai:.2f}%" if total_pengiriman > 0 else "-",
        "score_performance": score_kalori,
        "has_data": total_pengiriman > 0,
    }

    return {
        "pelanggaran": pelanggaran_data,
        "pencapaian": pencapaian_data,
        "pengurangan_poin_pagi": pelanggaran_data["total_pengurangan_poin_pagi"],
        "pengurangan_poin_malam": pelanggaran_data["total_pengurangan_poin_malam"],
        "score_performance": score_kalori,
    }


def agregasi_kebersihan(
    kondisi: Optional[str],
    score_override: Optional[float],
    order_pagi: float,
    order_malam: float,
    rule_version: str = "2025",
    shift_basis: Optional[str] = None,
) -> dict:
    score = score_performance_kebersihan(kondisi, score_override)
    kondisi_clean = (kondisi or "").lower().strip()

    if score is None:
        return {
            "kondisi": "belum_dinilai",
            "kondisi_label": "Belum dinilai",
            "persentase_punishment": 0.0,
            "shift_basis": shift_basis or "belum_dikonfirmasi",
            "status_aturan_shift": "Belum dinilai",
            "order_pagi": order_pagi,
            "order_malam": order_malam,
            "total_order": round(order_pagi + order_malam, 2),
            "pengurangan_poin_pagi": 0.0,
            "pengurangan_poin_malam": 0.0,
            "punishment_pagi": 0.0,
            "punishment_malam": 0.0,
            "total_punishment": 0.0,
            "score_performance": None,
            "is_assessed": False,
        }

    # Aturan Final (Keputusan 4): Kebersihan BUKAN bagian dari domain Punishment.
    # Punishment hanya terdiri dari 5 domain resmi: Terlambat, Kelayakan, Kalori, Komplain, Audit.
    # Sanksi Kebersihan 5% dinonaktifkan dari kalkulasi Punishment baru (0.0%).
    punish_pct = 0.0
    punish_pagi = 0.0
    punish_malam = 0.0
    status_shift = "Tidak Dikenakan Sanksi (Bukan Domain Punishment)"

    kondisi_labels = {
        "bersih": "Bersih dan rapi, tidak melanggar (10)",
        "bersih_rapi": "Bersih dan rapi, tidak melanggar (10)",
        "tidak_rapi": "Tidak rapi, tidak melanggar (8)",
        "kotor": "Kotor, tidak melanggar (6)",
        "melanggar": "Melanggar minimal satu kedisiplinan (4)",
    }

    return {
        "kondisi": kondisi_clean,
        "kondisi_label": kondisi_labels.get(kondisi_clean, kondisi_clean),
        "persentase_punishment": punish_pct,
        "shift_basis": shift_basis or "belum_dikonfirmasi",
        "status_aturan_shift": status_shift,
        "order_pagi": order_pagi,
        "order_malam": order_malam,
        "total_order": round(order_pagi + order_malam, 2),
        "pengurangan_poin_pagi": punish_pagi,
        "pengurangan_poin_malam": punish_malam,
        "punishment_pagi": punish_pagi,
        "punishment_malam": punish_malam,
        "total_punishment": round(punish_pagi + punish_malam, 2),
        "score_performance": score,
        "is_assessed": True,
    }


# ==============================================================================
# 4. MEMBANGUN RINGKASAN LENGKAP (SUMMARY SNAPSHOT)
# ==============================================================================


# ==============================================================================
# Phase 3B: Order / Kuota Catering Integration & Resolver
# ==============================================================================
PHASE_3B_CUTOVER_DATETIME = datetime(2026, 10, 2, 0, 0, 0, tzinfo=timezone.utc)


def is_existing_transitional_draft(report: Optional[Any]) -> bool:
    """
    Menentukan apakah laporan merupakan draft transisional yang dibuat sebelum Phase 3B cutover.
    Kriteria deterministik:
    - report tidak None
    - report.status == 'draft'
    - report.created_at < PHASE_3B_CUTOVER_DATETIME (dinormalisasi secara konsisten)
    - TIDAK ADA ketergantungan pada hardcoded operational ID seperti {3, 4}.
    - TIDAK ADA test-only synthetic escape hatch (is_transitional_draft dihapus).
    """
    if not report:
        return False
    if getattr(report, "status", None) != "draft":
        return False

    created_at = getattr(report, "created_at", None)
    if created_at is not None:
        if isinstance(created_at, str):
            try:
                created_at = datetime.fromisoformat(created_at)
            except Exception:
                created_at = None
        if isinstance(created_at, datetime):
            if created_at.tzinfo is not None:
                c_utc = created_at.astimezone(timezone.utc)
                if c_utc < PHASE_3B_CUTOVER_DATETIME:
                    return True
            else:
                c_naive_cutover = PHASE_3B_CUTOVER_DATETIME.replace(tzinfo=None)
                if created_at < c_naive_cutover:
                    return True
    return False


class SnapshotIntegrityError(ValueError):
    """Raised when a FINAL report snapshot is malformed, missing, or lacks required order/domain data."""
    pass


def normalize_punishment_snapshot(raw_snapshot: Any) -> dict:
    """
    Compatibility adapter to normalize punishment summary_snapshot into a standard structure.
    Accepts:
      A. Canonical production format: {"caterings": [...], ...}
      B. Legacy / test format: {"katering_details": [...], ...}
    Returns:
      {
        "is_valid": bool,
        "schema_type": "canonical" | "legacy" | "unknown",
        "start_date": str,
        "end_date": str,
        "rule_version": str,
        "vendors": {
            catering_id: {
                "catering_id": int,
                "catering_nama": str,
                "order_pagi": float,
                "order_malam": float,
                "total_order": float,
                "order_source": Optional[dict],
                "sanksi_terlambat": {"pagi": float, "malam": float},
                "sanksi_kelayakan": {"pagi": float, "malam": float},
                "sanksi_kalori": {"pagi": float, "malam": float},
                "sanksi_komplain": {"pagi": float, "malam": float},
                "sanksi_audit": {"pagi": float, "malam": float},
                "sanksi_kebersihan": {"pagi": float, "malam": float},
                "total_punishment": {"pagi": float, "malam": float, "total": float},
                "raw_catering": dict,
            }
        },
        "raw": dict
      }
    """
    if not raw_snapshot:
        return {
            "is_valid": False,
            "schema_type": "empty",
            "start_date": None,
            "end_date": None,
            "rule_version": None,
            "vendors": {},
            "raw": {},
        }

    if isinstance(raw_snapshot, str):
        try:
            import json as _json
            payload = _json.loads(raw_snapshot)
        except Exception:
            return {
                "is_valid": False,
                "schema_type": "invalid_json",
                "start_date": None,
                "end_date": None,
                "rule_version": None,
                "vendors": {},
                "raw": {},
            }
    elif isinstance(raw_snapshot, dict):
        payload = raw_snapshot
    else:
        return {
            "is_valid": False,
            "schema_type": "invalid_type",
            "start_date": None,
            "end_date": None,
            "rule_version": None,
            "vendors": {},
            "raw": {},
        }

    vendors = {}
    schema_type = "unknown"

    # 1. Canonical Schema: 'caterings'
    if "caterings" in payload and isinstance(payload["caterings"], list):
        schema_type = "canonical"
        for c in payload["caterings"]:
            if not isinstance(c, dict):
                continue
            cid = c.get("catering_id")
            if cid is None:
                continue

            opagi = float(c.get("order_pagi", c.get("jumlah_order_pagi", 0.0)) or 0.0)
            omalam = float(c.get("order_malam", c.get("jumlah_order_malam", 0.0)) or 0.0)

            d_terl = c.get("keterlambatan") or {}
            d_kely = c.get("menu_kelayakan") or {}
            d_kalo = c.get("jumlah_kalori") or {}
            d_komp = c.get("jumlah_komplain") or {}
            d_audt = c.get("hasil_audit") or {}
            d_kebr = c.get("kebersihan") or {}

            s_terl_pagi = float(d_terl.get("pengurangan_poin_pagi", d_terl.get("punishment_pagi", 0.0)) or 0.0)
            s_terl_malam = float(d_terl.get("pengurangan_poin_malam", d_terl.get("punishment_malam", 0.0)) or 0.0)

            s_kely_pagi = float(d_kely.get("pengurangan_poin_pagi", d_kely.get("punishment_pagi", 0.0)) or 0.0)
            s_kely_malam = float(d_kely.get("pengurangan_poin_malam", d_kely.get("punishment_malam", 0.0)) or 0.0)

            s_kalo_pagi = float(d_kalo.get("pengurangan_poin_pagi", d_kalo.get("punishment_pagi", 0.0)) or 0.0)
            s_kalo_malam = float(d_kalo.get("pengurangan_poin_malam", d_kalo.get("punishment_malam", 0.0)) or 0.0)

            s_komp_pagi = float(d_komp.get("pengurangan_poin_pagi", d_komp.get("punishment_pagi", 0.0)) or 0.0)
            s_komp_malam = float(d_komp.get("pengurangan_poin_malam", d_komp.get("punishment_malam", 0.0)) or 0.0)

            s_audt_pagi = float(d_audt.get("pengurangan_poin_pagi", d_audt.get("punishment_pagi", 0.0)) or 0.0)
            s_audt_malam = float(d_audt.get("pengurangan_poin_malam", d_audt.get("punishment_malam", 0.0)) or 0.0)

            s_kebr_pagi = float(d_kebr.get("pengurangan_poin_pagi", d_kebr.get("punishment_pagi", 0.0)) or 0.0)
            s_kebr_malam = float(d_kebr.get("pengurangan_poin_malam", d_kebr.get("punishment_malam", 0.0)) or 0.0)

            tot_pagi = float(c.get("total_punishment_pagi", c.get("punishment_pagi", s_terl_pagi + s_kely_pagi + s_kalo_pagi + s_komp_pagi + s_audt_pagi)) or 0.0)
            tot_malam = float(c.get("total_punishment_malam", c.get("punishment_malam", s_terl_malam + s_kely_malam + s_kalo_malam + s_komp_malam + s_audt_malam)) or 0.0)

            vendors[cid] = {
                "catering_id": cid,
                "catering_nama": c.get("catering_nama", ""),
                "order_pagi": opagi,
                "order_malam": omalam,
                "total_order": round(opagi + omalam, 2),
                "order_source": c.get("order_source"),
                "sanksi_terlambat": {"pagi": s_terl_pagi, "malam": s_terl_malam},
                "sanksi_kelayakan": {"pagi": s_kely_pagi, "malam": s_kely_malam},
                "sanksi_kalori": {"pagi": s_kalo_pagi, "malam": s_kalo_malam},
                "sanksi_komplain": {"pagi": s_komp_pagi, "malam": s_komp_malam},
                "sanksi_audit": {"pagi": s_audt_pagi, "malam": s_audt_malam},
                "sanksi_kebersihan": {"pagi": s_kebr_pagi, "malam": s_kebr_malam},
                "total_punishment": {
                    "pagi": tot_pagi,
                    "malam": tot_malam,
                    "total": round(tot_pagi + tot_malam, 2),
                },
                "raw_catering": c,
            }

    # 2. Legacy Schema: 'katering_details'
    elif "katering_details" in payload and isinstance(payload["katering_details"], list):
        schema_type = "legacy"
        for kd in payload["katering_details"]:
            if not isinstance(kd, dict):
                continue
            cid = kd.get("catering_id")
            if cid is None:
                continue

            opagi = float(kd.get("order_pagi", kd.get("jumlah_order_pagi", 0.0)) or 0.0)
            omalam = float(kd.get("order_malam", kd.get("jumlah_order_malam", 0.0)) or 0.0)

            d_terl = kd.get("terlambat") or {}
            d_kely = kd.get("kelayakan") or {}
            d_kalo = kd.get("kalori") or {}
            d_komp = kd.get("komplain") or {}
            d_audt = kd.get("audit") or {}
            d_kebr = kd.get("kebersihan") or {}

            s_terl_pagi = float(d_terl.get("punishment_pagi", d_terl.get("pengurangan_poin_pagi", 0.0)) or 0.0)
            s_terl_malam = float(d_terl.get("punishment_malam", d_terl.get("pengurangan_poin_malam", 0.0)) or 0.0)

            s_kely_pagi = float(d_kely.get("punishment_pagi", d_kely.get("pengurangan_poin_pagi", 0.0)) or 0.0)
            s_kely_malam = float(d_kely.get("punishment_malam", d_kely.get("pengurangan_poin_malam", 0.0)) or 0.0)

            s_kalo_pagi = float(d_kalo.get("punishment_pagi", d_kalo.get("pengurangan_poin_pagi", 0.0)) or 0.0)
            s_kalo_malam = float(d_kalo.get("punishment_malam", d_kalo.get("pengurangan_poin_malam", 0.0)) or 0.0)

            s_komp_pagi = float(d_komp.get("pengurangan_poin_pagi", d_komp.get("punishment_pagi", 0.0)) or 0.0)
            s_komp_malam = float(d_komp.get("pengurangan_poin_malam", d_komp.get("punishment_malam", 0.0)) or 0.0)

            s_audt_pagi = float(d_audt.get("punishment_pagi", d_audt.get("pengurangan_poin_pagi", 0.0)) or 0.0)
            s_audt_malam = float(d_audt.get("punishment_malam", d_audt.get("pengurangan_poin_malam", 0.0)) or 0.0)

            s_kebr_pagi = float(d_kebr.get("punishment_pagi", d_kebr.get("pengurangan_poin_pagi", 0.0)) or 0.0)
            s_kebr_malam = float(d_kebr.get("punishment_malam", d_kebr.get("pengurangan_poin_malam", 0.0)) or 0.0)

            tot_pagi = s_terl_pagi + s_kely_pagi + s_kalo_pagi + s_komp_pagi + s_audt_pagi
            tot_malam = s_terl_malam + s_kely_malam + s_kalo_malam + s_komp_malam + s_audt_malam

            vendors[cid] = {
                "catering_id": cid,
                "catering_nama": kd.get("catering_nama", ""),
                "order_pagi": opagi,
                "order_malam": omalam,
                "total_order": round(opagi + omalam, 2),
                "order_source": kd.get("order_source"),
                "sanksi_terlambat": {"pagi": s_terl_pagi, "malam": s_terl_malam},
                "sanksi_kelayakan": {"pagi": s_kely_pagi, "malam": s_kely_malam},
                "sanksi_kalori": {"pagi": s_kalo_pagi, "malam": s_kalo_malam},
                "sanksi_komplain": {"pagi": s_komp_pagi, "malam": s_komp_malam},
                "sanksi_audit": {"pagi": s_audt_pagi, "malam": s_audt_malam},
                "sanksi_kebersihan": {"pagi": s_kebr_pagi, "malam": s_kebr_malam},
                "total_punishment": {
                    "pagi": tot_pagi,
                    "malam": tot_malam,
                    "total": round(tot_pagi + tot_malam, 2),
                },
                "raw_catering": kd,
            }

    is_valid = bool(schema_type in ("canonical", "legacy") and len(vendors) > 0)

    return {
        "is_valid": is_valid,
        "schema_type": schema_type,
        "start_date": payload.get("start_date"),
        "end_date": payload.get("end_date"),
        "rule_version": payload.get("rule_version"),
        "vendors": vendors,
        "raw": payload,
    }


def resolve_punishment_order_source(
    session: Session,
    report: Optional[Any],
    catering_id: int,
    periode_mulai: date,
    periode_selesai: date,
    custom_orders: Optional[dict] = None,
) -> dict:
    """
    Resolver resmi penentu sumber data order untuk perhitungan punishment.

    BUSINESS RULE CHANGE (Authoritative Daily Order Source):
    - Sumber aktif kuantitas order denda punishment adalah TOTAL ORDER HARIAN (catering_daily_order)
      yang diinput melalui alur kerja Kalori.
    - Periode inklusif: tanggal >= periode_mulai AND tanggal <= periode_selesai.
    - Agregasi terpisah shift:
        order_pagi = SUM(jumlah_order) WHERE shift = "Pagi"
        order_malam = SUM(jumlah_order) WHERE shift = "Malam"
    - Missing != Zero:
        Baris ada dengan jumlah_order = 0 -> explicit zero (tercatat 0).
        Tidak ada baris -> data belum diinput / missing.
    - Old catering_order_quota:
        TIDAK digunakan sebagai sumber aktif untuk laporan baru atau recalculate draft.
        Status: COMPATIBILITY / HISTORICAL ONLY.
    - Historical FINAL reports:
        Tetap berbasis snapshot permanen yang tidak dapat diubah (immutable).
    """
    # 1. Historical FINAL reports MUST NOT change
    if report and getattr(report, "status", None) == "final":
        # Jika report sudah final, gunakan data tersimpan dari summary_snapshot atau legacy detail
        if getattr(report, "summary_snapshot", None):
            normalized = normalize_punishment_snapshot(report.summary_snapshot)
            if normalized["is_valid"] and catering_id in normalized["vendors"]:
                v = normalized["vendors"][catering_id]
                ord_src = v.get("order_source")
                if ord_src:
                    res_dict = dict(ord_src)
                    st = res_dict.get("source_status", res_dict.get("status", "final"))
                    res_dict["source_status"] = st
                    res_dict["status"] = st
                    return res_dict
                opagi = v["order_pagi"]
                omalam = v["order_malam"]
                raw_c = v.get("raw_catering", {})
                has_order_key = ("order_pagi" in raw_c or "jumlah_order_pagi" in raw_c or opagi > 0 or omalam > 0)
                if has_order_key and (opagi > 0 or omalam > 0 or raw_c.get("order_source") is not None):
                    return {
                        "source_type": "historical_final_snapshot",
                        "source_id": None,
                        "periode_mulai": periode_mulai.isoformat() if hasattr(periode_mulai, "isoformat") else str(periode_mulai),
                        "periode_selesai": periode_selesai.isoformat() if hasattr(periode_selesai, "isoformat") else str(periode_selesai),
                        "source_status": "final",
                        "status": "final",
                        "order_pagi": opagi,
                        "order_malam": omalam,
                        "total_order": round(opagi + omalam, 2),
                        "total_daily_rows": 0,
                        "pagi_rows": 0,
                        "malam_rows": 0,
                        "explicit_zero_rows": 0,
                        "has_explicit_zero": False,
                        "uncovered_operational_shifts": 0,
                        "is_complete": True,
                        "legacy_fallback_used": False,
                        "has_legacy_mismatch": False,
                        "legacy_mismatch": False,
                        "legacy_order_pagi": opagi,
                        "legacy_order_malam": omalam,
                        "authoritative_order_pagi": opagi,
                        "authoritative_order_malam": omalam,
                        "compatibility_reason": None,
                    }

        if session is not None and getattr(report, "id", None):
            legacy_row = session.exec(
                select(LaporanPunishmentCateringOrder).where(
                    LaporanPunishmentCateringOrder.laporan_id == report.id,
                    LaporanPunishmentCateringOrder.catering_id == catering_id,
                )
            ).first()
            if legacy_row is not None and ((legacy_row.jumlah_order_pagi or 0) > 0 or (legacy_row.jumlah_order_malam or 0) > 0):
                opagi = float(legacy_row.jumlah_order_pagi or 0.0)
                omalam = float(legacy_row.jumlah_order_malam or 0.0)
                return {
                    "source_type": "historical_final_snapshot",
                    "source_id": legacy_row.id,
                    "periode_mulai": periode_mulai.isoformat() if hasattr(periode_mulai, "isoformat") else str(periode_mulai),
                    "periode_selesai": periode_selesai.isoformat() if hasattr(periode_selesai, "isoformat") else str(periode_selesai),
                    "source_status": "final",
                    "status": "final",
                    "order_pagi": opagi,
                    "order_malam": omalam,
                    "total_order": round(opagi + omalam, 2),
                    "total_daily_rows": 0,
                    "pagi_rows": 0,
                    "malam_rows": 0,
                    "explicit_zero_rows": 0,
                    "has_explicit_zero": False,
                    "uncovered_operational_shifts": 0,
                    "is_complete": True,
                    "legacy_fallback_used": False,
                    "has_legacy_mismatch": False,
                    "legacy_mismatch": False,
                    "legacy_order_pagi": opagi,
                    "legacy_order_malam": omalam,
                    "authoritative_order_pagi": opagi,
                    "authoritative_order_malam": omalam,
                    "compatibility_reason": None,
                }

        # MISSING != ZERO: For FINAL reports, do NOT silently fall back to 0.0!
        raise SnapshotIntegrityError(
            f"Snapshot integrity error: Final report ID {getattr(report, 'id', None)} summary_snapshot "
            f"is malformed or missing order data for catering ID {catering_id}. "
            "Cannot silently fall back to zero order."
        )

    # 2. Query Authoritative catering_daily_order (INCLUSIVE date boundaries)
    daily_rows = []
    if session is not None:
        daily_rows = session.exec(
            select(CateringDailyOrder).where(
                CateringDailyOrder.catering_id == catering_id,
                CateringDailyOrder.tanggal >= periode_mulai,
                CateringDailyOrder.tanggal <= periode_selesai,
            )
        ).all()

    # 3. Shift Separation: Pagi and Malam never mix
    pagi_rows = [r for r in daily_rows if (r.shift or "").strip().lower() == "pagi"]
    malam_rows = [r for r in daily_rows if (r.shift or "").strip().lower() == "malam"]

    order_pagi = float(sum(int(r.jumlah_order or 0) for r in pagi_rows))
    order_malam = float(sum(int(r.jumlah_order or 0) for r in malam_rows))
    total_order = round(order_pagi + order_malam, 2)

    total_daily_rows = len(daily_rows)
    pagi_rows_count = len(pagi_rows)
    malam_rows_count = len(malam_rows)

    # 4. Missing vs Explicit Zero:
    # Baris ada dengan jumlah_order == 0 -> explicit zero
    # Tidak ada baris -> missing
    explicit_zero_pagi = sum(1 for r in pagi_rows if r.jumlah_order == 0)
    explicit_zero_malam = sum(1 for r in malam_rows if r.jumlah_order == 0)
    explicit_zero_rows = explicit_zero_pagi + explicit_zero_malam
    has_explicit_zero = explicit_zero_rows > 0

    # 5. Completeness evaluation across operational data (KaloriHarian)
    uncovered_operational_shifts = 0
    if session is not None and total_daily_rows > 0:
        kalori_records = session.exec(
            select(KaloriHarian).where(
                KaloriHarian.catering_id == catering_id,
                KaloriHarian.tanggal >= periode_mulai,
                KaloriHarian.tanggal <= periode_selesai,
            )
        ).all()
        daily_order_keys = {(r.tanggal, (r.shift or "").strip().capitalize()) for r in daily_rows}
        for kh in kalori_records:
            kh_shift = (kh.shift or "").strip().capitalize()
            if (kh.tanggal, kh_shift) not in daily_order_keys:
                uncovered_operational_shifts += 1

    # Status classification
    if total_daily_rows == 0:
        source_status = "missing"
        is_complete = False
        compatibility_reason = "Data jumlah order harian belum diinput di Kalori Harian"
    elif uncovered_operational_shifts > 0:
        source_status = "incomplete"
        is_complete = False
        compatibility_reason = f"Data jumlah order harian belum lengkap ({uncovered_operational_shifts} pengiriman kalori belum memiliki order harian)"
    else:
        source_status = "complete"
        is_complete = True
        compatibility_reason = None

    return {
        "source_type": "daily_order",
        "source_id": None,
        "periode_mulai": periode_mulai.isoformat() if hasattr(periode_mulai, "isoformat") else str(periode_mulai),
        "periode_selesai": periode_selesai.isoformat() if hasattr(periode_selesai, "isoformat") else str(periode_selesai),
        "source_status": source_status,
        "status": source_status,
        "order_pagi": order_pagi,
        "order_malam": order_malam,
        "total_order": total_order,
        "total_daily_rows": total_daily_rows,
        "pagi_rows": pagi_rows_count,
        "malam_rows": malam_rows_count,
        "explicit_zero_rows": explicit_zero_rows,
        "has_explicit_zero": has_explicit_zero,
        "uncovered_operational_shifts": uncovered_operational_shifts,
        "is_complete": is_complete,
        "legacy_fallback_used": False,
        "has_legacy_mismatch": False,
        "legacy_mismatch": False,
        "legacy_order_pagi": None,
        "legacy_order_malam": None,
        "authoritative_order_pagi": order_pagi if is_complete else None,
        "authoritative_order_malam": order_malam if is_complete else None,
        "compatibility_reason": compatibility_reason,
    }



def bangun_laporan_punishment_bulanan(
    session: Session,
    start_date: date,
    end_date: date,
    order_configs: Optional[list[dict]] = None,
    rule_version: Optional[str] = None,
    custom_orders: Optional[dict] = None,
    custom_kebersihan: Optional[dict] = None,
    shift_config: Optional[dict] = None,
    report: Optional[Any] = None,
) -> dict:
    caterings = session.exec(select(Catering).order_by(Catering.id.asc())).all()

    if report is None and session is not None:
        report = session.exec(
            select(LaporanPunishmentBulanan).where(
                LaporanPunishmentBulanan.periode_mulai == start_date,
                LaporanPunishmentBulanan.periode_selesai == end_date,
            )
        ).first()

    active_ver = get_active_rule_version()
    effective_rule_version = resolve_rule_version(rule_version)
    display_rule_version = str(rule_version).strip() if (rule_version is not None and str(rule_version).strip() != "") else effective_rule_version
    r_cfg = copy.deepcopy(get_rule_config(effective_rule_version))
    is_rule_approved = bool(r_cfg.get("status") == "DISETUJUI" and r_cfg.get("is_approved", False) is True)

    shift_config = shift_config or {}
    default_sb = r_cfg.get("shift_basis") if is_rule_approved else None
    sb_kelayakan = shift_config.get("kelayakan") or default_sb
    sb_audit = shift_config.get("audit") or default_sb
    sb_kebersihan = shift_config.get("kebersihan") or default_sb

    is_shift_confirmed = bool(
        is_rule_approved and
        sb_kelayakan in VALID_SHIFT_BASIS and
        sb_audit in VALID_SHIFT_BASIS and
        sb_kebersihan in VALID_SHIFT_BASIS
    )
    status_aturan_shift = "Terkonfirmasi" if is_shift_confirmed else "Butuh Konfirmasi Aturan Shift"

    # Buat map order config
    conf_map = {}
    if order_configs:
        for cfg in order_configs:
            cid = cfg.get("catering_id")
            if cid is not None:
                conf_map[cid] = cfg

    if custom_orders:
        for cid, (opagi, omalam) in custom_orders.items():
            if cid not in conf_map:
                conf_map[cid] = {}
            conf_map[cid]["jumlah_order_pagi"] = opagi
            conf_map[cid]["jumlah_order_malam"] = omalam

    if custom_kebersihan:
        for cid, (kkondisi, kscore) in custom_kebersihan.items():
            if cid not in conf_map:
                conf_map[cid] = {}
            conf_map[cid]["kebersihan_kondisi"] = kkondisi
            conf_map[cid]["kebersihan_score"] = kscore

    laporan_caterings = []

    # Track completeness across data sources
    completeness = {
        "keterlambatan": {"count": 0, "caterings": set(), "status": "Tidak Ada Data"},
        "kelayakan": {"count": 0, "caterings": set(), "status": "Tidak Ada Data"},
        "audit": {"count": 0, "caterings": set(), "status": "Tidak Ada Data"},
        "kalori": {"count": 0, "caterings": set(), "status": "Tidak Ada Data"},
        "komplain": {"count": 0, "caterings": set(), "status": "Tidak Ada Data"},
        "kebersihan": {"count": 0, "caterings": set(), "status": "Tidak Ada Data"},
    }

    resum_rows = []

    for idx, c in enumerate(caterings, start=1):
        cfg = conf_map.get(c.id, {})
        kebersihan_kondisi = cfg.get("kebersihan_kondisi")
        kebersihan_score = cfg.get("kebersihan_score")

        order_info = resolve_punishment_order_source(
            session=session,
            report=report,
            catering_id=c.id,
            periode_mulai=start_date,
            periode_selesai=end_date,
            custom_orders=custom_orders,
        )
        opagi = order_info["order_pagi"]
        omalam = order_info["order_malam"]

        # 1. Keterlambatan
        d_terlambat = agregasi_keterlambatan(session, c.id, start_date, end_date, opagi, omalam, effective_rule_version)
        if d_terlambat["has_data"]:
            completeness["keterlambatan"]["count"] += d_terlambat["jumlah_kejadian"]
            completeness["keterlambatan"]["caterings"].add(c.id)

        # 2. Kelayakan
        d_kelayakan = agregasi_kelayakan(session, c.id, start_date, end_date, opagi, omalam, effective_rule_version, shift_basis=sb_kelayakan)
        if d_kelayakan["has_data"]:
            completeness["kelayakan"]["count"] += d_kelayakan["total_laporan"]
            completeness["kelayakan"]["caterings"].add(c.id)

        # 3. Audit
        d_audit = agregasi_audit(session, c.id, start_date, end_date, opagi, omalam, effective_rule_version, shift_basis=sb_audit)
        if d_audit["has_data"]:
            completeness["audit"]["count"] += d_audit["total_audit"]
            completeness["audit"]["caterings"].add(c.id)

        # 4. Komplain
        d_komplain = agregasi_komplain(session, c.id, start_date, end_date, opagi, omalam, effective_rule_version)
        if d_komplain["has_data"]:
            completeness["komplain"]["count"] += d_komplain["jumlah_komplain"]
            completeness["komplain"]["caterings"].add(c.id)

        # 5. Kalori
        d_kalori = agregasi_kalori(session, c.id, start_date, end_date, opagi, omalam, effective_rule_version)
        if d_kalori["pencapaian"]["has_data"]:
            completeness["kalori"]["count"] += d_kalori["pencapaian"]["jumlah_pengiriman"]
            completeness["kalori"]["caterings"].add(c.id)

        # 6. Kebersihan
        d_kebersihan = agregasi_kebersihan(kebersihan_kondisi, kebersihan_score, opagi, omalam, effective_rule_version, shift_basis=sb_kebersihan)
        if d_kebersihan["is_assessed"]:
            completeness["kebersihan"]["count"] += 1
            completeness["kebersihan"]["caterings"].add(c.id)

        # Total Skor Performance (Max 100) - hanya jika Kebersihan sudah dinilai
        if d_kebersihan["score_performance"] is None:
            total_score = None
            grade_letter = "-"
            grade_desc = "Belum Lengkap (Kebersihan belum dinilai)"
        else:
            total_score = round(
                d_terlambat["score_performance"] +
                d_kelayakan["score_performance"] +
                d_kalori["score_performance"] +
                d_komplain["score_performance"] +
                d_audit["score_performance"] +
                d_kebersihan["score_performance"],
                2
            )
            grade_letter, grade_desc = hitung_grade_total(total_score)

        # Total Punishment (Hanya 5 Domain Resmi: Terlambat, Kelayakan, Kalori, Komplain, Audit)
        total_punish_pagi = round(
            d_terlambat["pengurangan_poin_pagi"] +
            d_kelayakan["pengurangan_poin_pagi"] +
            d_kalori["pengurangan_poin_pagi"] +
            d_komplain["pengurangan_poin_pagi"] +
            d_audit["pengurangan_poin_pagi"],
            2
        )

        total_punish_malam = round(
            d_terlambat["pengurangan_poin_malam"] +
            d_kelayakan["pengurangan_poin_malam"] +
            d_kalori["pengurangan_poin_malam"] +
            d_komplain["pengurangan_poin_malam"] +
            d_audit["pengurangan_poin_malam"],
            2
        )

        # Validasi konsistensi total (5 Domain Resmi)
        sum_details_pagi = round(
            d_terlambat["pengurangan_poin_pagi"] +
            d_kelayakan["pengurangan_poin_pagi"] +
            d_kalori["pengurangan_poin_pagi"] +
            d_komplain["pengurangan_poin_pagi"] +
            d_audit["pengurangan_poin_pagi"],
            2
        )
        assert total_punish_pagi == sum_details_pagi, f"Ketidakkonsistenan total punishment pagi katering {c.nama}"

        sum_details_malam = round(
            d_terlambat["pengurangan_poin_malam"] +
            d_kelayakan["pengurangan_poin_malam"] +
            d_kalori["pengurangan_poin_malam"] +
            d_komplain["pengurangan_poin_malam"] +
            d_audit["pengurangan_poin_malam"],
            2
        )
        assert total_punish_malam == sum_details_malam, f"Ketidakkonsistenan total punishment malam katering {c.nama}"

        catering_snapshot = {
            "no": idx,
            "catering_id": c.id,
            "catering_nama": c.nama,
            "jumlah_order_pagi": opagi,
            "jumlah_order_malam": omalam,
            "order_pagi": opagi,
            "order_malam": omalam,
            "total_order": round(opagi + omalam, 2),
            "order_source": {
                "source_type": order_info.get("source_type", "daily_order"),
                "source_id": order_info.get("source_id"),
                "periode_mulai": start_date.isoformat(),
                "periode_selesai": end_date.isoformat(),
                "status": order_info.get("source_status", order_info.get("status", "complete")),
                "order_pagi": order_info.get("order_pagi", opagi),
                "order_malam": order_info.get("order_malam", omalam),
                "total_order": order_info.get("total_order", round(opagi + omalam, 2)),
                "total_daily_rows": order_info.get("total_daily_rows", 0),
                "explicit_zero_rows": order_info.get("explicit_zero_rows", 0),
                "has_explicit_zero": order_info.get("has_explicit_zero", False),
                "pagi_rows": order_info.get("pagi_rows", 0),
                "malam_rows": order_info.get("malam_rows", 0),
                "uncovered_operational_shifts": order_info.get("uncovered_operational_shifts", 0),
                "legacy_fallback_used": order_info.get("legacy_fallback_used", False),
                "legacy_mismatch": order_info.get("has_legacy_mismatch", order_info.get("legacy_mismatch", False)),
                "is_complete": order_info.get("is_complete", True),
                "compatibility_reason": order_info.get("compatibility_reason"),
            },
            "kebersihan_kondisi": kebersihan_kondisi,
            "keterlambatan": d_terlambat,
            "menu_kelayakan": d_kelayakan,
            "hasil_audit": d_audit,
            "jumlah_kalori": d_kalori,
            "jumlah_komplain": d_komplain,
            "kebersihan": d_kebersihan,
            "total_score_performance": total_score,
            "grade_letter": grade_letter,
            "grade_desc": grade_desc,
            "total_punishment_pagi": total_punish_pagi,
            "total_punishment_malam": total_punish_malam,
            "punishment_pagi": total_punish_pagi,
            "punishment_malam": total_punish_malam,
            "total_punishment": round(total_punish_pagi + total_punish_malam, 2),
        }
        laporan_caterings.append(catering_snapshot)

        # RESUM Row
        resum_rows.append({
            "no": idx,
            "catering_nama": c.nama,
            # 1. Terlambat
            "terlambat_kejadian": d_terlambat["jumlah_kejadian"],
            "terlambat_pagi": d_terlambat["pengurangan_poin_pagi"],
            "terlambat_malam": d_terlambat["pengurangan_poin_malam"],
            "terlambat_score": d_terlambat["score_performance"],
            # 2. Kelayakan
            "kelayakan_persen": d_kelayakan["formatted_persen"],
            "kelayakan_pagi": d_kelayakan["pengurangan_poin_pagi"],
            "kelayakan_malam": d_kelayakan["pengurangan_poin_malam"],
            "kelayakan_score": d_kelayakan["score_performance"],
            # 3. Audit
            "audit_persen": d_audit["formatted_persen"],
            "audit_grade": d_audit["grade"],
            "audit_pagi": d_audit["pengurangan_poin_pagi"],
            "audit_malam": d_audit["pengurangan_poin_malam"],
            "audit_score": d_audit["score_performance"],
            # 4A. Kalori Minus
            "kalori_minus_pagi": d_kalori["pelanggaran"]["total_pengurangan_poin_pagi"],
            "kalori_minus_malam": d_kalori["pelanggaran"]["total_pengurangan_poin_malam"],
            # 4B. Pencapaian Kalori
            "kalori_pencapaian_persen": d_kalori["pencapaian"]["formatted_persen"],
            "kalori_score": d_kalori["score_performance"],
            # 5. Komplain
            "komplain_jumlah": d_komplain["jumlah_komplain"],
            "komplain_pagi": d_komplain["pengurangan_poin_pagi"],
            "komplain_malam": d_komplain["pengurangan_poin_malam"],
            "komplain_score": d_komplain["score_performance"],
            # Kebersihan
            "kebersihan_score": d_kebersihan["score_performance"],
            # Total
            "total_score_performance": total_score,
            "grade_letter": grade_letter,
            "total_punishment_pagi": total_punish_pagi,
            "total_punishment_malam": total_punish_malam,
            "punishment_pagi": total_punish_pagi,
            "punishment_malam": total_punish_malam,
            "total_punishment": round(total_punish_pagi + total_punish_malam, 2),
            "order_pagi": opagi,
            "order_malam": omalam,
            "total_order": round(opagi + omalam, 2),
        })

    # Status kelengkapan sumber data
    total_active_caterings = len(caterings)
    for src_key, info in completeness.items():
        matched_caterings = len(info["caterings"])
        if matched_caterings == total_active_caterings and info["count"] > 0:
            info["status"] = "Tersedia"
        elif matched_caterings > 0:
            info["status"] = "Belum Lengkap"
        else:
            info["status"] = "Tidak Ada Data"
        info["caterings_count"] = matched_caterings
        del info["caterings"]  # remove set for JSON serialization

    all_orders_complete = all(cs.get("order_source", {}).get("is_complete", False) for cs in laporan_caterings)
    incomplete_order_caterings = [
        cs["catering_nama"] for cs in laporan_caterings if not cs.get("order_source", {}).get("is_complete", False)
    ]
    completed_order_count = sum(1 for cs in laporan_caterings if cs.get("order_source", {}).get("is_complete", False))
    completeness["order"] = {
        "count": completed_order_count,
        "total": total_active_caterings,
        "caterings_count": completed_order_count,
        "is_complete": all_orders_complete,
        "status": "Tersedia" if all_orders_complete else ("Belum Lengkap" if completed_order_count > 0 else "Tidak Ada Data"),
    }

    audit_comp = check_audit_completeness(
        session=session,
        start_date=start_date,
        end_date=end_date,
        catering_ids=[c.id for c in caterings],
    )

    return {
        "start_date": start_date.isoformat(),
        "end_date": end_date.isoformat(),
        "rule_version": display_rule_version,
        "active_rule_version": active_ver,
        "rule_version_config": r_cfg,
        "is_rule_approved": is_rule_approved,
        "total_caterings": total_active_caterings,
        "completeness": completeness,
        "shift_config": {
            "kelayakan": sb_kelayakan or "belum_dikonfirmasi",
            "audit": sb_audit or "belum_dikonfirmasi",
            "kebersihan": sb_kebersihan or "belum_dikonfirmasi",
        },
        "status_aturan_shift": status_aturan_shift,
        "is_shift_rules_confirmed": is_shift_confirmed,
        "caterings": laporan_caterings,
        "resum": resum_rows,
        "aturan_asumsi": ATURAN_ASUMSI_BISNIS,
        "ketentuan_aktif": KETENTUAN_PERHITUNGAN_AKTIF,
        "audit_completeness": audit_comp,
        "is_order_complete": all_orders_complete,
        "incomplete_order_caterings": incomplete_order_caterings,
    }
