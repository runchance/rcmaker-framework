<?php

declare(strict_types=1);

namespace RC\Cli;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Filesystem
{
    public static function mkdir(string $path): void
    {
        if ($path === '' || is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    public static function removePath(string $path): void
    {
        if (is_dir($path)) {
            self::removeDir($path);
            return;
        }
        if (is_file($path) || is_link($path)) {
            self::removeFile($path);
        }
    }

    public static function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::removeEmptyDir($item->getPathname());
            } else {
                self::removeFile($item->getPathname());
            }
        }
        self::removeEmptyDir($path);
    }

    public static function removeFile(string $path): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ((!is_file($path) && !is_link($path)) || @unlink($path)) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Failed to remove file after retries: ' . $path);
    }

    public static function removeEmptyDir(string $path): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (!is_dir($path) || @rmdir($path)) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Failed to remove directory after retries: ' . $path);
    }

    public static function cleanupDirectory(string $path, string $keepName): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException('Failed to scan directory: ' . $path);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $keepName) {
                continue;
            }
            self::removePath($path . DIRECTORY_SEPARATOR . $item);
        }
    }

    public static function copyTree(string $sourceRoot, string $targetRoot, callable $exclude): void
    {
        $sourceRoot = rtrim(self::absolute($sourceRoot), DIRECTORY_SEPARATOR . '/\\');
        $targetRoot = rtrim(self::absolute($targetRoot), DIRECTORY_SEPARATOR . '/\\');
        $directoryIterator = new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS);
        $filterIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function ($item) use ($sourceRoot, $exclude): bool {
                $sourcePath = $item->getPathname();
                $relativePath = substr($sourcePath, strlen($sourceRoot) + 1);
                return !$exclude(self::normalizeRelative($relativePath), $item);
            }
        );
        $iterator = new RecursiveIteratorIterator($filterIterator, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = substr($sourcePath, strlen($sourceRoot) + 1);
            $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;
            if ($item->isDir()) {
                self::mkdir($targetPath);
                continue;
            }
            self::mkdir(dirname($targetPath));
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException("Failed to copy file: {$sourcePath} -> {$targetPath}");
            }
        }
    }

    public static function copyFileToStream(string $sourcePath, $targetStream): void
    {
        $source = fopen($sourcePath, 'rb');
        if (!is_resource($source)) {
            throw new RuntimeException('Failed to open binary input: ' . $sourcePath);
        }
        try {
            if (stream_copy_to_stream($source, $targetStream) === false) {
                throw new RuntimeException('Failed to append binary input: ' . $sourcePath);
            }
        } finally {
            fclose($source);
        }
    }

    public static function writeToStream($stream, string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write binary output.');
            }
            $offset += $written;
        }
    }

    public static function parseExcludePaths(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $paths = [];
        foreach (explode(',', str_replace('，', ',', $value)) as $path) {
            $path = trim(str_replace('\\', '/', $path), " \t\n\r\0\x0B/");
            if ($path === '') {
                continue;
            }
            $segments = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    throw new InvalidArgumentException('Exclude paths cannot contain ..: ' . $path);
                }
                $segments[] = $segment;
            }
            if ($segments === []) {
                throw new InvalidArgumentException('Exclude path must point inside the project.');
            }
            $paths[] = self::normalizeRelative(implode('/', $segments));
        }
        return array_values(array_unique($paths));
    }

    public static function shouldExclude(string $relativePath, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            if ($relativePath === $excludePath || str_starts_with($relativePath, $excludePath . '/')) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeRelative(string $path): string
    {
        return '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    public static function absolute(string $path, ?string $basePath = null): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }
        $basePath ??= getcwd() ?: '.';
        return rtrim($basePath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    public static function normalizedAbsolute(string $path, ?string $basePath = null): string
    {
        $path = str_replace('\\', '/', self::absolute($path, $basePath));
        $prefix = '';
        if (preg_match('/^([A-Za-z]:|\/\/[^\/]+\/[^\/]+|\/)/', $path, $match)) {
            $prefix = $match[1];
            $path = substr($path, strlen($prefix));
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return rtrim($prefix . ($prefix !== '' && !str_ends_with($prefix, '/') ? '/' : '') . implode('/', $segments), '/');
    }

    public static function isAbsolute(string $path): bool
    {
        return (bool)preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path);
    }
}
