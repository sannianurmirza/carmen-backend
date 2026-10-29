<?php

namespace App\Http\Controllers;

use App\Models\SMTPConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class SmtpController extends Controller
{
    /**
     * GET /settings/smtp
     * Mengambil konfigurasi server SMTP aktif.
     */
    public function show(): JsonResponse
    {
        $cfg = SMTPConfig::first();

        if (!$cfg) {
            return response()->json([
                'id' => 1,
                'smtp_host' => 'smtp.company.internal',
                'smtp_port' => 587,
                'smtp_username' => 'catering-noreply@company.internal',
                'smtp_password_configured' => false,
                'email_sender' => 'catering-noreply@company.internal',
                'sender_name' => 'PT JAI Catering Management',
                'use_tls' => true,
                'use_ssl' => false,
                'app_url' => 'https://catering.company.internal/catering-ir',
                'is_active' => true,
                'updated_at' => null,
                'updated_by_nama' => null,
            ]);
        }

        $updaterNama = null;
        if ($cfg->updated_by) {
            $u = User::find($cfg->updated_by);
            $updaterNama = $u ? ($u->username ?? $u->nama) : null;
        }

        return response()->json([
            'id' => $cfg->id,
            'smtp_host' => $cfg->smtp_host,
            'smtp_port' => (int) $cfg->smtp_port,
            'smtp_username' => $cfg->smtp_username,
            'smtp_password_configured' => !empty($cfg->smtp_password_encrypted),
            'email_sender' => $cfg->email_sender,
            'sender_name' => $cfg->sender_name ?: 'PT JAI Catering Management',
            'use_tls' => (bool) $cfg->use_tls,
            'use_ssl' => (bool) $cfg->use_ssl,
            'app_url' => $cfg->app_url,
            'is_active' => (bool) $cfg->is_active,
            'updated_at' => $cfg->updated_at ? Carbon::parse($cfg->updated_at)->toISOString() : null,
            'updated_by_nama' => $updaterNama,
        ]);
    }

    /**
     * PUT /settings/smtp
     * Memperbarui konfigurasi SMTP.
     */
    public function update(Request $request): JsonResponse
    {
        $host = trim((string) $request->input('smtp_host', ''));
        if (!$host) {
            return response()->json(['detail' => 'Host SMTP wajib diisi.'], 422);
        }

        $port = (int) $request->input('smtp_port', 587);
        if ($port < 1 || $port > 65535) {
            return response()->json(['detail' => 'Port SMTP harus antara 1 dan 65535.'], 422);
        }

        $emailSender = trim((string) $request->input('email_sender', ''));
        if (!$emailSender || !filter_var($emailSender, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['detail' => 'Email pengirim tidak valid.'], 422);
        }

        $cfg = SMTPConfig::first();
        if (!$cfg) {
            $cfg = new SMTPConfig();
        }

        $cfg->smtp_host = $host;
        $cfg->smtp_port = $port;
        $cfg->smtp_username = $request->filled('smtp_username') ? trim((string) $request->input('smtp_username')) : null;

        if ($request->boolean('clear_password')) {
            $cfg->smtp_password_encrypted = null;
        } elseif ($request->filled('smtp_password')) {
            $plainPass = (string) $request->input('smtp_password');
            $cfg->smtp_password_encrypted = Crypt::encryptString($plainPass);
        }

        $cfg->email_sender = $emailSender;
        $cfg->sender_name = $request->input('sender_name') ? trim((string) $request->input('sender_name')) : 'PT JAI Catering Management';
        $cfg->use_tls = $request->boolean('use_tls', true);
        $cfg->use_ssl = $request->boolean('use_ssl', false);
        $cfg->app_url = $request->input('app_url') ? trim((string) $request->input('app_url')) : null;
        $cfg->is_active = $request->boolean('is_active', true);
        $cfg->updated_at = Carbon::now('UTC');
        $cfg->updated_by = $request->user()?->id;

        $cfg->save();

        $updaterNama = $request->user()?->username ?? $request->user()?->nama;

        return response()->json([
            'id' => $cfg->id,
            'smtp_host' => $cfg->smtp_host,
            'smtp_port' => (int) $cfg->smtp_port,
            'smtp_username' => $cfg->smtp_username,
            'smtp_password_configured' => !empty($cfg->smtp_password_encrypted),
            'email_sender' => $cfg->email_sender,
            'sender_name' => $cfg->sender_name,
            'use_tls' => (bool) $cfg->use_tls,
            'use_ssl' => (bool) $cfg->use_ssl,
            'app_url' => $cfg->app_url,
            'is_active' => (bool) $cfg->is_active,
            'updated_at' => $cfg->updated_at ? Carbon::parse($cfg->updated_at)->toISOString() : null,
            'updated_by_nama' => $updaterNama,
        ]);
    }

    /**
     * POST /settings/smtp/test
     * Menguji konektivitas server SMTP, handshake TLS/SSL, dan autentikasi.
     */
    public function test(Request $request): JsonResponse
    {
        $cfg = SMTPConfig::first();

        $host = trim((string) $request->input('smtp_host', $cfg?->smtp_host ?? ''));
        $port = (int) $request->input('smtp_port', $cfg?->smtp_port ?? 587);
        $username = $request->input('smtp_username', $cfg?->smtp_username);
        $clearPassword = (bool) $request->input('clear_password', false);

        $password = null;
        if ($request->filled('smtp_password')) {
            $password = (string) $request->input('smtp_password');
        } elseif (!$clearPassword && !empty($cfg?->smtp_password_encrypted)) {
            try {
                $password = Crypt::decryptString($cfg->smtp_password_encrypted);
            } catch (\Throwable $e) {
                $password = $cfg->smtp_password_encrypted;
            }
        }

        $useTls = (bool) $request->input('use_tls', $cfg?->use_tls ?? true);
        $useSsl = (bool) $request->input('use_ssl', $cfg?->use_ssl ?? false);

        if (!$host) {
            return response()->json([
                'success' => false,
                'message' => 'Host SMTP wajib diisi untuk melakukan pengujian koneksi.',
            ]);
        }

        if ($port < 1 || $port > 65535) {
            return response()->json([
                'success' => false,
                'message' => 'Port SMTP tidak valid (1-65535).',
            ]);
        }

        $timeout = 6;
        $protocol = $useSsl ? 'ssl://' : '';
        $target = $protocol . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $cleanErr = trim($errstr) ?: "Kode error {$errno}";
            return response()->json([
                'success' => false,
                'message' => "Koneksi ke {$host}:{$port} gagal: {$cleanErr}",
            ]);
        }

        stream_set_timeout($socket, $timeout);

        // Baca greeting dari server SMTP (misal "220 smtp.domain.com ESMTP ...")
        $greeting = fgets($socket, 512);
        if ($greeting === false || !str_starts_with(trim($greeting), '220')) {
            fclose($socket);
            $cleanGreeting = $greeting !== false ? trim($greeting) : 'Tidak ada respon dari server';
            return response()->json([
                'success' => false,
                'message' => "Server merespon pada port {$port}, tetapi bukan banner SMTP yang valid: {$cleanGreeting}",
            ]);
        }

        // Kirim EHLO
        $clientName = gethostname() ?: 'carmen-client';
        fputs($socket, "EHLO {$clientName}\r\n");
        $ehloResponse = '';
        while ($line = fgets($socket, 512)) {
            $ehloResponse .= $line;
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                break;
            }
        }

        // Jika STARTTLS diaktifkan dan bukan mode SSL murni
        if ($useTls && !$useSsl && stripos($ehloResponse, 'STARTTLS') !== false) {
            fputs($socket, "STARTTLS\r\n");
            $tlsReply = fgets($socket, 512);
            if ($tlsReply && str_starts_with(trim($tlsReply), '220')) {
                $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoOk) {
                    // Re-EHLO setelah TLS aktif
                    fputs($socket, "EHLO {$clientName}\r\n");
                    while ($line = fgets($socket, 512)) {
                        if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                            break;
                        }
                    }
                }
            }
        }

        // Autentikasi jika username dan password tersedia
        if (!empty($username) && $password !== null && $password !== '') {
            fputs($socket, "AUTH LOGIN\r\n");
            $authInit = fgets($socket, 512);
            if ($authInit && str_starts_with(trim($authInit), '334')) {
                fputs($socket, base64_encode($username) . "\r\n");
                $userPrompt = fgets($socket, 512);
                if ($userPrompt && str_starts_with(trim($userPrompt), '334')) {
                    fputs($socket, base64_encode($password) . "\r\n");
                    $passReply = fgets($socket, 512);
                    if (!$passReply || !str_starts_with(trim($passReply), '235')) {
                        fclose($socket);
                        $authErr = $passReply ? trim($passReply) : 'Kredensial tidak diterima oleh server.';
                        return response()->json([
                            'success' => false,
                            'message' => "Autentikasi SMTP gagal untuk user '{$username}': {$authErr}",
                        ]);
                    }
                }
            }
        }

        fputs($socket, "QUIT\r\n");
        fclose($socket);

        return response()->json([
            'success' => true,
            'message' => "Koneksi ke {$host}:{$port} berhasil. Server SMTP merespon dengan benar.",
        ]);
    }
}
