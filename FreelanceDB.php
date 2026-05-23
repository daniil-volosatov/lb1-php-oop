<?php
namespace App\Database;

use PDO;
use PDOException;


interface DatabaseAdapter {
    public function connect();
}

class SqliteAdapter implements DatabaseAdapter {
    private $filename;
    public function __construct($filename = 'freelance.sqlite') {
        $this->filename = $filename;
    }
    public function connect() {
        $pdo = new PDO("sqlite:" . $this->filename);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}

class MongoAdapter implements DatabaseAdapter {
    public function connect() {
        // Заглушка для демонстрації можливості підключення іншої БД
        return "Connected to MongoDB";
    }
}


interface DatabaseInterface {
    public function getAllServices();
    public function addService($name, $price);
}

class FreelanceDB implements DatabaseInterface {
    private $pdo;

    // Клас тепер приймає Адаптер
    public function __construct(DatabaseAdapter $adapter) {
        try {
            $this->pdo = $adapter->connect();
            
            if ($this->pdo instanceof PDO) {
                $this->pdo->beginTransaction();
                $this->createTables();
                $this->seedData();
                $this->pdo->commit(); 
            }
        } catch (PDOException $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack(); 
            }
            die("Неможливо створити БД. Помилка: " . $e->getMessage());
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

    private function seedData() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM services");
        if ($stmt->fetchColumn() == 0) {
            $services = [
                ['Лендінг пейдж', 3000],
                ['Дизайн логотипу', 1500],
                ['Налаштування реклами', 2000]
            ];
            $insertStmt = $this->pdo->prepare("INSERT INTO services (name, price) VALUES (?, ?)");
            foreach ($services as $service) {
                $insertStmt->execute([$service[0], $service[1]]);
            }
        }
    }

    public function getAllServices() {
        if (!$this->pdo instanceof PDO) return [];
        try {
            $stmt = $this->pdo->query("SELECT * FROM services");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function addService($name, $price) {
        if (!$this->pdo instanceof PDO) return false;
        $stmt = $this->pdo->prepare("INSERT INTO services (name, price) VALUES (?, ?)");
        return $stmt->execute([$name, $price]);
    }
}

// ---------------------------------------------------------
// ПАТЕРН: DECORATOR (Декоратор) - Вимога 6
// ---------------------------------------------------------
class LoggerDecorator implements DatabaseInterface {
    protected $db;
    
    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }
    
    public function getAllServices() {
        // Логування перед дією
        error_log("[LOG] Запит на отримання всіх послуг з БД");
        return $this->db->getAllServices();
    }
    
    public function addService($name, $price) {
        // Логування перед записом до БД
        error_log("[LOG] Спроба запису моделі: {$name} - {$price} грн");
        return $this->db->addService($name, $price);
    }
}
?>