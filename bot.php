<?php
// ─── CONFIG ─────────────────────────────────────────────────────────────
$botToken    = getenv('BOT_TOKEN');
$adminId     = getenv('ADMIN_ID');
$channelId   = getenv('CHANNEL_ID');
$botUsername = getenv('BOT_USERNAME');
$usersFile   = __DIR__ . '/users.json';

$apiURL = "https://api.telegram.org/bot$botToken/";

// ─── UPDATE HANDLING ────────────────────────────────────────────────────
$update = json_decode(file_get_contents('php://input'), true);
if (!$update || !isset($update['message'])) exit;

$message   = $update['message'];
$chatId    = $message['chat']['id'];
$userId    = $message['from']['id'];
$messageId = $message['message_id'];
$text      = $message['text'] ?? '';

// ─── USER TRACKING ──────────────────────────────────────────────────────
$users = file_exists($usersFile)
    ? json_decode(file_get_contents($usersFile), true)
    : [];
if (!in_array($chatId, $users)) {
    $users[] = $chatId;
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// ─── HELPERS ────────────────────────────────────────────────────────────
function apiRequest(string $method, array $params = []) {
    global $apiURL;
    $ch = curl_init($apiURL . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function sendMessage(int $chatId, string $text, int $replyTo = null) {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];
    if ($replyTo) $params['reply_to_message_id'] = $replyTo;
    apiRequest('sendMessage', $params);
}

function forwardToChannel(array $message) {
    global $channelId;
    return apiRequest('forwardMessage', [
        'chat_id'      => $channelId,
        'from_chat_id' => $message['chat']['id'],
        'message_id'   => $message['message_id'],
    ]);
}

function copyMessage(int $toChat, int $fromChat, int $msgId) {
    return apiRequest('copyMessage', [
        'chat_id'      => $toChat,
        'from_chat_id' => $fromChat,
        'message_id'   => $msgId,
    ]);
}

function buildFileId(int $userId, int $msgId): string {
    return time() . '_' . $userId . '_' . $msgId;
}

function parseFileId(string $fileId): array|false {
    $parts = explode('_', $fileId);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
        return false;
    }
    return [
        'timestamp'   => (int)$parts[0],
        'user_id'     => (int)$parts[1],
        'message_id'  => (int)$parts[2],
    ];
}

// ─── COMMANDS ────────────────────────────────────────────────────────────
// /start [deep_link]
if (stripos($text, '/start') === 0) {
    $args = trim(substr($text, 6));
    if ($args !== '') {
        $info = parseFileId($args);
        if (!$info) {
            sendMessage($chatId, "❌ *Invalid File ID format.*", $messageId);
            exit;
        }
        $res = copyMessage($chatId, $channelId, $info['message_id']);
        if (empty($res['ok'])) {
            sendMessage($chatId, "❌ *File not found or expired.*", $messageId);
        }
    } else {
        $msg  = "👋 *Welcome to Report Cloud Storage Bot!*\n\n";
        $msg .= "📤 Send any file (photo, video, doc, audio, etc.) to save it.\n";
        $msg .= "🆔 You’ll get a unique File ID and deep link.\n\n";
        $msg .= "📥 To retrieve, send me the File ID or click your deep link.\n";
        $msg .= "\n❓ Use /help to see commands.";
        sendMessage($chatId, $msg, $messageId);
    }
    exit;
}

// /help
if (stripos($text, '/help') === 0) {
    $msg  = "📚 *Commands:*\n";
    $msg .= "/start - Welcome or retrieve via deep link\n";
    $msg .= "/help - This help message\n";
    $msg .= "/announce - *Admin only*: reply + /announce to broadcast\n\n";
    $msg .= "🔖 *File Retrieval:* Send File ID or deep link.\n";
    $msg .= "💾 *File Types:* All media and documents supported.";
    sendMessage($chatId, $msg, $messageId);
    exit;
}

// /announce
if (stripos($text, '/announce') === 0) {
    if ($userId != $adminId) {
        sendMessage($chatId, "❌ *Unauthorized.* Only admin can broadcast.", $messageId);
        exit;
    }
    if (isset($message['reply_to_message'])) {
        $toBroadcast = $message['reply_to_message'];
        foreach ($users as $u) {
            copyMessage((int)$u, $chatId, $toBroadcast['message_id']);
        }
        sendMessage($chatId, "✅ *Announcement sent to all users.*", $messageId);
    } else {
        sendMessage($chatId, "❌ *Usage:* Reply to a message with `/announce`.", $messageId);
    }
    exit;
}

// ─── FILE UPLOAD HANDLING ────────────────────────────────────────────────
$hasFile = isset($message['document']) || isset($message['photo']) || isset($message['video']) ||
           isset($message['audio']) || isset($message['voice']) || isset($message['animation']) ||
           isset($message['sticker']);

if ($hasFile) {
    $fwd = forwardToChannel($message);
    if (!empty($fwd['result']['message_id'])) {
        $newId   = $fwd['result']['message_id'];
        $fileId  = buildFileId($userId, $newId);
        $deepLink = "https://t.me/$botUsername?start=$fileId";

        $msg  = "✅ *Your file has been saved!*\n\n";
        $msg .= "📁 *File ID:* `$fileId`\n";
        $msg .= "🔗 *Deep Link:* [$deepLink]($deepLink)\n\n";
        $msg .= "📥 Send this File ID or click the link anytime to retrieve your file.";
        sendMessage($chatId, $msg, $messageId);
    } else {
        sendMessage($chatId, "❌ *Failed to save the file.*", $messageId);
    }
    exit;
}

// ─── FILE RETRIEVAL VIA TEXT FILE ID ─────────────────────────────────────
if ($text !== '' && preg_match('/^\d+_\d+_\d+$/', $text)) {
    $info = parseFileId($text);
    if (!$info) {
        sendMessage($chatId, "❌ *Invalid File ID format.*", $messageId);
        exit;
    }
    $res = copyMessage($chatId, $channelId, $info['message_id']);
    if (empty($res['ok'])) {
        sendMessage($chatId, "❌ *File not found or expired.*", $messageId);
    }
    exit;
}

// ─── UNKNOWN COMMAND ─────────────────────────────────────────────────────
sendMessage($chatId, "❓ I didn't understand that. Send /help for commands.", $messageId);
exit;
