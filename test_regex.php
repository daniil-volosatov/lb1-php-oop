<?php
declare(strict_types=1);

require_once __DIR__ . '/Validator.php';

use App\Utils\Validator;

echo '<h2>Тест регулярних виразів (ЛБ-3)</h2>';

echo '<h3>Обов’язкові завдання</h3>';
$text = 'Це **важливе** повідомлення!';
echo '<p>Форматування HTML: ' . Validator::textToHtml($text) . '</p>';

$email = 'test@nure.ua';
echo '<p>Email (' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . ') коректний? ' . (Validator::validateEmail($email) ? 'Так' : 'Ні') . '</p>';


echo '<h3>Блок 1</h3>';
$parts = Validator::extractEmailParts('nikita@gmail.com');
if ($parts !== null) {
    echo '<p>Завдання 14 (Email parts): Нік: ' . htmlspecialchars($parts['nickname'], ENT_QUOTES, 'UTF-8') .
        ', Домен: ' . htmlspecialchars($parts['domain'], ENT_QUOTES, 'UTF-8') .
        ', Суфікс: ' . htmlspecialchars($parts['suffix'], ENT_QUOTES, 'UTF-8') . '</p>';
}

$textToReplace = 'Я дуже люблю кодинг, кодинг це круто!';
echo '<p>Завдання 15 (Заміна): ' . Validator::replaceString($textToReplace, 'кодинг', 'гроші') . '</p>';

echo '<h3>Блок 2</h3>';
$capsText = 'КУПИТИ зараз СУПЕР пропозиція';
echo '<p>Завдання 8 (Усунення КАПСУ): ' . Validator::fixExcessiveCaps($capsText) . '</p>';

$filename = 'my best photo from 2026.jpg';
echo '<p>Завдання 29 (Файли без пробілів): ' . Validator::fixFilenames($filename) . '</p>';

