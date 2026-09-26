<?php
/**
 * Webhook payload → conversation state → lead → reply.
 * $io is injectable so tests can run without calling Meta:
 *   ['send' => fn($token,$psid,$text,$qr), 'name' => fn($token,$psid), 'pass' => fn($token,$psid,$note)]
 */

require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/intent.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/graph.php';

const NB_LIKE_STICKERS = ['369239263222822', '369239343222814', '369239383222810']; // thumbs-up sizes

function nb_config(): array
{
    $cfg = require __DIR__ . '/../config.php';
    date_default_timezone_set($cfg['timezone'] ?? 'Asia/Kathmandu');
    return $cfg;
}

function nb_db(array $cfg): PDO
{
    $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+05:45'");
    return $pdo;
}

function nb_live_io(array $cfg): array
{
    return [
        'send' => fn($t, $p, $x, $q) => nb_send_text($cfg, $t, $p, $x, $q),
        'name' => fn($t, $p) => nb_user_name($cfg, $t, $p),
        'pass' => fn($t, $p, $n) => nb_pass_to_inbox($cfg, $t, $p, $n),
        'seen' => fn($t, $p) => nb_sender_action($cfg, $t, $p, 'mark_seen'),
    ];
}

function nb_handle_payload(array $payload, PDO $db, array $cfg, array $io, ?int $now = null): void
{
    if (($payload['object'] ?? '') !== 'page') return;
    foreach ($payload['entry'] ?? [] as $entry) {
        $pageId = (string)($entry['id'] ?? '');
        $token  = $cfg['pages'][$pageId] ?? null;
        if (!$token) { nb_log($cfg, "Unknown page $pageId — add it to config pages"); continue; }
        foreach ($entry['messaging'] ?? [] as $ev) {
            try {
                nb_handle_event($ev, $pageId, $token, $db, $cfg, $io, $now ?? time());
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                nb_log($cfg, 'Event error: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            }
        }
        // 'standby' = events while another app (e.g. Page Inbox) owns the thread: we only observe.
    }
}

function nb_handle_event(array $ev, string $pageId, string $token, PDO $db, array $cfg, array $io, int $now): void
{
    $m = $ev['message'] ?? null;

    // ---------- echoes: our own sends, or a team member replying from the Inbox ----------
    if ($m && !empty($m['is_echo'])) {
        $psid = (string)($ev['recipient']['id'] ?? '');
        $ours = ($m['metadata'] ?? '') === 'nb_bot' || (string)($m['app_id'] ?? '') === (string)$cfg['app_id'];
        if ($ours || $psid === '') return;
        nb_log_msg($db, $pageId, $psid, 'echo', $m['mid'] ?? null, $m['text'] ?? '[attachment]', 'human');
        $pause = $now + (int)($cfg['bot']['human_pause_hours'] ?? 12) * 3600;
        $db->prepare("INSERT INTO mb_conversations (page_id, psid, human_until, needs_human) VALUES (?,?,?,0)
                      ON DUPLICATE KEY UPDATE human_until = GREATEST(COALESCE(human_until,0), VALUES(human_until)), needs_human = 0")
           ->execute([$pageId, $psid, $pause]);
        return;
    }

    $psid = (string)($ev['sender']['id'] ?? '');
    if ($psid === '' || $psid === $pageId) return;

    // ---------- normalise the inbound event ----------
    $msg = ['kind' => 'text', 'text' => '', 'qr_payload' => null];
    $referral = null; $mid = null; $logBody = '';
    if ($m) {
        $mid = $m['mid'] ?? null;
        $msg['text'] = (string)($m['text'] ?? '');
        $msg['qr_payload'] = $m['quick_reply']['payload'] ?? null;
        $referral = $m['referral'] ?? null;
        if ($msg['text'] === '' && !empty($m['attachments'])) {
            $sticker = (string)($m['sticker_id'] ?? ($m['attachments'][0]['payload']['sticker_id'] ?? ''));
            $msg['kind'] = in_array($sticker, NB_LIKE_STICKERS, true) ? 'like' : 'attachment';
        }
        $logBody = $msg['text'] !== '' ? $msg['text'] : '[' . $msg['kind'] . ']';
    } elseif (isset($ev['postback'])) {
        $msg['kind'] = 'postback';
        $referral = $ev['postback']['referral'] ?? null;
        $mid = $ev['postback']['mid'] ?? null;
        $logBody = '[postback] ' . ($ev['postback']['title'] ?? $ev['postback']['payload'] ?? '');
    } elseif (isset($ev['referral'])) {
        $msg['kind'] = 'referral';
        $referral = $ev['referral'];
        $logBody = '[referral]';
    } else {
        return; // delivery, read, reaction, handover notifications — nothing to do
    }

    // ---------- dedupe (Meta retries deliveries) ----------
    if (!nb_log_msg($db, $pageId, $psid, 'in', $mid, $logBody, null)) return;

    // ---------- conversation row + customer name ----------
    $db->prepare("INSERT IGNORE INTO mb_conversations (page_id, psid) VALUES (?,?)")->execute([$pageId, $psid]);
    $st = $db->prepare("SELECT customer_name FROM mb_conversations WHERE page_id=? AND psid=?");
    $st->execute([$pageId, $psid]);
    if (!$st->fetchColumn() && ($name = ($io['name'])($token, $psid))) {
        $db->prepare("UPDATE mb_conversations SET customer_name=? WHERE page_id=? AND psid=?")->execute([$name, $pageId, $psid]);
    }

    // ---------- decide under a row lock (customers often send 3 messages at once) ----------
    $db->beginTransaction();
    $st = $db->prepare("SELECT * FROM mb_conversations WHERE page_id=? AND psid=? FOR UPDATE");
    $st->execute([$pageId, $psid]);
    $conv = $st->fetch();

    $d = nb_decide($conv, $msg, $now, $cfg['bot'] ?? []);
    if (empty($cfg['bot']['enabled'])) $d['reply'] = null;

    $set = $d['set'];
    $set['last_seen'] = date('Y-m-d H:i:s', $now);
    if ($msg['text'] !== '') {
        $set['last_message'] = mb_substr($msg['text'], 0, 1000);
        if (empty($conv['first_message'])) $set['first_message'] = mb_substr($msg['text'], 0, 1000);
    }
    if ($referral) $set['ad_context'] = json_encode(nb_ref_summary($referral), JSON_UNESCAPED_UNICODE);
    if ($d['handover']) $set['needs_human'] = 1;
    if ($d['reply']) $set['bot_replies'] = (int)$conv['bot_replies'] + 1;
    nb_update($db, 'mb_conversations', $set, (int)$conv['id']);

    // mark the inbound log row with the detected intent
    if ($mid) $db->prepare("UPDATE mb_messages SET intent=? WHERE mid=?")->execute([$d['intent'], $mid]);

    if ($d['lead']) nb_save_lead($db, $d['lead'], $pageId, $psid, $conv, $d['phone'], $set, $now);
    $db->commit();

    // ---------- side effects after commit ----------
    if ($d['reply']) {
        $text = nb_text($d['reply'], $d['lang']);
        $r = ($io['send'])($token, $psid, $text, $d['quick_phone']);
        nb_log_msg($db, $pageId, $psid, 'out', $r['data']['message_id'] ?? null, $text, $d['reply']);
    }
    if ($d['handover'] && !empty($cfg['bot']['pass_to_inbox'])) {
        ($io['pass'])($token, $psid, 'needs_human:' . $d['intent']);
    }
}

/** Create / update / reopen the lead row. */
function nb_save_lead(PDO $db, string $op, string $pageId, string $psid, array $conv, ?string $phone, array $set, int $now): void
{
    $name = $conv['customer_name'];
    $ad   = $set['ad_context'] ?? $conv['ad_context'];
    $ctx  = nb_build_context($db, $pageId, $psid, $ad);
    $stamp = date('Y-m-d H:i', $now);

    if ($op === 'create') {
        $db->prepare("INSERT INTO mb_leads (page_id, psid, customer_name, phone, context, ad_context, status)
                      VALUES (?,?,?,?,?,?,'new')
                      ON DUPLICATE KEY UPDATE phone=VALUES(phone), context=VALUES(context),
                        ad_context=COALESCE(VALUES(ad_context), ad_context), status='new',
                        notes=CONCAT(COALESCE(notes,''), '\n[$stamp] Lead received again')")
           ->execute([$pageId, $psid, $name, $phone, $ctx, $ad]);
    } elseif ($op === 'update_phone') {
        $db->prepare("UPDATE mb_leads SET
                        alt_phones = TRIM(BOTH ',' FROM CONCAT_WS(',', alt_phones, phone)),
                        phone = ?, context = ?, status = 'new',
                        notes = CONCAT(COALESCE(notes,''), '\n[$stamp] Customer sent a new number')
                      WHERE page_id=? AND psid=?")
           ->execute([$phone, $ctx, $pageId, $psid]);
    } elseif ($op === 'reopen') {
        $db->prepare("UPDATE mb_leads SET status='new', context=?,
                        notes = CONCAT(COALESCE(notes,''), '\n[$stamp] Returning customer — messaged again')
                      WHERE page_id=? AND psid=?")
           ->execute([$ctx, $pageId, $psid]);
    }
}

/** Human-readable context for the sales team: ad + what the customer wrote. */
function nb_build_context(PDO $db, string $pageId, string $psid, ?string $adJson): string
{
    $parts = [];
    if ($adJson && ($ad = json_decode($adJson, true))) {
        if (!empty($ad['ad_title'])) $parts[] = 'Ad: ' . $ad['ad_title'];
        elseif (!empty($ad['ad_id'])) $parts[] = 'Ad ID: ' . $ad['ad_id'];
        if (!empty($ad['ref'])) $parts[] = 'Ref: ' . $ad['ref'];
    }
    $st = $db->prepare("SELECT body FROM mb_messages WHERE page_id=? AND psid=? AND direction='in'
                        ORDER BY id DESC LIMIT 6");
    $st->execute([$pageId, $psid]);
    $msgs = array_reverse($st->fetchAll(PDO::FETCH_COLUMN));
    if ($msgs) $parts[] = 'Customer: ' . implode(' | ', $msgs);
    return mb_substr(implode("\n", $parts), 0, 2000);
}

function nb_ref_summary(array $r): array
{
    $ads = $r['ads_context_data'] ?? [];
    return array_filter([
        'source'   => $r['source'] ?? null,     // ADS, SHORTLINK, CUSTOMER_CHAT_PLUGIN …
        'type'     => $r['type'] ?? null,
        'ref'      => $r['ref'] ?? null,
        'ad_id'    => $r['ad_id'] ?? null,
        'ad_title' => $ads['ad_title'] ?? null,
        'post_id'  => $ads['post_id'] ?? null,
        'product_id' => $ads['product_id'] ?? null,
        'photo_url'  => $ads['photo_url'] ?? null,
    ]);
}

/** Returns false if this message id was already processed. */
function nb_log_msg(PDO $db, string $pageId, string $psid, string $dir, ?string $mid, string $body, ?string $intent): bool
{
    $st = $db->prepare("INSERT IGNORE INTO mb_messages (page_id, psid, direction, mid, body, intent) VALUES (?,?,?,?,?,?)");
    $st->execute([$pageId, $psid, $dir, $mid, mb_substr($body, 0, 2000), $intent]);
    return $mid === null || $st->rowCount() > 0;
}

function nb_update(PDO $db, string $table, array $set, int $id): void
{
    if (!$set) return;
    $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
    $db->prepare("UPDATE `$table` SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
}
