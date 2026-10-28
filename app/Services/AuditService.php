<?php

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\AuditEntryComment;
use App\Models\AuditEntryItem;
use App\Models\AuditFinding;
use App\Models\AuditFindingPhoto;
use App\Models\AuditKriteria;
use App\Models\Catering;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuditService
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public static function getAllKriteria()
    {
        return AuditKriteria::orderBy('id', 'asc')->get();
    }

    public static function getDynamicMaxPoints(): array
    {
        $kriteria = self::getAllKriteria();
        $maxA = 0.0;
        $maxB = 0.0;
        foreach ($kriteria as $k) {
            $point = (float) $k->point;
            if (strtoupper($k->sisi ?? '') === 'A') {
                $maxA += $point;
            } elseif (strtoupper($k->sisi ?? '') === 'B') {
                $maxB += $point;
            }
        }
        return [
            'max_a' => round($maxA, 2),
            'max_b' => round($maxB, 2),
            'total_max' => round($maxA + $maxB, 2),
        ];
    }

    public static function getAuditGrade(float $nilaiAudit): string
    {
        if ($nilaiAudit > 95.0) {
            return 'A';
        } elseif ($nilaiAudit > 85.0) {
            return 'B';
        } elseif ($nilaiAudit > 70.0) {
            return 'C';
        } else {
            return 'D';
        }
    }

    public static function scorePerformanceAudit(string $grade): float
    {
        return match (strtoupper($grade)) {
            'A' => 10.0,
            'B' => 8.0,
            'C' => 6.0,
            default => 4.0,
        };
    }

    public function computeScore(AuditEntry $entry): array
    {
        $mode = $entry->assessment_mode ?: 'findings_v1';
        $status = $entry->status ?: 'draft';

        if ($mode === 'findings_v1') {
            if ($status === 'final') {
                $items = AuditEntryItem::where('audit_entry_id', $entry->id)->get();
                $hasSnapshot = $items->contains(fn($it) => $it->point_snapshot !== null);

                if ($hasSnapshot && $items->isNotEmpty()) {
                    $maxA = 0.0;
                    $maxB = 0.0;
                    $nilaiA = 0.0;
                    $nilaiB = 0.0;

                    foreach ($items as $it) {
                        $p = (float) ($it->point_snapshot ?? 0.0);
                        $sisi = strtoupper($it->sisi_snapshot ?? '');
                        if ($sisi === 'A') {
                            $maxA += $p;
                            if ($it->tercentang) {
                                $nilaiA += $p;
                            }
                        } elseif ($sisi === 'B') {
                            $maxB += $p;
                            if ($it->tercentang) {
                                $nilaiB += $p;
                            }
                        }
                    }
                } else {
                    $activeK = self::getAllKriteria()->keyBy('id');
                    $maxA = (float) $activeK->filter(fn($k) => strtoupper($k->sisi) === 'A')->sum('point');
                    $maxB = (float) $activeK->filter(fn($k) => strtoupper($k->sisi) === 'B')->sum('point');
                    $tercentangIds = $items->filter(fn($it) => $it->tercentang)->pluck('audit_kriteria_id')->all();
                    $nilaiA = (float) $activeK->filter(fn($k) => strtoupper($k->sisi) === 'A' && in_array($k->id, $tercentangIds))->sum('point');
                    $nilaiB = (float) $activeK->filter(fn($k) => strtoupper($k->sisi) === 'B' && in_array($k->id, $tercentangIds))->sum('point');
                }

                $penguranganA = $maxA - $nilaiA;
                $penguranganB = $maxB - $nilaiB;
                $pctA = $maxA > 0 ? ($nilaiA / $maxA * 100.0) : 0.0;
                $pctB = $maxB > 0 ? ($nilaiB / $maxB * 100.0) : 0.0;
                $totalMax = $maxA + $maxB;
                $nilaiAudit = $totalMax > 0 ? round(($nilaiA + $nilaiB) / $totalMax * 100.0, 2) : 0.0;

                return [
                    'maksimum_sisi_a' => round($maxA, 2),
                    'maksimum_sisi_b' => round($maxB, 2),
                    'pengurangan_sisi_a' => round($penguranganA, 2),
                    'pengurangan_sisi_b' => round($penguranganB, 2),
                    'nilai_sisi_a' => round($nilaiA, 2),
                    'nilai_sisi_b' => round($nilaiB, 2),
                    'persentase_sisi_a' => round($pctA, 2),
                    'persentase_sisi_b' => round($pctB, 2),
                    'total_poin_maksimum' => round($totalMax, 2),
                    'total_poin_aktual' => round($nilaiA + $nilaiB, 2),
                    'nilai_audit' => round($nilaiAudit, 2),
                ];
            } else {
                // findings_v1 DRAFT
                $activeK = self::getAllKriteria();
                $maxA = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'A')->sum('point');
                $maxB = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'B')->sum('point');

                $findings = AuditFinding::where('audit_entry_id', $entry->id)->get();
                $violatedIds = $findings->pluck('audit_kriteria_id')->unique()->all();

                $penguranganA = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'A' && in_array($k->id, $violatedIds))->sum('point');
                $penguranganB = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'B' && in_array($k->id, $violatedIds))->sum('point');

                $nilaiA = $maxA - $penguranganA;
                $nilaiB = $maxB - $penguranganB;
                $pctA = $maxA > 0 ? ($nilaiA / $maxA * 100.0) : 0.0;
                $pctB = $maxB > 0 ? ($nilaiB / $maxB * 100.0) : 0.0;
                $totalMax = $maxA + $maxB;
                $nilaiAudit = $totalMax > 0 ? round(($nilaiA + $nilaiB) / $totalMax * 100.0, 2) : 0.0;

                return [
                    'maksimum_sisi_a' => round($maxA, 2),
                    'maksimum_sisi_b' => round($maxB, 2),
                    'pengurangan_sisi_a' => round($penguranganA, 2),
                    'pengurangan_sisi_b' => round($penguranganB, 2),
                    'nilai_sisi_a' => round($nilaiA, 2),
                    'nilai_sisi_b' => round($nilaiB, 2),
                    'persentase_sisi_a' => round($pctA, 2),
                    'persentase_sisi_b' => round($pctB, 2),
                    'total_poin_maksimum' => round($totalMax, 2),
                    'total_poin_aktual' => round($nilaiA + $nilaiB, 2),
                    'nilai_audit' => round($nilaiAudit, 2),
                ];
            }
        }

        // Legacy Checklist
        $items = AuditEntryItem::where('audit_entry_id', $entry->id)->get();
        $tercentangIds = $items->filter(fn($it) => $it->tercentang)->pluck('audit_kriteria_id')->all();
        $activeK = self::getAllKriteria();
        $maxA = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'A')->sum('point');
        $maxB = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'B')->sum('point');
        $nilaiA = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'A' && in_array($k->id, $tercentangIds))->sum('point');
        $nilaiB = (float) $activeK->filter(fn($k) => strtoupper($k->sisi ?? '') === 'B' && in_array($k->id, $tercentangIds))->sum('point');
        $penguranganA = $maxA - $nilaiA;
        $penguranganB = $maxB - $nilaiB;
        $pctA = $maxA > 0 ? ($nilaiA / $maxA * 100.0) : 0.0;
        $pctB = $maxB > 0 ? ($nilaiB / $maxB * 100.0) : 0.0;
        $totalMax = $maxA + $maxB;
        $nilaiAudit = $totalMax > 0 ? round(($nilaiA + $nilaiB) / $totalMax * 100.0, 2) : 0.0;

        return [
            'maksimum_sisi_a' => round($maxA, 2),
            'maksimum_sisi_b' => round($maxB, 2),
            'pengurangan_sisi_a' => round($penguranganA, 2),
            'pengurangan_sisi_b' => round($penguranganB, 2),
            'nilai_sisi_a' => round($nilaiA, 2),
            'nilai_sisi_b' => round($nilaiB, 2),
            'persentase_sisi_a' => round($pctA, 2),
            'persentase_sisi_b' => round($pctB, 2),
            'total_poin_maksimum' => round($totalMax, 2),
            'total_poin_aktual' => round($nilaiA + $nilaiB, 2),
            'nilai_audit' => round($nilaiAudit, 2),
        ];
    }

    public function serializeSummary(AuditEntry $entry, $cateringsMap = null): array
    {
        $cateringsMap = $cateringsMap ?: Catering::all()->keyBy('id');
        $scoreRes = $this->computeScore($entry);

        $findings = AuditFinding::where('audit_entry_id', $entry->id)->get();
        $violatedKriteriaCount = $findings->pluck('audit_kriteria_id')->unique()->count();
        $findingIds = $findings->pluck('id')->all();
        $photoCount = !empty($findingIds) ? AuditFindingPhoto::whereIn('finding_id', $findingIds)->count() : 0;

        $catNama = $cateringsMap->get($entry->catering_id)?->nama ?? "Catering #{$entry->catering_id}";
        $tglStr = is_string($entry->tanggal) ? substr($entry->tanggal, 0, 10) : $entry->tanggal->format('Y-m-d');

        return [
            'id' => $entry->id,
            'catering_id' => $entry->catering_id,
            'catering_nama' => $catNama,
            'tanggal' => $tglStr,
            'assessment_mode' => $entry->assessment_mode ?: 'findings_v1',
            'status' => $entry->status ?: 'draft',
            'is_tanpa_temuan' => (bool) $entry->is_tanpa_temuan,
            'catatan_umum' => $entry->catatan_umum,
            'created_by' => $entry->created_by,
            'created_by_name' => $entry->created_by_name_snapshot,
            'finalized_by' => $entry->finalized_by,
            'finalized_by_name' => $entry->finalized_by_name_snapshot,
            'created_at' => $entry->created_at ? $entry->created_at->toISOString() : null,
            'updated_at' => $entry->updated_at ? $entry->updated_at->toISOString() : null,
            'finalized_at' => $entry->finalized_at ? $entry->finalized_at->toISOString() : null,
            'findings_count' => $findings->count(),
            'violated_kriteria_count' => $violatedKriteriaCount,
            'photos_count' => $photoCount,
            'maksimum_sisi_a' => $scoreRes['maksimum_sisi_a'],
            'maksimum_sisi_b' => $scoreRes['maksimum_sisi_b'],
            'pengurangan_sisi_a' => $scoreRes['pengurangan_sisi_a'],
            'pengurangan_sisi_b' => $scoreRes['pengurangan_sisi_b'],
            'nilai_sisi_a' => $scoreRes['nilai_sisi_a'],
            'nilai_sisi_b' => $scoreRes['nilai_sisi_b'],
            'persentase_sisi_a' => $scoreRes['persentase_sisi_a'],
            'persentase_sisi_b' => $scoreRes['persentase_sisi_b'],
            'total_poin_maksimum' => $scoreRes['total_poin_maksimum'],
            'total_poin_aktual' => $scoreRes['total_poin_aktual'],
            'nilai_audit' => $scoreRes['nilai_audit'],
            'total_nilai_a' => $scoreRes['nilai_sisi_a'],
            'total_nilai_b' => $scoreRes['nilai_sisi_b'],
            'nilai_a_pct' => $scoreRes['persentase_sisi_a'],
            'nilai_b_pct' => $scoreRes['persentase_sisi_b'],
            'nilai_final' => $scoreRes['nilai_audit'],
        ];
    }

    public function serializeDetail(AuditEntry $entry, $cateringsMap = null): array
    {
        $data = $this->serializeSummary($entry, $cateringsMap);
        $allKriteriaMap = self::getAllKriteria()->keyBy('id');

        $findings = AuditFinding::where('audit_entry_id', $entry->id)->orderBy('id', 'asc')->get();
        $findingDetails = [];

        foreach ($findings as $f) {
            $k = $allKriteriaMap->get($f->audit_kriteria_id);
            $photos = AuditFindingPhoto::where('finding_id', $f->id)->orderBy('id', 'asc')->get();

            $photoList = [];
            foreach ($photos as $p) {
                $photoList[] = [
                    'id' => $p->id,
                    'relative_path' => $p->relative_path,
                    'original_filename' => $p->original_filename,
                    'stored_filename' => $p->stored_filename,
                    'file_size' => (int) $p->file_size,
                    'mime_type' => $p->mime_type,
                    'created_at' => $p->created_at ? $p->created_at->toISOString() : null,
                ];
            }

            $findingDetails[] = [
                'id' => $f->id,
                'audit_entry_id' => $f->audit_entry_id,
                'audit_kriteria_id' => $f->audit_kriteria_id,
                'keterangan' => $f->keterangan,
                'nama_kriteria' => $f->nama_kriteria_snapshot ?: ($k ? $k->deskripsi : ''),
                'kategori' => $f->kategori_snapshot ?: ($k ? $k->kategori : ''),
                'material' => $f->material_snapshot ?: ($k ? $k->material : null),
                'sisi' => $f->sisi_snapshot ?: ($k ? $k->sisi : ''),
                'point' => $f->point_snapshot !== null ? (float) $f->point_snapshot : ($k ? (float) $k->point : 0.0),
                'created_by' => $f->created_by,
                'created_at' => $f->created_at ? $f->created_at->toISOString() : null,
                'updated_at' => $f->updated_at ? $f->updated_at->toISOString() : null,
                'photos' => $photoList,
            ];
        }

        $data['findings'] = $findingDetails;

        // Snapshot items if final
        $items = AuditEntryItem::where('audit_entry_id', $entry->id)->orderBy('audit_kriteria_id', 'asc')->get();
        $itemList = [];
        foreach ($items as $it) {
            $itemList[] = [
                'id' => $it->id,
                'audit_entry_id' => $it->audit_entry_id,
                'audit_kriteria_id' => $it->audit_kriteria_id,
                'tercentang' => (bool) $it->tercentang,
                'nama_kriteria_snapshot' => $it->nama_kriteria_snapshot,
                'kategori_snapshot' => $it->kategori_snapshot,
                'material_snapshot' => $it->material_snapshot,
                'sisi_snapshot' => $it->sisi_snapshot,
                'point_snapshot' => $it->point_snapshot !== null ? (float) $it->point_snapshot : null,
            ];
        }
        $data['items'] = $itemList;

        return $data;
    }

    public function createEntry(array $data, User $user): array
    {
        $cateringId = (int) ($data['catering_id'] ?? 0);
        $cat = Catering::find($cateringId);
        if (!$cat) {
            throw new HttpException(404, 'Catering tidak ditemukan');
        }
        if (!$cat->is_active) {
            throw new HttpException(400, 'Katering tidak aktif tidak dapat dipilih untuk audit baru');
        }

        $tanggal = substr(trim((string) ($data['tanggal'] ?? '')), 0, 10);
        if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new HttpException(400, 'Format tanggal tidak valid. Gunakan YYYY-MM-DD');
        }

        // Lock check
        $this->lockService->validateOperationalDataNotLocked($cateringId, $tanggal, 'Pembuatan audit baru');

        $now = Carbon::now();
        $catatanUmum = !empty($data['catatan_umum']) ? trim((string) $data['catatan_umum']) : null;

        $entry = AuditEntry::create([
            'catering_id' => $cateringId,
            'tanggal' => $tanggal,
            'assessment_mode' => 'findings_v1',
            'status' => 'draft',
            'is_tanpa_temuan' => false,
            'catatan_umum' => $catatanUmum,
            'created_by' => $user->id,
            'created_by_name_snapshot' => $user->nama,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->serializeDetail($entry);
    }

    public function updateEntry(int $id, array $data, User $user): array
    {
        $entry = AuditEntry::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Audit entry tidak ditemukan');
        }

        if ($entry->assessment_mode === 'legacy_checklist') {
            throw new HttpException(400, 'Audit legacy tidak dapat diedit melalui lifecycle baru.');
        }

        if ($entry->status === 'final') {
            throw new HttpException(400, 'Audit final bersifat immutable dan tidak dapat diubah.');
        }

        if (isset($data['catering_id'])) {
            $cat = Catering::find((int) $data['catering_id']);
            if (!$cat) {
                throw new HttpException(404, 'Catering tidak ditemukan');
            }
            $entry->catering_id = (int) $data['catering_id'];
        }

        if (!empty($data['tanggal'])) {
            $tgl = substr(trim((string) $data['tanggal']), 0, 10);
            $this->lockService->validateOperationalDataNotLocked($entry->catering_id, $tgl, 'Perubahan tanggal audit');
            $entry->tanggal = $tgl;
        }

        if (array_key_exists('catatan_umum', $data)) {
            $entry->catatan_umum = !empty($data['catatan_umum']) ? trim((string) $data['catatan_umum']) : null;
        }

        $entry->updated_at = Carbon::now();
        $entry->save();

        return $this->serializeDetail($entry);
    }

    public function deleteEntry(int $id, User $user): array
    {
        $entry = AuditEntry::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Audit entry tidak ditemukan');
        }

        $tglStr = is_string($entry->tanggal) ? substr($entry->tanggal, 0, 10) : $entry->tanggal->format('Y-m-d');
        $this->lockService->validateOperationalDataNotLocked($entry->catering_id, $tglStr, 'Penghapusan data audit');

        // Clean findings and photos
        $findings = AuditFinding::where('audit_entry_id', $entry->id)->get();
        foreach ($findings as $f) {
            $photos = AuditFindingPhoto::where('finding_id', $f->id)->get();
            foreach ($photos as $p) {
                $filePath = public_path('uploads/' . ltrim($p->relative_path, '/'));
                if (file_exists($filePath) && is_file($filePath)) {
                    @unlink($filePath);
                }
                $p->delete();
            }
            $f->delete();
        }

        AuditEntryItem::where('audit_entry_id', $entry->id)->delete();
        AuditEntryComment::where('audit_entry_id', $entry->id)->delete();
        $entry->delete();

        return ['ok' => true, 'deleted_id' => $id];
    }

    public function addFinding(int $auditId, int $kriteriaId, string $keterangan, array $photos, User $user): array
    {
        $trimmedKet = trim($keterangan);
        if ($trimmedKet === '') {
            throw new HttpException(400, 'Keterangan temuan wajib diisi.');
        }

        if (empty($photos)) {
            throw new HttpException(400, 'Setiap temuan wajib memiliki minimal satu foto bukti.');
        }

        if (count($photos) > 10) {
            throw new HttpException(400, 'Maksimal 10 foto per temuan.');
        }

        $entry = AuditEntry::find($auditId);
        if (!$entry) {
            throw new HttpException(404, 'Audit entry tidak ditemukan');
        }

        if ($entry->assessment_mode !== 'findings_v1') {
            throw new HttpException(400, 'Temuan hanya dapat ditambahkan pada audit findings_v1.');
        }

        if ($entry->status === 'final') {
            throw new HttpException(400, 'Audit final tidak dapat diubah.');
        }

        $kriteria = AuditKriteria::find($kriteriaId);
        if (!$kriteria) {
            throw new HttpException(404, 'Kriteria audit tidak ditemukan');
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $now = Carbon::now();
        $subDir = 'audit/' . $now->format('Y') . '/' . $now->format('m');
        $targetDiskDir = public_path('uploads/' . $subDir);
        if (!is_dir($targetDiskDir)) {
            @mkdir($targetDiskDir, 0755, true);
        }

        $finding = AuditFinding::create([
            'audit_entry_id' => $auditId,
            'audit_kriteria_id' => $kriteriaId,
            'keterangan' => $trimmedKet,
            'nama_kriteria_snapshot' => $kriteria->deskripsi,
            'kategori_snapshot' => $kriteria->kategori,
            'material_snapshot' => $kriteria->material,
            'sisi_snapshot' => $kriteria->sisi,
            'point_snapshot' => $kriteria->point,
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($photos as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, $allowedExts, true)) {
                throw new HttpException(400, "Format file '{$file->getClientOriginalName()}' tidak didukung.");
            }
            $fileSize = $file->getSize();
            if ($fileSize > 5 * 1024 * 1024) {
                throw new HttpException(400, "Ukuran file '{$file->getClientOriginalName()}' melebihi batas maksimal 5MB.");
            }

            $origName = $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType() ?: 'image/jpeg';
            $sha256 = hash_file('sha256', $file->getRealPath());
            $cleanExt = $ext === 'jpeg' ? 'jpg' : $ext;
            $storedFilename = Str::uuid()->toString() . '.' . $cleanExt;
            $relativePath = $subDir . '/' . $storedFilename;

            $file->move($targetDiskDir, $storedFilename);

            AuditFindingPhoto::create([
                'finding_id' => $finding->id,
                'relative_path' => $relativePath,
                'original_filename' => $origName,
                'stored_filename' => $storedFilename,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'sha256' => $sha256,
                'created_at' => $now,
            ]);
        }

        $entry->updated_at = $now;
        $entry->save();

        return $this->serializeDetail($entry);
    }

    public function deleteFinding(int $auditId, int $findingId, User $user): array
    {
        $entry = AuditEntry::find($auditId);
        if (!$entry) {
            throw new HttpException(404, 'Audit entry tidak ditemukan');
        }

        if ($entry->status === 'final') {
            throw new HttpException(400, 'Audit final tidak dapat diubah.');
        }

        $finding = AuditFinding::where('id', $findingId)->where('audit_entry_id', $auditId)->first();
        if (!$finding) {
            throw new HttpException(404, 'Temuan tidak ditemukan');
        }

        $photos = AuditFindingPhoto::where('finding_id', $finding->id)->get();
        foreach ($photos as $p) {
            $filePath = public_path('uploads/' . ltrim($p->relative_path, '/'));
            if (file_exists($filePath) && is_file($filePath)) {
                @unlink($filePath);
            }
            $p->delete();
        }

        $finding->delete();

        $entry->updated_at = Carbon::now();
        $entry->save();

        return $this->serializeDetail($entry);
    }

    public function finalize(int $auditId, bool $confirmNoFindings, User $user): array
    {
        $entry = AuditEntry::find($auditId);
        if (!$entry) {
            throw new HttpException(404, 'Audit entry tidak ditemukan');
        }

        if ($entry->status === 'final') {
            throw new HttpException(400, 'Audit sudah berstatus final dan tidak dapat difinalisasi ulang.');
        }

        if ($entry->assessment_mode !== 'findings_v1') {
            throw new HttpException(400, 'Hanya audit dengan assessment_mode findings_v1 yang dapat difinalisasi melalui alur ini.');
        }

        $findings = AuditFinding::where('audit_entry_id', $entry->id)->get();
        if ($findings->isEmpty()) {
            if (!$confirmNoFindings) {
                throw new HttpException(400, 'Audit tanpa temuan memerlukan konfirmasi eksplisit (confirm_no_findings: true).');
            }
            $entry->is_tanpa_temuan = true;
        } else {
            $entry->is_tanpa_temuan = false;
        }

        // Clean draft items
        AuditEntryItem::where('audit_entry_id', $entry->id)->delete();

        $violatedKriteriaIds = $findings->pluck('audit_kriteria_id')->unique()->all();
        $allKriteria = self::getAllKriteria();

        foreach ($allKriteria as $k) {
            $hasFinding = in_array($k->id, $violatedKriteriaIds, true);
            AuditEntryItem::create([
                'audit_entry_id' => $entry->id,
                'audit_kriteria_id' => $k->id,
                'tercentang' => !$hasFinding,
                'nama_kriteria_snapshot' => $k->deskripsi,
                'kategori_snapshot' => $k->kategori,
                'material_snapshot' => $k->material,
                'sisi_snapshot' => $k->sisi,
                'point_snapshot' => $k->point,
            ]);
        }

        $now = Carbon::now();
        $entry->status = 'final';
        $entry->finalized_by = $user->id;
        $entry->finalized_by_name_snapshot = $user->nama;
        $entry->finalized_at = $now;
        $entry->updated_at = $now;
        $entry->save();

        return $this->serializeDetail($entry);
    }
}
