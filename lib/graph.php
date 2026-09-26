<?php
/** Minimal Messenger Platform / Graph API client. */

function nb_graph(array $cfg, string $method, string $path, string $token, array $body = []): array
{
    $url = 'https://graph.facebook.com/' . $cfg['graph_version'] . '/' . ltrim($path, '/');
    $ch = curl_init();
    if ($method === 'GET') {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($body + ['access_token' => $token]);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$raw, true) ?: [];
    if ($code >= 400 || $err) nb_log($cfg, "Graph $method $path failed ($code) $err " . substr((string)$raw, 0, 500));
    return ['ok' => $code > 0 && $code < 400, 'code' => $code, 'data' => $json];
}

/** Send a text reply inside the 24-hour standard messaging window. */
function nb_send_text(array $cfg, string $token, string $psid, string $text, bool $phoneQuickReply = false): array
{
    $message = ['text' => $text, 'metadata' => 'nb_bot'];
    if ($phoneQuickReply) {
        // Native Messenger button: pre-filled with the phone on the user's Facebook profile (if any).
        $message['quick_replies'] = [['content_type' => 'user_phone_number']];
    }
    return nb_graph($cfg, 'POST', 'me/messages', $token, [
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => $message,
    ]);
}

function nb_sender_action(array $cfg, string $token, string $psid, string $action): void
{
    nb_graph($cfg, 'POST', 'me/messages', $token, ['recipient' => ['id' => $psid], 'sender_action' => $action]);
}

/** Customer's display name (needs pages_messaging). Returns null on failure. */
function nb_user_name(array $cfg, string $token, string $psid): ?string
{
    $r = nb_graph($cfg, 'GET', $psid, $token, ['fields' => 'first_name,last_name']);
    if (!$r['ok']) return null;
    $name = trim(($r['data']['first_name'] ?? '') . ' ' . ($r['data']['last_name'] ?? ''));
    return $name !== '' ? $name : null;
}

/** Handover Protocol: give the thread to the Page Inbox so staff can take over. */
function nb_pass_to_inbox(array $cfg, string $token, string $psid, string $note): void
{
    nb_graph($cfg, 'POST', 'me/pass_thread_control', $token, [
        'recipient'     => ['id' => $psid],
        'target_app_id' => $cfg['bot']['inbox_app_id'],
        'metadata'      => $note,
    ]);
}

function nb_log(array $cfg, string $line): void
{
    $f = $cfg['log_file'] ?? null;
    if (!$f) return;
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    @file_put_contents($f, date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
