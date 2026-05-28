<?php
declare(strict_types=1);

require_once __DIR__ . '/FreelanceDB.php';

use App\Database\DatabaseInterface;

final class ServiceModel
{
    public int $id;
    public string $name;
    public int $price;

    public function __construct(int $id, string $name, int $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
    }
}

final class ServiceFactory
{
    public static function create(int $id, string $name, int $price): ServiceModel
    {
        return new ServiceModel($id, $name, $price);
    }
}

interface PriceStrategy
{
    public function calculate(float $price): float;
}

final class SimplePriceStrategy implements PriceStrategy
{
    public function calculate(float $price): float
    {
        return $price;
    }
}

final class DiscountPriceStrategy implements PriceStrategy
{
    public function calculate(float $price): float
    {
        return $price * 0.90; // Знижка 10%
    }
}

final class VisitSnapshot
{
    public int $totalVisits;
    public int $totalHits;
    public int $visitorVisits;
    public int $visitorHits;

    public function __construct(int $totalVisits, int $totalHits, int $visitorVisits, int $visitorHits)
    {
        $this->totalVisits = $totalVisits;
        $this->totalHits = $totalHits;
        $this->visitorVisits = $visitorVisits;
        $this->visitorHits = $visitorHits;
    }
}

final class VisitCounter
{
    private DatabaseInterface $db;
    private string $visitorCookieName = 'freelance_visitor_id';
    private int $sessionWindowSeconds = 900;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function captureVisit(): VisitSnapshot
    {
        $visitorId = $this->getOrCreateVisitorId();
        $isNewSession = $this->isNewSession();

        $this->db->recordVisit($visitorId, $isNewSession);
        $stats = $this->db->getVisitStats();
        $visitorStats = $this->db->getVisitorStats($visitorId);

        return new VisitSnapshot(
            (int) ($stats['total_visits'] ?? 0),
            (int) ($stats['total_hits'] ?? 0),
            (int) ($visitorStats['visits'] ?? 0),
            (int) ($visitorStats['hits'] ?? 0)
        );
    }

    private function getOrCreateVisitorId(): string
    {
        if (isset($_COOKIE[$this->visitorCookieName]) && $_COOKIE[$this->visitorCookieName] !== '') {
            return (string) $_COOKIE[$this->visitorCookieName];
        }

        $visitorId = bin2hex(random_bytes(16));
        setcookie($this->visitorCookieName, $visitorId, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
            'httponly' => true,
        ]);

        return $visitorId;
    }

    private function isNewSession(): bool
    {
        $now = time();
        $lastCounted = isset($_SESSION['visit_counted_at']) ? (int) $_SESSION['visit_counted_at'] : null;

        if ($lastCounted === null || ($now - $lastCounted) > $this->sessionWindowSeconds) {
            $_SESSION['visit_counted_at'] = $now;
            return true;
        }

        return false;
    }
}

class WebPage
{
    protected string $title;
    private ?VisitSnapshot $visitSnapshot = null;
    private ?array $flash = null;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function setVisitSnapshot(VisitSnapshot $snapshot): void
    {
        $this->visitSnapshot = $snapshot;
    }

    public function setFlashMessage(?string $message, string $type = 'info'): void
    {
        if ($message === null || $message === '') {
            $this->flash = null;
            return;
        }

        $this->flash = [
            'message' => $message,
            'type' => $type,
        ];
    }

