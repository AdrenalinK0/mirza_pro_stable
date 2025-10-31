<?php
require_once 'config.php';

#---------------- Telegram API Function ----------------#
function telegram($method, $datas = [], $botToken = null)
{
    global $APIKEY, $ApiToken;

    $token = $botToken ?? ($ApiToken ?? $APIKEY);
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('Unable to initialise cURL for Telegram request.');
        return ['ok' => false, 'description' => 'cURL init failed'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $curlError = curl_error($ch);
        curl_close($ch);
        error_log('Telegram request failed: ' . $curlError);
        return ['ok' => false, 'description' => $curlError ?: 'Request failed'];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        error_log(sprintf('Invalid response (HTTP %d): %s', $httpCode, substr($rawResponse, 0, 200)));
        return ['ok' => false, 'error_code' => $httpCode, 'description' => 'Invalid response'];
    }

    if (isset($decoded['ok']) && !$decoded['ok']) {
        error_log(json_encode($decoded));
    }

    return $decoded;
}

#---------------- Telegram Helper Functions ----------------#
function sendMessage($chat_id, $text, $keyboard = null, $parse_mode = 'HTML', $botToken = null){
    return telegram('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,
    ], $botToken);
}

function sendDocument($chat_id, $documentPath, $caption = '', $botToken = null){
    return telegram('sendDocument', [
        'chat_id' => $chat_id,
        'document' => new CURLFile($documentPath),
        'caption' => $caption,
    ], $botToken);
}

function sendDocumentById($chat_id, $documentId, $caption = '', $botToken = null){
    return telegram('sendDocument', [
        'chat_id' => $chat_id,
        'document' => $documentId,
        'caption' => $caption,
    ], $botToken);
}

function forwardMessage($chat_id, $message_id, $from_chat_id, $botToken = null){
    return telegram('forwardMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'from_chat_id' => $from_chat_id,
    ], $botToken);
}

function sendPhoto($chat_id, $photoId, $caption = '', $botToken = null){
    return telegram('sendPhoto', [
        'chat_id' => $chat_id,
        'photo' => $photoId,
        'caption' => $caption,
    ], $botToken);
}

function sendVideo($chat_id, $videoId, $caption = '', $botToken = null){
    return telegram('sendVideo', [
        'chat_id' => $chat_id,
        'video' => $videoId,
        'caption' => $caption,
    ], $botToken);
}

function editMessageText($chat_id, $message_id, $text, $keyboard = null, $parse_mode = 'HTML', $botToken = null){
    return telegram('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,
    ], $botToken);
}

function deleteMessage($chat_id, $message_id, $botToken = null){
    return telegram('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
    ], $botToken);
}

function pinMessage($chat_id, $message_id, $botToken = null){
    return telegram('pinChatMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
    ], $botToken);
}

function unpinAllMessages($chat_id, $botToken = null){
    return telegram('unpinAllChatMessages', [
        'chat_id' => $chat_id,
    ], $botToken);
}

function answerInlineQuery($inline_query_id, $results, $botToken = null){
    return telegram('answerInlineQuery', [
        'inline_query_id' => $inline_query_id,
        'results' => json_encode($results)
    ], $botToken);
}

function getFileInfo($fileId, $botToken = null){
    return telegram('getFile', ['file_id' => $fileId], $botToken);
}

function convertPersianNumbersToEnglish($string){
    return str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        ['0','1','2','3','4','5','6','7','8','9'],
        $string
    );
}

#---------------- Duplicate Update Checker ----------------#
function isDuplicateUpdate($updateId){
    if (!is_numeric($updateId) || $updateId <= 0) return false;

    $cacheDir = __DIR__ . '/storage/cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) return false;

    $cacheFile = $cacheDir . '/recent_updates.json';
    $handle = fopen($cacheFile, 'c+');
    if (!$handle) return false;

    try {
        if (!flock($handle, LOCK_EX)) { fclose($handle); return false; }
        rewind($handle);
        $contents = stream_get_contents($handle);
        $recentUpdates = $contents ? json_decode($contents, true) : [];
        if (!is_array($recentUpdates)) $recentUpdates = [];

        $now = time();
        $ttl = 120; // seconds

        foreach ($recentUpdates as $id => $ts) {
            if (!is_numeric($ts) || ($now - (int)$ts) > $ttl) unset($recentUpdates[$id]);
        }

        if (isset($recentUpdates[$updateId])) { flock($handle, LOCK_UN); fclose($handle); return true; }

        $recentUpdates[$updateId] = $now;
        if (count($recentUpdates) > 200) { asort($recentUpdates); $recentUpdates = array_slice($recentUpdates, -200, null, true); }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($recentUpdates));

        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $e) {
        try { flock($handle, LOCK_UN); } catch (Throwable $ignored){}
        fclose($handle);
        return false;
    }

    return false;
}

#---------------- Incoming Update Handling ----------------#
$update = json_decode(file_get_contents("php://input"), true);
$update_id = $update['update_id'] ?? 0;
if (isDuplicateUpdate($update_id)) { http_response_code(200); exit; }

$from_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? $update["inline_query"]['from']['id'] ?? 0;
$chat_type = $update["message"]["chat"]["type"] ?? $update['callback_query']['message']['chat']['type'] ?? '';
$text = convertPersianNumbersToEnglish($update["message"]["text"] ?? '');
$text_inline = $update["callback_query"]["message"]['text'] ?? '';
$message_id = $update["message"]["message_id"] ?? $update["callback_query"]["message"]["message_id"] ?? 0;
$photo = $update["message"]["photo"] ?? 0;
$document = $update["message"]["document"] ?? 0;
$fileid = $update["message"]["document"]["file_id"] ?? 0;
$photoid = $photo ? end($photo)["file_id"] : '';
$caption = $update["message"]["caption"] ?? '';
$video = $update["message"]["video"] ?? 0;
$videoid = $video ? $video["file_id"] : 0;
$forward_from_id = $update["message"]["reply_to_message"]["forward_from"]["id"] ?? 0;
$datain = $update["callback_query"]["data"] ?? '';
$first_name = $update['message']['from']['first_name'] ?? $update["callback_query"]["from"]["first_name"] ?? $update["inline_query"]['from']['first_name'] ?? '';
$last_name = $update['message']['from']['last_name'] ?? $update["callback_query"]["from"]["last_name"] ?? $update["inline_query"]['from']['last_name'] ?? '';
$username = $update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? $update["inline_query"]['from']['username'] ?? 'NOT_USERNAME';
$user_phone = $update["message"]["contact"]["phone_number"] ?? 0;
$contact_id = $update["message"]["contact"]["user_id"] ?? 0;
$callback_query_id = $update["callback_query"]["id"] ?? 0;
$inline_query_id = $update["inline_query"]["id"] ?? 0;
$query = $update["inline_query"]["query"] ?? 0;