<?php

declare(strict_types=1);

namespace RC\Cli;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class ArtifactRepository
{
    public const BASE_URL = 'https://rcmaker.runchance.com/download';

    private $output;

    public function __construct(?callable $output = null)
    {
        $this->output = $output;
    }

    public static function phpVersions(): array
    {
        return ['8.1', '8.2', '8.3', '8.4', '8.5'];
    }

    public static function normalizePhpVersion(string $version): string
    {
        $version = trim($version);
        if (!in_array($version, self::phpVersions(), true)) {
            throw new InvalidArgumentException('Unsupported PHP version: ' . $version);
        }
        return $version;
    }

    public static function currentPlatform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => throw new RuntimeException('Unsupported host platform: ' . PHP_OS_FAMILY),
        };
    }

    public static function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if ($platform === '' || $platform === 'auto') {
            return self::currentPlatform();
        }
        $platform = match ($platform) {
            'darwin', 'osx', 'mac', 'macosx' => 'macos',
            'win', 'win32', 'win64' => 'windows',
            default => $platform,
        };
        if (!in_array($platform, ['linux', 'macos', 'windows'], true)) {
            throw new InvalidArgumentException('Unsupported platform: ' . $platform);
        }
        return $platform;
    }

    public static function normalizeArch(string $arch): string
    {
        $arch = strtolower(trim($arch));
        if ($arch === '' || $arch === 'auto') {
            $arch = strtolower((string)php_uname('m'));
        }
        $arch = match ($arch) {
            'amd64', 'x64', 'x86-64' => 'x86_64',
            'arm64', 'armv8', 'armv8l' => 'aarch64',
            default => $arch,
        };
        if (!in_array($arch, ['x86_64', 'aarch64'], true)) {
            throw new InvalidArgumentException('Unsupported architecture: ' . $arch);
        }
        return $arch;
    }

    public static function assertTarget(string $platform, string $arch): void
    {
        if ($platform === 'windows' && $arch !== 'x86_64') {
            throw new InvalidArgumentException('Windows artifacts currently support x86_64 only.');
        }
    }

    public static function assertHostTarget(string $platform, string $arch, string $operation): void
    {
        $hostPlatform = self::currentPlatform();
        $hostArch = self::normalizeArch('auto');
        if ($platform !== $hostPlatform || $arch !== $hostArch) {
            throw new RuntimeException(
                "{$operation} must run on the target platform. Host: {$hostPlatform}/{$hostArch}; target: {$platform}/{$arch}."
            );
        }
    }

    public static function runtimeArchive(string $version, string $platform, string $arch): string
    {
        return "php{$version}-{$platform}-{$arch}.zip";
    }

    public static function microArchive(string $version, string $platform, string $arch): string
    {
        return "php{$version}-micro-{$platform}-{$arch}.zip";
    }

    public static function beastArchive(string $platform, string $arch): string
    {
        return "rcmakerbeast-{$platform}-{$arch}.zip";
    }

    public static function runtimeEntry(string $platform): string
    {
        return $platform === 'windows' ? 'php.exe' : 'php';
    }

    public static function beastEntry(string $platform): string
    {
        return $platform === 'windows' ? 'rcmakerbeast.exe' : 'rcmakerbeast';
    }

    public function ensure(string $archiveName, string $expectedEntry, string $targetPath): string
    {
        if (is_file($targetPath)) {
            $this->emit('Use existing ' . basename($targetPath) . ' ...');
            return $targetPath;
        }
        $archivePath = tempnam(sys_get_temp_dir(), 'rcmaker-artifact-');
        if ($archivePath === false) {
            throw new RuntimeException('Failed to create temporary artifact file.');
        }
        $url = self::BASE_URL . '/' . rawurlencode($archiveName);
        $this->emit('Downloading ' . $url . ' ...');
        try {
            $this->download($url, $archivePath);
            $this->extractSingleFile($archivePath, $expectedEntry, $targetPath);
        } finally {
            if (is_file($archivePath)) {
                @unlink($archivePath);
            }
        }
        return $targetPath;
    }

    private function download(string $url, string $targetPath): void
    {
        Filesystem::mkdir(dirname($targetPath));
        $output = fopen($targetPath, 'wb');
        if (!is_resource($output)) {
            throw new RuntimeException('Failed to create download file: ' . $targetPath);
        }
        try {
            if (function_exists('curl_init')) {
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new RuntimeException('Failed to initialize cURL.');
                }
                curl_setopt_array($curl, [
                    CURLOPT_FILE => $output,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_FAILONERROR => true,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_USERAGENT => 'rcmaker/framework-cli',
                ]);
                $success = curl_exec($curl);
                $error = curl_error($curl);
                $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if ($success !== true || $status < 200 || $status >= 300) {
                    throw new RuntimeException('Download failed: ' . $url . ($error !== '' ? ' (' . $error . ')' : " (HTTP {$status})"));
                }
            } else {
                if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
                    throw new RuntimeException('Artifact download requires ext-curl or allow_url_fopen=1.');
                }
                $context = stream_context_create(['http' => [
                    'follow_location' => 1,
                    'max_redirects' => 5,
                    'timeout' => 60,
                    'user_agent' => 'rcmaker/framework-cli',
                ]]);
                $input = @fopen($url, 'rb', false, $context);
                if (!is_resource($input)) {
                    throw new RuntimeException('Download failed: ' . $url);
                }
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new RuntimeException('Download failed while writing: ' . $url);
                    }
                } finally {
                    fclose($input);
                }
            }
        } finally {
            fclose($output);
        }
        if (!is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('Downloaded artifact is empty: ' . $url);
        }
    }

    private function extractSingleFile(string $archivePath, string $expectedEntry, string $targetPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("The host PHP must have the 'zip' extension enabled.");
        }
        $zip = new ZipArchive();
        $result = $zip->open($archivePath);
        if ($result !== true) {
            throw new RuntimeException("Failed to open artifact archive (ZipArchive code {$result}).");
        }
        try {
            if ($zip->numFiles !== 1) {
                throw new RuntimeException('Artifact archive must contain exactly one file.');
            }
            $entry = $zip->getNameIndex(0);
            if ($entry !== $expectedEntry) {
                throw new RuntimeException("Artifact entry mismatch: expected {$expectedEntry}, found " . (string)$entry);
            }
            $input = $zip->getStream($expectedEntry);
            if (!is_resource($input)) {
                throw new RuntimeException('Failed to read artifact entry: ' . $expectedEntry);
            }
            Filesystem::mkdir(dirname($targetPath));
            $temporary = $targetPath . '.tmp-' . bin2hex(random_bytes(6));
            $output = fopen($temporary, 'wb');
            if (!is_resource($output)) {
                fclose($input);
                throw new RuntimeException('Failed to create extracted artifact: ' . $temporary);
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('Failed to extract artifact: ' . $expectedEntry);
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if (is_file($targetPath)) {
                Filesystem::removeFile($targetPath);
            }
            if (!rename($temporary, $targetPath)) {
                @unlink($temporary);
                throw new RuntimeException('Failed to finalize artifact: ' . $targetPath);
            }
        } finally {
            $zip->close();
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($targetPath, 0755);
        }
    }

    private function emit(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
