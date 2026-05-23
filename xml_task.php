<?php
$xmlFile = 'freelancers.xml';

//ЧАСТИНА 1: DOM (Вимога 4)
$dom = new DOMDocument('1.0', 'utf-8');
$dom->formatOutput = true;

// Перевіряємо, чи існує XML-документ
if (file_exists($xmlFile)) {
    $dom->load($xmlFile);
    $root = $dom->documentElement;
} else {
    // Якщо не існує, створюємо кореневий елемент 'users'
    $root = $dom->createElement('users');
    $dom->appendChild($root);
    
    // Додаємо тестові дані для перевірки
    $user1 = $dom->createElement('user');
    $user1->appendChild($dom->createElement('name', 'Іван Фрилансер'));
    $user1->appendChild($dom->createElement('role', 'Дизайнер'));
    $root->appendChild($user1);
    
    $user2 = $dom->createElement('user');
    $user2->appendChild($dom->createElement('name', 'Олена Кодер'));
    $user2->appendChild($dom->createElement('role', 'Програміст'));
    $root->appendChild($user2);
    
    $dom->save($xmlFile);
}

// --- ЧАСТИНА 2: SAX парсер (Вимоги 1, 2, 3) ---
echo "<h2>Список користувачів платформи (SAX Parser)</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 50%;'>";
echo "<tr style='background-color: #5621d1; color: white;'><th>Поле</th><th>Значення</th></tr>";

$parser = xml_parser_create();
$currentTag = "";

// Функція-обробник початкових тегів
function startElement($parser, $name, $attrs) {
    global $currentTag;
    $currentTag = $name;
    if ($name == "USER") {
        echo "<tr style='background:#f2f2f2;'><td colspan='2'><b>Новий запис:</b></td></tr>";
    }
}

// Функція-обробник кінцевих тегів
function endElement($parser, $name) {
    global $currentTag;
    $currentTag = "";
}

// Функція-обробник текстового вмісту
function characterData($parser, $data) {
    global $currentTag;
    $data = trim($data);
    if (!empty($data) && $currentTag != "USERS" && $currentTag != "USER") {
        echo "<tr><td>{$currentTag}</td><td>{$data}</td></tr>";
    }
}

// Реєстрація обробників
xml_set_element_handler($parser, "startElement", "endElement");
xml_set_character_data_handler($parser, "characterData");

// Запуск парсера
$fp = fopen($xmlFile, "r");
while ($data = fread($fp, 4096)) {
    xml_parse($parser, $data, feof($fp));
}
fclose($fp);
xml_parser_free($parser);
echo "</table>";
?>