    public function renderHeader(): void
    {
        echo "<!DOCTYPE html><html lang='uk'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
        echo "<title>{$this->escape($this->title)}</title>";
        echo "<link rel='icon' type='image/png' href='images/Logo-nobackground.png'>";
        echo "<link rel='stylesheet' href='style.css'>";
        echo "</head><body><div class='container'>";
        echo "<header class='header' role='banner'>";
        echo "  <a class='brand' href='index.php?page=shop' aria-label='Freelance Store home'>";
        echo "    <span class='brand-logo'><img src='images/Logo-white-background.png' alt='Freelance Store logo'></span>";
        echo "    <span class='brand-copy'>";
        echo "      <span class='brand-title'><span class='brand-accent'>Freelance</span> Магазин</span>";
        echo "      <span class='brand-subtitle'>Сучасні IT-послуги для вашого бізнесу</span>";
        echo "    </span>";
        echo "  </a>";
        echo "  <nav class='nav' role='navigation' aria-label='Main navigation'>";
        echo "    <a href='index.php?page=shop'>Послуги</a>";
        echo "    <a href='index.php?page=cart'>Кошик</a>";
        echo "    <a href='xml_task.php'>XML-Дані</a>";
        
        if (isset($_SESSION['user_id'])) {
            echo "    <a href='index.php?page=chat'>Чат</a>"; // Додали кнопку чату сюди
            echo "    <a href='index.php?page=profile' style='color: var(--colour-primary); font-weight: bold;'>Профіль (" . $this->escape($_SESSION['user_name']) . ")</a>";
            if ($_SESSION['user_role'] === 'admin') {
                echo "    <a href='index.php?page=admin' style='color: red;'>CRM (Адмін)</a>";
            }
            echo "    <form method='POST' action='index.php' style='display:inline;'><input type='hidden' name='action' value='logout'><button type='submit' class='btn btn-ghost' style='padding: 5px 10px;'>Вийти</button></form>";
        } else {
            echo "    <a href='index.php?page=login'>Увійти</a>";
            echo "    <a href='index.php?page=register'>Реєстрація</a>";
        }

        echo "  </nav>";
        echo "</header>";

        if ($this->flash !== null) {
            $flashType = $this->flash['type'] === 'success' ? 'alert--success' : 'alert--error';
            echo "<div class='alert {$flashType}' role='status'>{$this->escape($this->flash['message'])}</div>";
        }

        echo "<main role='main'>";
    }

    public function renderFooter(): void
    {
        if ($this->visitSnapshot instanceof VisitSnapshot) {
            $totalVisits = number_format($this->visitSnapshot->totalVisits);
            $totalHits = number_format($this->visitSnapshot->totalHits);
            $visitorVisits = number_format($this->visitSnapshot->visitorVisits);
            $visitorHits = number_format($this->visitSnapshot->visitorHits);

            echo "<section class='visit-stats' aria-label='Visit statistics'>";
            echo "  <div><strong>Усього відвідувань:</strong> {$totalVisits}</div>";
            echo "  <div><strong>Усього переглядів:</strong> {$totalHits}</div>";
            echo "  <div><strong>Ваші візити:</strong> {$visitorVisits}</div>";
            echo "  <div><strong>Ваші перегляди:</strong> {$visitorHits}</div>";
            echo "</section>";
        }

        echo "</main>";
        echo "<footer class='page-footer'>";
        echo "  <div>&copy; 2026 Freelance Store | Усі права захищено</div>";
        echo "  <div class='muted'>Створено з турботою • Доступно для всіх</div>";
        echo "</footer>";
        echo "</div></body></html>";
    }

    public function renderBody(): void
    {
        echo "<section class='empty-state centre'><p class='muted' style='margin:0'>Порожня сторінка</p></section>";
    }

    public function renderAll(): void
    {
        $this->renderHeader();
        $this->renderBody();
        $this->renderFooter();
    }
}

// СТОРІНКА ПРОФІЛЮ ТА ФОТОГАЛЕРЕЯ АВАТАРА
final class ProfilePage extends WebPage
{
    private DatabaseInterface $db;

    public function __construct(string $title, DatabaseInterface $db)
    {
        parent::__construct($title);
        $this->db = $db;
    }

