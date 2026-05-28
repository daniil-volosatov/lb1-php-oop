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

    private function __construct() {}
    private function __clone() {}

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
        $adapter = new SqliteAdapter(__DIR__ . '/freelance.sqlite');
        $this->db = new LoggerDecorator(new FreelanceDB($adapter));
        $this->visitCounter = new VisitCounter($this->db);
        
        $this->checkAuthCookie();
    }

    private function checkAuthCookie(): void
    {
        if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_user'])) {
            $email = (string)$_COOKIE['remember_user'];
            $user = $this->db->getUserByEmail($email);
            if ($user) {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
            }
        }
    }

    public function handleRequest(): void
    {
        $this->initialiseCart();
        $visitSnapshot = $this->visitCounter->captureVisit();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $this->handlePostAction((string) $_POST['action']);
        }

        $pageType = Router::getInstance()->getRoute();
        $flash = $this->consumeFlash();

        switch ($pageType) {
            case 'cart':
                $page = new CartPage('Мій кошик');
                break;
            case 'login':
                $page = new LoginPage('Вхід в систему');
                break;
            case 'register':
                $page = new RegisterPage('Реєстрація');
                break;
            case 'profile':
                if (!isset($_SESSION['user_id'])) $this->redirect('login');
                $page = new ProfilePage('Мій профіль', $this->db);
                break;
                case 'chat':
                if (!isset($_SESSION['user_id'])) {
                    $this->setFlash('Увійдіть в систему, щоб користуватися чатом.', 'error');
                    $this->redirect('login');
                }
                $page = new ChatPage('Freelance Чат');
                break;
            case 'admin':
                if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') $this->redirect('shop');
                $orders = $this->db->getAllOrders();
                foreach ($orders as &$order) {
                    $order['items'] = $this->db->getOrderItems((int)$order['id']);
                }
                $page = new AdminPage('Панель адміністратора', $orders);
                break;
            default:
                // ЦЕЙ БЛОК ВІДПОВІДАЄ ЗА ГОЛОВНУ СТОРІНКУ (SHOP)
                $searchQuery = $this->getSearchQuery();
                $loadError = null;
                $services = $this->loadServices($searchQuery, $loadError);
                $page = new ShopPage('Головна | Послуги', $services, $searchQuery, $loadError);
                break;
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
            case 'add_to_cart': $this->handleAddToCart(); break;
            case 'update_cart': $this->handleUpdateCart(); break;
            case 'clear_cart': $this->handleClearCart(); break;
            case 'checkout': $this->handleCheckout(); break;
            case 'submit_feedback': $this->handleFeedback(); break;
            case 'login': $this->handleLogin(); break;
            case 'register': $this->handleRegister(); break;
            case 'logout': $this->handleLogout(); break;
            case 'update_profile': $this->handleUpdateProfile(); break;
            case 'add_service': $this->handleAddService(); break;
        }
    }

    private function handleAddService(): void
    {
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            $this->redirect('shop');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_INT);

        if ($name === '' || $price === false || $price <= 0) {
            $this->setFlash('Помилка: Назва не може бути порожньою, а ціна має бути більшою за нуль.', 'error');
        } else {
            try {
                $this->db->addService($name, $price);
                $this->setFlash('Послугу успішно додано до каталогу!', 'success');
            } catch (RuntimeException $e) {
                $this->setFlash($e->getMessage(), 'error');
            }
        }
        $this->redirect('admin');
    }

    private function handleLogin(): void
    {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $remember = isset($_POST['remember']);

        if (!Validator::validateEmail($email)) {
            $this->setFlash('Некоректний формат email.', 'error');
            $this->redirect('login');
        }

        $user = $this->db->getUserByEmail($email);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

            if ($remember) {
                setcookie('remember_user', $email, time() + (86400 * 30), "/");
            }

            $this->setFlash('Ви успішно увійшли!', 'success');
            $this->redirect('shop');
        } else {
            $this->setFlash('Невірний email або пароль.', 'error');
            $this->redirect('login');
        }
    }

    private function handleRegister(): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password_confirm = (string)($_POST['password_confirm'] ?? '');

        if ($name === '' || !Validator::validateEmail($email)) {
            $this->setFlash('Перевірте правильність заповнення імені та email.', 'error');
            $this->redirect('register');
        }

        if (!Validator::validatePassword($password)) {
            $this->setFlash('Пароль має містити мінімум 8 символів.', 'error');
            $this->redirect('register');
        }

        if ($password !== $password_confirm) {
            $this->setFlash('Паролі не співпадають.', 'error');
            $this->redirect('register');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $this->db->createUser($name, $email, $hash);
            $this->setFlash('Реєстрація успішна! Тепер ви можете увійти.', 'success');
            $this->redirect('login');
        } catch (RuntimeException $e) {
            $this->setFlash($e->getMessage(), 'error');
            $this->redirect('register');
        }
    }

    private function handleLogout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email'], $_SESSION['user_role']);
        setcookie('remember_user', '', time() - 3600, '/');
        $this->setFlash('Ви вийшли з системи.', 'info');
        $this->redirect('shop');
    }

    // ФОТОГАЛЕРЕЯ ДЛЯ ПРОФІЛЮ 
    private function handleUpdateProfile(): void
    {
        if (!isset($_SESSION['user_id'])) $this->redirect('login');

        $messages = [];
        $errors = [];

        if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadDir = __DIR__ . '/images/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileName = Validator::validateImageUpload($_FILES['avatar']);
                $dest = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                    $avatarPath = 'images/avatars/' . $fileName;
                    $this->db->updateUserAvatar($_SESSION['user_id'], $avatarPath);
                    $messages[] = 'Фото профілю успішно оновлено!';
                } else {
                    $errors[] = 'Не вдалося зберегти файл фото профілю.';
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $galleryFiles = $this->normaliseUploadedFiles($_FILES['gallery_images'] ?? []);
        if (!empty($galleryFiles)) {
            $galleryDir = __DIR__ . '/images/gallery/user_' . $_SESSION['user_id'] . '/';
            if (!is_dir($galleryDir)) {
                mkdir($galleryDir, 0777, true);
            }

            $uploadedCount = 0;
            foreach ($galleryFiles as $file) {
                try {
                    $fileName = Validator::validateImageUpload($file);
                    $dest = $galleryDir . $fileName;
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $errors[] = sprintf('Не вдалося зберегти файл %s.', $file['name']);
                        continue;
                    }

                    $galleryPath = 'images/gallery/user_' . $_SESSION['user_id'] . '/' . $fileName;
                    $this->db->addUserGalleryImage($_SESSION['user_id'], $galleryPath);
                    $uploadedCount++;
                } catch (RuntimeException $e) {
                    $errors[] = $file['name'] !== '' ? $file['name'] . ': ' . $e->getMessage() : $e->getMessage();
                }
            }

            if ($uploadedCount > 0) {
                $messages[] = sprintf('Додано фото в галерею: %d.', $uploadedCount);
            }
        }

        if (!empty($errors)) {
            $prefix = $messages ? implode(' ', $messages) . ' ' : '';
            $this->setFlash($prefix . 'Деякі файли не вдалося завантажити: ' . implode('; ', $errors), 'error');
        } elseif (!empty($messages)) {
            $this->setFlash(implode(' ', $messages), 'success');
        } else {
            $this->setFlash('Файли не вибрано.', 'error');
        }

        $this->redirect('profile');
    }

    private function normaliseUploadedFiles(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return [];
        }

        $normalised = [];
        foreach ($files['name'] as $index => $name) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE && $name === '') {
                continue;
            }

            $normalised[] = [
                'name' => $name ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $error,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalised;
    }

    // ОФОРМЛЕННЯ ЗАМОВЛЕННЯ
    private function handleCheckout(): void
    {
        if (!isset($_SESSION['user_id'])) {
            $this->setFlash('Будь ласка, увійдіть в систему, щоб оформити замовлення.', 'error');
            $this->redirect('login');
        }

        if (empty($_SESSION['cart'])) {
            $this->setFlash('Ваш кошик порожній.', 'error');
            $this->redirect('cart');
        }

        $total = 0.0;
        $strategy = new DiscountPriceStrategy();
        foreach ($_SESSION['cart'] as $item) {
            $basePrice = $item['price'] * $item['qty'];
            $total += $strategy->calculate($basePrice);
        }

        try {
            $this->db->createOrder($_SESSION['user_id'], $total, $_SESSION['cart']);
            $_SESSION['cart'] = []; // Очищаємо кошик після покупки
            $this->setFlash('Дякуємо! Ваше замовлення успішно оформлено.', 'success');
            $this->redirect('profile');
        } catch (RuntimeException $e) {
            $this->setFlash('Помилка при оформленні замовлення: ' . $e->getMessage(), 'error');
            $this->redirect('cart');
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
    echo '<h1>Щось пішло не так.</h1>';
    echo '<p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
