<?php
declare(strict_types=1);

require_once __DIR__ . '/FreelanceDB.php';
use App\Database\FreelanceDB;
use App\Database\SqliteAdapter;

$adapter = new SqliteAdapter(__DIR__ . '/freelance.sqlite');
$db = new FreelanceDB($adapter);

$host = '127.0.0.1';
$port = 8090;

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, $host, $port);
socket_listen($socket);
echo "WebSocket server running at {$host}:{$port}\nWaiting for connections...\n";

$clients = [$socket];
$clientNames = [];
$nameToSockets = [];
$socketToUser = [];

while (true) {
    $read = $clients;
    $write = null;
    $except = null;

    if (socket_select($read, $write, $except, 0, 10) < 1) {
        continue;
    }

    if (in_array($socket, $read, true)) {
        $newSocket = socket_accept($socket);
        $header = socket_read($newSocket, 1024);
        
        $lines = preg_split("/\r\n/", $header);
        $requestLine = $lines[0] ?? '';
        $token = '';
        if (preg_match('/GET\s+\/\?token=([a-f0-9]+)/i', $requestLine, $matches)) {
            $token = $matches[1];
        }

        $user = null;
        if ($token !== '') {
            try {
                $user = $db->validateWebSocketToken($token);
            } catch (Exception $e) {
                echo "DB Error: " . $e->getMessage() . "\n";
            }
        }

        if ($user === null) {
            echo "Rejecting connection: Invalid token.\n";
            $response = "HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n";
            @socket_write($newSocket, $response, strlen($response));
            @socket_close($newSocket);
            $key = array_search($socket, $read, true);
            unset($read[$key]);
            continue;
        }

        performHandshake($header, $newSocket, $host, $port);
        socket_getpeername($newSocket, $ip);
        echo "Authenticated client connected: {$user['username']} ({$ip})\n";

        $socketKey = getSocketKey($newSocket);
        $clients[] = $newSocket;
        $socketToUser[$socketKey] = $user;

        $senderKeys = getNameKeys($user['username']);
        $clientNames[$socketKey] = $senderKeys;
        foreach ($senderKeys as $senderKey) {
            if ($senderKey !== '') {
                $nameToSockets[$senderKey][$socketKey] = $newSocket;
            }
        }

        $key = array_search($socket, $read, true);
        unset($read[$key]);
    }

    foreach ($read as $changedSocket) {
        $bytes = @socket_recv($changedSocket, $buffer, 2048, 0);
        $socketKey = getSocketKey($changedSocket);

        if ($bytes === false || $bytes === 0) {
            $key = array_search($changedSocket, $clients, true);
            $previousKeys = $clientNames[$socketKey] ?? [];
            foreach ($previousKeys as $previousKey) {
                if ($previousKey === '') {
                    continue;
                }
                unset($nameToSockets[$previousKey][$socketKey]);
                if (empty($nameToSockets[$previousKey])) {
                    unset($nameToSockets[$previousKey]);
                }
            }
            unset($clients[$key], $clientNames[$socketKey], $socketToUser[$socketKey]);
            socket_close($changedSocket);
            continue;
        }

        if (!isset($socketToUser[$socketKey])) {
            $key = array_search($changedSocket, $clients, true);
            unset($clients[$key]);
            @socket_close($changedSocket);
            continue;
        }

        $currentUser = $socketToUser[$socketKey];
        $sender = $currentUser['username'];
        $role = $currentUser['role'];

        $message = unmask($buffer);
        $data = json_decode($message, true);
        if (!is_array($data)) {
            continue;
        }

        $type = $data['type'] ?? 'chat';
        
        if ($type === 'register' || $type === 'presence') {
            continue;
        }

        $recipient = trim((string) ($data['recipient'] ?? ''));
        $recipientKeys = getNameKeys($recipient);

        if ($type === 'notification') {
            if ($role !== 'admin') {
                $errorPayload = mask(json_encode([
                    'sender' => 'system',
                    'msg' => 'Тільки адміністратори можуть надсилати сповіщення.',
                    'date' => date('Y-m-d H:i:s'),
                    'type' => 'error',
                ]));
                sendDirectMessage($errorPayload, [$changedSocket]);
                continue;
            }

            $payload = [
                'sender' => $sender,
                'msg' => trim((string) ($data['msg'] ?? '')),
                'date' => date('Y-m-d H:i:s'),
                'type' => 'notification',
            ];
            $response = mask(json_encode($payload));
            broadcastMessage($response, $clients, $socket);
            continue;
        }

        $text = trim((string) ($data['msg'] ?? ''));
        if ($text === '') {
            continue;
        }

        $payload = [
            'sender' => $sender,
            'msg' => $text,
            'date' => date('Y-m-d H:i:s'),
            'type' => $type,
        ];

        if (empty($recipientKeys)) {
            $errorPayload = mask(json_encode([
                'sender' => 'system',
                'msg' => 'Оберіть отримувача для приватного чату.',
                'date' => date('Y-m-d H:i:s'),
                'type' => 'error',
            ]));
            sendDirectMessage($errorPayload, [$changedSocket]);
            continue;
        }

        $payload['recipient'] = $recipient;
        $response = mask(json_encode($payload));
        $targetsByKey = [$socketKey => $changedSocket];
        $recipientMatched = false;

        foreach ($recipientKeys as $lookupKey) {
            if (!isset($nameToSockets[$lookupKey])) {
                continue;
            }
            foreach ($nameToSockets[$lookupKey] as $targetKey => $targetSocket) {
                if ($targetSocket === $changedSocket) {
                    continue;
                }
                $recipientMatched = true;
                $targetsByKey[$targetKey] = $targetSocket;
            }
        }

        if (!$recipientMatched) {
            $errorPayload = mask(json_encode([
                'sender' => 'system',
                'msg' => 'Отримувач не підключений до чату.',
                'date' => date('Y-m-d H:i:s'),
                'type' => 'error',
            ]));
            sendDirectMessage($errorPayload, [$changedSocket]);
            continue;
        }

        sendDirectMessage($response, array_values($targetsByKey));
    }
}

