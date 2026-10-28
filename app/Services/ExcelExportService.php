<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

class ExcelExportService
{
    /**
     * Resolves the base path of the backend-laravel application.
     */
    public static function getBasePath(?string $path = ''): string
    {
        $base = dirname(__DIR__, 2);
        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\') : $base;
    }

    /**
     * Resolves the Python executable path.
     */
    public static function resolvePythonBinary(): string
    {
        $explicit = env('PYTHON_BINARY_PATH') ?: env('PYTHON_BINARY') ?: config('app.python_binary');
        if ($explicit && file_exists($explicit)) {
            return $explicit;
        }

        $workspaceRoot = dirname(self::getBasePath());
        // Windows venv candidate
        $venvCandidateWin = $workspaceRoot . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        if (file_exists($venvCandidateWin)) {
            return $venvCandidateWin;
        }
        // Linux venv candidate
        $venvCandidateLinux = $workspaceRoot . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
        if (file_exists($venvCandidateLinux)) {
            return $venvCandidateLinux;
        }

        return 'python';
    }

    /**
     * Generates official Excel workbook bytes using the template preservation engine.
     *
     * @param string $module Module key
     * @param array $payload Structured data for the generator
     * @return string Raw bytes of the generated .xlsx workbook
     */
    public static function generate(string $module, array $payload): string
    {
        $python = self::resolvePythonBinary();
        $script = self::getBasePath('scripts/generate_official_excel.py');

        if (!file_exists($script)) {
            throw new HttpException(500, "Script generator Excel resmi tidak ditemukan: {$script}");
        }

        $inputJson = tempnam(sys_get_temp_dir(), 'carmen_exp_in_') . '.json';
        $outputXlsx = tempnam(sys_get_temp_dir(), 'carmen_exp_out_') . '.xlsx';

        // Auto-inject db_path if not provided (use active configured database)
        if (!isset($payload['db_path'])) {
            $configuredDb = config('database.connections.sqlite.database');
            if ($configuredDb && file_exists($configuredDb)) {
                $payload['db_path'] = $configuredDb;
            } else {
                $payload['db_path'] = self::getBasePath('database/carmen_laravel_dev.sqlite');
            }
        }

        file_put_contents($inputJson, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $cmd = [
            $python,
            $script,
            $module,
            $inputJson,
            $outputXlsx
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, self::getBasePath());

        if (!is_resource($process)) {
            @unlink($inputJson);
            @unlink($outputXlsx);
            throw new HttpException(500, "Gagal menjalankan proses Python generator Excel.");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        @unlink($inputJson);

        if ($exitCode !== 0 || !file_exists($outputXlsx) || filesize($outputXlsx) === 0) {
            @unlink($outputXlsx);
            $errDetail = trim($stderr) ?: trim($stdout);
            \Log::error("ExcelExportService failure [{$module}]: {$errDetail}");
            throw new HttpException(500, "Gagal membuat file Excel laporan resmi: {$errDetail}");
        }

        $bytes = file_get_contents($outputXlsx);
        @unlink($outputXlsx);

        return $bytes;
    }

    /**
     * Builds a standard HTTP response with binary Excel content and UTF-8 Content-Disposition.
     *
     * @param string $bytes Raw XLSX file bytes
     * @param string $filename Download filename
     * @return Response
     */
    public static function createDownloadResponse(string $bytes, string $filename): Response
    {
        $encodedFilename = rawurlencode($filename);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encodedFilename,
            'Content-Length' => strlen($bytes),
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    public static function downloadResponse(string $bytes, string $filename): Response
    {
        return self::createDownloadResponse($bytes, $filename);
    }
}
