<?php
// 9. Оголошення простору імен
namespace App\Database;

use PDO;
use PDOException;

class FreelanceDB {
    private $pdo;

    public function __construct($filename = 'freelance.sqlite') {
        try {
            // 1. З'єднання за допомогою PDO
            $this->pdo = new PDO("sqlite:" . $filename);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 2 & 5. Транзакція в конструкторі
            $this->pdo->beginTransaction();
            $this->createTables();
            $this->seedData();
            $this->pdo->commit(); // Фіксуємо зміни
            
        } catch (PDOException $e) {
            // Скасовуємо транзакцію у разі помилки
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack(); 
            }
            // 4 & 5. Відстеження помилок та повідомлення в браузер
            die("Неможливо створити базу даних. Помилка: " . $e->getMessage() . " | Код помилки: " . $e->getCode());
        }
    }

    private function createTables() {
        $query = "CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price INTEGER NOT NULL
        )";
        $this->pdo->exec($query);
    }

    // Заповнення початковими даними, якщо база порожня
    private function seedData() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM services");
        if ($stmt->fetchColumn() == 0) {
            $services = [
                ['Лендінг пейдж', 3000],
                ['Дизайн логотипу', 1500],
                ['Налаштування реклами', 2000]
            ];
            // 3. Повторювані вставки з використанням підготовлених запитів
            $insertStmt = $this->pdo->prepare("INSERT INTO services (name, price) VALUES (?, ?)");
            foreach ($services as $service) {
                $insertStmt->execute([$service[0], $service[1]]);
            }
        }
    }

    public function getAllServices() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM services");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // 4. Використання errorInfo
            $errorInfo = $this->pdo->errorInfo();
            echo "Помилка вибірки: " . $errorInfo[2];
            return [];
        }
    }
}
?>