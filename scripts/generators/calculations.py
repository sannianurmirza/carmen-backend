"""
Semua rumus di sini diambil langsung dari formula yang ada di file Excel
KETERANGAN_PUNISHMENTT_JULI_2026.xlsx, bukan tebakan. Yang masih berupa
asumsi ditandai jelas dengan komentar ASUMSI.
"""
from typing import Iterable
from sqlmodel import Session, select
from models import (
    Periode, Audit, Komplain, KaloriEntry, KaloriPencapaian,
    PengirimanTerlambat, ScoreRule,
)

# Persentase deduksi kalori per kategori.
# Terbukti dari Excel: kategori 1 = 3% (baris normal), kategori 2 = 5%
# (satu-satunya contoh, ENERGI). Kategori 3 & 4 tidak ada contoh datanya
# di file -> ASUMSI disamakan dengan kategori 1 (3%) sampai dikonfirmasi.
KALORI_PERSEN_DEFAULT = {
    1: 0.03,
    2: 0.05,
    3: 0.03,   # ASUMSI, belum ada contoh di file
    4: 0.03,   # ASUMSI, belum ada contoh di file
}

KOMPLAIN_PERSEN = 0.05
AUDIT_GRADE_TABLE = [
    # (batas_bawah, batas_atas, grade)  -- persis dari kolom I/J sheet HASIL AUDIT
    (95, 100, "A"),
    (85, 95, "B"),
    (70, 85, "C"),
    (0, 70, "D"),
]


def hitung_grade_audit(nilai_persen: float) -> str:
    for lo, hi, grade in AUDIT_GRADE_TABLE:
        if lo < nilai_persen <= hi or (lo == 0 and nilai_persen <= hi):
            return grade
    return "D"


def lookup_score(session: Session, kategori: str, **kwargs) -> tuple[float, str]:
    """Cari skor dari tabel ScoreRule. Mengembalikan (score, label_yang_cocok)."""
    rules = session.exec(select(ScoreRule).where(ScoreRule.kategori == kategori)).all()
    if kategori == "audit":
        grade = kwargs["grade"]
        for r in rules:
            if r.grade == grade:
                return r.score, r.label
    elif kategori == "komplain":
        ada_komplain = kwargs["ada_komplain"]
        for r in rules:
            if (r.label == "ada_komplain") == ada_komplain:
                return r.score, r.label
    elif kategori == "kalori_pencapaian":
        persen = kwargs["persen"]
        for r in rules:
            lo = r.batas_bawah if r.batas_bawah is not None else -1e9
            hi = r.batas_atas if r.batas_atas is not None else 1e9
            if lo <= persen <= hi:
                return r.score, r.label
    return 0.0, "tidak_ditemukan"


def hitung_audit(session: Session, periode: Periode, audit: Audit) -> dict:
    grade = hitung_grade_audit(audit.nilai_audit_persen)
    score, _ = lookup_score(session, "audit", grade=grade)
    return {
        "nilai_audit_persen": audit.nilai_audit_persen,
        "grade": grade,
        "score_performance": score,
    }


def hitung_komplain(session: Session, periode: Periode, entries: Iterable[Komplain]) -> dict:
    entries = list(entries)
    pagi = sum(
        periode.jumlah_order_pagi * KOMPLAIN_PERSEN * e.jumlah
        for e in entries if e.shift.lower() == "pagi"
    )
    malam = sum(
        periode.jumlah_order_malam * KOMPLAIN_PERSEN * e.jumlah
        for e in entries if e.shift.lower() == "malam"
    )
    ada_komplain = len(entries) > 0
    score, _ = lookup_score(session, "komplain", ada_komplain=ada_komplain)
    return {
        "jumlah_komplain": sum(e.jumlah for e in entries),
        "pengurangan_poin_pagi": round(pagi, 2),
        "pengurangan_poin_malam": round(malam, 2),
        "score_performance": score,
        "rincian": [
            {"tanggal": str(e.tanggal) if e.tanggal else None, "shift": e.shift,
             "jumlah": e.jumlah, "jenis": e.jenis_komplain}
            for e in entries
        ],
    }


def _persen_kalori(entry: KaloriEntry) -> tuple[float, float]:
    default = KALORI_PERSEN_DEFAULT.get(entry.kategori, 0.03)
    pagi = entry.override_persen_pagi if entry.override_persen_pagi is not None else default
    malam = entry.override_persen_malam if entry.override_persen_malam is not None else default
    return pagi, malam


def hitung_kalori_pelanggaran(periode: Periode, entries: Iterable[KaloriEntry]) -> dict:
    entries = list(entries)
    per_kategori = {}
    total_pagi = 0.0
    total_malam = 0.0
    for kat in (1, 2, 3, 4):
        kat_entries = [e for e in entries if e.kategori == kat]
        pagi_kat = 0.0
        malam_kat = 0.0
        for e in kat_entries:
            persen_pagi, persen_malam = _persen_kalori(e)
            pagi_kat += periode.jumlah_order_pagi * persen_pagi
            malam_kat += periode.jumlah_order_malam * persen_malam
        per_kategori[kat] = {
            "ada": len(kat_entries) > 0,
            "jumlah_kejadian": sum(e.jumlah for e in kat_entries),
            "pengurangan_poin_pagi": round(pagi_kat, 2),
            "pengurangan_poin_malam": round(malam_kat, 2),
        }
        total_pagi += pagi_kat
        total_malam += malam_kat
    return {
        "per_kategori": per_kategori,
        "total_pengurangan_poin_pagi": round(total_pagi, 2),
        "total_pengurangan_poin_malam": round(total_malam, 2),
    }


