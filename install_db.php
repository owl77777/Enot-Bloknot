<?php
require 'config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id BIGINT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "✅ Таблица 'notes' создана!";
} catch (PDOException $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
?>
