<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Freelance Чат та Нотифікації</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f9; padding: 20px; }
        .chat-box { background: white; border-radius: 8px; padding: 20px; width: 400px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .messages { height: 250px; overflow-y: scroll; border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; background: #fafafa; }
        .msg { padding: 5px; margin-bottom: 5px; border-bottom: 1px solid #eee; }
        .msg span.date { font-size: 0.8em; color: #888; }
        .msg b { color: #5621d1; }
        .notification { background-color: #ffeb3b; padding: 10px; margin-bottom: 10px; border-radius: 5px; font-weight: bold; display: none;}
        input[type="text"] { width: calc(100% - 22px); padding: 10px; margin-bottom: 10px; }
        button { padding: 10px; width: 100%; background: #5621d1; color: white; border: none; cursor: pointer; margin-bottom: 5px;}
    </style>
</head>
<body>

    <div id="notification-area" class="notification">Нове сповіщення!</div>

    <div class="chat-box">
        <h2>Freelance Чат (Тет-а-тет)</h2>
        <input type="text" id="username" placeholder="Ваше ім'я (Відправник)" value="Нікіта">
        <div class="messages" id="chat-window"></div>
        <input type="text" id="message" placeholder="Введіть повідомлення...">
        <button onclick="sendMessage('chat')">Відправити в чат</button>
        <button onclick="sendMessage('notification')" style="background: #e91e63;">Відправити нотифікацію всім</button>
    </div>

    <script>
        var ws = new WebSocket("ws://127.0.0.1:8090");

        ws.onopen = function() {
            document.getElementById("chat-window").innerHTML += "<div class='msg'><i>Підключено до сервера!</i></div>";
        };

        ws.onmessage = function(event) {
            var data = JSON.parse(event.data);
            
            if(data.type === 'notification') {
                var notifArea = document.getElementById('notification-area');
                notifArea.innerHTML = "📢 <b>" + data.sender + "</b> надіслав сповіщення: " + data.msg + " <span style='font-size:10px'>" + data.date + "</span>";
                notifArea.style.display = "block";
                setTimeout(() => notifArea.style.display = "none", 5000);
            } else {
                var chatWindow = document.getElementById("chat-window");
                chatWindow.innerHTML += "<div class='msg'><b>" + data.sender + ":</b> " + data.msg + " <br><span class='date'>" + data.date + "</span></div>";
                chatWindow.scrollTop = chatWindow.scrollHeight;
            }
        };

        function sendMessage(type) {
            var user = document.getElementById("username").value;
            var msg = document.getElementById("message").value;
            if (msg.trim() === "") return;

            var payload = { sender: user, msg: msg, type: type };
            ws.send(JSON.stringify(payload));
            document.getElementById("message").value = "";
        }
    </script>
</body>
</html>