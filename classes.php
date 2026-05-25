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
    // Factory pattern: centralises creation of service models.
    public static function create(int $id, string $name, int $price): ServiceModel
    {
        return new ServiceModel($id, $name, $price);
    }
}

// Strategy pattern: encapsulates pricing behaviour.
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
        return $price * 0.90;
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

        // Persist totals per visit session and per visitor.
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

        // Cookie keeps the visitor identifier stable across sessions.
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
        echo "      <span class='brand-title'><span class='brand-accent'>Freelance</span> Store</span>";
        echo "      <span class='brand-subtitle'>Modern services for your next project</span>";
        echo "    </span>";
        echo "  </a>";
        echo "  <nav class='nav' role='navigation' aria-label='Main navigation'>";
        echo "    <a href='index.php?page=shop'>Послуги</a>";
        echo "    <a href='index.php?page=cart'>Кошик</a>";
        echo "    <a href='xml_task.php'>XML-Користувачі</a>";
        echo "    <a href='chat.php' target='_blank' rel='noopener'>Чат</a>";
        echo "  </nav>";
        echo "  <div class='header-actions'>";
        echo "    <a class='icon-btn' href='index.php?page=cart' title='Open cart' aria-label='Open cart'>🛒</a>";
        echo "  </div>";
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
        echo "  <div>&copy; 2026 Freelance Store</div>";
        echo "  <div class='muted'>Built with care • Accessibility friendly</div>";
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

        echo "<section class='page-title'><div><h1>Доступні послуги</h1><p class='section-lead'>Choose a service and add it to your cart.</p></div></section>";
        echo "<form method='GET' action='index.php' class='search-form' aria-label='Service search'>";
        echo "  <input type='hidden' name='page' value='shop'>";
        echo "  <label class='sr-only' for='service-search'>Пошук послуг</label>";
        echo "  <input id='service-search' type='text' name='q' value='{$safeSearch}' placeholder='Пошук за назвою...' />";
        echo "  <button class='btn btn-ghost' type='submit'>Пошук</button>";
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
            echo "  <p class='desc'>Professional service tailored to your needs.</p>";
            echo "  <div class='card-footer'>";
            echo "    <form method='POST' action='index.php' class='cart-form'>";
            echo "      <input type='hidden' name='action' value='add_to_cart'>";
            echo "      <input type='hidden' name='product_id' value='{$safeId}'>";
            echo "      <div class='qty-field'><label class='sr-only' for='qty-{$safeId}'>Quantity</label><input id='qty-{$safeId}' type='number' name='qty' value='1' min='1' aria-label='Quantity'></div>";
            echo "      <button class='btn btn-primary' type='submit'>Add to cart</button>";
            echo "    </form>";
            echo "  </div>";
            echo "</article>";
        }
        echo "</section>";

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
        echo "    <button class='btn btn-primary' type='submit'>Надіслати</button>";
        echo "  </form>";
        echo "</section>";
    }
}

final class CartPage extends WebPage
{
    public function renderBody(): void
    {
        echo "<section class='page-title'><div><h1 class='cart-heading'>Ваш кошик</h1><p class='section-lead'>Знижка 10% застосовується до всієї суми.</p></div></section>";
        echo "<section class='cart-wrap'>";
        if (empty($_SESSION['cart'])) {
            echo "<div class='empty-state centre'><p class='muted' style='margin:0 0 10px'>Кошик порожній.</p><a class='btn btn-primary' href='index.php?page=shop'>Перейти до покупок</a></div>";
        } else {
            echo "<form method='POST' action='index.php?page=cart' aria-label='Cart form'>";
            echo "  <input type='hidden' name='action' value='update_cart'>";
            echo "  <table class='cart-table' role='table'>";
            echo "    <thead><tr><th>Послуга</th><th>Ціна</th><th>Кількість</th><th>Сума</th><th>Дія</th></tr></thead>";
            echo "    <tbody>";
            $total = 0.0;
            // Strategy pattern usage for discount calculations.
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
                echo "<td class='qty' data-label='Кількість'><input class='qty-input' type='number' name='cart_qty[{$safeId}]' value='{$safeQty}' min='0' aria-label='Quantity for {$safeName}'></td>";
                echo "<td class='total' data-label='Сума'><b class='cart-total'>{$safeDiscounted} грн</b></td>";
                echo "<td data-label='Дія'><button class='btn btn-danger' type='submit' name='remove_id' value='{$safeId}'>Видалити</button></td>";
                echo "</tr>";
            }
            $totalStr = number_format($total, 2, '.', '');
            echo "    </tbody>";
            echo "  </table>";
            echo "  <div class='cart-summary'><div class='muted'>Total (incl. discount)</div><div style='font-weight:800;font-size:1.1rem;color:var(--colour-primary)'>{$totalStr} грн</div></div>";
            echo "  <div class='cart-actions'>";
            echo "    <button class='btn btn-primary' type='submit'>Update quantities</button>";
            echo "</form>";
            echo "    <form method='POST' action='index.php?page=cart' class='inline-form'>";
            echo "      <input type='hidden' name='action' value='clear_cart'>";
            echo "      <button class='btn btn-danger' type='submit'>Clear cart</button>";
            echo "    </form>";
            echo "    <a class='btn btn-ghost' href='index.php?page=shop'>Continue shopping</a>";
            echo "  </div>";
        }
        echo "</section>";
    }
}
