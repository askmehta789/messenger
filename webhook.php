<?php
/**
 * Messenger webhook endpoint — set this URL as the Callback URL in your Meta app.
 *   GET  = subscription verification
 *   POST = events (messages, messaging_postbacks, messaging_referrals, message_echoes)
 */
require_once __DIR__ . '/lib/handler.php';
$cfg = nb_config();

// ---- 1. Verification handshake (PHP turns hub.mode into hub_mode) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals($cfg['verify_token'], (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo $_GET['hub_challenge'] ?? '';
    } else {
        http_response_code(403);
    }
    exit;
}

// ---- 2. Signature check: reject anything not signed by Meta with our app secret ----
$raw = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $raw, $cfg['app_secret']);
if (!hash_equals($expected, $sig)) {
    nb_log($cfg, 'Rejected webhook: bad signature');
    http_response_code(403);
    exit;
}

// ---- 3. Acknowledge immediately (Meta expects 200 within a few seconds), then process ----
http_response_code(200);
header('Content-Type: text/plain');
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    header('Connection: close');
    header('Content-Length: 14');
    @ob_end_flush();
    flush();
}

try {
    $payload = json_decode($raw, true);
    if (is_array($payload)) nb_handle_payload($payload, nb_db($cfg), $cfg, nb_live_io($cfg));
} catch (Throwable $e) {
    nb_log($cfg, 'Fatal: ' . $e->getMessage());
}
