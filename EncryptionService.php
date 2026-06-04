<?php
declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class EncryptionService
{
    private static ?string $key = null;

    private static function getKey(): string
    {
        if (self::$key === null) {
            $keyFile = __DIR__ . '/.enc_key';
            if (file_exists($keyFile)) {
                $rawKey = trim((string)file_get_contents($keyFile));
                if ($rawKey !== '') {
                    self::$key = hex2bin($rawKey);
                }
            }
            
            if (self::$key === null) {
                $key = random_bytes(32);
                file_put_contents($keyFile, bin2hex($key));
                self::$key = $key;
            }
        }
        return self::$key;
    }

    private static function fallbackXor(string $data, string $key): string
    {
        $keyLen = strlen($key);
        if ($keyLen === 0) {
            $key = 'default_fallback_key';
            $keyLen = strlen($key);
        }
        $res = '';
        $dataLen = strlen($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $res .= $data[$i] ^ $key[$i % $keyLen];
        }
        return $res;
    }

    public static function encrypt(string $data, bool $deterministic = false): string
    {
        if ($data === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            return 'FALLBACK:' . base64_encode(self::fallbackXor($data, self::getKey()));
        }

        $method = 'aes-256-cbc';
        $key = self::getKey();

        if ($deterministic) {
            $iv = substr(hash('sha256', $key . '_deterministic'), 0, 16);
            $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
            if ($encrypted === false) {
                throw new RuntimeException('Encryption failed.');
            }
            return base64_encode($encrypted);
        } else {
            $ivLength = openssl_cipher_iv_length($method);
            $iv = random_bytes($ivLength);
            $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
            if ($encrypted === false) {
                throw new RuntimeException('Encryption failed.');
            }
            return base64_encode($iv . $encrypted);
        }
    }

    public static function decrypt(string $payload, bool $deterministic = false): string
    {
        if ($payload === '') {
            return '';
        }

        if (strpos($payload, 'FALLBACK:') === 0) {
            $base64 = substr($payload, 9);
            $decoded = base64_decode($base64);
            if ($decoded === false) {
                return $payload;
            }
            return self::fallbackXor($decoded, self::getKey());
        }

        if (!function_exists('openssl_decrypt')) {
            return $payload;
        }

        $method = 'aes-256-cbc';
        $key = self::getKey();
        
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return $payload;
        }

        if ($deterministic) {
            $iv = substr(hash('sha256', $key . '_deterministic'), 0, 16);
            $decrypted = openssl_decrypt($decoded, $method, $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : $payload;
        } else {
            $ivLength = openssl_cipher_iv_length($method);
            if (strlen($decoded) <= $ivLength) {
                return $payload;
            }
            $iv = substr($decoded, 0, $ivLength);
            $ciphertext = substr($decoded, $ivLength);
            $decrypted = openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : $payload;
        }
    }
}