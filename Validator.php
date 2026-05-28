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
            return mb_convert_case($matches[0], MB_CASE_TITLE, 'UTF-8');
        }, $text) ?? $text;
    }

    public static function fixFilenames(string $filename): string
    {
        return preg_replace('/[ ]+/', '_', $filename) ?? $filename;
    }

    public static function validatePassword(string $password): bool
    {
        return mb_strlen($password, 'UTF-8') >= 8;
    }

    public static function validateImageUpload(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Помилка завантаження.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('Недопустимий формат.');
        }

        return bin2hex(random_bytes(8)) . '.' . explode('/', $mime)[1];
    }
}