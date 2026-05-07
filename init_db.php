<?php
try {
    $dbPath = __DIR__ . '/freelance.sqlite';
    $pdo = new PDO("sqlite:" . $dbPath);
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        price INTEGER NOT NULL
    )");

    $pdo->exec("DELETE FROM services");

    $services = [
        ['name' => 'Лендінг пейдж', 'price' => 3000],
        ['name' => 'Дизайн логотипу', 'price' => 1500],
        ['name' => 'Налаштування реклами', 'price' => 2000]
    ];

    $stmt = $pdo->prepare("INSERT INTO services (name, price) VALUES (:name, :price)");
    foreach ($services as $service) {
        $stmt->execute($service);
    }

    echo "✅ Базу даних 'freelance.sqlite' успішно створено та заповнено послугами!";
} catch (PDOException $e) {
    echo "❌ Помилка БД: " . $e->getMessage();
}