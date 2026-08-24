<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;

final class TokenKey
{
    private const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EDDSA'];

    public static function execute(string $rootPath, array $options, ?callable $output = null): array
    {
        $algorithm = strtoupper(trim((string)($options['algorithm'] ?? 'RS256')));
        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new RuntimeException('Unsupported signing algorithm: ' . $algorithm);
        }

        $configFile = trim((string)($options['openssl-config'] ?? ''));
        if ($configFile !== '') {
            $configFile = Filesystem::normalizedAbsolute($configFile, $rootPath);
            if (!is_file($configFile) || !is_readable($configFile)) {
                throw new RuntimeException('OpenSSL config is not readable: ' . $configFile);
            }
        }

        if ($algorithm === 'EDDSA') {
            if (!function_exists('sodium_crypto_sign_keypair')) {
                throw new RuntimeException('The sodium extension is required for EdDSA.');
            }
            $keyPair = sodium_crypto_sign_keypair();
            $privateKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));
            $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
        } else {
            if (!extension_loaded('openssl')) {
                throw new RuntimeException('The openssl extension is required.');
            }
            $config = self::opensslOptions($algorithm, $configFile);
            $resource = openssl_pkey_new($config);
            if ($resource === false) {
                throw new RuntimeException('Failed to generate private key: ' . self::opensslErrors());
            }
            if (!openssl_pkey_export($resource, $privateKey, null, $config)) {
                throw new RuntimeException('Failed to export private key: ' . self::opensslErrors());
            }
            $details = openssl_pkey_get_details($resource);
            if ($details === false || empty($details['key'])) {
                throw new RuntimeException('Failed to export public key: ' . self::opensslErrors());
            }
            $publicKey = $details['key'];
        }

        $sslDir = rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . 'ssl';
        Filesystem::mkdir($sslDir);
        [$privatePath, $publicPath] = self::allocatePaths($sslDir, $algorithm);
        self::writeExclusive($privatePath, $privateKey, 0600);
        try {
            self::writeExclusive($publicPath, $publicKey, 0644);
        } catch (\Throwable $throwable) {
            @unlink($privatePath);
            throw $throwable;
        }

        self::emit($output, 'Private key: ' . $privatePath);
        self::emit($output, 'Public key: ' . $publicPath);
        return ['algorithm' => $algorithm, 'private' => $privatePath, 'public' => $publicPath];
    }

    private static function opensslOptions(string $algorithm, string $configFile): array
    {
        $digest = [
            'RS256' => 'sha256',
            'RS384' => 'sha384',
            'RS512' => 'sha512',
            'ES256' => 'sha256',
            'ES384' => 'sha384',
        ][$algorithm];
        $options = ['digest_alg' => $digest];
        if ($configFile !== '') {
            $options['config'] = $configFile;
        }
        if (str_starts_with($algorithm, 'RS')) {
            $options['private_key_type'] = OPENSSL_KEYTYPE_RSA;
            $options['private_key_bits'] = ['RS256' => 2048, 'RS384' => 3072, 'RS512' => 4096][$algorithm];
        } else {
            $options['private_key_type'] = OPENSSL_KEYTYPE_EC;
            $options['curve_name'] = ['ES256' => 'prime256v1', 'ES384' => 'secp384r1'][$algorithm];
        }
        return $options;
    }

    private static function allocatePaths(string $directory, string $algorithm): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffix = (string)random_int(100000, 999999);
            $privatePath = $directory . DIRECTORY_SEPARATOR . $algorithm . '_' . $suffix . '.key';
            $publicPath = $directory . DIRECTORY_SEPARATOR . $algorithm . '_' . $suffix . '.pub';
            if (!file_exists($privatePath) && !file_exists($publicPath)) {
                return [$privatePath, $publicPath];
            }
        }
        throw new RuntimeException('Failed to allocate unique key filenames.');
    }

    private static function writeExclusive(string $path, string $contents, int $mode): void
    {
        $stream = fopen($path, 'xb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Failed to create key file: ' . $path);
        }
        try {
            Filesystem::writeToStream($stream, $contents);
        } finally {
            fclose($stream);
        }
        @chmod($path, $mode);
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }
        return $errors !== [] ? implode("\n", $errors) : 'unknown OpenSSL error';
    }

    private static function emit(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
