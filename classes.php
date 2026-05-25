<?php
require_once 'FreelanceDB.php';


use App\Database\SqliteAdapter;
use App\Database\FreelanceDB;
use App\Database\LoggerDecorator;


class ServiceModel {
    public $id;
    public $name;
    public $price;
    public function __construct($id, $name, $price) {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
    }
}

class ServiceFactory {
    public static function create($id, $name, $price) {
        return new ServiceModel($id, $name, $price);
    }
}


interface PriceStrategy {
    public function calculate($price);
}
class SimplePriceStrategy implements PriceStrategy {
    public function calculate($price) { return $price; } // Стандартна ціна
}
class DiscountPriceStrategy implements PriceStrategy {
    public function calculate($price) { return $price * 0.90; } // Знижка 10%
}


class WebPage {
    protected $title;
    public function __construct($title) { $this->title = $title; }

    protected function e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
    
    public function renderHeader() {
        // Зверни увагу! Посилання тепер йдуть через роутер MVC (index.php?page=...)
        echo "<!DOCTYPE html><html lang='uk'><head><meta charset='UTF-8'><title>{$this->title}</title>
        <link rel='stylesheet' href='style.css'></head><body><div class='container'><header>
        <h1>Freelance Store</h1><nav>
        <a href='index.php?page=shop'>Послуги</a> 
        <a href='index.php?page=cart'>Кошик</a>
        <a href='xml_task.php'>XML-Користувачі</a> 
        <a href='chat.php' target='_blank'>Чат</a>
        </nav></header>";
    }
    
    public function renderFooter() {
        echo "<footer><hr><p>&copy; 2026 Всі права захищено.</p></footer></div></body></html>";
    }
    
    public function renderBody() { 
        echo "<main><p>Порожня сторінка</p></main>"; 
    }
    
    public function renderAll() {
        $this->renderHeader(); 
        $this->renderBody(); 
        $this->renderFooter();
    }
}

class ShopPage extends WebPage {
    private $products;
    
    public function __construct($title) {
        parent::__construct($title);
        $this->products = [];
        
        // Використовуємо ADAPTER та DECORATOR
        $adapter = new SqliteAdapter('freelance.sqlite');
        $db = new LoggerDecorator(new FreelanceDB($adapter));
        
        $services = $db->getAllServices();
        
        foreach ($services as $service) {
            // Використовуємо FACTORY
            $model = ServiceFactory::create($service['id'], $service['name'], $service['price']);
            $this->products[$model->id] = $model;
        }
    }
    
    public function renderBody() {
        echo "<main><h2>Доступні послуги</h2>";
        foreach ($this->products as $id => $product) {
            echo "<div class='product'><h3>{$product->name}</h3><p>Ціна: {$product->price} грн</p>
            <form method='POST' action='index.php'>
            <input type='hidden' name='action' value='add_to_cart'>
            <input type='hidden' name='product_id' value='{$id}'>
            <input type='hidden' name='product_name' value='{$product->name}'>
            <input type='hidden' name='product_price' value='{$product->price}'>
            Кількість: <input type='number' name='qty' value='1' min='1'>
            <button type='submit' name='buy'>Купити</button>
            </form></div>";
        }
        echo "</main>";
    }
}

class CartPage extends WebPage {
    public function renderBody() {
        echo "<main><h2>Ваш кошик</h2><p>Знижка 10% застосовується до всієї суми.</p>";
        if (empty($_SESSION['cart'])) {
            echo "<p>Кошик порожній. <a href='index.php?page=shop'>Перейти до покупок</a></p>";
        } else {
            echo "<form method='POST' action='index.php?page=cart'>";
            echo "<input type='hidden' name='action' value='update_cart'>";
            echo "<table><tr><th>Послуга</th><th>Ціна</th><th>Кількість</th><th>Сума</th><th>Дія</th></tr>";
            $total = 0;
            
            // Використовуємо STRATEGY для розрахунку (застосовуємо знижку 10%)
            $strategy = new DiscountPriceStrategy();

            foreach ($_SESSION['cart'] as $id => $item) {
                $basePrice = $item['price'] * $item['qty'];
                $discountedPrice = $strategy->calculate($basePrice);
                $total += $discountedPrice;
                
                $safeId = $this->e($id);
                $safeName = $this->e($item['name']);
                $safePrice = number_format((float) $item['price'], 0, '.', '');
                $safeDiscounted = number_format((float) $discountedPrice, 2, '.', '');
                $safeQty = (int) $item['qty'];

                echo "<tr>";
                echo "<td>{$safeName}</td>";
                echo "<td>{$safePrice} грн</td>";
                echo "<td><input class='qty-input' type='number' name='cart_qty[{$safeId}]' value='{$safeQty}' min='0'></td>";
                echo "<td><b class='cart-total'>{$safeDiscounted} грн</b></td>";
                echo "<td><button class='btn btn-danger' type='submit' name='remove_id' value='{$safeId}'>Видалити</button></td>";
                echo "</tr>";
            }
            $finalTotal = number_format((float) $total, 2, '.', '');
            echo "</table>";
            echo "<div class='cart-summary'><p>Разом до сплати: <b>{$finalTotal} грн</b></p></div>";
            echo "<div class='cart-actions'>";
            echo "<button class='btn btn-primary' type='submit'>Оновити кількість</button>";
            echo "</form>";
            echo "<form method='POST' action='index.php?page=cart' class='inline-form'>";
            echo "<input type='hidden' name='action' value='clear_cart'>";
            echo "<button class='btn btn-danger' type='submit'>Очистити кошик</button>";
            echo "</form>";
            echo "<a class='btn btn-secondary' href='index.php?page=shop'>Продовжити покупки</a>";
            echo "</div>";
        }
        echo "</main>";
    }
}
?>