<?php
declare(strict_types=1);

namespace App\Utils;

final class Validator
{
    public static function textToHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $bolded = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);
        return preg_replace('/\r\n|\r|\n/', '<br>', $bolded ?? $escaped);
    }

    public static function htmlToText(string $html): string
    {
        $withNewlines = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $stripped = preg_replace('/<[^>]+>/', '', $withNewlines ?? $html);
        return html_entity_decode($stripped ?? $html, ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail(string $email): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email);
    }

    public static function extractEmailParts(string $email): ?array
    {
        preg_match('/^([^@]+)@([^.]+)\.(.+)$/', $email, $matches);
        if (count($matches) === 4) {
            return [
                'nickname' => $matches[1],
                'domain' => $matches[2],
                'suffix' => $matches[3],
            ];
        }
        return null;
    }

    public static function replaceString(string $text, string $search, string $replace): string
    {
        $pattern = '/' . preg_quote($search, '/') . '/i';
        return preg_replace($pattern, $replace, $text) ?? $text;
    }

    public static function fixExcessiveCaps(string $text): string
    {
        return preg_replace_callback('/\b[A-ZА-ЯІЇЄҐ]{2,}\b/u', static function (array $matches): string {
            if (\function_exists('mb_convert_case')) {
                return \mb_convert_case($matches[0], MB_CASE_TITLE, 'UTF-8');
            }

            // Fallback for environments without mbstring: use a best-effort ASCII-safe transform
            return ucwords(strtolower($matches[0]));
        }, $text) ?? $text;
    }

    public static function fixFilenames(string $filename): string
    {
        return preg_replace('/[ ]+/', '_', $filename) ?? $filename;
    }

    public static function validatePassword(string $password): bool
    {
        $len = \function_exists('mb_strlen') ? \mb_strlen($password, 'UTF-8') : \strlen($password);
        return $len >= 8;
    }

    public static function validateImageUpload(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Помилка завантаження.');
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            throw new \RuntimeException('Розмір файлу не повинен перевищувати 5MB.');
        }

        $allowedMimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
        ];

        $mime = null;
        if (\class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
        } elseif (\function_exists('mime_content_type')) {
            $mime = \mime_content_type($file['tmp_name']);
        } elseif (\function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file['tmp_name']);
            $mime = $imageInfo['mime'] ?? null;
        }

        $ext = null;
        if ($mime !== null) {
            $ext = array_search($mime, $allowedMimes, true);
        }

        if ($ext === false || $ext === null) {
            $nameExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($nameExt !== '' && array_key_exists($nameExt, $allowedMimes)) {
                $ext = $nameExt;
            }
        }

        if ($ext === false || $ext === null) {
            throw new \RuntimeException('Дозволені лише формати JPG, PNG та GIF.');
        }

        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        return sprintf('%s.%s', bin2hex(random_bytes(8)), $ext);
    }
}
