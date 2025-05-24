<?php

$botToken = getenv('BOT_TOKEN');
$adminId = getenv('ADMIN_ID');
$channelId = getenv('CHANNEL_ID');

// Read incoming Telegram update
$update = json_decode(file_get_contents("php://input"), true);

// Handle messages
if (isset($update["message"])) {
    $message = $update["message"];
    $chatId = $message["chat"]["id"];
    $text = trim($message["text"] ?? '');
    $userId = $message["from"]["id"];
    
    // Reply to /start
    if ($text === "/start") {
        file_put_contents("users.json", json_encode(array_unique(array_merge(json_decode(@file_get_contents("users.json"), true) ?? [], [$userId]))));
        sendMessage($chatId, "👋 Welcome! Send me a file to get your File ID.\n\nTo retrieve a file, use: `/get <file_id>`", true);
    }

    // Handle file upload (document, photo, video, etc.)
    $fileTypes = ['document', 'video', 'photo', 'audio', 'voice'];
    foreach ($fileTypes as $type) {
        if (isset($message[$type])) {
            $file = $message[$type];
            $fileId = is_array($file) ? end($file)["file_id"] : $file["file_id"];
            $caption = $message["caption"] ?? '';
            $uniqueId = str_replace(['-', '+', '_'], '', base64_encode(random_bytes(6)));

            file_put_contents("data/$uniqueId.json", json_encode([
                "file_id" => $fileId,
                "type" => $type,
                "caption" => $caption
            ]));

            sendMessage($chatId, "✅ File saved!\n\n🆔 File ID: `$uniqueId`", true);
        }
    }

    // Handle /get command
    if (str_starts_with($text, "/get")) {
        $parts = explode(" ", $text);
        $fileId = $parts[1] ?? null;
        if ($fileId && file_exists("data/$fileId.json")) {
            $data = json_decode(file_get_contents("data/$fileId.json"), true);
            sendFile($chatId, $data);
        } else {
            sendMessage($chatId, "❌ File not found.", true);
        }
    }

    // Handle /announce (admin only)
    if (str_starts_with($text, "/announce") && $userId == $adminId) {
        $msg = trim(str_replace("/announce", "", $text));
        $users = json_decode(file_get_contents("users.json"), true) ?? [];
        foreach ($users as $u) {
            sendMessage($u, "📢 Announcement:\n$msg");
        }
        sendMessage($chatId, "✅ Broadcast sent.");
    }
}

function sendMessage($chatId, $text, $markdown = false) {
    global $botToken;
    file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?" . http_build_query([
        "chat_id" => $chatId,
        "text" => $text,
        "parse_mode" => $markdown ? "Markdown" : null
    ]));
}

function sendFile($chatId, $data) {
    global $botToken;
    $type = $data["type"];
    $fileId = $data["file_id"];
    $caption = $data["caption"] ?? '';

    $url = "https://api.telegram.org/bot$botToken/send" . ucfirst($type);
    file_get_contents($url . '?' . http_build_query([
        "chat_id" => $chatId,
        $type => $fileId,
        "caption" => $caption,
        "parse_mode" => "Markdown"
    ]));
}
