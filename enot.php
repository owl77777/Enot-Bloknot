<?php
require 'config.php'; //файл конфигурации

$input = json_decode(file_get_contents('php://input'), true);

// Обработка сообщений
if (isset($input['message'])) {
    $chat_id = $input['message']['chat']['id'];
    $text = $input['message']['text'];

    if ($text === '/start') {
        sendMessage($chat_id, "Я - Enot Блокнот. Надежно сохраню твои записи в базе данных и зашифрую их.  \n\nПросто отправь текст, и я сохраню его!\n/list — показать все записи.");
    } 
    elseif ($text === '/list') {
        showNotesList($chat_id);
    }
    else {
        saveNote($chat_id, $text);
    }
}

// Обработка кнопок "Показать"/"Удалить"
elseif (isset($input['callback_query'])) {
    handleCallback($input['callback_query']);
}

// Сохранить заметку
function saveNote($chat_id, $text) {
    global $pdo;
    try {
        $encrypted = encrypt($text);
        $stmt = $pdo->prepare("INSERT INTO notes (chat_id, message) VALUES (?, ?)");
        $stmt->execute([$chat_id, $encrypted]);
        sendMessage($chat_id, "🔐 Запись сохранена!\n/list");
    } catch (PDOException $e) {
        logError($e->getMessage());
        sendMessage($chat_id, "⚠️ Ошибка при сохранении");
    }
}

// Показать список заметок
function showNotesList($chat_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, message FROM notes WHERE chat_id = ? ORDER BY id DESC");
        $stmt->execute([$chat_id]);
        $notes = $stmt->fetchAll();

        if (empty($notes)) {
            sendMessage($chat_id, "📭 Список пуст");
            return;
        }

        $message = "📋 *Ваши записи:*\n\n";
        $keyboard = [];
        foreach ($notes as $note) {
            $decrypted = decrypt($note['message']);
            $short_text = mb_substr($decrypted, 0, 20) . (mb_strlen($decrypted) > 20 ? '...' : '');
            $message .= "🆔 {$note['id']}: {$short_text}\n";
            
            $keyboard[] = [
                [
                    'text' => "📝 Показать",
                    'callback_data' => "show_{$note['id']}"
                ],
                [
                    'text' => "❌ Удалить",
                    'callback_data' => "delete_{$note['id']}"
                ]
            ];
        }

        sendMessage($chat_id, $message, $keyboard);
    } catch (PDOException $e) {
        logError($e->getMessage());
        sendMessage($chat_id, "⚠️ Ошибка при загрузке записей");
    }
}

// Обработка нажатия кнопок
function handleCallback($callback) {
    global $pdo;
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];
    list($action, $note_id) = explode('_', $data);

    try {
        if ($action === 'show') {
            $stmt = $pdo->prepare("SELECT message FROM notes WHERE id = ? AND chat_id = ?");
            $stmt->execute([$note_id, $chat_id]);
            $note = $stmt->fetch();
            
            if ($note) {
                $decrypted = decrypt($note['message']);
                sendMessage($chat_id, "📄 Заметка #$note_id:\n\n$decrypted");
            }
        } 
        elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ? AND chat_id = ?");
            $stmt->execute([$note_id, $chat_id]);
            answerCallback($callback['id'], "🗑 Заметка удалена!");
            showNotesList($chat_id); // Обновляем список
        }
    } catch (PDOException $e) {
        logError($e->getMessage());
        answerCallback($callback['id'], "⚠️ Ошибка операции");
    }
}

// Отправка сообщения
function sendMessage($chat_id, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($keyboard) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    file_get_contents($url . '?' . http_build_query($data));
}

// Ответ на callback
function answerCallback($callback_id, $text) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
    file_get_contents($url . '?' . http_build_query([
        'callback_query_id' => $callback_id,
        'text' => $text
    ]));
}

// Логирование ошибок
function logError($error) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - " . $error . "\n", FILE_APPEND);
}
?>
