<?php
// 9. Оголошення простору імен
namespace App\Utils;

class Validator {
    
    // Обов'язкове 6: Перетворення рядків до формату HTML (жирний текст)
    public static function textToHtml($text) {
        return preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $text);
    }

    // Обов'язкове 7: Перевірка синтаксичної правильності e-mail
    public static function validateEmail($email) {
        return preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email);
    }

    // Блок 1, Завд. 14: Витягнути нікнейм, ім'я домену та суфікс із e-mail адреси
    public static function extractEmailParts($email) {
        preg_match('/^([^@]+)@([^.]+)\.(.+)$/', $email, $matches);
        if (count($matches) == 4) {
            return [
                'nickname' => $matches[1],
                'domain' => $matches[2],
                'suffix' => $matches[3]
            ];
        }
        return null;
    }

    // Блок 1, Завд. 15: Замінити всюди в тексті РЯДОК 1 на РЯДОК 2
    public static function replaceString($text, $search, $replace) {
        // Використовуємо preg_quote, щоб екранувати спецсимволи у пошуковому рядку
        $pattern = '/' . preg_quote($search, '/') . '/i';
        return preg_replace($pattern, $replace, $text);
    }

    // Блок 2, Завд. 8: Усунення надмірної кількості великих літер (робимо капс маленьким)
    public static function fixExcessiveCaps($text) {
        return preg_replace_callback('/\b[A-ZА-ЯІЇЄҐ]{2,}\b/u', function($matches) {
            return mb_convert_case($matches[0], MB_CASE_TITLE, "UTF-8");
        }, $text);
    }

    // Блок 2, Завд. 29: Заміна пробілів в назвах файлів на «_» (підкреслення)
    public static function fixFilenames($filename) {
        return preg_replace('/[ ]+/', '_', $filename);
    }

    // Блок 3, Завд. 6: Витягти посилання з HTML-документів
    public static function extractLinks($html) {
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);
        return $matches[1] ?? [];
    }
}
?>