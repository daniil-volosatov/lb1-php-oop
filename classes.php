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
        echo "<main><h2>Ваш кошик (Діє знижка 10%!)</h2>";
        if (empty($_SESSION['cart'])) {
            echo "<p>Кошик порожній. <a href='index.php'>Перейти до покупок</a></p>";
        } else {
            echo "<table><tr><th>Послуга</th><th>Базова ціна</th><th>Кількість</th><th>Сума (Зі знижкою)</th></tr>";
            $total = 0;
            
            // Використовуємо STRATEGY для розрахунку (застосовуємо знижку 10%)
            $strategy = new DiscountPriceStrategy();

            foreach ($_SESSION['cart'] as $item) {
                $basePrice = $item['price'] * $item['qty'];
                $discountedPrice = $strategy->calculate($basePrice);
                $total += $discountedPrice;
                
                echo "<tr><td>{$item['name']}</td><td>{$item['price']} грн</td>
                <td>{$item['qty']}</td><td><b style='color:green;'>{$discountedPrice} грн</b></td></tr>";
            }
            echo "</table><p>Разом до сплати: <b>{$total} грн</b></p>";
        }
        echo "</main>";
    }
}
?>