    public function renderBody(): void
    {
        // Безпечно отримуємо email з сесії. Якщо його немає - беремо порожній рядок
        $email = $_SESSION['user_email'] ?? '';
        $user = $email !== '' ? $this->db->getUserByEmail($email) : null;

        // Якщо користувача не знайдено через стару сесію - просимо перезайті
        if (!$user) {
            echo "<section class='empty-state centre' style='padding: 50px;'>
                    <h3>Сесія застаріла</h3>
                    <p class='muted'>Будь ласка, натисніть кнопку «Вийти» в меню зверху і увійдіть в систему заново.</p>
                  </section>";
            return;
        }

        $avatarHtml = '';
        if (!empty($user['avatar_path'])) {
            $avatarHtml = "<img src='{$this->escape($user['avatar_path'])}' alt='Аватар' style='width: 150px; height: 150px; border-radius: 50%; object-fit: cover; display: block; margin: 0 auto 20px;'>";
        } else {
            $avatarHtml = "<div style='width: 150px; height: 150px; border-radius: 50%; background: #eee; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: #999;'>Немає фото</div>";
        }

        echo "<section class='page-title'><div><h1>Особистий кабінет</h1></div></section>";
        echo "<section class='feedback-card' style='max-width: 600px; margin: 0 auto; text-align: center;'>";
        echo $avatarHtml;
        echo "<h3>{$this->escape($user['name'])}</h3>";
        echo "<p class='muted'>Email: {$this->escape($user['email'])}</p>";
        echo "<p class='muted'>Роль: " . ($user['role'] === 'admin' ? 'Адміністратор' : 'Клієнт') . "</p>";
        
        echo "<hr style='margin: 20px 0; border-top: 1px solid #eee;'>";
        echo "<h4>Оновити фото профілю</h4>";
        echo "<form method='POST' action='index.php' enctype='multipart/form-data' class='feedback-form' style='display: flex; flex-direction: column; align-items: center;'>";
        echo "  <input type='hidden' name='action' value='update_profile'>";
        echo "  <input type='file' name='avatar' accept='image/png, image/jpeg, image/gif' required style='margin-bottom: 15px;'>";
        echo "  <button class='btn btn-primary' type='submit'>Завантажити фото</button>";
        echo "</form>";
        echo "</section>";
    }
}
// СТОРІНКА АДМІНІСТРАТОРА (CRM)
// СТОРІНКА АДМІНІСТРАТОРА (CRM)
final class AdminPage extends WebPage
{
    private array $orders;

    public function __construct(string $title, array $orders)
    {
        parent::__construct($title);
        $this->orders = $orders;
    }

    public function renderBody(): void
    {
        echo "<section class='page-title'><div><h1>CRM Панель адміністратора</h1><p class='section-lead'>Керування каталогом та перегляд замовлень.</p></div></section>";
        
        // ФОРМА ДОДАВАННЯ НОВОЇ ПОСЛУГИ
        echo "<section class='feedback-card' style='margin-bottom: 30px;'>";
        echo "  <h3 style='margin-top:0'>Додати нову послугу</h3>";
        echo "  <form method='POST' action='index.php' class='feedback-form' style='display:flex; gap:10px; align-items:flex-end; flex-wrap: wrap;'>";
        echo "    <input type='hidden' name='action' value='add_service'>";
        echo "    <div class='form-field' style='flex: 2; min-width: 200px;'><label>Назва послуги</label><input name='name' type='text' required></div>";
        echo "    <div class='form-field' style='flex: 1; min-width: 100px;'><label>Ціна (грн)</label><input name='price' type='number' min='1' required></div>";
        echo "    <button class='btn btn-primary' type='submit' style='margin-bottom: 15px;'>Додати до каталогу</button>";
        echo "  </form>";
        echo "</section>";

        // РОЗШИРЕНА ТАБЛИЦЯ ЗАМОВЛЕНЬ
        echo "<section class='cart-wrap'>";
        echo "  <h3 style='margin-top:0'>Історія замовлень клієнтів</h3>";
        if (empty($this->orders)) {
            echo "<div class='empty-state centre'><p class='muted'>Поки що немає жодного замовлення.</p></div>";
        } else {
            echo "<table class='cart-table' role='table'>";
            echo "  <thead><tr><th>ID</th><th>Клієнт (Email)</th><th>Придбані послуги</th><th>Загальна сума</th><th>Дата</th></tr></thead>";
            echo "  <tbody>";
            foreach ($this->orders as $order) {
                echo "<tr>";
                echo "<td>#{$order['id']}</td>";
                echo "<td><strong>{$this->escape($order['user_name'])}</strong><br><small class='muted'>{$this->escape($order['user_email'])}</small></td>";
                
                // Виводимо конкретні послуги з цього замовлення
                echo "<td><ul style='margin:0; padding-left:20px; font-size: 0.9rem;'>";
                foreach ($order['items'] as $item) {
                    $itemPrice = number_format((float)$item['price'], 0, '.', '');
                    echo "<li>{$this->escape($item['service_name'])} — {$item['qty']} шт. (по {$itemPrice} грн)</li>";
                }
                echo "</ul></td>";

                echo "<td><b style='color: var(--colour-primary)'>" . number_format((float)$order['total_price'], 2, '.', '') . " грн</b></td>";
                echo "<td>{$order['created_at']}</td>";
                echo "</tr>";
            }
            echo "  </tbody>";
            echo "</table>";
        }
        echo "</section>";
    }
}

