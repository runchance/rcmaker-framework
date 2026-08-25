<?php

declare(strict_types=1);

namespace RC\Cli;

use Phar;
use RuntimeException;
use Throwable;

final class BuildBinary
{
    private const WINDOWS_ENTRY = '__rcmaker_windows.php';

    private const DEFAULT_EXCLUDE_PREFIXES = [
        '/.agents',
        '/.claude',
        '/.codex',
        '/.continue',
        '/.cursor',
        '/.gemini',
        '/.github',
        '/.idea',
        '/.roo',
        '/.setting',
        '/.tmp',
        '/.vscode',
        '/build',
        '/coverage',
        '/node_modules',
        '/official',
        '/runtime',
        '/scripts',
        '/test',
        '/tests',
        '/tools',
        '/vendor-bin',
    ];

    private const DEFAULT_EXCLUDE_DIRECTORY_NAMES = [
        '.agents',
        '.claude',
        '.codex',
        '.continue',
        '.cursor',
        '.gemini',
        '.git',
        '.github',
        '.hg',
        '.idea',
        '.roo',
        '.setting',
        '.svn',
        '.vscode',
        'node_modules',
        'vendor-bin',
    ];

    private const DEFAULT_EXCLUDE_VENDOR_DIRECTORY_NAMES = [
        'benchmark',
        'benchmarks',
        'doc',
        'docs',
        'example',
        'examples',
        'test',
        'tests',
    ];

    private const DEFAULT_EXCLUDE_FILE_NAMES = [
        '.editorconfig',
        '.env.example',
        '.gitattributes',
        '.gitignore',
        '.gitmodules',
        'bun.lock',
        'bun.lockb',
        'composer.json',
        'composer.lock',
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
        'dockerfile',
        'jsconfig.json',
        'makefile',
        'package-lock.json',
        'package.json',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpunit.xml',
        'phpunit.xml.dist',
        'pnpm-lock.yaml',
        'psalm.xml',
        'rector.php',
        'tsconfig.json',
        'windows.bat',
        'yarn.lock',
    ];

    private const DEFAULT_EXCLUDE_EXTENSIONS = [
        'map',
        'markdown',
        'md',
        'rst',
    ];

    public static function execute(string $rootPath, array $options, ?callable $output = null): array
    {
        $rootPath = realpath($rootPath) ?: $rootPath;
        if (!is_dir($rootPath)) {
            throw new RuntimeException('Project root does not exist: ' . $rootPath);
        }
        if (!class_exists(Phar::class)) {
            throw new RuntimeException("The host PHP must have the 'phar' extension enabled.");
        }
        if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException("Binary build requires phar.readonly=0.");
        }

        $version = ArtifactRepository::normalizePhpVersion((string)($options['with-php'] ?? '8.4'));
        $platform = ArtifactRepository::normalizePlatform((string)($options['platform'] ?? 'auto'));
        $arch = ArtifactRepository::normalizeArch((string)($options['arch'] ?? 'auto'));
        ArtifactRepository::assertTarget($platform, $arch);
        $encrypt = (bool)($options['encrypt'] ?? false);

        $customIni = self::resolveCustomIni((string)($options['custom-ini'] ?? ''), $rootPath);
        $excludePaths = Filesystem::parseExcludePaths((string)($options['exclude-files'] ?? ''));
        $buildDir = $rootPath . DIRECTORY_SEPARATOR . 'build';
        $stagingDir = $buildDir . DIRECTORY_SEPARATOR . 'rcmaker-framework-build-src';
        $pharPath = $buildDir . DIRECTORY_SEPARATOR . 'rcmaker.phar';
        $binaryName = $platform === 'windows' ? 'rcmaker.exe' : 'rcmaker.bin';
        $binaryPath = $buildDir . DIRECTORY_SEPARATOR . $binaryName;
        $entryFile = $platform === 'windows' ? self::WINDOWS_ENTRY : 'index.php';
        $repository = new ArtifactRepository($output);

        Filesystem::mkdir($buildDir);
        Filesystem::removePath($binaryPath);
        self::releasePhar($pharPath);
        Filesystem::removePath($stagingDir);

