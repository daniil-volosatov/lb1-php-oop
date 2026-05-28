<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

interface DatabaseAdapter
{
    public function connect(): PDO;
    public function disconnect(): void;
}

final class SqliteAdapter implements DatabaseAdapter
{
    private string $filename;
    private ?PDO $pdo = null;

    public function __construct(string $filename = 'freelance.sqlite')
    {
        $this->filename = $filename;
    }

    public function connect(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $pdo = new PDO('sqlite:' . $this->filename);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo = $pdo;

        return $this->pdo;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }
}

final class MySqlAdapter implements DatabaseAdapter
{
    private string $dsn;
    private string $user;
    private string $password;
    private ?PDO $pdo = null;

    public function __construct(string $host, string $database, string $user, string $password, int $port = 3306)
    {
        $this->dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $this->user = $user;
        $this->password = $password;
    }

    public function connect(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $pdo = new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo = $pdo;

        return $this->pdo;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }
}

final class MongoAdapter implements DatabaseAdapter
{
    public function connect(): PDO
    {
        throw new RuntimeException('Mongo adapter is illustrative and not configured for PDO use.');
    }

    public function disconnect(): void
    {
    }
}

interface DatabaseInterface
{
    public function getAllServices(): array;
    public function searchServices(string $query): array;
    public function getServiceById(int $id): ?array;
    public function addService(string $name, int $price): bool;
    public function deleteService(int $id): bool;
    public function addFeedback(string $name, string $email, string $message): bool;
    public function recordVisit(string $visitorId, bool $isNewSession): void;
    public function getVisitStats(): array;
    public function getVisitorStats(string $visitorId): array;
    
    public function createUser(string $name, string $email, string $passwordHash, string $role = 'user'): bool;
    public function getUserByEmail(string $email): ?array;
    public function updateUserAvatar(int $userId, string $avatarPath): bool;
    public function createOrder(int $userId, float $totalPrice, array $cartItems): bool;
    public function getAllOrders(): array;
    public function getOrderItems(int $orderId): array;
    
    public function closeConnection(): void;
}

final class FreelanceDB implements DatabaseInterface
{
    private DatabaseAdapter $adapter;
    private ?PDO $pdo = null;

    public function __construct(DatabaseAdapter $adapter)
    {
        $this->adapter = $adapter;
        $this->pdo = $adapter->connect();

        try {
            $this->pdo->beginTransaction();
            $this->createTables();
            $this->seedData();
            $this->ensureVisitStats();
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $this->wrapPdoException($exception, null, 'Unable to initialise the database.');
        }
    }

    public function closeConnection(): void
    {
        $this->pdo = null;
        $this->adapter->disconnect();
    }

    public function getAllServices(): array
    {
        $this->ensureConnection();

        try {
            $statement = $this->pdo->query('SELECT id, name, price FROM services ORDER BY id ASC');
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load services.');
        }
    }

    public function searchServices(string $query): array
    {
        $this->ensureConnection();

        if ($query === '') {
            return $this->getAllServices();
        }

        try {
            $statement = $this->pdo->prepare('SELECT id, name, price FROM services WHERE name LIKE :query ORDER BY id ASC');
            $statement->execute([':query' => '%' . $query . '%']);
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to search services.');
        }
    }

    public function getServiceById(int $id): ?array
    {
        $this->ensureConnection();

        try {
            $statement = $this->pdo->prepare('SELECT id, name, price FROM services WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $id]);
            $result = $statement->fetch();
            return $result === false ? null : $result;
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load the requested service.');
        }
    }

    public function addService(string $name, int $price): bool
    {
        $this->ensureConnection();

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare('INSERT INTO services (name, price) VALUES (:name, :price)');
            $statement->execute([
                ':name' => $name,
                ':price' => $price,
            ]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to add the service.');
        }
    }

    public function deleteService(int $id): bool
    {
        $this->ensureConnection();

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare('DELETE FROM services WHERE id = :id');
            $statement->execute([':id' => $id]);
            $this->pdo->commit();
            return $statement->rowCount() > 0;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to delete the service.');
        }
    }

    public function addFeedback(string $name, string $email, string $message): bool
    {
        $this->ensureConnection();

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                'INSERT INTO feedback (name, email, message, created_at) VALUES (:name, :email, :message, :created_at)'
            );
            $statement->execute([
                ':name' => $name,
                ':email' => $email,
                ':message' => $message,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to save the feedback.');
        }
    }