final class LoginPage extends WebPage
{
    public function renderBody(): void
    {
        echo "<section class='feedback-card' style='max-width: 500px; margin: 0 auto;'>";
        echo "  <h2 class='section-title'>Вхід в систему</h2>";
        echo "  <form method='POST' action='index.php' class='feedback-form'>";
        echo "    <input type='hidden' name='action' value='login'>";
        echo "    <div class='form-field'><label>Ваш Email</label><input name='email' type='email' required></div>";
        echo "    <div class='form-field'><label>Пароль</label><input name='password' type='password' required></div>";
        echo "    <div class='form-field'><label style='display:flex; align-items:center;'><input type='checkbox' name='remember' style='width:auto; margin-right:10px;'> Запам'ятати мене</label></div>";
        echo "    <button class='btn btn-primary' style='width: 100%' type='submit'>Увійти</button>";
        echo "  </form>";
        echo "  <p style='text-align:center; margin-top:15px;'>Немає акаунту? <a href='index.php?page=register'>Зареєструватися</a></p>";
        echo "</section>";
    }
}

final class RegisterPage extends WebPage
{
    public function renderBody(): void
    {
        echo "<section class='feedback-card' style='max-width: 500px; margin: 0 auto;'>";
        echo "  <h2 class='section-title'>Створення акаунту</h2>";
        echo "  <form method='POST' action='index.php' class='feedback-form'>";
        echo "    <input type='hidden' name='action' value='register'>";
        echo "    <div class='form-field'><label>Ім'я та Прізвище</label><input name='name' type='text' required></div>";
        echo "    <div class='form-field'><label>Email (для входу)</label><input name='email' type='email' required></div>";
        echo "    <div class='form-field'><label>Пароль (мін. 8 символів)</label><input name='password' type='password' minlength='8' required></div>";
        echo "    <div class='form-field'><label>Підтвердіть пароль</label><input name='password_confirm' type='password' minlength='8' required></div>";
        echo "    <button class='btn btn-primary' style='width: 100%' type='submit'>Зареєструватися</button>";
        echo "  </form>";
        echo "</section>";
    }
}

final class ShopPage extends WebPage
{
    private array $products;
    private ?string $searchQuery;
    private ?string $loadError;

    public function __construct(string $title, array $services, ?string $searchQuery = null, ?string $loadError = null)
    {
        parent::__construct($title);
        $this->products = $services;
        $this->searchQuery = $searchQuery;
        $this->loadError = $loadError;
    }

