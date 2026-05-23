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
        // 1. Обробка бізнес-логіки (POST-запит для кошика)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
            $id = $_POST['product_id'];
            $_SESSION['cart'][$id] = [
                'name' => $_POST['product_name'],
                'price' => $_POST['product_price'],
                'qty' => $_POST['qty']
            ];
            // Редирект для уникнення повторного POST при оновленні сторінки
            header("Location: index.php?page=cart");
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