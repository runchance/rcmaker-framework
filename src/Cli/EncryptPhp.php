<?php

declare(strict_types=1);

namespace RC\Cli;

use Phar;
use RuntimeException;
use Throwable;

final class EncryptPhp
{
    public static function execute(string $rootPath, array $options, ?callable $output = null): array
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException("The host PHP must have the 'openssl' extension enabled.");
        }

        $rootPath = realpath($rootPath) ?: $rootPath;
        $inputOption = trim((string)($options['input'] ?? ''));
        $outputOption = trim((string)($options['output'] ?? ''));
        if ($inputOption === '' || $outputOption === '') {
            throw new RuntimeException('Input and output paths are required.');
        }
        $inputPath = Filesystem::normalizedAbsolute($inputOption, $rootPath);
        $outputPath = Filesystem::normalizedAbsolute($outputOption, $rootPath);
        if (!file_exists($inputPath)) {
            throw new RuntimeException('Input path does not exist: ' . $inputPath);
        }

        $version = ArtifactRepository::normalizePhpVersion((string)($options['with-php'] ?? '8.4'));
        $platform = ArtifactRepository::normalizePlatform((string)($options['platform'] ?? 'auto'));
        $arch = ArtifactRepository::normalizeArch((string)($options['arch'] ?? 'auto'));
        ArtifactRepository::assertTarget($platform, $arch);

        $force = (bool)($options['force'] ?? false);
        $buildBinaryOption = trim((string)($options['build-bin'] ?? ''));
        $buildBinaryPath = $buildBinaryOption !== ''
            ? Filesystem::normalizedAbsolute($buildBinaryOption, $rootPath)
            : '';
        $runtimeOutputOption = trim((string)($options['runtime-output'] ?? ''));
        $downloadRuntime = (bool)($options['download-runtime'] ?? false)
            || $runtimeOutputOption !== '';
        $excludePaths = Filesystem::parseExcludePaths((string)($options['exclude-files'] ?? ''));
        $customIni = self::resolveCustomIni((string)($options['custom-ini'] ?? ''), $rootPath);
        $samePath = self::pathsEqual($inputPath, $outputPath);

        if ($samePath && !$force) {
            throw new RuntimeException('In-place encryption requires overwrite confirmation.');
        }
        if (!$samePath && self::pathContains($outputPath, $inputPath)) {
            throw new RuntimeException('Output path cannot be an ancestor of the input path.');
        }
        foreach (['Runtime output' => $runtimeOutputOption, 'Binary output' => $buildBinaryPath] as $label => $path) {
            if ($path === '') {
                continue;
            }
            $path = Filesystem::normalizedAbsolute($path, $rootPath);
            if (self::pathsEqual($path, $inputPath) || self::pathsEqual($path, $outputPath)) {
                throw new RuntimeException($label . ' must not replace the input or encrypted output path.');
            }
        }
        if (!$samePath && file_exists($outputPath)) {
            if (!$force) {
                throw new RuntimeException('Output already exists; enable overwrite to replace it: ' . $outputPath);
            }
            Filesystem::removePath($outputPath);
        }

        $workDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR
            . 'rcmaker-framework-encrypt-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Filesystem::mkdir($workDir);
        $repository = new ArtifactRepository($output);

        try {
            $hostPlatform = ArtifactRepository::currentPlatform();
            $hostArch = ArtifactRepository::normalizeArch('auto');
            $beastEntry = ArtifactRepository::beastEntry($hostPlatform);
            $beastPath = $repository->ensure(
                ArtifactRepository::beastArchive($hostPlatform, $hostArch),
                $beastEntry,
                $workDir . DIRECTORY_SEPARATOR . $beastEntry
            );
            self::emit($output, 'Encrypt PHP source ...');

            if (is_file($inputPath)) {
                $command = [$beastPath, 'file', $inputPath, $outputPath];
                if ($force) {
                    $command[] = '--force';
                }
                ProcessRunner::run($command, $rootPath);
            } else {
                $stagingDir = $workDir . DIRECTORY_SEPARATOR . 'staging';
                Filesystem::mkdir($stagingDir);
                Filesystem::copyTree(
                    $inputPath,
                    $stagingDir,
                    static fn(string $relative): bool => Filesystem::shouldExclude($relative, $excludePaths)
                );
                $command = [$beastPath, 'dir', $stagingDir, $outputPath];
                if ($force) {
                    $command[] = '--force';
                }
                ProcessRunner::run($command, $rootPath);
            }

            $runtimePath = null;
            if ($downloadRuntime) {
                $runtimePath = self::runtimeOutput(
                    $outputPath,
                    $runtimeOutputOption,
                    $platform,
                    $rootPath
                );
                if (is_file($runtimePath)) {
                    if (!$force) {
                        throw new RuntimeException('Runtime output already exists: ' . $runtimePath);
                    }
                    Filesystem::removeFile($runtimePath);
                }
                $repository->ensure(
                    ArtifactRepository::runtimeArchive($version, $platform, $arch),
                    ArtifactRepository::runtimeEntry($platform),
                    $runtimePath
                );
                self::emit($output, 'Runtime saved to: ' . $runtimePath);
            }

            $binaryPath = null;
            if ($buildBinaryPath !== '') {
                if (!class_exists(Phar::class)) {
                    throw new RuntimeException("The host PHP must have the 'phar' extension enabled.");
                }
                if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
                    throw new RuntimeException('Single-file build requires phar.readonly=0.');
                }

                $binaryPath = $buildBinaryPath;
                if (file_exists($binaryPath) && !$force) {
                    throw new RuntimeException('Binary output already exists: ' . $binaryPath);
                }
                $sfxPath = $repository->ensure(
                    ArtifactRepository::microArchive($version, $platform, $arch),
                    'micro.sfx',
                    $workDir . DIRECTORY_SEPARATOR . 'micro.sfx'
                );
                $pharPath = $workDir . DIRECTORY_SEPARATOR . 'payload.phar';
                self::buildPhar(
                    $outputPath,
                    $pharPath,
                    (string)($options['entry'] ?? ''),
                    self::pharAlias($binaryPath)
                );
                try {
                    self::buildBinary($sfxPath, $pharPath, $binaryPath, $customIni, $force, $platform);
                } finally {
                    self::releasePhar($pharPath);
                }
                self::emit($output, 'Binary saved to: ' . $binaryPath);
            }
        } finally {
            Filesystem::removePath($workDir);
        }

        return [
            'input' => $inputPath,
            'output' => $outputPath,
            'runtime' => $runtimePath ?? null,
            'binary' => $binaryPath ?? null,
            'platform' => $platform,
            'arch' => $arch,
            'php' => $version,
        ];
    }

    private static function buildPhar(string $sourcePath, string $pharPath, string $entry, string $alias): void
    {
        self::releasePhar($pharPath);
        $phar = new Phar($pharPath, 0, $alias);
        $phar->startBuffering();
        $phar->setSignatureAlgorithm(Phar::SHA256);
        if (is_file($sourcePath)) {
            $entryFile = basename($sourcePath);
            $phar->addFile($sourcePath, $entryFile);
        } else {
            $entryFile = self::resolveEntry($sourcePath, $entry);
            $phar->buildFromDirectory($sourcePath);
        }
        $phar->setStub(
            "#!/usr/bin/env php\n<?php\n"
            . "Phar::mapPhar('{$alias}');\n"
            . "require 'phar://{$alias}/{$entryFile}';\n"
            . "__HALT_COMPILER();\n"
        );
        $phar->stopBuffering();
        unset($phar);
    }

    private static function releasePhar(string $pharPath): void
    {
        try {
            Phar::unlinkArchive($pharPath);
        } catch (Throwable) {
            Filesystem::removePath($pharPath);
        }
        clearstatcache(true, $pharPath);
    }

    private static function buildBinary(
        string $sfxPath,
        string $payloadPath,
        string $outputPath,
        string $customIni,
        bool $force,
        string $platform
    ): void {
        Filesystem::mkdir(dirname($outputPath));
        $temporary = $outputPath . '.tmp-' . bin2hex(random_bytes(6));
        $stream = fopen($temporary, 'wb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to create binary output: ' . $temporary);
        }
        try {
            Filesystem::copyFileToStream($sfxPath, $stream);
            if ($customIni !== '') {
                Filesystem::writeToStream(
                    $stream,
                    "\xfd\xf6\x69\xe6" . pack('N', strlen($customIni)) . $customIni
                );
            }
            Filesystem::copyFileToStream($payloadPath, $stream);
        } catch (Throwable $throwable) {
            fclose($stream);
            @unlink($temporary);
            throw $throwable;
        }
        fclose($stream);

        if (is_file($outputPath)) {
            if (!$force) {
                @unlink($temporary);
                throw new RuntimeException('Binary output already exists: ' . $outputPath);
            }
            Filesystem::removeFile($outputPath);
        }
        if (!rename($temporary, $outputPath)) {
            @unlink($temporary);
            throw new RuntimeException('Failed to finalize binary output: ' . $outputPath);
        }
        if ($platform !== 'windows') {
            @chmod($outputPath, 0755);
        }
    }

    private static function resolveEntry(string $sourceRoot, string $entry): string
    {
        $entry = ltrim(trim(str_replace('\\', '/', $entry)), '/');
        if ($entry === '') {
            $entry = 'index.php';
        }
        if (Filesystem::isAbsolute($entry) || preg_match('#(?:^|/)\.\.(?:/|$)#', $entry)) {
            throw new RuntimeException('Phar entry must be a safe relative path.');
        }
        $entryPath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        if (!is_file($entryPath)) {
            throw new RuntimeException('Entry file does not exist in encrypted output: ' . $entry);
        }
        return $entry;
    }

    private static function resolveCustomIni(string $value, string $rootPath): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains(strtolower($value), '.ini')) {
            $path = Filesystem::absolute($value, $rootPath);
            if (!is_file($path)) {
                throw new RuntimeException('Custom ini file does not exist: ' . $path);
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Failed to read custom ini file: ' . $path);
            }
            return $contents;
        }
        return str_replace(';', "\n", $value);
    }

    private static function runtimeOutput(
        string $outputPath,
        string $runtimeOutput,
        string $platform,
        string $rootPath
    ): string {
        if ($runtimeOutput !== '') {
            return Filesystem::normalizedAbsolute($runtimeOutput, $rootPath);
        }
        $baseDir = is_dir($outputPath) ? $outputPath : dirname($outputPath);
        return rtrim($baseDir, DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR
            . ArtifactRepository::runtimeEntry($platform);
    }

    private static function pharAlias(string $path): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($path, PATHINFO_FILENAME));
        return $name !== '' ? $name : 'app';
    }

    private static function pathsEqual(string $first, string $second): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($first, $second) === 0
            : $first === $second;
    }

    private static function pathContains(string $parent, string $child): bool
    {
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        $child = rtrim(str_replace('\\', '/', $child), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $parent = strtolower($parent);
            $child = strtolower($child);
        }
        return $child === $parent || str_starts_with($child, $parent . '/');
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
