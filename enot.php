<?php
require 'config.php';

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

/**
 * Логирование ошибок
 */
function logError($message) {
    file_put_contents(
        __DIR__ . '/error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}
// Ответ на callback
function answerCallback($callback_id, $text) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
    file_get_contents($url . '?' . http_build_query([
        'callback_query_id' => $callback_id,
        'text' => $text
    ]));
}


/**
 * Отправляет текст как файл
 */
function sendAsFile($chat_id, $text, $filename = null) {
    if ($filename === null) {
        $filename = "note_" . date('Y-m-d_H-i-s') . ".txt";
    }
    
    file_put_contents($filename, $text);
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    $data = [
        'chat_id' => $chat_id,
        'document' => new CURLFile(realpath($filename))
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    unlink($filename); // Удаляем временный файл
    
    return $response;
}

/**
 * Улучшенная функция экранирования MarkdownV2
 */
function prepareText($text) {
    // Символы, которые нужно экранировать
    $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    
    // Сначала обрабатываем URL, чтобы не экранировать их содержимое
    $text = preg_replace_callback('/(https?:\/\/[^\s]+)/', function($matches) {
        $url = $matches[0];
        // Экранируем только закрывающие скобки в URL
        $url = str_replace([')'], ['\)'], $url);
        return $url;
    }, $text);

    // Затем экранируем все спецсимволы вне URL
    $result = '';
    $inUrl = false;
    $length = mb_strlen($text);
    
    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($text, $i, 1);
        
        if ($char === '[' || $char === '(') {
            $inUrl = true;
        } elseif ($char === ']' || $char === ')') {
            $inUrl = false;
        }
        
        if (!$inUrl && in_array($char, $chars)) {
            $char = '\\' . $char;
        }
        
        $result .= $char;
    }
    
    return $result;
}

/**
 * Безопасная отправка сообщения с улучшенной обработкой Markdown
 */
function sendMessage($chat_id, $text, $keyboard = null, $parse_mode = 'MarkdownV2') {
    $text = trim($text);
    if (empty($text)) {
        logError("Пустое сообщение для чата $chat_id");
        return false;
    }

    // Подготавливаем текст в зависимости от режима разметки
    if ($parse_mode === 'MarkdownV2') {
        $text = prepareText($text);
    }

    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true
    ];

    if ($keyboard) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }

    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($http_code != 200) {
        $error = json_decode($response, true)['description'] ?? $response;
        logError("Ошибка отправки: HTTP $http_code - $error\nТекст: ".substr($text, 0, 1000));
        
        // Пытаемся отправить как plain text при ошибке
        if ($parse_mode !== '') {
            unset($data['parse_mode']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $response = curl_exec($ch);
        }
    }

    curl_close($ch);
    return $http_code === 200;
}
?>