function getSocketKey($socket): string
{
    if (is_object($socket)) {
        return 'obj-' . spl_object_id($socket);
    }

    return 'res-' . (int) $socket;
}

function updateClientName($socket, array $senderKeys, array &$clientNames, array &$nameToSockets): void
{
    $socketKey = getSocketKey($socket);
    $previousKeys = $clientNames[$socketKey] ?? [];
    foreach ($previousKeys as $previousKey) {
        if ($previousKey === '') {
            continue;
        }
        unset($nameToSockets[$previousKey][$socketKey]);
        if (empty($nameToSockets[$previousKey])) {
            unset($nameToSockets[$previousKey]);
        }
    }

    if (empty($senderKeys)) {
        unset($clientNames[$socketKey]);
        return;
    }

    $clientNames[$socketKey] = $senderKeys;
    foreach ($senderKeys as $senderKey) {
        if ($senderKey === '') {
            continue;
        }
        if (!isset($nameToSockets[$senderKey])) {
            $nameToSockets[$senderKey] = [];
        }
        $nameToSockets[$senderKey][$socketKey] = $socket;
    }
}

function getNameKeys(string $name): array
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return [];
    }

    $collapsed = preg_replace('/\s+/u', ' ', $trimmed);
    if ($collapsed === null) {
        $collapsed = $trimmed;
    }

    if (function_exists('mb_strtolower')) {
        $collapsed = mb_strtolower($collapsed, 'UTF-8');
    } else {
        $collapsed = strtolower($collapsed);
    }

    $keys = [];
    if ($collapsed !== '') {
        $keys[] = $collapsed;
    }
    $latinKey = normaliseName($collapsed);
    if ($latinKey !== '') {
        $keys[] = $latinKey;
    }

    $unicodeKey = normaliseUnicodeName($collapsed);
    if ($unicodeKey !== '' && $unicodeKey !== $latinKey) {
        $keys[] = $unicodeKey;
    }

    return array_values(array_unique($keys));
}

function normaliseUnicodeName(string $name): string
{
    $sanitised = preg_replace('/[^\p{L}\p{N}]/u', '', $name);
    return $sanitised ?? '';
}

function broadcastMessage(string $msg, array $clients, $serverSocket): void
{
    foreach ($clients as $client) {
        if ($client !== $serverSocket) {
            @socket_write($client, $msg, strlen($msg));
        }
    }
}

function sendDirectMessage(string $msg, array $targets): void
{
    foreach ($targets as $client) {
        @socket_write($client, $msg, strlen($msg));
    }
}

function normaliseName(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }

    $collapsed = preg_replace('/\s+/u', ' ', $trimmed);
    if ($collapsed === null) {
        $collapsed = $trimmed;
    }

    // Normalise casing for consistent matching behaviour.
    if (function_exists('mb_strtolower')) {
        $collapsed = mb_strtolower($collapsed, 'UTF-8');
    } else {
        $collapsed = strtolower($collapsed);
    }

    $transliteration = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd',
        'е' => 'e', 'є' => 'ye', 'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i',
        'ї' => 'yi', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ю' => 'yu', 'я' => 'ya', 'ь' => '', 'ъ' => '', 'ё' => 'e', 'э' => 'e', 'ы' => 'y',
    ];
    $latin = strtr($collapsed, $transliteration);
    $sanitised = preg_replace('/[^a-z0-9]/', '', $latin);
    return $sanitised ?? '';
}

function unmask(string $text): string
{
    if (strlen($text) < 2) {
        return '';
    }

    $length = ord($text[1]) & 127;
    if ($length === 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    } elseif ($length === 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }

    $decoded = '';
    for ($i = 0; $i < strlen($data); ++$i) {
        $decoded .= $data[$i] ^ $masks[$i % 4];
    }

    return $decoded;
}

function mask(string $text): string
{
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($text);
    if ($length <= 125) {
        $header = pack('CC', $b1, $length);
    } elseif ($length < 65536) {
        $header = pack('CCn', $b1, 126, $length);
    } else {
        $header = pack('CCNN', $b1, 127, $length);
    }

    return $header . $text;
}

function performHandshake(string $receivedHeader, $clientConnection, string $host, int $port): void
{
    $headers = [];
    $lines = preg_split("/\r\n/", $receivedHeader);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }

    if (!isset($headers['Sec-WebSocket-Key'])) {
        return;
    }

    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $buffer = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "WebSocket-Origin: {$host}\r\n" .
        "WebSocket-Location: ws://{$host}:{$port}/\r\n" .
        "Sec-WebSocket-Accept: {$secAccept}\r\n\r\n";
    socket_write($clientConnection, $buffer, strlen($buffer));
}
