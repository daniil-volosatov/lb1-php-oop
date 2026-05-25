<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/classes.php';
require_once __DIR__ . '/Validator.php';

use App\Database\DatabaseInterface;
use App\Database\FreelanceDB;
use App\Database\LoggerDecorator;
use App\Database\SqliteAdapter;
use App\Utils\Validator;

final class Router
{
    private static ?Router $instance = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function getInstance(): Router
    {
        if (self::$instance === null) {
            self::$instance = new Router();
        }

        return self::$instance;
    }

    public function getRoute(): string
    {
        return isset($_GET['page']) ? (string) $_GET['page'] : 'shop';
    }
}

final class FrontController
{
    private DatabaseInterface $db;
    private VisitCounter $visitCounter;

    public function __construct()
    {
        // Adapter + Decorator patterns: swap database adapters and log writes.
        $adapter = new SqliteAdapter(__DIR__ . '/freelance.sqlite');
        $this->db = new LoggerDecorator(new FreelanceDB($adapter));
        $this->visitCounter = new VisitCounter($this->db);
    }

    public function handleRequest(): void
    {
        // MVC controller: orchestrates requests, models, and views.
        $this->initialiseCart();
        $visitSnapshot = $this->visitCounter->captureVisit();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $this->handlePostAction((string) $_POST['action']);
        }

        $pageType = Router::getInstance()->getRoute();
        $flash = $this->consumeFlash();

        if ($pageType === 'cart') {
            $page = new CartPage('Мій кошик');
        } else {
            $searchQuery = $this->getSearchQuery();
            $loadError = null;
            $services = $this->loadServices($searchQuery, $loadError);
            $page = new ShopPage('Головна | Послуги', $services, $searchQuery, $loadError);
        }

        if ($flash !== null) {
            $page->setFlashMessage($flash['message'], $flash['type']);
        }

        $page->setVisitSnapshot($visitSnapshot);
        $page->renderAll();
        $this->db->closeConnection();
    }

    private function initialiseCart(): void
    {
        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
    }

    private function handlePostAction(string $action): void
    {
        switch ($action) {
            case 'add_to_cart':
                $this->handleAddToCart();
                break;
            case 'update_cart':
                $this->handleUpdateCart();
                break;
            case 'clear_cart':
                $this->handleClearCart();
                break;
            case 'submit_feedback':
                $this->handleFeedback();
                break;
        }
    }

    private function handleAddToCart(): void
    {
        $serviceId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $qty = filter_input(INPUT_POST, 'qty', FILTER_VALIDATE_INT);
        $quantity = $qty !== false && $qty !== null ? max(1, $qty) : 1;

        if ($serviceId === false || $serviceId === null) {
            $this->setFlash('Некоректний ідентифікатор послуги.', 'error');
            $this->redirect('shop');
        }

        try {
            $service = $this->db->getServiceById($serviceId);
        } catch (RuntimeException $exception) {
            $this->setFlash($exception->getMessage(), 'error');
            $this->redirect('shop');
        }
        if ($service === null) {
            $this->setFlash('Послугу не знайдено.', 'error');
            $this->redirect('shop');
        }

        $id = (string) $service['id'];
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id]['qty'] += $quantity;
        } else {
            $_SESSION['cart'][$id] = [
                'name' => (string) $service['name'],
                'price' => (float) $service['price'],
                'qty' => $quantity,
            ];
        }

        $this->setFlash('Послугу додано до кошика.', 'success');
        $this->redirect('cart');
    }

    private function handleUpdateCart(): void
    {
        if (isset($_POST['remove_id'])) {
            $id = (string) $_POST['remove_id'];
            unset($_SESSION['cart'][$id]);
            $this->setFlash('Послугу видалено з кошика.', 'success');
            $this->redirect('cart');
        }

        if (!isset($_POST['cart_qty']) || !is_array($_POST['cart_qty'])) {
            $this->setFlash('Немає даних для оновлення кошика.', 'error');
            $this->redirect('cart');
        }

        foreach ($_POST['cart_qty'] as $id => $qty) {
            $cartId = (string) $id;
            $quantity = (int) $qty;

            if (!isset($_SESSION['cart'][$cartId])) {
                continue;
            }

            if ($quantity <= 0) {
                unset($_SESSION['cart'][$cartId]);
                continue;
            }

            $_SESSION['cart'][$cartId]['qty'] = $quantity;
        }

        $this->setFlash('Кошик оновлено.', 'success');
        $this->redirect('cart');
    }

    private function handleClearCart(): void
    {
        $_SESSION['cart'] = [];
        $this->setFlash('Кошик очищено.', 'success');
        $this->redirect('cart');
    }

    private function handleFeedback(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($name === '' || $message === '' || !Validator::validateEmail($email)) {
            $this->setFlash('Перевірте ім’я, email та повідомлення.', 'error');
            $this->redirect('shop');
        }

        try {
            $this->db->addFeedback($name, $email, $message);
            $this->setFlash('Дякуємо! Ваше повідомлення надіслано.', 'success');
        } catch (RuntimeException $exception) {
            $this->setFlash($exception->getMessage(), 'error');
        }

        $this->redirect('shop');
    }

    private function loadServices(?string $searchQuery, ?string &$loadError): array
    {
        try {
            $serviceRows = $searchQuery !== null && $searchQuery !== ''
                ? $this->db->searchServices($searchQuery)
                : $this->db->getAllServices();
        } catch (RuntimeException $exception) {
            $loadError = $exception->getMessage();
            return [];
        }

        $services = [];
        foreach ($serviceRows as $service) {
            $services[] = ServiceFactory::create(
                (int) $service['id'],
                (string) $service['name'],
                (int) $service['price']
            );
        }

        return $services;
    }

    private function getSearchQuery(): ?string
    {
        if (!isset($_GET['q'])) {
            return null;
        }

        $query = trim((string) $_GET['q']);
        return $query === '' ? null : $query;
    }

    private function setFlash(string $message, string $type): void
    {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type,
        ];
    }

    private function consumeFlash(): ?array
    {
        if (!isset($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $flash;
    }

    private function redirect(string $page): void
    {
        header('Location: index.php?page=' . $page);
        exit;
    }
}

try {
    $app = new FrontController();
    $app->handleRequest();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo '<h1>Something went wrong.</h1>';
    echo '<p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
