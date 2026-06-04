<?php
declare(strict_types=1);

require_once __DIR__ . '/classes.php';
require_once __DIR__ . '/FreelanceDB.php';
require_once __DIR__ . '/EncryptionService.php';

use App\Security\EncryptionService;
use App\Database\FreelanceDB;
use App\Database\SqliteAdapter;
use App\Database\EncryptingDecorator;
use App\Database\CachingDecorator;

echo "=== Security and caching verification ===\n\n";

// Test EncryptionService
echo "Testing EncryptionService...\n";
$plainText = "Hello!";

$encRandom1 = EncryptionService::encrypt($plainText, false);
$encRandom2 = EncryptionService::encrypt($plainText, false);
$decRandom1 = EncryptionService::decrypt($encRandom1, false);

$encDet1 = EncryptionService::encrypt($plainText, true);
$encDet2 = EncryptionService::encrypt($plainText, true);
$decDet1 = EncryptionService::decrypt($encDet1, true);

if ($decRandom1 === $plainText && $decDet1 === $plainText) {
    echo " Encryption & Decryption works.\n";
} else {
    echo " Decryption mismatch.\n";
}

if ($encRandom1 !== $encRandom2) {
    echo " Randomized encryption yields different ciphertexts.\n";
} else {
    echo " Randomized encryption yielded identical ciphertexts.\n";
}

if ($encDet1 === $encDet2) {
    echo " Deterministic encryption yields identical ciphertexts (good for search).\n";
} else {
    echo " Deterministic encryption yielded different ciphertexts.\n";
}

// Test DB initialization and tokens
echo "\nTesting DB Initialization and Token Functions...\n";
$dbFile = __DIR__ . '/freelance.sqlite';
if (file_exists($dbFile)) {
    unlink($dbFile);
    echo "  [INFO] Reset database file.\n";
}

$adapter = new SqliteAdapter($dbFile);
$rawDb = new FreelanceDB($adapter);
$db = new CachingDecorator(new EncryptingDecorator($rawDb));

$adminRowRaw = $rawDb->getUserByEmail('admin@store.com');
$encAdminEmail = EncryptionService::encrypt('admin@store.com', true);

$pdo = new PDO('sqlite:' . $dbFile);
$stmt = $pdo->prepare('SELECT name, email FROM users LIMIT 1');
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null;
$pdo = null;

if ($row && $row['email'] !== 'admin@store.com' && $row['name'] !== 'Admin') {
    echo " Seeding successfully stored Admin name and email in encrypted format.\n";
} else {
    echo " Seeding stored Admin credentials in plaintext!\n";
}

$adminRowDec = $db->getUserByEmail('admin@store.com');
if ($adminRowDec && $adminRowDec['email'] === 'admin@store.com' && $adminRowDec['name'] === 'Admin') {
    echo " EncryptingDecorator correctly decrypts retrieved user info.\n";
} else {
    echo " EncryptingDecorator failed to decrypt user info.\n";
}

// Test CachingDecorator
echo "\nTesting CachingDecorator...\n";
$cacheDir = __DIR__ . '/cache';
$filesBefore = glob($cacheDir . '/*.cache');
echo " Cache files count before query: " . count($filesBefore) . "\n";

// Query services
$services = $db->getAllServices();
$filesAfter = glob($cacheDir . '/*.cache');
echo " Cache files count after query: " . count($filesAfter) . "\n";

if (count($filesAfter) > 0) {
    echo " CachingDecorator successfully created cache files.\n";
    $cacheFile = $filesAfter[0];
    $cacheContent = file_get_contents($cacheFile);
    $isSerialized = (@unserialize($cacheContent) !== false);
    if (!$isSerialized) {
        echo " Cache file contents are encrypted (not plain serialized PHP).\n";
    } else {
        echo " Cache file contents are stored in plaintext serialized format!\n";
    }
} else {
    echo " CachingDecorator failed to write cache files.\n";
}

$db->addService("Test Service", 999);
$filesAfterMutation = glob($cacheDir . '/*.cache');
echo " Cache files count after adding service: " . count($filesAfterMutation) . "\n";
if (count($filesAfterMutation) === 0) {
    echo " Invalidation successfully cleared the cache files.\n";
} else {
    echo " Cache files were not cleared after mutation.\n";
}

// Test Tokens
echo "\nTesting WebSocket and Remember Me Tokens...\n";
$wsToken = $db->createWebSocketToken(1, 'Nikita', 'user');
echo " WS Token created: $wsToken\n";
$wsUser = $db->validateWebSocketToken($wsToken);
if ($wsUser && $wsUser['username'] === 'Nikita') {
    echo " WebSocket token validated successfully.\n";
} else {
    echo " WebSocket token validation failed.\n";
}

$wsUserRepeat = $db->validateWebSocketToken($wsToken);
if ($wsUserRepeat === null) {
    echo "WebSocket token is one-time use only.\n";
} else {
    echo " WebSocket token allowed repeated validation!\n";
}

$remToken = bin2hex(random_bytes(32));
$db->createRememberToken(1, $remToken);
$remUser = $db->validateRememberToken($remToken);
if ($remUser && $remUser['name'] === 'Admin') {
    echo " Remember token validated successfully.\n";
} else {
    echo "Remember token validation failed.\n";
}

$db->deleteRememberToken($remToken);
$remUserDel = $db->validateRememberToken($remToken);
if ($remUserDel === null) {
    echo " Remember token deleted successfully.\n";
} else {
    echo " Remember token deletion failed.\n";
}

echo "\n=== All tests finished ===\n";