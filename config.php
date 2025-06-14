<?php
// Задержка между сообщениями в секундах
define('FLOOD_DELAY', 1); 
// При отправке нескольких сообщений
usleep(FLOOD_DELAY * 1000000);
//обработчики голосовых сообщений от SpeechKit
define('YANDEX_SPEECHKIT_KEY', 'SPEECHKIT_KEY');
define('YANDEX_FOLDER_ID', 'FOLDER_ID');
define('DB_HOST', 'HOST');
define('DB_NAME', 'DB_NAME');
define('DB_USER', 'DB_USER');
define('DB_PASS', 'DB_PASS');
define('BOT_TOKEN', 'BOT_TOKEN');

// Ключ шифрования (32 символа!)
define('ENCRYPTION_KEY', 'KEY');

// Подключение к БД
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - Ошибка БД: " . $e->getMessage() . "\n", FILE_APPEND);
    die("Ошибка подключения к БД");
}

// Шифрование данных
function encrypt($data) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt($data) {
    $data = base64_decode($data);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
}

?>