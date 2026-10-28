<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BahanStandarController;
use App\Http\Controllers\CateringController;
use App\Http\Controllers\CateringDailyOrderController;
use App\Http\Controllers\KaloriHarianController;
use App\Http\Controllers\KebersihanController;
use App\Http\Controllers\KelayakanController;
use App\Http\Controllers\KeterlambatanController;
use App\Http\Controllers\KomplainController;
use App\Http\Controllers\MenuPlanController;
use App\Http\Controllers\MinusController;
use App\Http\Controllers\OperationalLockController;
use App\Http\Controllers\PerformanceMonthlyController;
use App\Http\Controllers\PunishmentMonthlyController;
use App\Http\Controllers\RewardPunishmentController;
use App\Http\Controllers\ScoreRuleController;
use App\Http\Controllers\SendReportController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\StandarBeratController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

$registerCarmenRoutes = function () {
    // Health Check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'app' => 'CARMEN',
            'engine' => 'Laravel 11',
            'database' => 'SQLite dev',
        ]);
    });

    // Public Auth endpoints
    Route::get('/auth/csrf', [AuthController::class, 'csrf']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Public QR Verification endpoint
    Route::get('/approval/signature/verify/{token}', [SignatureController::class, 'verify']);

    // Authenticated Auth endpoints
    Route::middleware('carmen.auth')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Mutating endpoints requiring CSRF
        Route::middleware('carmen.csrf')->group(function () {
            Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        });

        // ---------- Master Data: General Read Endpoints (All authenticated users) ----------
        Route::get('/catering', [CateringController::class, 'index']);
        Route::get('/caterings', [CateringController::class, 'index']);
        Route::get('/catering/{id}', [CateringController::class, 'show'])->whereNumber('id');
        Route::get('/catering/{id}/deletion-impact', [CateringController::class, 'deletionImpact'])->whereNumber('id');

        Route::get('/bahan-standar', [BahanStandarController::class, 'index']);
        Route::get('/standar-berat', [StandarBeratController::class, 'index']);
        Route::get('/jenis-masakan', [StandarBeratController::class, 'jenisMasakan']);
        Route::get('/presets', [StandarBeratController::class, 'presets']);
        Route::get('/score-rules', [ScoreRuleController::class, 'index']);

        // ---------- Stage 3: Operational Read Endpoints ----------
        Route::get('/operational-lock/status', [OperationalLockController::class, 'status']);
        Route::get('/catering-daily-order', [CateringDailyOrderController::class, 'show']);

        Route::get('/kebersihan-kantin/daily', [KebersihanController::class, 'showDaily']);
        Route::get('/kebersihan-daily', [KebersihanController::class, 'showDaily']);
        Route::get('/kebersihan-kantin/daily/list', [KebersihanController::class, 'listDaily']);
        Route::get('/kebersihan-daily/list', [KebersihanController::class, 'listDaily']);
        Route::get('/kebersihan-kantin', [KebersihanController::class, 'listLegacy']);
        Route::get('/kebersihan-kantin/{id}', [KebersihanController::class, 'showLegacy'])->whereNumber('id');
        Route::get('/kebersihan/export-excel', [KebersihanController::class, 'exportExcel']);
        Route::get('/kebersihan-kantin/daily/export-excel', [KebersihanController::class, 'exportExcel']);
        Route::get('/kebersihan-daily/export-excel', [KebersihanController::class, 'exportExcel']);
        Route::get('/kebersihan-kantin/export-excel', [KebersihanController::class, 'exportExcel']);

        Route::get('/kalori-harian', [KaloriHarianController::class, 'index']);
        Route::get('/kalori-harian/export-monthly', [KaloriHarianController::class, 'exportMonthly']);

        Route::get('/minus', [MinusController::class, 'index']);
        Route::get('/minus/export-excel', [MinusController::class, 'exportExcel']);
        Route::get('/minus/{id}', [MinusController::class, 'show'])->whereNumber('id');

        // ---------- Stage 4: Menu Plan & Approval Read Endpoints ----------
        Route::get('/menu-plans', [MenuPlanController::class, 'index']);
        Route::get('/menu-plans/active-menu', [MenuPlanController::class, 'activeMenu']);
        Route::get('/menu-plans/{id}', [MenuPlanController::class, 'show'])->whereNumber('id');
        Route::get('/menu-plans/{id}/export', [MenuPlanController::class, 'export'])->whereNumber('id');

        // ---------- Stage 7: Approval & E-Sign Read Endpoints ----------
        Route::get('/approvals', [ApprovalController::class, 'index']);
        Route::get('/approvals/pending-count', [ApprovalController::class, 'pendingCount']);
        Route::get('/approvals/revision-count', [ApprovalController::class, 'revisionCount']);
        Route::get('/approvals/{id}', [ApprovalController::class, 'show'])->whereNumber('id');
        Route::get('/approvals/{id}/file', [ApprovalController::class, 'downloadFile'])->whereNumber('id');

        Route::get('/send-report/history', [SendReportController::class, 'history']);
        Route::get('/send-report/{id}/file', [SendReportController::class, 'downloadFile'])->whereNumber('id');

        Route::get('/users/me/signature', [SignatureController::class, 'mySignature']);
        Route::get('/users/me/signature/qr-image', [SignatureController::class, 'mySignatureQrImage']);
        Route::get('/me/signature-qr/image', [SignatureController::class, 'mySignatureQrImage']);
        Route::get('/settings/me/signature/qr-image', [SignatureController::class, 'mySignatureQrImage']);

        Route::get('/users/{id}/signature', [SignatureController::class, 'showUserSignature'])->whereNumber('id');
        Route::get('/settings/users/{id}/signature', [SignatureController::class, 'showUserSignature'])->whereNumber('id');
        Route::get('/users/{id}/signature/qr-image', [SignatureController::class, 'showUserSignatureQrImage'])->whereNumber('id');
        Route::get('/settings/users/{id}/signature/qr-image', [SignatureController::class, 'showUserSignatureQrImage'])->whereNumber('id');

        // ---------- Stage 5: Operational Incidents & Audit Read Endpoints ----------
        // Keterlambatan
        Route::get('/keterlambatan', [KeterlambatanController::class, 'index']);
        Route::get('/keterlambatan/summary', [KeterlambatanController::class, 'summary']);
        Route::get('/keterlambatan/export-excel', [KeterlambatanController::class, 'exportExcel']);
        Route::get('/keterlambatan/{id}', [KeterlambatanController::class, 'show'])->whereNumber('id');

        // Kelayakan Menu
        Route::get('/kelayakan', [KelayakanController::class, 'index']);
        Route::get('/kelayakan/export-excel', [KelayakanController::class, 'exportExcel']);

        // Komplain Catering
        Route::get('/komplain', [KomplainController::class, 'index']);
        Route::get('/komplain/source-menu', [KomplainController::class, 'sourceMenu']);
        Route::get('/komplain/summary', [KomplainController::class, 'summary']);
        Route::get('/komplain/export-excel-weekly', [KomplainController::class, 'exportExcelWeekly']);
        Route::get('/komplain/{id}/export-excel', [KomplainController::class, 'exportExcel'])->whereNumber('id');
        Route::get('/komplain/{id}', [KomplainController::class, 'show'])->whereNumber('id');

        // Audit Catering
        Route::get('/audit-kriteria', [AuditController::class, 'getKriteria']);
        Route::get('/audit-entries', [AuditController::class, 'index']);
        Route::get('/audit-entries/{id}', [AuditController::class, 'show'])->whereNumber('id');
        Route::get('/audit-entries/{id}/export-excel', [AuditController::class, 'exportExcel'])->whereNumber('id');
        Route::get('/audit-findings/photos/{photo_id}', [AuditController::class, 'getPhoto'])->whereNumber('photo_id');
        Route::get('/audit-entry', [AuditController::class, 'index']);
        Route::get('/audit-entry/{id}', [AuditController::class, 'show'])->whereNumber('id');
        Route::get('/audit-entry/{id}/export-excel', [AuditController::class, 'exportExcel'])->whereNumber('id');

        // ---------- Stage 6: Monthly Punishment, Performance & Reward-Punishment Read Endpoints ----------
        // Punishment Bulanan
        Route::get('/punishment-monthly/preview', [PunishmentMonthlyController::class, 'preview']);
        Route::get('/punishment-monthly', [PunishmentMonthlyController::class, 'index']);
        Route::get('/punishment-monthly/{id}', [PunishmentMonthlyController::class, 'show'])->whereNumber('id');
        Route::get('/punishment-monthly/{id}/export-excel', [PunishmentMonthlyController::class, 'exportExcel'])->whereNumber('id');

        // Performance Bulanan
        Route::get('/performance/preview', [PerformanceMonthlyController::class, 'preview']);
        Route::get('/performance/preview/export-excel', [PerformanceMonthlyController::class, 'exportPreviewExcel']);
        Route::get('/performance', [PerformanceMonthlyController::class, 'index']);
        Route::get('/performance/{id}', [PerformanceMonthlyController::class, 'show'])->whereNumber('id');
        Route::get('/performance/{id}/export-excel', [PerformanceMonthlyController::class, 'exportExcel'])->whereNumber('id');
        Route::get('/performance/{id}/export-vendor/{catering_id}', [PerformanceMonthlyController::class, 'exportVendorExcel'])->whereNumber('id')->whereNumber('catering_id');

        // Reward & Punishment Periods Read
        Route::get('/reward-punishment/periods', [RewardPunishmentController::class, 'listPeriods']);
        Route::get('/reward-punishment/periods/{period_id}', [RewardPunishmentController::class, 'getPeriodDetail'])->whereNumber('period_id');
        Route::get('/reward-punishment/periods/{period_id}/export-excel', [RewardPunishmentController::class, 'exportExcel'])->whereNumber('period_id');

        // ---------- Master Data: Scoped Settings Read (Admin & Staff) ----------
        Route::middleware('role:admin,staff')->group(function () {
            Route::get('/settings/calorie-standard', [BahanStandarController::class, 'getCalorieStandard']);
            Route::get('/settings/calorie-master', [BahanStandarController::class, 'getCalorieMaster']);
            Route::get('/settings/standar-berat', [StandarBeratController::class, 'getStandarBeratSettings']);
        });

        // ---------- Mutating Operations (CSRF protected) ----------
        Route::middleware('carmen.csrf')->group(function () {
            // Stage 3: Catering Daily Order
            Route::post('/catering-daily-order', [CateringDailyOrderController::class, 'store']);

            // Stage 3: Kebersihan Daily & Legacy
            Route::post('/kebersihan-kantin/daily', [KebersihanController::class, 'upsertDaily']);
            Route::post('/kebersihan-daily', [KebersihanController::class, 'upsertDaily']);
            Route::post('/kebersihan-kantin/daily/{id}/finalize', [KebersihanController::class, 'finalizeDaily'])->whereNumber('id');
            Route::post('/kebersihan-daily/{id}/finalize', [KebersihanController::class, 'finalizeDaily'])->whereNumber('id');
            Route::delete('/kebersihan-kantin/daily/{id}', [KebersihanController::class, 'deleteDaily'])->whereNumber('id');
            Route::delete('/kebersihan-daily/{id}', [KebersihanController::class, 'deleteDaily'])->whereNumber('id');

            Route::post('/kebersihan-kantin', [KebersihanController::class, 'storeLegacy']);
            Route::put('/kebersihan-kantin/{id}', [KebersihanController::class, 'updateLegacy'])->whereNumber('id');
            Route::post('/kebersihan-kantin/{id}/finalize', [KebersihanController::class, 'finalizeLegacy'])->whereNumber('id');
            Route::delete('/kebersihan-kantin/{id}', [KebersihanController::class, 'deleteLegacy'])->whereNumber('id');

            // Stage 3: Kalori Harian
            Route::post('/kalori-harian', [KaloriHarianController::class, 'store']);
            Route::put('/kalori-harian/{id}', [KaloriHarianController::class, 'update'])->whereNumber('id');
            Route::delete('/kalori-harian/{id}', [KaloriHarianController::class, 'destroy'])->whereNumber('id');
            Route::post('/kalori-harian/konfirmasi-mingguan', [KaloriHarianController::class, 'konfirmasiMingguan']);

            // Stage 3: Minus
            Route::post('/minus', [MinusController::class, 'store']);
            Route::post('/minus/export-excel', [MinusController::class, 'exportExcelPost']);
            Route::put('/minus/{id}', [MinusController::class, 'update'])->whereNumber('id');
            Route::patch('/minus/{id}', [MinusController::class, 'update'])->whereNumber('id');
            Route::delete('/minus/{id}', [MinusController::class, 'destroy'])->whereNumber('id');

            // Stage 4: Menu Plan Mutations
            Route::post('/menu-plans', [MenuPlanController::class, 'store']);
            Route::put('/menu-plans/{id}', [MenuPlanController::class, 'updateItems'])->whereNumber('id');
            Route::post('/menu-plans/{id}/submit-approval', [MenuPlanController::class, 'submitApproval'])->whereNumber('id');
            Route::post('/menu-plans/{id}/activate', [MenuPlanController::class, 'activate'])->whereNumber('id');
            Route::delete('/menu-plans/{id}', [MenuPlanController::class, 'destroy'])->whereNumber('id');

            // Stage 7: Approval & E-Sign Mutations
            Route::post('/send-report', [SendReportController::class, 'sendReport']);
            Route::post('/approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id');
            Route::patch('/approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id');
            Route::patch('/approvals/{id}', [ApprovalController::class, 'approve'])->whereNumber('id');
            Route::post('/approvals/{id}/revision-request', [ApprovalController::class, 'revisionRequest'])->whereNumber('id');
            Route::post('/approvals/{id}/revisions', [ApprovalController::class, 'submitRevision'])->whereNumber('id');
            Route::post('/approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id');

            Route::post('/users/me/signature/replace', [SignatureController::class, 'replaceMySignature']);
            Route::post('/me/signature-qr/replace', [SignatureController::class, 'replaceMySignature']);
            Route::post('/settings/me/signature/replace', [SignatureController::class, 'replaceMySignature']);

            Route::post('/users/{id}/signature/reset', [SignatureController::class, 'resetUserSignature'])->whereNumber('id');
            Route::post('/settings/users/{id}/signature/reset', [SignatureController::class, 'resetUserSignature'])->whereNumber('id');

            // Stage 5: Keterlambatan Mutations
            Route::post('/keterlambatan', [KeterlambatanController::class, 'store']);
            Route::put('/keterlambatan/{id}', [KeterlambatanController::class, 'update'])->whereNumber('id');
            Route::delete('/keterlambatan/{id}', [KeterlambatanController::class, 'destroy'])->whereNumber('id');

            // Stage 5: Kelayakan Mutations
            Route::post('/kelayakan', [KelayakanController::class, 'store']);
            Route::delete('/kelayakan/{id}', [KelayakanController::class, 'destroy'])->whereNumber('id');

            // Stage 5: Komplain Mutations
            Route::post('/komplain/upload-foto', [KomplainController::class, 'uploadFoto']);
            Route::post('/komplain', [KomplainController::class, 'store']);
            Route::put('/komplain/{id}', [KomplainController::class, 'update'])->whereNumber('id');
            Route::delete('/komplain/{id}', [KomplainController::class, 'destroy'])->whereNumber('id');

            // Stage 5: Audit Mutations
            Route::post('/audit-entries', [AuditController::class, 'store']);
            Route::put('/audit-entries/{id}', [AuditController::class, 'update'])->whereNumber('id');
            Route::delete('/audit-entries/{id}', [AuditController::class, 'destroy'])->whereNumber('id');
            Route::post('/audit-entries/{id}/findings', [AuditController::class, 'addFinding'])->whereNumber('id');
            Route::delete('/audit-entries/{id}/findings/{finding_id}', [AuditController::class, 'deleteFinding'])->whereNumber('id')->whereNumber('finding_id');
            Route::post('/audit-entries/{id}/finalize', [AuditController::class, 'finalize'])->whereNumber('id');
            Route::post('/audit-entry', [AuditController::class, 'store']);
            Route::delete('/audit-entry/{id}', [AuditController::class, 'destroy'])->whereNumber('id');

            // Stage 6: Monthly Punishment Mutations
            Route::post('/punishment-monthly', [PunishmentMonthlyController::class, 'store']);
            Route::post('/punishment-monthly/{id}/finalize', [PunishmentMonthlyController::class, 'finalize'])->whereNumber('id');
            Route::delete('/punishment-monthly/{id}', [PunishmentMonthlyController::class, 'destroy'])->whereNumber('id');

            // Stage 6: Monthly Performance Mutations
            Route::post('/performance', [PerformanceMonthlyController::class, 'store']);
            Route::put('/performance/{id}', [PerformanceMonthlyController::class, 'update'])->whereNumber('id');
            Route::post('/performance/{id}/finalize', [PerformanceMonthlyController::class, 'finalize'])->whereNumber('id');
            Route::delete('/performance/{id}', [PerformanceMonthlyController::class, 'destroy'])->whereNumber('id');

            // Stage 6: Reward & Punishment Mutations (Admin & Staff)
            Route::middleware('role:admin,staff')->group(function () {
                Route::post('/reward-punishment/periods', [RewardPunishmentController::class, 'createPeriod']);
                Route::put('/reward-punishment/periods/{period_id}', [RewardPunishmentController::class, 'updatePeriod'])->whereNumber('period_id');
                Route::post('/reward-punishment/periods/{period_id}/sync', [RewardPunishmentController::class, 'syncPeriod'])->whereNumber('period_id');
                Route::put('/reward-punishment/periods/{period_id}/items/{item_id}', [RewardPunishmentController::class, 'updateItem'])->whereNumber('period_id')->whereNumber('item_id');
                Route::put('/reward-punishment/periods/{period_id}/shifts/{shift_id}', [RewardPunishmentController::class, 'updateShift'])->whereNumber('period_id')->whereNumber('shift_id');
            });

            // Master Data mutations (Admin & Staff)
            Route::middleware('role:admin,staff')->group(function () {
                // Catering mutations
                Route::post('/catering', [CateringController::class, 'store']);
                Route::patch('/catering/{id}', [CateringController::class, 'update'])->whereNumber('id');
                Route::patch('/catering/{id}/status', [CateringController::class, 'updateStatus'])->whereNumber('id');

                // Bahan Standar mutations
                Route::post('/bahan-standar', [BahanStandarController::class, 'store']);
                Route::put('/bahan-standar/{id}', [BahanStandarController::class, 'update'])->whereNumber('id');
                Route::post('/settings/calorie-standard', [BahanStandarController::class, 'createCalorieStandard']);
                Route::put('/settings/calorie-standard/{id}', [BahanStandarController::class, 'updateCalorieStandard'])->whereNumber('id');
                Route::delete('/settings/calorie-standard/{id}', [BahanStandarController::class, 'deleteCalorieStandard'])->whereNumber('id');
                Route::put('/settings/calorie-master/{id}', [BahanStandarController::class, 'updateCalorieMaster'])->whereNumber('id');

                // Standar Berat mutations
                Route::put('/settings/standar-berat/{id}', [StandarBeratController::class, 'updateStandarBeratSettings'])->whereNumber('id');
                Route::post('/settings/standar-berat/jenis-masakan', [StandarBeratController::class, 'createJenisMasakan']);
                Route::post('/settings/standar-berat/cell', [StandarBeratController::class, 'upsertCell']);
                Route::delete('/settings/standar-berat/cell', [StandarBeratController::class, 'deleteCell']);
                Route::delete('/settings/standar-berat/jenis-masakan/{jenis_masakan}', [StandarBeratController::class, 'deleteJenisMasakan']);
                Route::delete('/settings/standar-berat/{id}', [StandarBeratController::class, 'destroy'])->whereNumber('id');

                // Score Rule mutations
                Route::put('/score-rules/{id}', [ScoreRuleController::class, 'update'])->whereNumber('id');
            });
        });

        // Admin-only routes (Sensitive administrative actions)
        Route::middleware('role:admin')->group(function () {
            // User Management Endpoints
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store'])->middleware('carmen.csrf');
            Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
            Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id')->middleware('carmen.csrf');
            Route::patch('/users/{id}/status', [UserController::class, 'updateStatus'])->whereNumber('id')->middleware('carmen.csrf');
            Route::put('/users/{id}/status', [UserController::class, 'updateStatus'])->whereNumber('id')->middleware('carmen.csrf');
            Route::put('/settings/users/{id}/password', [UserController::class, 'resetPassword'])->whereNumber('id')->middleware('carmen.csrf');
            Route::put('/users/{id}/password', [UserController::class, 'resetPassword'])->whereNumber('id')->middleware('carmen.csrf');

            Route::delete('/catering/{id}', [CateringController::class, 'destroy'])->whereNumber('id')->middleware('carmen.csrf');
            Route::post('/reward-punishment/periods/{id}/finalize', [RewardPunishmentController::class, 'finalize'])->whereNumber('id')->middleware('carmen.csrf');
            Route::post('/menu-plans/{id}/finalize', function ($id) {
                return response()->json([
                    'message' => "Menu plan ID {$id} berhasil difinalisasi.",
                    'status' => 'FINAL'
                ]);
            })->middleware('carmen.csrf');
        });
    });
};

// Register directly at root
$registerCarmenRoutes();

// Also register with prefix /catering-ir/api for Next.js BFF proxy
Route::prefix('catering-ir/api')->group($registerCarmenRoutes);
