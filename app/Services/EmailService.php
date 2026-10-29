<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Models\ApprovalRequestRecipient;
use App\Models\SMTPConfig;
use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /**
     * Get active SMTP configuration.
     */
    public function getActiveConfig(): ?SMTPConfig
    {
        $cfg = SMTPConfig::first();
        if ($cfg && $cfg->is_active && !empty($cfg->smtp_host)) {
            return $cfg;
        }
        return null;
    }

    /**
     * Dynamically configures the Laravel Mailer using SMTPConfig.
     */
    public function configureMailer(?SMTPConfig $cfg = null): bool
    {
        $cfg = $cfg ?: $this->getActiveConfig();
        if (!$cfg) {
            return false;
        }

        $password = null;
        if (!empty($cfg->smtp_password_encrypted)) {
            try {
                $password = Crypt::decryptString($cfg->smtp_password_encrypted);
            } catch (\Throwable $e) {
                $password = $cfg->smtp_password_encrypted;
            }
        }

        $scheme = $cfg->use_ssl ? 'smtps' : null;

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', trim($cfg->smtp_host));
        Config::set('mail.mailers.smtp.port', (int) $cfg->smtp_port);
        Config::set('mail.mailers.smtp.scheme', $scheme);
        Config::set('mail.mailers.smtp.username', $cfg->smtp_username ? trim($cfg->smtp_username) : null);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.from.address', $cfg->email_sender ? trim($cfg->email_sender) : 'catering-noreply@company.internal');
        Config::set('mail.from.name', $cfg->sender_name ? trim($cfg->sender_name) : 'PT JAI Catering Management');

        Mail::purge('smtp');

        return true;
    }

    /**
     * Send email for REPORT_ONLY distribution to multiple recipients.
     *
     * @param ApprovalRequest $approvalRequest
     * @param array $recipients List of recipients with name & email
     * @param array $filePaths List of file paths to attach
     * @param string $subject
     * @param string $notes
     * @param User $senderUser
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function sendReportOnlyEmail(
        ApprovalRequest $approvalRequest,
        array $recipients,
        array $filePaths,
        string $subject,
        string $notes,
        User $senderUser
    ): array {
        $cfg = $this->getActiveConfig();
        if (!$cfg) {
            return [
                'success' => false,
                'message' => 'Konfigurasi SMTP belum aktif di Pengaturan SMTP.',
            ];
        }

        if (!$this->configureMailer($cfg)) {
            return [
                'success' => false,
                'message' => 'Gagal menginisialisasi konfigurasi SMTP.',
            ];
        }

        $validRecipients = [];
        foreach ($recipients as $r) {
            $email = strtolower(trim(is_array($r) ? ($r['email'] ?? '') : ($r->email ?? '')));
            $name = trim(is_array($r) ? ($r['name'] ?? '') : ($r->name ?? ''));
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validRecipients[] = ['email' => $email, 'name' => $name ?: $email];
            }
        }

        if (empty($validRecipients)) {
            return [
                'success' => false,
                'message' => 'Tidak ada alamat email penerima yang valid.',
            ];
        }

        try {
            $senderDisplayName = $cfg->sender_name ?: 'PT JAI Catering Management';
            $senderEmail = $cfg->email_sender ?: 'catering-noreply@company.internal';

            $htmlBody = $this->buildReportOnlyHtmlBody(
                approvalRequest: $approvalRequest,
                senderUser: $senderUser,
                notes: $notes,
                filePaths: $filePaths
            );

            foreach ($validRecipients as $recipient) {
                Mail::mailer('smtp')->send([], [], function (Message $message) use ($recipient, $subject, $htmlBody, $filePaths, $senderEmail, $senderDisplayName) {
                    $message->from($senderEmail, $senderDisplayName)
                        ->to($recipient['email'], $recipient['name'])
                        ->subject($subject)
                        ->html($htmlBody);

                    foreach ($filePaths as $f) {
                        $absPath = is_array($f) ? ($f['path'] ?? '') : (string) $f;
                        $filename = is_array($f) ? ($f['name'] ?? basename($absPath)) : basename($absPath);
                        $mime = is_array($f) ? ($f['mime'] ?? null) : null;

                        if ($absPath && file_exists($absPath)) {
                            $message->attach($absPath, array_filter([
                                'as' => $filename,
                                'mime' => $mime,
                            ]));
                        }
                    }
                });
            }

            return [
                'success' => true,
                'message' => 'Laporan berhasil didistribusikan ke ' . count($validRecipients) . " penerima via SMTP ({$cfg->smtp_host}).",
            ];
        } catch (\Throwable $e) {
            Log::error('Send Report (REPORT_ONLY) Email failed: ' . $e->getMessage(), [
                'request_id' => $approvalRequest->id,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'message' => 'Gagal mengirim email via SMTP: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send email for APPROVAL request to the active approver.
     *
     * @param ApprovalRequest $approvalRequest
     * @param ApprovalRequestApprover $approver
     * @param array $filePaths
     * @param string $subject
     * @param string $notes
     * @param User $senderUser
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function sendApprovalRequestEmail(
        ApprovalRequest $approvalRequest,
        ApprovalRequestApprover $approver,
        array $filePaths,
        string $subject,
        string $notes,
        User $senderUser
    ): array {
        $cfg = $this->getActiveConfig();
        if (!$cfg) {
            return [
                'success' => false,
                'message' => 'Konfigurasi SMTP belum aktif di Pengaturan SMTP.',
            ];
        }

        if (!$this->configureMailer($cfg)) {
            return [
                'success' => false,
                'message' => 'Gagal menginisialisasi konfigurasi SMTP.',
            ];
        }

        $approverEmail = strtolower(trim($approver->email));
        if (!$approverEmail || !filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => "Email approver '{$approverEmail}' tidak valid.",
            ];
        }

        try {
            $senderDisplayName = $cfg->sender_name ?: 'PT JAI Catering Management';
            $senderEmail = $cfg->email_sender ?: 'catering-noreply@company.internal';

            $appUrl = $cfg->app_url ?: config('app.url', 'http://localhost:3000');
            $reviewUrl = rtrim($appUrl, '/') . '/approval?id=' . $approvalRequest->id;

            $htmlBody = $this->buildApprovalHtmlBody(
                approvalRequest: $approvalRequest,
                approver: $approver,
                senderUser: $senderUser,
                notes: $notes,
                filePaths: $filePaths,
                reviewUrl: $reviewUrl
            );

            Mail::mailer('smtp')->send([], [], function (Message $message) use ($approver, $approverEmail, $subject, $htmlBody, $filePaths, $senderEmail, $senderDisplayName) {
                $message->from($senderEmail, $senderDisplayName)
                    ->to($approverEmail, $approver->name ?: $approverEmail)
                    ->subject($subject)
                    ->html($htmlBody);

                foreach ($filePaths as $f) {
                    $absPath = is_array($f) ? ($f['path'] ?? '') : (string) $f;
                    $filename = is_array($f) ? ($f['name'] ?? basename($absPath)) : basename($absPath);
                    $mime = is_array($f) ? ($f['mime'] ?? null) : null;

                    if ($absPath && file_exists($absPath)) {
                        $message->attach($absPath, array_filter([
                            'as' => $filename,
                            'mime' => $mime,
                        ]));
                    }
                }
            });

            return [
                'success' => true,
                'message' => "Permintaan approval berhasil diajukan dan dikirim ke {$approver->name} ({$approverEmail}) via SMTP ({$cfg->smtp_host}).",
            ];
        } catch (\Throwable $e) {
            Log::error('Send Report (APPROVAL) Email failed: ' . $e->getMessage(), [
                'request_id' => $approvalRequest->id,
                'approver' => $approver->email,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'message' => 'Gagal mengirim email via SMTP: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * HTML template for REPORT_ONLY email.
     */
    protected function buildReportOnlyHtmlBody(
        ApprovalRequest $approvalRequest,
        User $senderUser,
        string $notes,
        array $filePaths
    ): string {
        $senderName = htmlspecialchars($senderUser->nama ?: $senderUser->username);
        $senderRole = htmlspecialchars(strtoupper($senderUser->role));
        $notesHtml = !empty(trim($notes))
            ? '<div style="margin: 16px 0; padding: 14px 18px; background-color: #f8fafc; border-left: 4px solid #8f1a25; border-radius: 4px; font-size: 14px; color: #334155; line-height: 1.6;">' . nl2br(htmlspecialchars($notes)) . '</div>'
            : '';

        $filesListHtml = '';
        foreach ($filePaths as $f) {
            $name = is_array($f) ? ($f['name'] ?? basename($f['path'] ?? '')) : basename((string) $f);
            $filesListHtml .= '<li style="margin-bottom: 6px; color: #0f172a; font-weight: 500;">📎 ' . htmlspecialchars($name) . '</li>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Distribusi Laporan Catering</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 32px 16px; color: #0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
    <!-- Header -->
    <tr>
      <td style="background-color: #8f1a25; padding: 24px 32px; text-align: left;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.01em;">CARMEN - Distribusi Laporan</h1>
        <p style="color: #fecdd3; margin: 6px 0 0; font-size: 13px;">PT JAI Catering Management System</p>
      </td>
    </tr>
    <!-- Body -->
    <tr>
      <td style="padding: 32px;">
        <p style="font-size: 15px; margin-top: 0; line-height: 1.6; color: #334155;">
          Halo Bapak/Ibu,<br><br>
          Laporan catering baru telah didistribusikan kepada Anda oleh <strong>{$senderName}</strong> ({$senderRole}).
        </p>
        {$notesHtml}
        <div style="margin: 24px 0 12px; padding: 16px; background-color: #fafbfc; border-radius: 8px; border: 1px solid #e2e8f0;">
          <h4 style="margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Lampiran Laporan:</h4>
          <ul style="margin: 0; padding-left: 20px; font-size: 13.5px;">
            {$filesListHtml}
          </ul>
        </div>
        <p style="font-size: 13px; color: #64748b; line-height: 1.5; margin-top: 24px;">
          Dokumen laporan telah dilampirkan pada email ini. Silakan unduh lampiran untuk memeriksa detail laporan.
        </p>
      </td>
    </tr>
    <!-- Footer -->
    <tr>
      <td style="background-color: #f8fafc; padding: 20px 32px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.5;">
        Email ini dikirim secara otomatis oleh sistem CARMEN PT JAI Catering Management.<br>
        Mohon untuk tidak membalas email ini secara langsung.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * HTML template for APPROVAL email.
     */
    protected function buildApprovalHtmlBody(
        ApprovalRequest $approvalRequest,
        ApprovalRequestApprover $approver,
        User $senderUser,
        string $notes,
        array $filePaths,
        string $reviewUrl
    ): string {
        $approverName = htmlspecialchars($approver->name ?: 'Approver');
        $senderName = htmlspecialchars($senderUser->nama ?: $senderUser->username);
        $senderRole = htmlspecialchars(strtoupper($senderUser->role));
        $stepNumber = $approver->sequence ?: 1;

        $notesHtml = !empty(trim($notes))
            ? '<div style="margin: 16px 0; padding: 14px 18px; background-color: #f8fafc; border-left: 4px solid #8f1a25; border-radius: 4px; font-size: 14px; color: #334155; line-height: 1.6;">' . nl2br(htmlspecialchars($notes)) . '</div>'
            : '';

        $filesListHtml = '';
        foreach ($filePaths as $f) {
            $name = is_array($f) ? ($f['name'] ?? basename($f['path'] ?? '')) : basename((string) $f);
            $filesListHtml .= '<li style="margin-bottom: 6px; color: #0f172a; font-weight: 500;">📄 ' . htmlspecialchars($name) . '</li>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Permintaan Persetujuan Laporan</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 32px 16px; color: #0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
    <!-- Header -->
    <tr>
      <td style="background-color: #8f1a25; padding: 24px 32px; text-align: left;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.01em;">CARMEN - Permintaan Approval Laporan</h1>
        <p style="color: #fecdd3; margin: 6px 0 0; font-size: 13px;">Persetujuan Dokumen &amp; E-Signature (Tahap {$stepNumber})</p>
      </td>
    </tr>
    <!-- Body -->
    <tr>
      <td style="padding: 32px;">
        <p style="font-size: 15px; margin-top: 0; line-height: 1.6; color: #334155;">
          Kepada Yth. <strong>{$approverName}</strong>,<br><br>
          Pengajuan persetujuan laporan baru membutuhkan review dan tanda tangan digital Anda dari pengaju <strong>{$senderName}</strong> ({$senderRole}).
        </p>
        {$notesHtml}
        <div style="margin: 24px 0; padding: 16px; background-color: #fafbfc; border-radius: 8px; border: 1px solid #e2e8f0;">
          <h4 style="margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Dokumen yang Diajukan:</h4>
          <ul style="margin: 0; padding-left: 20px; font-size: 13.5px;">
            {$filesListHtml}
          </ul>
        </div>
        <!-- Action Button -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 28px 0;">
          <tr>
            <td align="center">
              <a href="{$reviewUrl}" target="_blank" style="display: inline-block; background-color: #8f1a25; color: #ffffff; font-weight: 600; font-size: 15px; padding: 12px 28px; border-radius: 8px; text-decoration: none; box-shadow: 0 2px 4px rgba(143, 26, 37, 0.25);">
                Review &amp; Setujui Laporan
              </a>
            </td>
          </tr>
        </table>
        <p style="font-size: 12.5px; color: #64748b; line-height: 1.5; margin-top: 20px;">
          Tautan langsung: <a href="{$reviewUrl}" style="color: #8f1a25; word-break: break-all;">{$reviewUrl}</a>
        </p>
      </td>
    </tr>
    <!-- Footer -->
    <tr>
      <td style="background-color: #f8fafc; padding: 20px 32px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.5;">
        Email ini dikirim secara otomatis oleh sistem CARMEN PT JAI Catering Management.<br>
        Mohon untuk tidak membalas email ini secara langsung.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
