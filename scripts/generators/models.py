"""
Model database, dibuat mengikuti struktur nyata dari
KETERANGAN_PUNISHMENTT_JULI_2026.xlsx (sheet: HASIL AUDIT, JUMLAH KOMPLIN,
JUMPLAH KALORI, MENU DAN KELAYAAN, PENGIRIMAN TERLAMBAT DATANG, RESUM).
"""
from datetime import date, datetime, timezone
from typing import Optional
from sqlmodel import SQLModel, Field, UniqueConstraint


def get_utc_now() -> datetime:
    return datetime.now(timezone.utc)


class Catering(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    nama: str
    normalized_name: Optional[str] = Field(default=None, index=True)
    warna: str = Field(default="biru")
    color_hex: Optional[str] = Field(default=None)
    bentuk: str = Field(default="circle")
    reward_punishment_group: Optional[str] = Field(default=None, nullable=True)  # "A" | "B" | None
    is_active: bool = Field(default=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class Periode(SQLModel, table=True):
    """Satu periode penilaian untuk satu catering, mis. '20 Juni - 19 Juli 2026'."""
    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id")
    nama_periode: str
    # Jumlah order dipakai bersama oleh audit, komplain, dan kalori
    # (di file aslinya angkanya sama persis di ketiga sheet untuk catering yang sama).
    jumlah_order_pagi: float = 0
    jumlah_order_malam: float = 0


class Audit(SQLModel, table=True):
    """Sheet: HASIL AUDIT"""
    id: Optional[int] = Field(default=None, primary_key=True)
    periode_id: int = Field(foreign_key="periode.id")
    # Disimpan sebagai persentase 0-100 (bukan rasio 0-1 seperti di Excel),
    # supaya lebih gampang dibaca dan diisi lewat form/API.
    nilai_audit_persen: float


class Komplain(SQLModel, table=True):
    """Sheet: JUMLAH KOMPLIN. Satu baris = satu kejadian komplain."""
    id: Optional[int] = Field(default=None, primary_key=True)
    periode_id: int = Field(foreign_key="periode.id")
    tanggal: Optional[date] = None
    jenis_komplain: str
    shift: str  # "Pagi" atau "Malam"
    jumlah: int = 1  # jumlah kejadian dalam entri ini, jadi pengali formula


class KaloriEntry(SQLModel, table=True):
    """Sheet: JUMPLAH KALORI, bagian atas (4 kategori pelanggaran)."""
    id: Optional[int] = Field(default=None, primary_key=True)
    periode_id: int = Field(foreign_key="periode.id")
    # kategori 1: tidak berturut-turut · 2: berturut-turut ·
    # 3: setelah 2 hari berturut-turut (berikutnya) · 4: berturut-turut saat sidak
    kategori: int
    tanggal: Optional[str] = None  # teks bebas, karena di Excel formatnya macam-macam ("30, 3 Juli 2026")
    jumlah: int = 1
    # Override manual kalau perlu menyamai angka lama yang tidak konsisten;
    # kalau None, dihitung otomatis dari jumlah_order x persentase kategori.
    override_persen_pagi: Optional[float] = None
    override_persen_malam: Optional[float] = None


class KaloriPencapaian(SQLModel, table=True):
    """Sheet: JUMPLAH KALORI, bagian bawah (PENCAPAIAN KALORI)."""
    id: Optional[int] = Field(default=None, primary_key=True)
    periode_id: int = Field(foreign_key="periode.id")
    jumlah_mencapai: int = 0
    jumlah_tidak_mencapai: int = 0


class MenuKelayakan(SQLModel, table=True):
    """Sheet: MENU DAN KELAYAAN"""
    id: Optional[int] = Field(default=None, primary_key=True)
    periode_id: int = Field(foreign_key="periode.id")
    nilai_persen: float
    pengurangan_poin: float = 0


class PengirimanTerlambat(SQLModel, table=True):
    """Sheet: PENGIRIMAN TERLAMBAT DATANG"""
    id: Optional[int] = Field(default=None, primary_key=True)
    periode_id: int = Field(foreign_key="periode.id")
    tanggal: Optional[str] = None
    lamanya_menit: float = 0
    jumlah: int = 0
    pengurangan_poin_pagi: float = 0
    pengurangan_poin_malam: float = 0


class ScoreRule(SQLModel, table=True):
    """
    Tabel aturan skor yang bisa diubah lewat API, dipakai untuk kategori
    yang di file Excel belum punya cukup contoh untuk memastikan semua
    tingkatannya (lihat README bagian 'Asumsi yang perlu dikonfirmasi').
    """
    id: Optional[int] = Field(default=None, primary_key=True)
    kategori: str  # "audit" | "komplain" | "kalori_pencapaian"
    label: str  # mis. "A", "0_komplain", ">=90%"
    batas_bawah: Optional[float] = None
    batas_atas: Optional[float] = None
    grade: Optional[str] = None
    score: float


class BahanStandar(SQLModel, table=True):
    """Master data: standar kalori per 100g per bahan makanan dan standar berat master."""
    id: Optional[int] = Field(default=None, primary_key=True)
    nama: str = Field(unique=True)
    standar_kalori: float  # kkal per 100 gram
    standar_berat: Optional[float] = Field(default=None)  # standar berat (gram)
    satuan: Optional[str] = Field(default="gram")
    is_active: Optional[bool] = Field(default=True)
    updated_at: Optional[datetime] = Field(default=None)
    updated_by: Optional[int] = Field(default=None, foreign_key="user.id")


class StandarBerat(SQLModel, table=True):
    """Master data: standar berat (gram) per kombinasi jenis masakan x
    peran bahan dalam satu porsi (mis. 'MIE AYAM' x 'LAUK UTAMA' = 40 gram)."""
    id: Optional[int] = Field(default=None, primary_key=True)
    jenis_masakan: str
    item_role: str
    berat_gram: float
    satuan: Optional[str] = Field(default="gram")
    is_active: Optional[bool] = Field(default=True)
    updated_at: Optional[datetime] = Field(default=None)
    updated_by: Optional[int] = Field(default=None, foreign_key="user.id")


class KaloriHarian(SQLModel, table=True):
    """Header: satu entri = satu catering, satu tanggal, satu shift."""
    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id")
    tanggal: date
    shift: str  # "Pagi" atau "Malam"
    jenis_masakan: Optional[str] = None
    nama_masakan_nasi: Optional[str] = None
    nama_masakan_sayur: Optional[str] = None
    foto_url: Optional[str] = None
    menu_components: Optional[str] = Field(default=None)
    menu_plan_id: Optional[int] = Field(default=None, foreign_key="monthly_menu_plan.id", nullable=True, index=True)
    menu_plan_version: Optional[int] = Field(default=None, nullable=True)


class KaloriHarianBahan(SQLModel, table=True):
    """Baris bahan di dalam satu KaloriHarian."""
    id: Optional[int] = Field(default=None, primary_key=True)
    kalori_harian_id: int = Field(foreign_key="kaloriharian.id")
    kategori: str  # label grup tampilan: "Nasi" | "Sayur" | "Lauk" | "Buah" | "Lain-lain"
    item_role: Optional[str] = None
    nama_bahan: str
    standar_kalori: float   # snapshot dari BahanStandar saat dipilih
    standar_berat: Optional[float] = None  # snapshot dari StandarBerat
    berat_aktual: float
    preset_name: Optional[str] = Field(default=None)  # penanda preset misal "bumbu"


class KelayakanEntry(SQLModel, table=True):
    """Sheet / penelusuran: Form penilaian kelayakan menu/kondisi catering."""
    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id")
    tanggal: date
    shift: str  # "Pagi" atau "Malam"

    nasi_kelayakan: int
    nasi_rasa: int
    lauk_kelayakan: int
    lauk_rasa: int
    sayur_kelayakan: int
    sayur_rasa: int
    sambal_kelayakan: int
    sambal_rasa: int
    kerupuk_kelayakan: int
    kerupuk_rasa: int
    buah_kelayakan: int
    buah_rasa: int
    kondisi_rantang: int


class AuditKriteria(SQLModel, table=True):
    """Master data (read-only, di-seed dari audit_kriteria.json)."""
    id: Optional[int] = Field(default=None, primary_key=True)
    sisi: str  # "A" atau "B"
    kategori: str
    material: Optional[str] = None
    deskripsi: str
    point: float


class AuditEntry(SQLModel, table=True):
    """Header satu pengisian audit: satu catering, satu tanggal."""
    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    tanggal: date
    assessment_mode: str = Field(default="legacy_checklist", index=True)  # "legacy_checklist" | "findings_v1"
    status: str = Field(default="final", index=True)                      # "draft" | "final"
    is_tanpa_temuan: bool = Field(default=False)
    catatan_umum: Optional[str] = Field(default=None, nullable=True)
    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_by_name_snapshot: Optional[str] = Field(default=None, nullable=True)
    finalized_by_name_snapshot: Optional[str] = Field(default=None, nullable=True)
    created_at: datetime = Field(default_factory=get_utc_now)
    updated_at: datetime = Field(default_factory=get_utc_now)
    finalized_at: Optional[datetime] = Field(default=None, nullable=True)


class AuditEntryItem(SQLModel, table=True):
    """Status centang tiap kriteria untuk satu AuditEntry."""
    id: Optional[int] = Field(default=None, primary_key=True)
    audit_entry_id: int = Field(foreign_key="auditentry.id", index=True)
    audit_kriteria_id: int = Field(foreign_key="auditkriteria.id", index=True)
    tercentang: bool = False
    nama_kriteria_snapshot: Optional[str] = Field(default=None, nullable=True)
    kategori_snapshot: Optional[str] = Field(default=None, nullable=True)
    material_snapshot: Optional[str] = Field(default=None, nullable=True)
    sisi_snapshot: Optional[str] = Field(default=None, nullable=True)
    point_snapshot: Optional[float] = Field(default=None, nullable=True)


class AuditFinding(SQLModel, table=True):
    """Temuan ketidaksesuaian kriteria audit."""
    id: Optional[int] = Field(default=None, primary_key=True)
    audit_entry_id: int = Field(foreign_key="auditentry.id", index=True)
    audit_kriteria_id: int = Field(foreign_key="auditkriteria.id", index=True)
    keterangan: str
    nama_kriteria_snapshot: Optional[str] = Field(default=None, nullable=True)
    kategori_snapshot: Optional[str] = Field(default=None, nullable=True)
    material_snapshot: Optional[str] = Field(default=None, nullable=True)
    sisi_snapshot: Optional[str] = Field(default=None, nullable=True)  # "A" | "B"
    point_snapshot: Optional[float] = Field(default=None, nullable=True)
    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_by_name_snapshot: Optional[str] = Field(default=None, nullable=True)
    created_at: datetime = Field(default_factory=get_utc_now)
    updated_at: datetime = Field(default_factory=get_utc_now)


class AuditFindingPhoto(SQLModel, table=True):
    """Foto bukti pendukung temuan audit."""
    id: Optional[int] = Field(default=None, primary_key=True)
    finding_id: int = Field(foreign_key="auditfinding.id", index=True)
    relative_path: str  # misal "audit/2026/09/<uuid>.jpg"
    original_filename: str
    stored_filename: str
    mime_type: str
    file_size: int
    sha256: str
    created_at: datetime = Field(default_factory=get_utc_now)


class AuditEntryComment(SQLModel, table=True):
    """Satu comment per kategori per entri (BUKAN per kriteria)."""
    id: Optional[int] = Field(default=None, primary_key=True)
    audit_entry_id: int = Field(foreign_key="auditentry.id")
    sisi: str       # "A" atau "B"
    kategori: str   # mis. "BAHAN", "PERLENGKAPAN", dst
    comment: Optional[str] = None


class MinusEntry(SQLModel, table=True):
    """Pencatatan Keterangan Kalori Minus."""
    id: Optional[int] = Field(default=None, primary_key=True)
    kalori_harian_id: Optional[int] = Field(default=None, foreign_key="kaloriharian.id", nullable=True)
    catering_id: int = Field(foreign_key="catering.id")
    tanggal: date
    shift: str = Field(default="Pagi")
    menu: str
    foto_url: Optional[str] = None
    hasil_kalori: float
    problem: Optional[str] = Field(default="")
    perbaikan: Optional[str] = Field(default="")
    is_active: bool = Field(default=True)
    status_pengisian: str = Field(default="belum_dilengkapi")


class User(SQLModel, table=True):
    """Model User untuk sistem autentikasi internal PT JAI."""
    id: Optional[int] = Field(default=None, primary_key=True)
    username: str = Field(index=True, unique=True)
    password_hash: str
    nama: str
    email: Optional[str] = Field(default=None, nullable=True, index=True)
    role: str = Field(default="user")  # "admin" atau "user"
    is_active: bool = Field(default=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)
    last_login_at: Optional[datetime] = Field(default=None)


class UserSession(SQLModel, table=True):
    """Session server-side di SQLite untuk autentikasi cookie HttpOnly."""
    id: Optional[int] = Field(default=None, primary_key=True)
    session_token_hash: str = Field(index=True, unique=True)
    user_id: int = Field(foreign_key="user.id", index=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    expires_at: datetime
    last_activity_at: Optional[datetime] = Field(default_factory=get_utc_now)
    revoked_at: Optional[datetime] = Field(default=None)


class Keterlambatan(SQLModel, table=True):
    """Pencatatan keterlambatan pengiriman makanan katering per tanggal dan shift."""
    __table_args__ = (
        UniqueConstraint("catering_id", "tanggal", "shift", name="uq_keterlambatan_catering_tanggal_shift"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    tanggal: date = Field(index=True)
    shift: str = Field(index=True)  # "pagi" atau "malam"
    is_terlambat: bool = Field(default=False)
    status_pengiriman: str = Field(default="tepat_waktu")  # "tepat_waktu", "terlambat", "tidak_dikirim"
    menit_terlambat: Optional[int] = Field(default=None, nullable=True)
    kategori: Optional[str] = Field(default=None, nullable=True)
    komentar: Optional[str] = Field(default=None, nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class KomplainCatering(SQLModel, table=True):
    """Pencatatan komplain katering per kejadian dengan relasi ke laporan makanan Kalori Harian."""
    __tablename__ = "komplain_catering"

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    tanggal: date = Field(index=True)
    shift: str = Field(index=True)  # "Pagi" atau "Malam"
    source_report_id: int = Field(foreign_key="kaloriharian.id", index=True)
    menu_snapshot: str
    foto_path: Optional[str] = Field(default=None, nullable=True)
    keterangan: str
    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class PunishmentRuleVersion(SQLModel, table=True):
    """Master aturan punishment dan score performance dengan masa berlaku / versioning."""
    __tablename__ = "punishment_rule_version"

    id: Optional[int] = Field(default=None, primary_key=True)
    rule_version: str = Field(index=True)  # "2018", "2024", "2025"
    category: str = Field(index=True)      # "keterlambatan", "kelayakan", "kalori_pencapaian", "kalori_minus", "komplain", "audit", "kebersihan"
    condition_label: str
    min_val: Optional[float] = None
    max_val: Optional[float] = None
    percentage: float = 0.0                # Persentase punishment (0.0 - 1.0, misal 0.05 untuk 5%)
    performance_score: float = 0.0         # Bobot score performance (0 - 30)
    effective_from: Optional[date] = None
    effective_until: Optional[date] = None
    notes: Optional[str] = None


class LaporanPunishmentBulanan(SQLModel, table=True):
    """Header data laporan punishment bulanan gabungan seluruh katering."""
    __tablename__ = "laporan_punishment_bulanan"

    id: Optional[int] = Field(default=None, primary_key=True)
    bulan_laporan: int = Field(index=True)  # 1-12
    tahun_laporan: int = Field(index=True)  # misal 2026
    periode_mulai: date = Field(index=True)
    periode_selesai: date = Field(index=True)
    nama_periode: str                       # misal "20 Juni 2026 - 19 Juli 2026"
    status: str = Field(default="draft", index=True)  # "draft" atau "final"
    rule_version: str = Field(default="2025")
    approved_by: Optional[str] = Field(default="TSU")
    checked_by: Optional[str] = Field(default="NIA")
    prepared_by: Optional[str] = Field(default="IRO")
    summary_snapshot: str                   # JSON snapshot lengkap data kalkulasi dan resum per catering
    total_caterings: int = Field(default=6)
    notes: Optional[str] = Field(default=None, nullable=True)
    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_at: Optional[datetime] = None
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class LaporanPunishmentCateringOrder(SQLModel, table=True):
    """Jumlah order dasar dan status kebersihan katering untuk satu laporan bulanan."""
    __tablename__ = "laporan_punishment_catering_order"

    id: Optional[int] = Field(default=None, primary_key=True)
    laporan_id: int = Field(foreign_key="laporan_punishment_bulanan.id", index=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    jumlah_order_pagi: float = 0.0
    jumlah_order_malam: float = 0.0
    kebersihan_score: Optional[float] = Field(default=None, nullable=True)
    kebersihan_kondisi: str = "belum_dinilai"  # "bersih", "tidak_rapi", "kotor", "melanggar", "belum_dinilai"


class LaporanPerformanceBulanan(SQLModel, table=True):
    """Laporan Performance Catering bulanan gabungan seluruh katering aktif."""
    __tablename__ = "laporan_performance_bulanan"

    id: Optional[int] = Field(default=None, primary_key=True)
    bulan_laporan: int = Field(index=True)  # 1-12
    tahun_laporan: int = Field(index=True)  # misal 2026
    periode_mulai: date = Field(index=True)
    periode_selesai: date = Field(index=True)
    nama_periode: str                       # misal "20 Juli 2026 - 19 Agustus 2026"
    tanggal_dibuat: date
    status: str = Field(default="draft", index=True)  # "draft" atau "final"
    rule_version: str = Field(default="2025_v1")
    summary_snapshot: str                   # JSON snapshot lengkap
    total_caterings: int = Field(default=5)
    notes: Optional[str] = Field(default=None, nullable=True)
    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_at: Optional[datetime] = None
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class LaporanPerformanceItem(SQLModel, table=True):
    """Rincian penilaian komponen performance per katering untuk satu laporan bulanan."""
    __tablename__ = "laporan_performance_item"

    id: Optional[int] = Field(default=None, primary_key=True)
    laporan_id: int = Field(foreign_key="laporan_performance_bulanan.id", index=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    score_terlambat: float = 0.0
    score_kelayakan: Optional[float] = Field(default=None, nullable=True)
    score_kalori: Optional[float] = Field(default=None, nullable=True)
    score_komplain: float = 0.0
    score_audit: Optional[float] = Field(default=None, nullable=True)
    score_kebersihan: Optional[float] = Field(default=None, nullable=True)
    kebersihan_kondisi: str = "menunggu_konfirmasi"
    subtotal_5_komponen: float = 0.0
    total_score: Optional[float] = Field(default=None, nullable=True)
    grade: Optional[str] = Field(default=None, nullable=True)
    action: Optional[str] = Field(default=None, nullable=True)
    score_bulan_lalu: Optional[float] = Field(default=None, nullable=True)
    grade_bulan_lalu: Optional[str] = Field(default=None, nullable=True)
    previous_data_json: str = Field(default="{}")
    raw_data_json: str = "{}"


class KebersihanKantinAssessment(SQLModel, table=True):
    """
    Model Source-of-Truth untuk Penilaian Kebersihan Kantin & Kedisiplinan Katering.
    Menyimpan FAKTA observasi sanitasi dan kepatuhan disiplin per katering per periode.
    TIDAK menyimpan authoritative score, punishment percentage, atau denda pcs.
    """
    __tablename__ = "kebersihan_kantin_assessment"
    __table_args__ = (
        UniqueConstraint("catering_id", "periode_mulai", "periode_selesai", name="uq_kebersihan_catering_periode"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    periode_mulai: date = Field(index=True)
    periode_selesai: date = Field(index=True)

    # Kondisi fisik sanitasi: "bersih", "tidak_rapi", "kotor"
    kondisi_kebersihan: str

    # 3 Item Disiplin Fisik (Rev. 04/PK/JAI/IV/2018)
    pelanggaran_rantang_tidak_langsung_masuk_mobil: bool = Field(default=False)
    pelanggaran_buka_pilah_rantang_sisa_di_jai: bool = Field(default=False)
    pelanggaran_memberi_makan_kucing: bool = Field(default=False)

    catatan: Optional[str] = Field(default=None, nullable=True)
    status: str = Field(default="draft", index=True)  # "draft" atau "final"

    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)
    finalized_at: Optional[datetime] = Field(default=None, nullable=True)

    @property
    def melanggar_disiplin(self) -> bool:
        return bool(
            self.pelanggaran_rantang_tidak_langsung_masuk_mobil or
            self.pelanggaran_buka_pilah_rantang_sisa_di_jai or
            self.pelanggaran_memberi_makan_kucing
        )


class KebersihanKantinDailyAssessment(SQLModel, table=True):
    """
    Model Source-of-Truth baru untuk Penilaian Kebersihan Kantin & Kedisiplinan Harian per Shift.
    Menyimpan FAKTA observasi sanitasi dan kepatuhan disiplin per katering, tanggal, dan shift.
    Identitas operasional unik: (catering_id, tanggal, shift).
    Shift terbatas: "Pagi" atau "Malam".
    """
    __tablename__ = "kebersihan_kantin_daily_assessment"
    __table_args__ = (
        UniqueConstraint("catering_id", "tanggal", "shift", name="uq_daily_kebersihan_catering_tanggal_shift"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    tanggal: date = Field(index=True)
    shift: str = Field(index=True)  # "Pagi" atau "Malam"

    # Kondisi fisik sanitasi: "bersih", "tidak_rapi", "kotor"
    kondisi_kebersihan: str

    # 3 Item Disiplin Fisik (Rev. 04/PK/JAI/IV/2018)
    pelanggaran_rantang_tidak_langsung_masuk_mobil: bool = Field(default=False)
    pelanggaran_buka_pilah_rantang_sisa_di_jai: bool = Field(default=False)
    pelanggaran_memberi_makan_kucing: bool = Field(default=False)

    catatan: Optional[str] = Field(default=None, nullable=True)
    status: str = Field(default="draft", index=True)  # "draft" atau "final"

    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    updated_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    finalized_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)
    finalized_at: Optional[datetime] = Field(default=None, nullable=True)

    @property
    def melanggar_disiplin(self) -> bool:
        return bool(
            self.pelanggaran_rantang_tidak_langsung_masuk_mobil or
            self.pelanggaran_buka_pilah_rantang_sisa_di_jai or
            self.pelanggaran_memberi_makan_kucing
        )


class CateringOrderQuota(SQLModel, table=True):
    """
    Model Source-of-Truth untuk Volume Pesanan / Kuota Katering per Shift per Periode.
    Menyimpan fakta kuota porsi harian/periodik operasional.
    """
    __tablename__ = "catering_order_quota"
    __table_args__ = (
        UniqueConstraint("catering_id", "periode_mulai", "periode_selesai", name="uq_order_catering_periode"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    periode_mulai: date = Field(index=True)
    periode_selesai: date = Field(index=True)

    # Naming tunggal yang jelas
    order_pagi: float = Field(default=0.0)
    order_malam: float = Field(default=0.0)

    status: str = Field(default="draft", index=True)  # "draft" atau "confirmed"
    catatan: Optional[str] = Field(default=None, nullable=True)

    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    updated_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    confirmed_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)
    confirmed_at: Optional[datetime] = Field(default=None, nullable=True)


class CateringDailyOrder(SQLModel, table=True):
    """
    Model Source-of-Truth baru untuk Order Porsi Harian Katering per Shift.
    Menyimpan fakta jumlah order harian (catering_id, tanggal, shift).
    Shift terbatas hanya: "Pagi" atau "Malam".
    """
    __tablename__ = "catering_daily_order"
    __table_args__ = (
        UniqueConstraint("catering_id", "tanggal", "shift", name="uq_daily_order_catering_tanggal_shift"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    tanggal: date = Field(index=True)
    shift: str = Field(index=True)  # "Pagi" atau "Malam"
    jumlah_order: int = Field(default=0)

    created_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    updated_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


def derive_kebersihan_performance_score(
    kondisi_kebersihan: str,
    pelanggaran_flags: object = False
) -> float:
    """
    Pure helper function untuk menghitung proyeksi skor performance kebersihan (skala 10):
    - any discipline violation -> 4.0
    - kondisi "bersih" + no violation -> 10.0
    - kondisi "tidak_rapi" + no violation -> 8.0
    - kondisi "kotor" + no violation -> 6.0
    CATATAN: Fungsi ini murni helper preview/validasi,
    TIDAK disimpan sebagai authoritative DB column dan TIDAK dipanggil dari Performance engine pada Phase 1.
    """
    if isinstance(pelanggaran_flags, (tuple, list)):
        has_violation = any(bool(x) for x in pelanggaran_flags)
    else:
        has_violation = bool(pelanggaran_flags)

    if has_violation:
        return 4.0

    k = (kondisi_kebersihan or "").lower().strip()
    if k == "bersih":
        return 10.0
    elif k == "tidak_rapi":
        return 8.0
    elif k == "kotor":
        return 6.0
    else:
        raise ValueError(
            f"Kondisi kebersihan '{kondisi_kebersihan}' tidak valid. "
            f"Nilai yang didukung: bersih, tidak_rapi, kotor."
        )


class ApprovalRequest(SQLModel, table=True):
    """Model entitas pengajuan approval laporan."""
    __tablename__ = "approval_request"

    id: Optional[int] = Field(default=None, primary_key=True)
    delivery_type: Optional[str] = Field(default="APPROVAL", index=True, nullable=True)  # APPROVAL, REPORT_ONLY
    requester_user_id: int = Field(foreign_key="user.id", index=True)
    requester_name: str
    original_filename: str
    stored_file_path: str  # Jalur file aman relatif di dalam folder uploads/ (contoh: "approvals/abc123_laporan.xlsx")
    file_size_bytes: int = Field(default=0)
    mime_type: str = Field(default="application/octet-stream")
    note: Optional[str] = Field(default=None, nullable=True)
    recipient_emails: Optional[str] = Field(default=None, nullable=True)
    status: str = Field(default="PENDING", index=True)  # PENDING, REVISION_REQUIRED, APPROVED, REJECTED, SENT
    requested_at: datetime = Field(default_factory=get_utc_now, index=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class ApprovalRequestRevision(SQLModel, table=True):
    """Model entitas versi file laporan yang diajukan (auditable file versioning)."""
    __tablename__ = "approval_request_revision"

    id: Optional[int] = Field(default=None, primary_key=True)
    approval_request_id: int = Field(foreign_key="approval_request.id", index=True)
    revision_number: int = Field(default=1, index=True)
    original_filename: str
    stored_file_path: str
    file_size_bytes: int = Field(default=0)
    mime_type: str = Field(default="application/octet-stream")
    submitted_by: int = Field(foreign_key="user.id", index=True)
    submitted_at: datetime = Field(default_factory=get_utc_now, index=True)
    note: Optional[str] = Field(default=None, nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)


class ApprovalRequestApprover(SQLModel, table=True):
    """Model entitas approver per tahapan pengajuan approval (sequential workflow)."""
    __tablename__ = "approval_request_approver"

    id: Optional[int] = Field(default=None, primary_key=True)
    approval_request_id: int = Field(foreign_key="approval_request.id", index=True)
    cycle: int = Field(default=1, index=True)  # 1, 2, 3... Siklus approval baru setelah revisi file
    sequence: int = Field(default=1, index=True)  # 1, 2, 3...
    name: str
    position: str  # Jabatan (SPV, KSL, Manager, dll)
    email: str = Field(index=True)
    matched_user_id: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True, index=True)
    status: str = Field(default="WAITING", index=True)  # WAITING, PENDING, APPROVED, REJECTED, REVISION
    reviewed_at: Optional[datetime] = Field(default=None, nullable=True)
    reviewed_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)
    rejection_reason: Optional[str] = Field(default=None, nullable=True)
    revision_comment: Optional[str] = Field(default=None, nullable=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class ApprovalRequestRecipient(SQLModel, table=True):
    """Model entitas penerima laporan distribusi (REPORT_ONLY recipient list)."""
    __tablename__ = "approval_request_recipient"

    id: Optional[int] = Field(default=None, primary_key=True)
    approval_request_id: int = Field(foreign_key="approval_request.id", index=True)
    sequence: int = Field(default=1, index=True)
    name: str
    position: Optional[str] = Field(default="", nullable=True)  # Jabatan
    email: str = Field(index=True)
    created_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)


class UserSignatureQR(SQLModel, table=True):
    """
    Model Identitas Tanda Tangan QR Permanen Pengguna.
    Masing-masing pengguna (khususnya Approver) memiliki tepat SATU tanda tangan QR aktif secara permanen.
    QR ini digunakan berulang kali pada semua dokumen yang disetujui oleh pengguna tersebut.
    """
    __tablename__ = "user_signature_qr"

    id: Optional[int] = Field(default=None, primary_key=True)
    user_id: int = Field(foreign_key="user.id", index=True)
    signature_token: str = Field(unique=True, index=True)
    qr_image_path: Optional[str] = Field(default=None, nullable=True)
    is_active: bool = Field(default=True, index=True)
    created_at: datetime = Field(default_factory=get_utc_now)
    updated_at: datetime = Field(default_factory=get_utc_now)
    regenerated_at: Optional[datetime] = Field(default=None, nullable=True)
    revoked_at: Optional[datetime] = Field(default=None, nullable=True)
    note: Optional[str] = Field(default=None, nullable=True)


class ApprovalArtifact(SQLModel, table=True):
    """
    Model rantai artefak file bertanda tangan elektronik (immutable artifact chain).
    Menyimpan riwayat setiap tahapan approval file (Original -> APPROVAL_1 -> APPROVAL_2 -> APPROVED).
    """
    __tablename__ = "approval_artifact"

    id: Optional[int] = Field(default=None, primary_key=True)
    approval_request_id: int = Field(foreign_key="approval_request.id", index=True)
    approver_user_id: int = Field(foreign_key="user.id", index=True)
    approver_name_snapshot: str
    approver_position_snapshot: str
    sequence: int = Field(default=1, index=True)
    cycle: int = Field(default=1, index=True)
    revision_number: int = Field(default=1, index=True)
    stage: str = Field(index=True)  # APPROVAL_1, APPROVAL_2, APPROVED
    source_file_path: str
    source_file_sha256: str
    output_file_path: str
    output_file_sha256: str
    status: str = Field(default="READY")  # READY, GENERATION_FAILED
    generation_error: Optional[str] = Field(default=None, nullable=True)
    approved_at: datetime = Field(default_factory=get_utc_now, index=True)
    created_at: datetime = Field(default_factory=get_utc_now)


class Notification(SQLModel, table=True):
    """Model entitas notifikasi in-app untuk pengguna CARMEN."""
    __tablename__ = "notification"

    id: Optional[int] = Field(default=None, primary_key=True)
    recipient_user_id: int = Field(foreign_key="user.id", index=True)
    notification_type: str = Field(index=True)  # APPROVAL_REQUEST, APPROVAL_PASSED, APPROVAL_FINAL, REVISION_REQUIRED, REJECTED, REVISION_RESUBMITTED, REPORT_DISTRIBUTION, SYSTEM
    title: str
    message: str
    related_entity_type: Optional[str] = Field(default=None, nullable=True, index=True)  # "approval_request", dll.
    related_entity_id: Optional[int] = Field(default=None, nullable=True, index=True)
    link_url: Optional[str] = Field(default=None, nullable=True)  # Deep link url, misal: "/approval?tab=todo&id=324"
    event_key: Optional[str] = Field(default=None, nullable=True, index=True, unique=True)  # Idempotency deduplication key
    read_at: Optional[datetime] = Field(default=None, nullable=True, index=True)
    created_at: datetime = Field(default_factory=get_utc_now, index=True)


class SMTPConfig(SQLModel, table=True):
    """
    Model entitas konfigurasi server SMTP tunggal (authoritative single-config).
    Digunakan secara terpusat untuk seluruh pengiriman email notifikasi dan workflow approval.
    """
    __tablename__ = "smtp_config"

    id: Optional[int] = Field(default=None, primary_key=True)
    smtp_host: str = Field(index=True)
    smtp_port: int = Field(default=587)
    smtp_username: Optional[str] = Field(default=None, nullable=True)
    smtp_password_encrypted: Optional[str] = Field(default=None, nullable=True)
    email_sender: str = Field(index=True)
    sender_name: Optional[str] = Field(default="PT JAI Catering Management", nullable=True)
    use_tls: bool = Field(default=True)
    use_ssl: bool = Field(default=False)
    app_url: Optional[str] = Field(default=None, nullable=True)
    is_active: bool = Field(default=True, index=True)
    updated_at: Optional[datetime] = Field(default_factory=get_utc_now)
    updated_by: Optional[int] = Field(default=None, foreign_key="user.id", nullable=True)


class RewardPunishmentPeriod(SQLModel, table=True):
    """
    Model Periode Evaluasi Reward & Punishment Katering Bulanan.
    Menyimpan parameter tanggal cut-off, target alokasi bulan berikutnya, status DRAFT/FINAL, dan catatan operasional.
    """
    __tablename__ = "reward_punishment_period"

    id: Optional[int] = Field(default=None, primary_key=True)
    nama_periode: str = Field(index=True)  # mis. "Agustus 2026"
    tanggal_mulai: date
    tanggal_selesai: date
    target_bulan_berikutnya: str  # mis. "September 2026"
    status: str = Field(default="DRAFT", index=True)  # "DRAFT" | "FINAL"
    catatan: Optional[str] = None
    keterangan: Optional[str] = None
    created_by: Optional[int] = Field(default=None, foreign_key="user.id")
    finalized_by: Optional[int] = Field(default=None, foreign_key="user.id")
    finalized_at: Optional[datetime] = None
    created_at: datetime = Field(default_factory=get_utc_now)
    updated_at: datetime = Field(default_factory=get_utc_now)


class RewardPunishmentShift(SQLModel, table=True):
    """
    Model Header Shift per Grup dan Periode untuk Reward & Punishment.
    Menyimpan total order berjalan grup dan jumlah karyawan shift (misal Siang: 1900, Malam: 1100).
    """
    __tablename__ = "reward_punishment_shift"

    id: Optional[int] = Field(default=None, primary_key=True)
    period_id: int = Field(foreign_key="reward_punishment_period.id", index=True)
    group_code: str = Field(index=True)  # "A" | "B"
    shift: str = Field(index=True)  # "Pagi" | "Malam"
    jumlah_order_total: float = Field(default=0.0)
    jumlah_karyawan: Optional[float] = Field(default=None, nullable=True)


class RewardPunishmentCatering(SQLModel, table=True):
    """
    Model Baris Katering per Periode, Grup, dan Shift.
    Menyimpan snapshot performa, sanksi 6 domain operasional, reward manual,
    dan kalkulasi alokasi order bulan berikutnya.
    Formula terkunci:
      hasil_perhitungan = jumlah_order_berjalan + total_sanksi + reward
      prosentase_order = jumlah_order_berjalan / jumlah_order_total
      prosentase_order_berikutnya = hasil_perhitungan / jumlah_order_total
      perkiraan_order = prosentase_order_berikutnya * jumlah_karyawan (jika jumlah_karyawan diisi)
    """
    __tablename__ = "reward_punishment_catering"

    id: Optional[int] = Field(default=None, primary_key=True)
    period_id: int = Field(foreign_key="reward_punishment_period.id", index=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)
    catering_nama_snapshot: str
    group_code: str = Field(index=True)  # "A" | "B"
    shift: str = Field(index=True)  # "Pagi" | "Malam"

    # Order berjalan & Performa
    jumlah_order_berjalan: float = Field(default=0.0)
    prosentase_order: float = Field(default=0.0)
    performa_katering: Optional[float] = None

    # Sanksi komponen operasional (nilai numerik positif sesuai workbook)
    sanksi_terlambat: float = Field(default=0.0)
    sanksi_kelayakan: float = Field(default=0.0)
    sanksi_kalori: float = Field(default=0.0)
    sanksi_komplain: float = Field(default=0.0)
    sanksi_audit: float = Field(default=0.0)
    sanksi_kebersihan: float = Field(default=0.0)
    total_sanksi: float = Field(default=0.0)

    # Reward & Alokasi
    reward: float = Field(default=0.0)
    hasil_perhitungan: float = Field(default=0.0)
    prosentase_order_berikutnya: float = Field(default=0.0)
    perkiraan_order: Optional[float] = Field(default=None, nullable=True)
    order_minmax: Optional[float] = None
    aktual_order: Optional[float] = None
    catatan: Optional[str] = None


class MonthlyMenuPlan(SQLModel, table=True):
    """
    Header Rencana Menu Bulanan per Katering PT Jatim Autocomp Indonesia.
    Identitas unik terverifikasi: (catering_id, effective_year, effective_month, version).
    """
    __tablename__ = "monthly_menu_plan"
    __table_args__ = (
        UniqueConstraint("catering_id", "effective_year", "effective_month", "version", name="uq_menu_plan_catering_period_version"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    catering_id: int = Field(foreign_key="catering.id", index=True)

    submission_period: str = Field(index=True)      # Contoh: "Juni 2026"
    effective_menu_period: str = Field(index=True) # Contoh: "Juli 2026"
    effective_year: int = Field(index=True)        # 2026
    effective_month: int = Field(index=True)       # 7
    version: int = Field(default=1, index=True)

    # Status: DRAFT, PENDING_APPROVAL, APPROVED, ACTIVE, SUPERSEDED, REJECTED, REVISION_REQUIRED
    status: str = Field(default="DRAFT", index=True)
    is_active: bool = Field(default=False, index=True)
    approval_request_id: Optional[int] = Field(default=None, foreign_key="approval_request.id", nullable=True, index=True)

    catatan: Optional[str] = Field(default=None, nullable=True)
    created_by_user_id: int = Field(foreign_key="user.id", index=True)
    created_at: datetime = Field(default_factory=get_utc_now, index=True)
    updated_at: datetime = Field(default_factory=get_utc_now)
    approved_at: Optional[datetime] = Field(default=None, nullable=True)
    activated_at: Optional[datetime] = Field(default=None, nullable=True)


class MonthlyMenuItem(SQLModel, table=True):
    """
    Item Komponen Menu per Tanggal, Shift, dan Urutan (1..6) per Rencana Menu Katering.
    """
    __tablename__ = "monthly_menu_item"
    __table_args__ = (
        UniqueConstraint("plan_id", "menu_date", "shift", "item_order", name="uq_menu_item_plan_date_shift_order"),
    )

    id: Optional[int] = Field(default=None, primary_key=True)
    plan_id: int = Field(foreign_key="monthly_menu_plan.id", index=True)
    menu_date: date = Field(index=True)
    shift: str = Field(index=True)                 # "Pagi" atau "Malam"
    item_order: int = Field(index=True)            # 1, 2, 3, 4, 5, 6
    item_role: Optional[str] = Field(default=None) # mis. "NASI/MIE", "LAUK UTAMA", "SAYUR", dst.
    menu_name: str                                 # Nama makanan / masakan
    notes: Optional[str] = Field(default=None, nullable=True)



