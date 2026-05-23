<?php
require_once 'Validator.php';
use App\Utils\Validator;

echo "<h2>Тест Регулярних виразів (ЛБ-3, Оцінка 5)</h2>";

// Обов'язкові завдання
echo "<h3>Обов'язкові завдання</h3>";
$text = "Це **важливе** повідомлення!";
echo "<p>Форматування HTML: " . Validator::textToHtml($text) . "</p>";

$email = "test@nure.ua";
echo "<p>Email ($email) коректний? " . (Validator::validateEmail($email) ? "Так" : "Ні") . "</p>";

// Блок 1 (Варіант 14 та 15)
echo "<h3>Блок 1</h3>";
$parts = Validator::extractEmailParts("nikita@gmail.com");
echo "<p>Завдання 14 (Email parts): Нік: {$parts['nickname']}, Домен: {$parts['domain']}, Суфікс: {$parts['suffix']}</p>";

$textToReplace = "Я дуже люблю кодинг, кодинг це круто!";
echo "<p>Завдання 15 (Заміна): " . Validator::replaceString($textToReplace, "кодинг", "гроші") . "</p>";

// Блок 2 (Завдання 8 та 29)
echo "<h3>Блок 2</h3>";
$capsText = "КУПИТИ зараз СУПЕР пропозиція";
echo "<p>Завдання 8 (Усунення КАПСУ): " . Validator::fixExcessiveCaps($capsText) . "</p>";

$filename = "my best photo from 2026.jpg";
echo "<p>Завдання 29 (Файли без пробілів): " . Validator::fixFilenames($filename) . "</p>";

// Блок 3 (Завдання 6)
echo "<h3>Блок 3</h3>";
$html = '<a href="https://github.com">GitHub</a> і <a href="/about.php">Про нас</a>';
$links = Validator::extractLinks($html);
echo "<p>Завдання 6 (Посилання з HTML): " . implode(', ', $links) . "</p>";
?>