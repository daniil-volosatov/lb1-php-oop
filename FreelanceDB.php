<?php
class FreelanceDB {
    private $db;

    public function __construct($filename = 'freelance.sqlite') {
        try {
            $this->db = new SQLite3($filename);
            $this->db->enableExceptions(true); 
            $this->createTables();
        } catch (Exception $e) {
            die("Помилка підключення до БД: " . $e->getMessage());
        }
    }

    private function createTables() {
        try {
            $query = "CREATE TABLE IF NOT EXISTS services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price INTEGER NOT NULL
            )";
            $this->db->exec($query);
        } catch (Exception $e) {
            echo "Помилка створення таблиці: " . $e->getMessage();
        }
    }

    public function addService($name, $price) {
        try {
            $stmt = $this->db->prepare("INSERT INTO services (name, price) VALUES (:name, :price)");
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':price', $price, SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getAllServices() {
        $results = [];
        try {
            $res = $this->db->query("SELECT * FROM services");
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
        } catch (Exception $e) {
            echo "Помилка вибірки: " . $e->getMessage();
        }
        return $results;
    }

    public function deleteService($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM services WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            echo "Помилка видалення: " . $e->getMessage();
            return false;
        }
    }
}
?>