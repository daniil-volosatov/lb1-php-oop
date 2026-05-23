<?php
// Простой WebSocket Сервер на чистом PHP
$host = '127.0.0.1';
$port = 8090;

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, $host, $port);
socket_listen($socket);
echo "WebSocket сервер запущен на $host:$port\nЖдем подключений...\n";

$clients = [$socket];

while (true) {
    $read = $clients;
    $write = null;
    $except = null;
    
    if (socket_select($read, $write, $except, 0, 10) < 1) continue;

    // Подключение нового клиента
    if (in_array($socket, $read)) {
        $new_socket = socket_accept($socket);
        $clients[] = $new_socket;
        $header = socket_read($new_socket, 1024);
        perform_handshake($header, $new_socket, $host, $port);
        socket_getpeername($new_socket, $ip);
        echo "Новый пользователь подключился: $ip\n";
        $key = array_search($socket, $read);
        unset($read[$key]);
    }

    // Обработка сообщений от существующих клиентов
    foreach ($read as $changed_socket) {
        $bytes = @socket_recv($changed_socket, $buf, 1024, 0);
        
        // Если клиент отключился (например, обновил страницу)
        if ($bytes === false || $bytes == 0) {
            $key = array_search($changed_socket, $clients);
            unset($clients[$key]);
            socket_close($changed_socket);
            continue;
        }

        $msg = unmask($buf);
        $data = json_decode($msg, true);
        
        // Отправляем сообщение ТОЛЬКО если это реальный текст (а не системный сигнал)
        if (is_array($data) && !empty($data['sender']) && !empty($data['msg'])) {
            $response = mask(json_encode([
                'sender' => $data['sender'],
                'msg' => $data['msg'],
                'date' => date('Y-m-d H:i:s'),
                'type' => $data['type'] ?? 'chat'
            ]));
            send_message($response, $clients, $socket);
        }
    }
}

function send_message($msg, $clients, $server_socket) {
    foreach($clients as $client) {
        if ($client != $server_socket) @socket_write($client, $msg, strlen($msg));
    }
}

function unmask($text) {
    if (strlen($text) < 2) return "";
    $length = ord($text[1]) & 127;
    if($length == 126) { $masks = substr($text, 4, 4); $data = substr($text, 8); }
    elseif($length == 127) { $masks = substr($text, 10, 4); $data = substr($text, 14); }
    else { $masks = substr($text, 2, 4); $data = substr($text, 6); }
    $decoded = "";
    for ($i = 0; $i < strlen($data); ++$i) { $decoded .= $data[$i] ^ $masks[$i%4]; }
    return $decoded;
}

function mask($text) {
    $b1 = 0x80 | (0x1 & 0x0f); $length = strlen($text);
    if($length <= 125) $header = pack('CC', $b1, $length);
    elseif($length > 125 && $length < 65536) $header = pack('CCn', $b1, 126, $length);
    else $header = pack('CCNN', $b1, 127, $length);
    return $header.$text;
}

function perform_handshake($receved_header, $client_conn, $host, $port) {
    $headers = array();
    $lines = preg_split("/\r\n/", $receved_header);
    foreach($lines as $line) {
        $line = chop($line);
        if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)) { $headers[$matches[1]] = $matches[2]; }
    }
    if (!isset($headers['Sec-WebSocket-Key'])) return;
    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $buffer  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "WebSocket-Origin: $host\r\n" .
    "WebSocket-Location: ws://$host:$port/\r\n".
    "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
    socket_write($client_conn, $buffer, strlen($buffer));
}
?>