    public function recordVisit(string $visitorId, bool $isNewSession): void
    {
        $this->ensureConnection();

        try {
            $this->pdo->beginTransaction();

            $updateStatsSql = $isNewSession
                ? 'UPDATE visit_stats SET total_visits = total_visits + 1, total_hits = total_hits + 1, updated_at = :updated_at WHERE id = 1'
                : 'UPDATE visit_stats SET total_hits = total_hits + 1, updated_at = :updated_at WHERE id = 1';
            $statsStatement = $this->pdo->prepare($updateStatsSql);
            $statsStatement->execute([':updated_at' => date('Y-m-d H:i:s')]);

            $visitorStatement = $this->pdo->prepare(
                'INSERT INTO visit_visitors (visitor_id, visits, hits, last_visit_at)
                VALUES (:visitor_id, :visits, :hits, :last_visit_at)
                ON CONFLICT(visitor_id) DO UPDATE SET
                    visits = visits + :visit_delta,
                    hits = hits + 1,
                    last_visit_at = :last_visit_at'
            );
            $visitorStatement->execute([
                ':visitor_id' => $visitorId,
                ':visits' => $isNewSession ? 1 : 0,
                ':visit_delta' => $isNewSession ? 1 : 0,
                ':hits' => 1,
                ':last_visit_at' => date('Y-m-d H:i:s'),
            ]);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, null, 'Unable to record visit statistics.');
        }
    }

    public function getVisitStats(): array
    {
        $this->ensureConnection();

        try {
            $statement = $this->pdo->query('SELECT total_visits, total_hits, updated_at FROM visit_stats WHERE id = 1');
            $stats = $statement->fetch();

            if ($stats === false) {
                throw new RuntimeException('Visit statistics are unavailable.');
            }

            return $stats;
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load visit statistics.');
        }
    }

    public function getVisitorStats(string $visitorId): array
    {
        $this->ensureConnection();

        try {
            $statement = $this->pdo->prepare('SELECT visits, hits, last_visit_at FROM visit_visitors WHERE visitor_id = :visitor_id');
            $statement->execute([':visitor_id' => $visitorId]);
            $stats = $statement->fetch();

            if ($stats === false) {
                return [
                    'visits' => 0,
                    'hits' => 0,
                    'last_visit_at' => null,
                ];
            }

            return $stats;
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load visitor statistics.');
        }
    }

    public function createUser(string $name, string $email, string $passwordHash, string $role = 'user'): bool
    {
        $this->ensureConnection();
        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:name, :email, :password_hash, :role, :created_at)');
            $statement->execute([
                ':name' => $name,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':role' => $role,
                ':created_at' => date('Y-m-d H:i:s')
            ]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to register user (Email may already exist).');
        }
    }

    public function getUserByEmail(string $email): ?array
    {
        $this->ensureConnection();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $statement->execute([':email' => $email]);
            $result = $statement->fetch();
            return $result === false ? null : $result;
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load user.');
        }
    }

    public function updateUserAvatar(int $userId, string $avatarPath): bool
    {
        $this->ensureConnection();
        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare('UPDATE users SET avatar_path = :avatar_path WHERE id = :id');
            $statement->execute([
                ':avatar_path' => $avatarPath,
                ':id' => $userId
            ]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to update avatar.');
        }
    }

    public function createOrder(int $userId, float $totalPrice, array $cartItems): bool
    {
        $this->ensureConnection();
        try {
            $this->pdo->beginTransaction();
            
            $orderStmt = $this->pdo->prepare('INSERT INTO orders (user_id, total_price, created_at) VALUES (:user_id, :total_price, :created_at)');
            $orderStmt->execute([
                ':user_id' => $userId,
                ':total_price' => $totalPrice,
                ':created_at' => date('Y-m-d H:i:s')
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare('INSERT INTO order_items (order_id, service_name, qty, price) VALUES (:order_id, :service_name, :qty, :price)');
            foreach ($cartItems as $item) {
                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':service_name' => $item['name'],
                    ':qty' => (int)$item['qty'],
                    ':price' => (float)$item['price']
                ]);
            }

            $this->pdo->commit();
            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrapPdoException($exception, null, 'Unable to create order.');
        }
    }

    public function getAllOrders(): array
    {
        $this->ensureConnection();
        try {
            $statement = $this->pdo->query('
                SELECT o.id, o.total_price, o.created_at, u.name as user_name, u.email as user_email
                FROM orders o
                JOIN users u ON o.user_id = u.id
                ORDER BY o.created_at DESC
            ');
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load orders.');
        }
    }

    public function getOrderItems(int $orderId): array
    {
        $this->ensureConnection();
        try {
            $statement = $this->pdo->prepare('SELECT service_name, qty, price FROM order_items WHERE order_id = :order_id');
            $statement->execute([':order_id' => $orderId]);
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw $this->wrapPdoException($exception, $statement ?? null, 'Unable to load order items.');
        }
    }

    private function createTables(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price INTEGER NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS visit_stats (
            id INTEGER PRIMARY KEY,
            total_visits INTEGER NOT NULL DEFAULT 0,
            total_hits INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS visit_visitors (
            visitor_id TEXT PRIMARY KEY,
            visits INTEGER NOT NULL DEFAULT 0,
            hits INTEGER NOT NULL DEFAULT 0,
            last_visit_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "user",
            avatar_path TEXT,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            total_price REAL NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            service_name TEXT NOT NULL,
            qty INTEGER NOT NULL,
            price REAL NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )');
    }

    private function seedData(): void
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM services');
        $count = (int) $statement->fetchColumn();

        if ($count === 0) {
            $services = [
                ['Лендінг пейдж', 3000],
                ['Дизайн логотипу', 1500],
                ['Налаштування реклами', 2000],
            ];
            $insertStatement = $this->pdo->prepare('INSERT INTO services (name, price) VALUES (:name, :price)');

            foreach ($services as $service) {
                $insertStatement->execute([
                    ':name' => $service[0],
                    ':price' => $service[1],
                ]);
            }
        }
        
        $adminStmt = $this->pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"');
        if ((int) $adminStmt->fetchColumn() === 0) {
            $insertAdmin = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:name, :email, :password_hash, :role, :created_at)');
            $insertAdmin->execute([
                ':name' => 'Admin',
                ':email' => 'admin@store.com',
                ':password_hash' => password_hash('12345678', PASSWORD_DEFAULT),
                ':role' => 'admin',
                ':created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    private function ensureVisitStats(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO visit_stats (id, total_visits, total_hits, updated_at) VALUES (1, 0, 0, :updated_at)'
        );
        $statement->execute([':updated_at' => date('Y-m-d H:i:s')]);
    }

    private function ensureConnection(): void
    {
        if (!$this->pdo instanceof PDO) {
            throw new RuntimeException('Database connection is not available.');
        }
    }

    private function wrapPdoException(PDOException $exception, ?PDOStatement $statement, string $fallbackMessage): RuntimeException
    {
        $errorCode = $statement ? $statement->errorCode() : $this->pdo?->errorCode();
        $errorInfo = $statement ? $statement->errorInfo() : $this->pdo?->errorInfo();
        $details = $errorInfo ? implode(' | ', $errorInfo) : 'No detailed error info available.';

        error_log(sprintf(
            '[DB] %s (errorCode=%s, errorInfo=%s)',
            $exception->getMessage(),
            $errorCode ?? 'unknown',
            $details
        ));

        return new RuntimeException(sprintf('%s (errorCode=%s).', $fallbackMessage, $errorCode ?? 'unknown'), 0, $exception);
    }
}

final class LoggerDecorator implements DatabaseInterface
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function getAllServices(): array
    {
        error_log('[DB] Loading all services.');
        return $this->db->getAllServices();
    }

    public function searchServices(string $query): array
    {
        error_log(sprintf('[DB] Searching services: "%s".', $query));
        return $this->db->searchServices($query);
    }

    public function getServiceById(int $id): ?array
    {
        return $this->db->getServiceById($id);
    }

    public function addService(string $name, int $price): bool
    {
        error_log(sprintf('[DB] Writing service "%s" (%d).', $name, $price));
        return $this->db->addService($name, $price);
    }

    public function deleteService(int $id): bool
    {
        error_log(sprintf('[DB] Deleting service ID %d.', $id));
        return $this->db->deleteService($id);
    }

    public function addFeedback(string $name, string $email, string $message): bool
    {
        error_log(sprintf('[DB] Writing feedback from "%s".', $email));
        return $this->db->addFeedback($name, $email, $message);
    }

    public function recordVisit(string $visitorId, bool $isNewSession): void
    {
        if ($isNewSession) {
            error_log(sprintf('[DB] Recording new visit for visitor %s.', $visitorId));
        }

        $this->db->recordVisit($visitorId, $isNewSession);
    }

    public function getVisitStats(): array
    {
        return $this->db->getVisitStats();
    }

    public function getVisitorStats(string $visitorId): array
    {
        return $this->db->getVisitorStats($visitorId);
    }
    
    public function createUser(string $name, string $email, string $passwordHash, string $role = 'user'): bool
    {
        error_log(sprintf('[DB] Registering new user: %s.', $email));
        return $this->db->createUser($name, $email, $passwordHash, $role);
    }

    public function getUserByEmail(string $email): ?array
    {
        return $this->db->getUserByEmail($email);
    }

    public function updateUserAvatar(int $userId, string $avatarPath): bool
    {
        error_log(sprintf('[DB] Updating avatar for user ID %d.', $userId));
        return $this->db->updateUserAvatar($userId, $avatarPath);
    }

    public function createOrder(int $userId, float $totalPrice, array $cartItems): bool
    {
        error_log(sprintf('[DB] Creating order for user ID %d. Total: %f.', $userId, $totalPrice));
        return $this->db->createOrder($userId, $totalPrice, $cartItems);
    }

    public function getAllOrders(): array
    {
        error_log('[DB] Loading all orders for admin.');
        return $this->db->getAllOrders();
    }

    public function getOrderItems(int $orderId): array
    {
        return $this->db->getOrderItems($orderId);
    }

    public function closeConnection(): void
    {
        $this->db->closeConnection();
    }
}