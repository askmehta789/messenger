<?php
/**
 * Pure decision engine — no DB, no HTTP. Given the conversation state and one
 * inbound event, decide what to reply and how the state changes.
 * Fully unit-tested in tests/run_tests.php.
 *
 * $conv keys: phone, bot_replies, ask_count, last_ask_at, invalid_count,
 *             last_confirm_at, human_until, handover_sent, phone_at, lang
 * $msg keys:  kind (text|attachment|like|postback|referral), text, qr_payload
 */

function nb_decide(array $conv, array $msg, int $now, array $cfg): array
{
    $c = $cfg + [
        'ask_cooldown_sec'     => 45,      // no two "send your number" asks within this window
        'max_asks'             => 4,       // asks without getting a number → hand to human
        'max_invalid'          => 2,       // invalid-number replies before handing to human
        'confirm_cooldown_sec' => 6 * 3600,// Flow 7 at most once per 6 h
        'lead_renew_days'      => 30,      // returning customer after N days → reopen lead
        'quick_reply_phone'    => true,
    ];
    $text = (string)($msg['text'] ?? '');
    $kind = $msg['kind'] ?? 'text';
    $lang = $text !== '' ? nb_detect_lang($text, $conv['lang'] ?? 'ne') : ($conv['lang'] ?? 'ne');

    $out = ['reply' => null, 'quick_phone' => false, 'lang' => $lang, 'phone' => null,
            'lead' => null, 'handover' => false, 'intent' => null, 'set' => ['lang' => $lang]];

    // ---- phone detection: native quick-reply first, then free text ----
    $phone = null; $attempt = false;
    if (!empty($msg['qr_payload'])) {
        $phone = np_normalize_mobile((string)$msg['qr_payload']);
        $attempt = true;
    }
    if (!$phone && $text !== '') {
        $r = np_extract_phone($text);
        $phone = $r['phone']; $attempt = $attempt || $r['attempt'];
    }
    $intent = $phone ? 'phone' : ($kind === 'text' ? nb_intent($text) : $kind);
    if (!$phone && $attempt) $intent = 'phone_invalid';
    $out['intent'] = $intent;

    $hasPhone  = !empty($conv['phone']);
    $humanMode = !empty($conv['human_until']) && (int)$conv['human_until'] > $now;
    $firstEver = (int)($conv['bot_replies'] ?? 0) === 0;

    // ---- a valid number always gets captured, even while a human is handling the chat ----
    if ($phone) {
        $out['phone'] = $phone;
        if (!$hasPhone) {
            $out['lead'] = 'create';
            $out['set'] += ['phone' => $phone, 'phone_at' => $now, 'invalid_count' => 0, 'ask_count' => 0];
            $out['reply'] = $humanMode ? null : 'phone_ok';
        } elseif ($conv['phone'] !== $phone) {
            $out['lead'] = 'update_phone';
            $out['set'] += ['phone' => $phone, 'phone_at' => $now];
            $out['reply'] = $humanMode ? null : 'phone_ok';
        } else {
            $out['lead'] = nb_is_stale($conv, $now, $c) ? 'reopen' : null;
            if ($out['lead']) $out['set']['phone_at'] = $now;
            $out['reply'] = (!$humanMode && nb_confirm_allowed($conv, $now, $c)) ? 'already_have' : null;
        }
        if ($out['reply'] === 'already_have') $out['set']['last_confirm_at'] = $now;
        return $out;
    }

    if ($humanMode) return $out;                           // a person is handling it — stay silent

    // ---- explicit request for a person, or a complaint ----
    if ($intent === 'human') {
        $out['handover'] = true;
        $out['set']['human_until'] = $now + 24 * 3600;
        if (empty($conv['handover_sent'])) { $out['reply'] = 'handover'; $out['set']['handover_sent'] = 1; }
        return $out;
    }

    // ---- number already on file (Flow 7) ----
    if ($hasPhone) {
        if (nb_is_stale($conv, $now, $c)) { $out['lead'] = 'reopen'; $out['set']['phone_at'] = $now; }
        if (in_array($intent, ['ack', 'like', 'empty'], true)) return $out;
        if (nb_confirm_allowed($conv, $now, $c) || $out['lead'] === 'reopen') {
            $out['reply'] = 'already_have';
            $out['set']['last_confirm_at'] = $now;
        }
        return $out;
    }

    // ---- invalid number (Flow 5) ----
    if ($intent === 'phone_invalid') {
        $n = (int)($conv['invalid_count'] ?? 0);
        if ($n >= $c['max_invalid']) {
            $out['handover'] = true;
            $out['set']['human_until'] = $now + 24 * 3600;
            if (empty($conv['handover_sent'])) { $out['reply'] = 'handover'; $out['set']['handover_sent'] = 1; }
            return $out;
        }
        $out['reply'] = 'phone_invalid';
        $out['quick_phone'] = $c['quick_reply_phone'];
        $out['set']['invalid_count'] = $n + 1;
        $out['set']['last_ask_at'] = $now;
        return $out;
    }

    // ---- ask for the number (Flows 1, 2, 6) with anti-spam limits ----
    if (!$firstEver && in_array($intent, ['like', 'ack', 'empty'], true)) return $out;
    $asks = (int)($conv['ask_count'] ?? 0);
    $recent = !empty($conv['last_ask_at']) && ($now - (int)$conv['last_ask_at']) < $c['ask_cooldown_sec'];
    if ($asks >= $c['max_asks']) {
        $out['handover'] = true;                              // customer keeps chatting without a number
        $out['set']['human_until'] = $now + 24 * 3600;
        return $out;                                          // silent — the team takes over from the inbox
    }
    if ($recent && !$firstEver) return $out;                 // customer sent several messages in a burst

    $out['reply'] = $firstEver ? 'greeting' : ($intent === 'call_me' ? 'call_me' : 'ask_phone');
    $out['quick_phone'] = $c['quick_reply_phone'];
    $out['set']['ask_count'] = $asks + 1;
    $out['set']['last_ask_at'] = $now;
    return $out;
}

function nb_confirm_allowed(array $conv, int $now, array $c): bool
{
    return empty($conv['last_confirm_at']) || ($now - (int)$conv['last_confirm_at']) >= $c['confirm_cooldown_sec'];
}

function nb_is_stale(array $conv, int $now, array $c): bool
{
    return !empty($conv['phone_at']) && ($now - (int)$conv['phone_at']) > $c['lead_renew_days'] * 86400;
}