def hitung_menu_kelayakan(nilai_persen: float) -> dict:
    """
    Aturan skor menu & kelayakan (dikonfirmasi user):
    > 80%        -> score 20
    65% < X <=80 -> score 10
    <= 65%       -> score 5
    """
    if nilai_persen > 80:
        score = 20
    elif nilai_persen > 65:
        score = 10
    else:
        score = 5
    return {"nilai_persen": nilai_persen, "score_performance": score}


def hitung_pengiriman_terlambat(entries: Iterable[PengirimanTerlambat]) -> dict:
    """
    Aturan skor pengiriman terlambat (dikonfirmasi user), diambil dari jumlah kejadian:
    0 kali    -> score 30
    1 kali    -> score 20
    2-3 kali  -> score 10
    >3 kali   -> score 0
    """
    entries = list(entries)
    jumlah_total = sum(e.jumlah for e in entries)
    if jumlah_total == 0:
        score = 30
    elif jumlah_total == 1:
        score = 20
    elif jumlah_total <= 3:
        score = 10
    else:
        score = 0
    return {"jumlah_total": jumlah_total, "score_performance": score}


def hitung_kalori_pencapaian(session: Session, pencapaian: KaloriPencapaian) -> dict:
    mencapai = pencapaian.jumlah_mencapai
    tidak = pencapaian.jumlah_tidak_mencapai
    pengiriman = mencapai + tidak
    persen = (mencapai / pengiriman * 100) if pengiriman > 0 else 0.0
    score, _ = lookup_score(session, "kalori_pencapaian", persen=persen)
    return {
        "jumlah_mencapai": mencapai,
        "jumlah_tidak_mencapai": tidak,
        "jumlah_pengiriman": pengiriman,
        "persentase": round(persen, 2),
        "score_performance": score,
    }


AMBANG_KALORI_MENCAPAI = 900


def hitung_energi_bahan(standar_kalori: float, berat_aktual: float, standar_berat: float = None) -> float:
    b = standar_berat if standar_berat is not None else berat_aktual
    return (standar_kalori / 100.0) * b


def hitung_kalori_harian(bahan_rows) -> dict:
    total = sum(
        hitung_energi_bahan(b.standar_kalori, b.berat_aktual, getattr(b, "standar_berat", None))
        for b in bahan_rows
    )
    total = round(total, 2)
    return {"total_kalori": total, "mencapai": total >= AMBANG_KALORI_MENCAPAI}


def format_indo_float(val: float) -> str:
    """Format angka desimal dengan koma Indonesia (misal 54.2 -> '54,2')."""
    formatted = f"{round(val, 2):.2f}".rstrip('0').rstrip('.')
    return formatted.replace('.', ',')


def hitung_kategori_shortfall(bahan_rows) -> list[dict]:
    """
    Menghitung kategori mana saja yang Total Actual Berat-nya kurang dari Standard Berat.
    """
    cat_map = {}
    for b in bahan_rows:
        cat_key = (getattr(b, "kategori", None) or getattr(b, "item_role", None) or "Lain-lain").strip().lower()
        std = getattr(b, "standar_berat", None) or 0.0
        act = getattr(b, "berat_aktual", None) or 0.0

        if cat_key not in cat_map:
            cat_map[cat_key] = {"kategori": cat_key, "standard_berat": 0.0, "actual_berat": 0.0}

        cat_map[cat_key]["standard_berat"] += std
        cat_map[cat_key]["actual_berat"] += act

    shortfalls = []
    for cat_key, data in cat_map.items():
        if data["standard_berat"] > 0 and data["actual_berat"] < data["standard_berat"]:
            shortfalls.append({
                "kategori": cat_key,
                "standard_berat": round(data["standard_berat"], 2),
                "actual_berat": round(data["actual_berat"], 2),
            })
    return shortfalls


def format_problem_text(shortfall_list: list[dict]) -> str:
    """
    Mengubah daftar shortfall kategori menjadi format narasi Problem persis minus.xlsx.
    """
    if not shortfall_list:
        return ""

    blocks = []
    for s in shortfall_list:
        cat_name = s["kategori"].lower()
        std_str = format_indo_float(s["standard_berat"])
        act_str = format_indo_float(s["actual_berat"])
        block = f"Berat {cat_name} kurang\nStandard berat = {std_str}gr\nActual berat = {act_str}gr"
        blocks.append(block)

    return "\n\n".join(blocks)


def format_perbaikan_text(catering_nama: str) -> str:
    """
    Mengubah nama catering menjadi format narasi Perbaikan berpenomoran persis minus.xlsx.
    """
    nama = catering_nama.strip().capitalize() if catering_nama else "Catering"
    return f"1. Informasi ke catering {nama} bahwa hasil kalori minus agar dilakukan perbaikan"



