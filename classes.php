<?php
class WebPage {
    protected $title;

    public function __construct($title) {
        $this->title = $title;
    }

    public function renderHeader() {
        echo "<!DOCTYPE html>
        <html lang='uk'>
        <head>
            <meta charset='UTF-8'>
            <title>{$this->title}</title>
            <link rel='stylesheet' href='style.css'>
        </head>
        <body>
        <div class='container'>
            <header>
                <h1>Freelance Store</h1>
                <nav>
                    <a href='index.php'>Послуги</a>
                    <a href='cart.php'>Кошик</a>
                </nav>
            </header>";
    }

    public function renderFooter() {
        echo "<footer><hr><p>&copy; 2026 Всі права захищено.</p></footer>
        </div>
        </body></html>";
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
        $this->products = [
            1 => ['name' => 'Лендінг пейдж', 'price' => 3000],
            2 => ['name' => 'Дизайн логотипу', 'price' => 1500],
            3 => ['name' => 'Налаштування реклами', 'price' => 2000]
        ];
    }

    public function renderBody() {
        echo "<main><h2>Доступні послуги</h2>";
        foreach ($this->products as $id => $product) {
            echo "<div class='product'>
                <h3>{$product['name']}</h3>
                <p>Ціна: <b>{$product['price']} грн</b></p>
                <form method='POST' action='index.php'>
                    <input type='hidden' name='product_id' value='{$id}'>
                    <input type='hidden' name='product_name' value='{$product['name']}'>
                    <input type='hidden' name='product_price' value='{$product['price']}'>
                    Кількість: <input type='number' name='qty' value='1' min='1'>
                    <button type='submit' name='buy'>Купити</button>
                </form>
            </div>";
        }
        echo "</main>";
    }
}

class CartPage extends WebPage {
    public function renderBody() {
        echo "<main><h2>Ваш кошик</h2>";
        
        if (empty($_SESSION['cart'])) {
            echo "<p>Кошик порожній. <a href='index.php'>Перейти до покупок</a></p>";
        } else {
            echo "<table>
                <tr><th>Послуга</th><th>Ціна</th><th>Кількість</th><th>Сума</th></tr>";
            $total = 0;
            foreach ($_SESSION['cart'] as $item) {
                $sum = $item['price'] * $item['qty'];
                $total += $sum;
                echo "<tr>
                    <td>{$item['name']}</td>
                    <td>{$item['price']} грн</td>
                    <td>{$item['qty']}</td>
                    <td>{$sum} грн</td>
                </tr>";
            }
            echo "</table>";
            echo "<h3>Разом до сплати: {$total} грн</h3>";
        }
        echo "</main>";
    }
}
?>