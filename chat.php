<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}

require_once __DIR__ . '/FreelanceDB.php';

use App\Database\FreelanceDB;
use App\Database\SqliteAdapter;

$adapter = new SqliteAdapter(__DIR__ . '/freelance.sqlite');
$db = new FreelanceDB($adapter);

$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Гість';
$role = $_SESSION['user_role'] ?? 'user';
$isAdmin = ($role === 'admin');

$token = $db->createWebSocketToken((int)$userId, $userName, $role);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Freelance Чат та Нотифікації</title>
    <link rel="icon" type="image/png" href="images/Logo-nobackground.png">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="container">
        <header class="header">
            <a class="brand" href="index.php?page=shop" aria-label="Freelance Store home">
                <span class="brand-logo"><img src="images/Logo-white-background.png" alt="Freelance Store logo"></span>
                <span class="brand-copy">
                    <span class="brand-title"><span class="brand-accent">Freelance</span> Store</span>
                    <span class="brand-subtitle">Live chat and notifications</span>
                </span>
            </a>
            <nav class="nav" aria-label="Chat navigation">
                <a href="index.php?page=shop">Послуги</a>
                <a href="index.php?page=cart">Кошик</a>
                <a href="chat.php" class="active">Чат</a>
            </nav>
        </header>

        <main class="chat-shell">
            <div id="notification-area" class="notification-banner" style="display:none; background: #ffc107; padding: 10px; text-align: center; font-weight: bold; margin-bottom: 15px; border-radius: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Нове сповіщення!</div>

            <section class="chat-panel" aria-labelledby="chat-title">
                <div class="chat-panel__header">
                    <div>
                        <h1 id="chat-title" style="margin:0;font-size:1.2rem;">Freelance Чат</h1>
                        <div class="chat-meta">Тет-а-тет messaging demo</div>
                    </div>
                    <div class="chat-meta">WebSocket live</div>
                </div>

                <div class="chat-panel__body" id="chat-window" aria-live="polite" aria-label="Chat messages"></div>

                <div class="chat-composer">
                    <label class="sr-only" for="username">Ваше ім'я</label>
                    <input type="text" id="username" placeholder="Ваше ім'я (Відправник)" value="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>" readonly style="background: #e9ecef; cursor: not-allowed; border: 1px solid #ccc; padding: 8px; border-radius: 4px;" aria-label="Ваше ім'я">
                    <label class="sr-only" for="recipient">Отримувач</label>
                    <input type="text" id="recipient" placeholder="Кому (Ім'я отримувача)" aria-label="Отримувач" style="padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
                    <label class="sr-only" for="message">Повідомлення</label>
                    <input type="text" id="message" placeholder="Введіть повідомлення..." aria-label="Повідомлення" style="padding: 8px; border-radius: 4px; border: 1px solid #ccc;" onkeypress="if(event.key === 'Enter') sendMessage('chat')">
                    <button class="btn btn-primary" type="button" onclick="sendMessage('chat')">Відправити в чат</button>
                    <?php if ($isAdmin): ?>
                        <button class="btn btn-danger" type="button" onclick="sendMessage('notification')">Нотифікація всім</button>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script>
        function escapeHtml(text) {
            if (!text) return "";
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        var ws = new WebSocket("ws://127.0.0.1:8090/?token=" + encodeURIComponent("<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>"));
        var registeredName = "";

        ws.onopen = function() {
            document.getElementById("chat-window").innerHTML += "<div class='message message--received'><i>Підключено до сервера!</i><span class='message__meta'>system</span></div>";
            registeredName = "";
            registerClient(true);
        };

        ws.onmessage = function(event) {
            var data = JSON.parse(event.data);
            
            if(data.type === 'notification') {
                var notifArea = document.getElementById('notification-area');
                notifArea.innerHTML = "📢 <b>" + escapeHtml(data.sender) + "</b> надіслав сповіщення: " + escapeHtml(data.msg) + " <span style='font-size:12px'>" + escapeHtml(data.date) + "</span>";
                notifArea.style.display = "block";
                setTimeout(() => notifArea.style.display = "none", 5000);
            } else if (data.type === 'error') {
                var chatWindow = document.getElementById("chat-window");
                chatWindow.innerHTML += "<div class='message message--received'><b>System:</b> " + escapeHtml(data.msg) + "<span class='message__meta'>" + escapeHtml(data.date) + "</span></div>";
                chatWindow.scrollTop = chatWindow.scrollHeight;
            } else {
                var chatWindow = document.getElementById("chat-window");
                var senderClass = data.sender === document.getElementById('username').value ? 'message--sent' : 'message--received';
                chatWindow.innerHTML += "<div class='message " + senderClass + "'><b>" + escapeHtml(data.sender) + ":</b> " + escapeHtml(data.msg) + "<span class='message__meta'>" + escapeHtml(data.date) + "</span></div>";
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
                alert("Будь ласка, вкажіть отримувача.");
                return;
            }

            var payload = { sender: user, recipient: recipient, msg: msg, type: type };
            ws.send(JSON.stringify(payload));
            document.getElementById("message").value = "";
        }
    </script>
</body>
</html>