    public function renderBody(): void
    {
        $safeSearch = $this->searchQuery !== null ? $this->escape($this->searchQuery) : '';

        echo "<section class='page-title'><div><h1>Доступні IT-послуги</h1><p class='section-lead'>Оберіть необхідну послугу та додайте її до кошика.</p></div></section>";
        echo "<form method='GET' action='index.php' class='search-form' aria-label='Пошук послуг'>";
        echo "  <input type='hidden' name='page' value='shop'>";
        echo "  <label class='sr-only' for='service-search'>Пошук послуг</label>";
        echo "  <input id='service-search' type='text' name='q' value='{$safeSearch}' placeholder='Пошук за назвою...' />";
        echo "  <button class='btn btn-ghost' type='submit'>Знайти</button>";
        echo "</form>";

        if ($this->loadError !== null) {
            echo "<div class='alert alert--error' role='alert'>{$this->escape($this->loadError)}</div>";
        }

        if (empty($this->products)) {
            echo "<div class='state-note centre'><p class='muted' style='margin:0'>Послуг не знайдено. Спробуйте інший запит.</p></div>";
        }

        echo "<section class='products-grid' aria-live='polite'>";
        foreach ($this->products as $product) {
            $safeId = $this->escape((string) $product->id);
            $safeName = $this->escape($product->name);
            $safePrice = number_format((float) $product->price, 0, '.', '');
            echo "<article class='card' role='article'>";
            echo "  <div class='meta'><h3>{$safeName}</h3><div class='price'>{$safePrice} грн</div></div>";
            echo "  <p class='desc'>Професійна послуга, адаптована під ваші бізнес-потреби.</p>";
            echo "  <div class='card-footer'>";
            echo "    <form method='POST' action='index.php' class='cart-form'>";
            echo "      <input type='hidden' name='action' value='add_to_cart'>";
            echo "      <input type='hidden' name='product_id' value='{$safeId}'>";
            echo "      <div class='qty-field'><label class='sr-only' for='qty-{$safeId}'>Кількість</label><input id='qty-{$safeId}' type='number' name='qty' value='1' min='1' aria-label='Quantity'></div>";
            echo "      <button class='btn btn-primary' type='submit'>В кошик</button>";
            echo "    </form>";
            echo "  </div>";
            echo "</article>";
        }
        echo "</section>";

        // ПРИХОВУЄМО ЗВОРОТНИЙ ЗВ'ЯЗОК ДЛЯ АВТОРИЗОВАНИХ
        if (!isset($_SESSION['user_id'])) {
            echo "<section class='feedback-card'>";
            echo "  <h2 class='section-title'>Зворотний зв’язок</h2>";
            echo "  <p class='section-lead'>Напишіть нам, якщо потрібна індивідуальна консультація.</p>";
            echo "  <form method='POST' action='index.php?page=shop' class='feedback-form'>";
            echo "    <input type='hidden' name='action' value='submit_feedback'>";
            echo "    <div class='form-grid'>";
            echo "      <div class='form-field'><label for='feedback-name'>Ваше ім’я</label><input id='feedback-name' name='name' type='text' required></div>";
            echo "      <div class='form-field'><label for='feedback-email'>Email</label><input id='feedback-email' name='email' type='email' required></div>";
            echo "    </div>";
            echo "    <div class='form-field'><label for='feedback-message'>Повідомлення</label><textarea id='feedback-message' name='message' rows='4' required></textarea></div>";
            echo "    <button class='btn btn-primary' type='submit'>Надіслати запит</button>";
            echo "  </form>";
            echo "</section>";
        }
    }
}

