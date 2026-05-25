<?php
declare(strict_types=1);

$xmlFile = 'freelancers.xml';

// Part 1: DOM initialisation and XML bootstrapping.
$dom = new DOMDocument('1.0', 'utf-8');
$dom->formatOutput = true;

if (file_exists($xmlFile)) {
    $dom->load($xmlFile);
    $root = $dom->documentElement;
} else {
    $root = $dom->createElement('users');
    $dom->appendChild($root);

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

// Part 2: SAX parser output as HTML table.
echo "<h2>Список користувачів платформи (SAX Parser)</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 50%;'>";
echo "<tr style='background-color: #5621d1; color: white;'><th>Поле</th><th>Значення</th></tr>";

$parser = xml_parser_create();
$currentTag = '';

function startElement($parser, $name, $attrs): void
{
    global $currentTag;
    $currentTag = $name;
    if ($name === 'USER') {
        echo "<tr style='background:#f2f2f2;'><td colspan='2'><b>Новий запис:</b></td></tr>";
    }
}

function endElement($parser, $name): void
{
    global $currentTag;
    $currentTag = '';
}

function characterData($parser, $data): void
{
    global $currentTag;
    $data = trim($data);
    if ($data !== '' && $currentTag !== 'USERS' && $currentTag !== 'USER') {
        $safeTag = htmlspecialchars($currentTag, ENT_QUOTES, 'UTF-8');
        $safeData = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        echo "<tr><td>{$safeTag}</td><td>{$safeData}</td></tr>";
    }
}

xml_set_element_handler($parser, 'startElement', 'endElement');
xml_set_character_data_handler($parser, 'characterData');

$handle = fopen($xmlFile, 'r');
if ($handle !== false) {
    while ($data = fread($handle, 4096)) {
        xml_parse($parser, $data, feof($handle));
    }
    fclose($handle);
}

xml_parser_free($parser);
echo '</table>';
