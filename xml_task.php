<?php
declare(strict_types=1);

$xmlFile = 'freelancers.xml';

// 1. ОБРОБКА ФОРМИ: Якщо користувач відправив дані
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['name']) && !empty($_POST['message'])) {
    
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;
    $dom->preserveWhiteSpace = false;

    
    if (file_exists($xmlFile)) {
        $dom->load($xmlFile);
        $root = $dom->documentElement;
    } else {
        $root = $dom->createElement('users');
        $dom->appendChild($root);
    }

    
    $newUser = $dom->createElement('user');
    
    // Записуємо Ім'я
    $nameNode = $dom->createElement('name', htmlspecialchars(trim($_POST['name'])));
    $newUser->appendChild($nameNode);
    
    // Записуємо Текст (Повідомлення)
    $messageNode = $dom->createElement('message', htmlspecialchars(trim($_POST['message'])));
    $newUser->appendChild($messageNode);
    
  
    $root->appendChild($newUser);
    $dom->save($xmlFile);

    // Оновлюємо сторінку, щоб уникнути дублювання при натисканні F5
    header("Location: xml_task.php");
    exit;
}

// Якщо файлу ще взагалі немає, створюємо порожній базовий файл
if (!file_exists($xmlFile)) {
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;
    $root = $dom->createElement('users');
    $dom->appendChild($root);
    $dom->save($xmlFile);
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XML Гостьова книга</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="background-color: #f4f7f6; font-family: Arial, sans-serif; padding: 20px;">

    <div style="max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        
        <h2 style="color: #333; margin-top: 0;">Додати запис у XML (DOM)</h2>
        <p style="color: #666; font-size: 0.9rem;">Введіть дані, і вони будуть збережені у файл <b>freelancers.xml</b>.</p>
        
        <form method="POST" action="xml_task.php" style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 40px; border: 1px solid #eee; padding: 20px; border-radius: 6px; background: #fafafa;">
            <div>
                <label style="font-weight: bold; font-size: 0.9rem;">Ваше ім'я:</label><br>
                <input type="text" name="name" placeholder="Наприклад: Нікіта" required style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div>
                <label style="font-weight: bold; font-size: 0.9rem;">Текст повідомлення:</label><br>
                <input type="text" name="message" placeholder="Наприклад: Привіт!" required style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <button type="submit" class="btn btn-primary" style="background-color: #5621d1; color: white; padding: 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Зберегти в XML</button>
        </form>

        <h2 style="color: #333;">Збережені записи (SAX Parser)</h2>
        <table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>
            <tr style='background-color: #5621d1; color: white;'>
                <th style="width: 30%;">Поле (Тег)</th>
                <th>Текст</th>
            </tr>
            
            <?php
            // 2. ЧИТАННЯ ДАНИХ ЧЕРЕЗ SAX PARSER
            $parser = xml_parser_create();
            $currentTag = '';

            // Обробник відкриття тегу
            function startElement($parser, $name, $attrs): void {
                global $currentTag;
                $currentTag = $name;
                if ($name === 'USER') {
                    echo "<tr style='background-color: #f1f1f1;'><td colspan='2' style='text-align:center; color: #5621d1; font-size: 0.9rem;'><b>Новий запис</b></td></tr>";
                }
            }

            // Обробник закриття тегу
            function endElement($parser, $name): void {
                global $currentTag;
                $currentTag = '';
            }

            // Обробник тексту всередині тегу
            function characterData($parser, $data): void {
                global $currentTag;
                $data = trim($data);
                if ($data !== '' && $currentTag !== 'USERS' && $currentTag !== 'USER') {
                    $safeTag = htmlspecialchars($currentTag, ENT_QUOTES, 'UTF-8');
                    $safeData = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
                    echo "<tr><td><b>{$safeTag}</b></td><td>{$safeData}</td></tr>";
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
            ?>
        </table>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="index.php" style="color: #5621d1; text-decoration: none; font-weight: bold;">&larr; Повернутися на головну сайту</a>
        </div>
    </div>

</body>
</html>