final class CartPage extends WebPage
{
    public function renderBody(): void
    {
        echo "<section class='page-title'><div><h1 class='cart-heading'>Ваш кошик</h1><p class='section-lead'>Знижка 10% автоматично застосовується до всієї суми.</p></div></section>";
        echo "<section class='cart-wrap'>";
        if (empty($_SESSION['cart'])) {
            echo "<div class='empty-state centre'><p class='muted' style='margin:0 0 10px'>Кошик порожній.</p><a class='btn btn-primary' href='index.php?page=shop'>Перейти до каталогу послуг</a></div>";
        } else {
            echo "<form method='POST' action='index.php?page=cart' aria-label='Форма кошика'>";
            echo "  <input type='hidden' name='action' value='update_cart'>";
            echo "  <table class='cart-table' role='table'>";
            echo "    <thead><tr><th>Послуга</th><th>Ціна</th><th>Кількість</th><th>Сума</th><th>Дія</th></tr></thead>";
            echo "    <tbody>";
            $total = 0.0;
            $strategy = new DiscountPriceStrategy();

            foreach ($_SESSION['cart'] as $id => $item) {
                $basePrice = $item['price'] * $item['qty'];
                $discountedPrice = $strategy->calculate($basePrice);
                $total += $discountedPrice;

                $safeId = $this->escape((string) $id);
                $safeName = $this->escape((string) $item['name']);
                $safePrice = number_format((float) $item['price'], 0, '.', '');
                $safeDiscounted = number_format((float) $discountedPrice, 2, '.', '');
                $safeQty = (int) $item['qty'];

                echo "<tr>";
                echo "<td class='product' data-label='Послуга'>{$safeName}</td>";
                echo "<td class='price' data-label='Ціна'>{$safePrice} грн</td>";
                echo "<td class='qty' data-label='Кількість'><input class='qty-input' type='number' name='cart_qty[{$safeId}]' value='{$safeQty}' min='0' aria-label='Кількість'></td>";
                echo "<td class='total' data-label='Сума'><b class='cart-total'>{$safeDiscounted} грн</b></td>";
                echo "<td data-label='Дія'><button class='btn btn-danger' type='submit' name='remove_id' value='{$safeId}'>Видалити</button></td>";
                echo "</tr>";
            }
            $totalStr = number_format($total, 2, '.', '');
            echo "    </tbody>";
            echo "  </table>";
            echo "  <div class='cart-summary'><div class='muted'>Загальна сума (зі знижкою)</div><div style='font-weight:800;font-size:1.1rem;color:var(--colour-primary)'>{$totalStr} грн</div></div>";
            echo "  <div class='cart-actions'>";
            echo "    <button class='btn btn-ghost' type='submit' style='border: 1px solid #ccc;'>Оновити кількість</button>";
            echo "</form>";
            
            // КНОПКА ОФОРМЛЕННЯ ЗАМОВЛЕННЯ АБО ПРОПОЗИЦІЯ УВІЙТИ
            if (isset($_SESSION['user_id'])) {
                echo "    <form method='POST' action='index.php' class='inline-form' style='margin-left: 10px;'>";
                echo "      <input type='hidden' name='action' value='checkout'>";
                echo "      <button class='btn btn-primary' type='submit' style='background-color: #28a745;'>Оформити замовлення</button>";
                echo "    </form>";
            } else {
                echo "    <a class='btn btn-primary' href='index.php?page=login' style='margin-left: 10px;'>Увійдіть, щоб купити</a>";
            }

            echo "    <form method='POST' action='index.php?page=cart' class='inline-form' style='margin-left: auto;'>";
            echo "      <input type='hidden' name='action' value='clear_cart'>";
            echo "      <button class='btn btn-danger' type='submit'>Очистити кошик</button>";
            echo "    </form>";
            echo "  </div>";
        }
        echo "</section>";
    }
}
// СТОРІНКА ЧАТУ ТА НОТИФІКАЦІЙ
final class ChatPage extends WebPage
{
    public function renderBody(): void
    {
        // Автоматично беремо ім'я користувача з сесії
        $userName = $_SESSION['user_name'] ?? 'Гість';
        $isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');
        
        // Кнопка нотифікацій доступна тільки адміну
        $adminBtn = $isAdmin ? "<button class='btn btn-danger' type='button' onclick='sendMessage(\"notification\")'>Нотифікація всім</button>" : "";

        // Використовуємо HEREDOC для зручного виводу HTML та JS
        echo <<<HTML
        <section class='page-title'><div><h1>Freelance Чат</h1><p class='section-lead'>Спілкуйтесь з клієнтами та виконавцями в реальному часі.</p></div></section>
        
        <div id="notification-area" class="notification-banner" style="display:none; background: #ffc107; padding: 10px; text-align: center; font-weight: bold; margin-bottom: 15px; border-radius: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Нове сповіщення!</div>

        <section class="feedback-card" style="max-width: 800px; margin: 0 auto; padding: 20px;">
            <div id="chat-window" style="height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #fafafa; border-radius: 5px; display: flex; flex-direction: column; gap: 10px;"></div>
            
            <div class="chat-composer" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="text" id="username" value="{$this->escape($userName)}" readonly style="flex: 1; min-width: 120px; background: #e9ecef; cursor: not-allowed; border: 1px solid #ccc; padding: 8px; border-radius: 4px;" title="Ваше ім'я (підтягується з профілю)">
                <input type="text" id="recipient" placeholder="Кому (Ім'я отримувача)" style="flex: 1; min-width: 150px; padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
                <input type="text" id="message" placeholder="Введіть повідомлення..." style="flex: 3; min-width: 200px; padding: 8px; border-radius: 4px; border: 1px solid #ccc;" onkeypress="if(event.key === 'Enter') sendMessage('chat')">
                <button class="btn btn-primary" type="button" onclick="sendMessage('chat')">Відправити</button>
                {$adminBtn}
            </div>
        </section>

        <script>
            var ws = new WebSocket("ws://127.0.0.1:8090");
            var registeredName = "";

            ws.onopen = function() {
                var chatWindow = document.getElementById("chat-window");
                chatWindow.innerHTML += '<div style="text-align: center; color: #28a745; font-size: 0.9em; margin-bottom: 10px;"><i>Підключено до сервера чату!</i></div>';
                registerClient(true);
            };

            ws.onmessage = function(event) {
                var data = JSON.parse(event.data);
                
                if(data.type === 'notification') {
                    var notifArea = document.getElementById('notification-area');
                    notifArea.innerHTML = '📢 <b>' + data.sender + '</b> сповіщає: ' + data.msg + ' <span style="font-size:12px; font-weight:normal; margin-left: 10px;">' + data.date + '</span>';
                    notifArea.style.display = 'block';
                    setTimeout(() => notifArea.style.display = 'none', 7000);
                } else if (data.type === 'error') {
                    var chatWindow = document.getElementById("chat-window");
                    chatWindow.innerHTML += '<div style="text-align: center; color: #dc3545; font-size: 0.9em; margin-bottom: 10px;"><b>Система:</b> ' + data.msg + '</div>';
                    chatWindow.scrollTop = chatWindow.scrollHeight;
                } else {
                    var chatWindow = document.getElementById("chat-window");
                    var isMe = data.sender === document.getElementById('username').value;
                    var align = isMe ? 'flex-end' : 'flex-start';
                    var bg = isMe ? '#d1e7dd' : '#ffffff';
                    var border = isMe ? '#badbcc' : '#dddddd';
                    
                    var msgHtml = '<div style="align-self: ' + align + '; max-width: 75%;">';
                    msgHtml += '<div style="background: ' + bg + '; border: 1px solid ' + border + '; padding: 8px 12px; border-radius: 15px; display: inline-block; text-align: left; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">';
                    msgHtml += '<b style="font-size: 0.85em; color: #555;">' + data.sender + '</b><br>';
                    msgHtml += '<span style="font-size: 1rem; color: #333;">' + data.msg + '</span><br>';
                    msgHtml += '<span style="font-size: 0.7em; color: #999; float: right; margin-top: 5px; margin-left: 15px;">' + data.date + '</span>';
                    msgHtml += '</div></div>';
                    
                    chatWindow.innerHTML += msgHtml;
                    chatWindow.scrollTop = chatWindow.scrollHeight;
                }
            };

            function registerClient(force) {
                if (ws.readyState !== WebSocket.OPEN) return;
                var user = document.getElementById("username").value.trim();
                if (user === "") return;
                if (!force && user === registeredName) return;
                registeredName = user;
                ws.send(JSON.stringify({ sender: user, type: "register" }));
            }

            function sendMessage(type) {
                registerClient(true);
                var user = document.getElementById("username").value.trim();
                var recipient = document.getElementById("recipient").value.trim();
                var msg = document.getElementById("message").value;
                if (msg.trim() === "") return;

                if (type === "chat" && recipient === "") {
                    alert("Будь ласка, вкажіть ім'я отримувача для приватного повідомлення.");
                    return;
                }

                ws.send(JSON.stringify({ sender: user, recipient: recipient, msg: msg, type: type }));
                document.getElementById("message").value = "";
            }
        </script>
HTML;
    }
}