<?php
session_start();
require_once 'classes.php';


class Router {
    private static $instance = null;
    
    private function __construct() {} // Закритий конструктор
    private function __clone() {}     // Заборона клонування
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Router();
        }
        return self::$instance;
    }
    
    // Метод визначення маршруту
    public function getRoute() {
        return $_GET['page'] ?? 'shop';
    }
}


class FrontController {
    public function handleRequest() {
        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        // 1. Обробка бізнес-логіки (POST-запити для кошика)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $action = $_POST['action'];

            if ($action === 'add_to_cart') {
                $id = (string) $_POST['product_id'];
                $qty = max(1, (int) ($_POST['qty'] ?? 1));

                if (isset($_SESSION['cart'][$id])) {
                    $_SESSION['cart'][$id]['qty'] += $qty;
                } else {
                    $_SESSION['cart'][$id] = [
                        'name' => $_POST['product_name'],
                        'price' => (float) $_POST['product_price'],
                        'qty' => $qty
                    ];
                }
            }

            if (isset($_POST['remove_id'])) {
                $id = (string) $_POST['remove_id'];
                unset($_SESSION['cart'][$id]);
            }

            if ($action === 'update_cart' && isset($_POST['cart_qty']) && is_array($_POST['cart_qty'])) {
                foreach ($_POST['cart_qty'] as $id => $qty) {
                    $id = (string) $id;
                    $qty = (int) $qty;

                    if (!isset($_SESSION['cart'][$id])) {
                        continue;
                    }

                    if ($qty <= 0) {
                        unset($_SESSION['cart'][$id]);
                        continue;
                    }

                    $_SESSION['cart'][$id]['qty'] = $qty;
                }
            }

            if ($action === 'clear_cart') {
                $_SESSION['cart'] = [];
            }

            // Редирект для уникнення повторного POST при оновленні сторінки
            $redirectPage = ($action === 'add_to_cart' || isset($_POST['remove_id']) || $action === 'clear_cart') ? 'cart' : ($_GET['page'] ?? 'cart');
            header("Location: index.php?page=" . $redirectPage);
            exit;
        }

        // 2. Маршрутизація (Singleton Router)
        $router = Router::getInstance();
        $pageType = $router->getRoute();

        // 3. Формування View (Відображення)
        if ($pageType === 'cart') {
            $page = new CartPage("Мій кошик");
        } else {
            $page = new ShopPage("Головна | Послуги");
        }
        
        $page->renderAll();
    }
}

// Запуск нашого MVC додатку
$app = new FrontController();
$app->handleRequest();
?>