<?php
require 'config.php';
$input = json_decode(file_get_contents('php://input'), true);
    
// Обработка сообщений
if (isset($input['message'])) {
    $chat_id = $input['message']['chat']['id'];
    $user_id = $input['message']['from']['id'];
    
// Обработка текстовых команд
    if (isset($input['message']['text'])) {
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
// Обработка голосовых сообщений
    elseif (isset($input['message']['voice'])) {
        $voice_file_id = $input['message']['voice']['file_id'];
        if (handleVoiceMessage($chat_id, $user_id, $voice_file_id)) {
            sendMessage($chat_id, "🔊 Голосовая заметка сохранена! \n /list");
        } else {
            sendMessage($chat_id, "⚠️ Не удалось распознать речь. Попробуйте ещё раз.");
        }
    }
}

// Обработка кнопок
elseif (isset($input['callback_query'])) {
    handleCallback($input['callback_query']);
}

// Сохранить заметку
function saveNote($chat_id, $text) {
    global $pdo;
    try {
        // Оставляем оригинальный текст, но используем подготовленные выражения
        $encrypted = encrypt($text);
        $stmt = $pdo->prepare("INSERT INTO notes (chat_id, message) VALUES (:chat_id, :message)");
        $stmt->execute([
            ':chat_id' => $chat_id,
            ':message' => $encrypted
        ]);
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
            $short_text = mb_substr($decrypted, 0, 20);
            if (mb_strlen($decrypted) > 20) {
                $short_text .= '...';
            }
            
            // Создаем одну строку с кнопками
            $keyboard[] = [
                [
                    'text' => "📄 " . $short_text,
                    'callback_data' => "show_{$note['id']}"
                ],
                [
                    'text' => "❌",
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
                // Отправляем сообщение с кнопкой "Назад"
                $keyboard = [
                    [
                        ['text' => "🔙 Назад к списку", 'callback_data' => "backtolist"]
                    ]
                ];
                sendMessage($chat_id, "📄 Заметка #$note_id:\n\n$decrypted", $keyboard);
            }
        } 
        elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ? AND chat_id = ?");
            $stmt->execute([$note_id, $chat_id]);
            answerCallback($callback['id'], "🗑 Заметка удалена!");
            showNotesList($chat_id); // Обновляем список
        }
        elseif ($action === 'backtolist') {
            // Удаляем предыдущее сообщение с полным текстом заметки
            deleteMessage($chat_id, $callback['message']['message_id']);
            showNotesList($chat_id);
        }
    } catch (PDOException $e) {
        logError($e->getMessage());
        answerCallback($callback['id'], "⚠️ Ошибка операции");
    }
}

function deleteMessage($chat_id, $message_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/deleteMessage";
    file_get_contents($url . '?' . http_build_query([
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]));
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

function prepareText($text, $is_markdown = true) {
    if (!$is_markdown) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    // Сначала экранируем HTML-сущности
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    
    $markdown_chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    
    // Экранируем URL (безопасный вариант)
    $text = preg_replace_callback('/(https?:\/\/[^\s]+)/', function($matches) {
        return htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8');
    }, $text);

    $result = '';
    $length = mb_strlen($text);
    
    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($text, $i, 1);
        
        if (in_array($char, $markdown_chars)) {
            $char = '\\' . $char;
        }
        
        $result .= $char;
    }
    
    return $result;
}

/**
 * Улучшенная функция отправки сообщений
 */
function sendMessage($chat_id, $text, $keyboard = null, $parse_mode = 'MarkdownV2') {
    $text = trim($text);
    if (empty($text)) {
        logError("Пустое сообщение для чата $chat_id");
        return false;
    }

    // Отключаем Markdown для служебных сообщений
    if (strpos($text, '🆔') !== false || strpos($text, '📋') !== false) {
        $parse_mode = '';
    }

    $data = [
        'chat_id' => $chat_id,
        'text' => $parse_mode === 'MarkdownV2' ? prepareText($text) : $text,
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
        logError("Ошибка отправки: HTTP $http_code - $error");
        
        // Пытаемся отправить как plain text при ошибке
        unset($data['parse_mode']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($ch);
    }

    curl_close($ch);
    return $http_code === 200;
}

// Функция обработки голосового сообщения
function handleVoiceMessage($chat_id, $user_id, $voice_file_id) {
    global $pdo; // Добавляем глобальное подключение
    
    // Проверяем наличие необходимых констант
    if (!defined('YANDEX_FOLDER_ID') || !defined('YANDEX_SPEECHKIT_KEY')) {
        logError("Не настроены ключи Yandex SpeechKit");
        return false;
    }

    // 1. Скачиваем файл
    $voice_file_path = downloadTelegramFile($voice_file_id);
    if (!$voice_file_path) {
        logError("Не удалось скачать голосовое сообщение");
        return false;
    }
    
    // 2. Распознаём речь
    $text = yandexSpeechToText($voice_file_path);
    if (!$text) {
        logError("Не удалось распознать речь");
        return false;
    }
    
    // 3. Сохраняем в БД (используем ту же структуру, что и в saveNote)
    try {
        $encrypted = encrypt($text);
        $stmt = $pdo->prepare("INSERT INTO notes (chat_id, message) VALUES (?, ?)");
        $stmt->execute([$chat_id, $encrypted]);
        
        return true;
    } catch (PDOException $e) {
        logError("Ошибка сохранения голосовой заметки: " . $e->getMessage());
        return false;
    }
}

// Скачивание файла из Telegram
function downloadTelegramFile($file_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getFile?file_id=" . $file_id;
    $response = json_decode(file_get_contents($url), true);
    
    if ($response['ok']) {
        $file_path = $response['result']['file_path'];
        $temp_file = tempnam(sys_get_temp_dir(), 'voice_');
        $file_content = file_get_contents("https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path);
        file_put_contents($temp_file, $file_content);
        return $temp_file;
    }
    return false;
}


// Распознавание речи через Yandex SpeechKit
function yandexSpeechToText($audio_file) {
    $audio_data = file_get_contents($audio_file);
    $url = "https://stt.api.cloud.yandex.net/speech/v1/stt:recognize?folderId=" . YANDEX_FOLDER_ID;
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'Authorization: Api-Key ' . YANDEX_SPEECHKIT_KEY,
            'Content-Type: audio/ogg'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $audio_data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = json_decode(curl_exec($ch), true);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Удаляем временный ogg-файл
    unlink($audio_file);
    
    if ($http_code != 200) {
        logError("Ошибка SpeechKit: HTTP $http_code - " . json_encode($response));
        return false;
    }
    
    return $response['result'] ?? false;
    
    file_put_contents('speechkit.log', date('Y-m-d H:i:s') . " - Response: " . print_r($response, true) . "\n", FILE_APPEND);
}
?>