        try {
            Filesystem::mkdir($stagingDir);
            Filesystem::copyTree(
                $rootPath,
                $stagingDir,
                static function (string $relativePath) use ($excludePaths): bool {
                    if ($relativePath === '/windows.php') {
                        return true;
                    }
                    if (self::shouldExcludeByDefault($relativePath)) {
                        return true;
                    }
                    return Filesystem::shouldExclude($relativePath, $excludePaths);
                }
            );
            if ($platform === 'windows') {
                self::installWindowsEntry($stagingDir);
            }
            if (!is_file($stagingDir . DIRECTORY_SEPARATOR . $entryFile)) {
                throw new RuntimeException("Build entry {$entryFile} is missing or excluded.");
            }

            if ($encrypt) {
                self::emit($output, 'Encrypt staged project files ...');
                $hostPlatform = ArtifactRepository::currentPlatform();
                $hostArch = ArtifactRepository::normalizeArch('auto');
                $beastEntry = ArtifactRepository::beastEntry($hostPlatform);
                $beastPath = $repository->ensure(
                    ArtifactRepository::beastArchive($hostPlatform, $hostArch),
                    $beastEntry,
                    $buildDir . DIRECTORY_SEPARATOR . $beastEntry
                );
                ProcessRunner::run([$beastPath, 'dir', $stagingDir, '--in-place', '--force'], $rootPath);
            }

            self::emit($output, 'Create Phar payload ...');
            $phar = new Phar($pharPath, 0, 'rcmaker');
            $phar->startBuffering();
            $phar->setSignatureAlgorithm(Phar::SHA256);
            $phar->buildFromDirectory($stagingDir);
            $stub = "#!/usr/bin/env php\n"
                . "<?php\n"
                . "define('IN_PHAR', true);\n"
                . "Phar::mapPhar('rcmaker');\n"
                . "require 'phar://rcmaker/{$entryFile}';\n"
                . "__HALT_COMPILER();\n";
            $phar->setStub($stub);
            $phar->stopBuffering();
            unset($phar);
        } finally {
            Filesystem::removePath($stagingDir);
        }

        $sfxArchive = ArtifactRepository::microArchive($version, $platform, $arch);
        $sfxPath = $repository->ensure(
            $sfxArchive,
            'micro.sfx',
            $buildDir . DIRECTORY_SEPARATOR . substr($sfxArchive, 0, -4) . '.sfx'
        );

        self::emit($output, 'Combine Micro SFX and Phar payload ...');
        $temporaryBinary = $binaryPath . '.tmp-' . bin2hex(random_bytes(6));
        $stream = fopen($temporaryBinary, 'wb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to create binary output: ' . $temporaryBinary);
        }
        try {
            Filesystem::copyFileToStream($sfxPath, $stream);
            if ($customIni !== '') {
                Filesystem::writeToStream(
                    $stream,
                    "\xfd\xf6\x69\xe6" . pack('N', strlen($customIni)) . $customIni
                );
            }
            Filesystem::copyFileToStream($pharPath, $stream);
        } catch (Throwable $throwable) {
            fclose($stream);
            @unlink($temporaryBinary);
            throw $throwable;
        }
        fclose($stream);
        self::releasePhar($pharPath);

        if (!rename($temporaryBinary, $binaryPath)) {
            @unlink($temporaryBinary);
            throw new RuntimeException('Failed to finalize binary output: ' . $binaryPath);
        }
        if ($platform !== 'windows') {
            @chmod($binaryPath, 0755);
        }
        Filesystem::cleanupDirectory($buildDir, $binaryName);
        self::emit($output, 'Binary saved to: ' . $binaryPath);

        return [
            'path' => $binaryPath,
            'platform' => $platform,
            'arch' => $arch,
            'php' => $version,
            'encrypted' => $encrypt,
        ];
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

    private static function installWindowsEntry(string $stagingDir): void
    {
        $source = __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'windows-entry.php';
        if (!is_file($source)) {
            throw new RuntimeException('Framework Windows build entry is missing: ' . $source);
        }

        $target = $stagingDir . DIRECTORY_SEPARATOR . self::WINDOWS_ENTRY;
        if (!copy($source, $target)) {
            throw new RuntimeException('Failed to install the framework Windows build entry.');
        }
    }

    private static function containsExcludedDirectory(string $relativePath): bool
    {
        return self::containsDirectoryNamed($relativePath, self::DEFAULT_EXCLUDE_DIRECTORY_NAMES);
    }

    private static function containsDirectoryNamed(string $relativePath, array $directoryNames): bool
    {
        $segments = explode('/', trim(str_replace('\\', '/', $relativePath), '/'));
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), $directoryNames, true)) {
                return true;
            }
        }
        return false;
    }

    private static function shouldExcludeByDefault(string $relativePath): bool
    {
        $relativePath = Filesystem::normalizeRelative($relativePath);
        if (self::containsExcludedDirectory($relativePath)) {
            return true;
        }
        foreach (self::DEFAULT_EXCLUDE_PREFIXES as $prefix) {
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }
        if (str_starts_with($relativePath, '/vendor/')
            && self::containsDirectoryNamed($relativePath, self::DEFAULT_EXCLUDE_VENDOR_DIRECTORY_NAMES)
        ) {
            return true;
        }

        $fileName = strtolower(basename($relativePath));
        if (in_array($fileName, self::DEFAULT_EXCLUDE_FILE_NAMES, true)) {
            return true;
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::DEFAULT_EXCLUDE_EXTENSIONS, true);
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
