<?php
declare(strict_types=1);

require_once __DIR__ . '/FreelanceDB.php';

use App\Database\FreelanceDB;
use App\Database\SqliteAdapter;

try {
    $adapter = new SqliteAdapter(__DIR__ . '/freelance.sqlite');
    new FreelanceDB($adapter);
    echo "✅ Базу даних 'freelance.sqlite' успішно ініціалізовано!";
} catch (RuntimeException $exception) {
    echo '❌ Помилка БД: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}
