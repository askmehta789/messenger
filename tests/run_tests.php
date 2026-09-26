<?php
/**
 * php tests/run_tests.php            → unit tests (phone, intent, engine)
 * php tests/run_tests.php --db       → also end-to-end webhook tests against config.php's database
 */
require_once __DIR__ . '/../lib/phone.php';
require_once __DIR__ . '/../lib/intent.php';
require_once __DIR__ . '/../lib/messages.php';
require_once __DIR__ . '/../lib/engine.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $label) { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $label\n"; } }

echo "Phone detection\n";
$valid = [
    '9812345678' => '9812345678', '9712345678' => '9712345678', '9612345678' => '9612345678',
    '+9779812345678' => '9812345678', '+977 9812345678' => '9812345678', '+977-981-234-5678' => '9812345678',
    '977 98 1234 5678' => '9812345678', '00977 9801234567' => '9801234567', '98-123-45678' => '9812345678',
    '981 234 5678' => '9812345678', '(981) 234-5678' => '9812345678', '९८१२३४५६७८' => '9812345678',
    'mero number 9841234567 ho' => '9841234567', 'मेरो नम्बर ९८०१२३४५६७ हो' => '9801234567',
    '9812345678 ma call garnus' => '9812345678', '2 ota chaiyo 9812345678' => '9812345678',
    '9812345678 1100' => '9812345678', 'call me on 98.1234.5678' => '9812345678',
];
foreach ($valid as $in => $want) { $r = np_extract_phone($in); ok($r['phone'] === $want, "valid '$in' → got " . var_export($r['phone'], true)); }

$notPhoneNoAttempt = ['Price kati ho?', '1100', 'Rs 1800 ho?', '2 ota', '2 pcs 1800', 'size 42', 'hello', '1100 1800', '2026', '25000', '1100 ma 2 ota'];
foreach ($notPhoneNoAttempt as $in) { $r = np_extract_phone($in); ok($r['phone'] === null && !$r['attempt'], "not a phone, not an attempt: '$in'"); }

$invalidAttempt = ['98123', '+977 98123', '981234567', '98123456789', '9512345678', '+977 01 4412345', '8812345678', '98123456', '+977 98123'];
foreach ($invalidAttempt as $in) { $r = np_extract_phone($in); ok($r['phone'] === null && $r['attempt'], "invalid attempt: '$in'"); }

echo "Intent + language\n";
$intents = [
    'Price kati ho?' => 'product', 'मूल्य कति हो?' => 'product', 'kati parcha?' => 'product', 'यो कति पर्छ?' => 'product',
    'offer cha?' => 'product', 'delivery charge?' => 'product', 'कसरी अर्डर गर्ने?' => 'product', 'original ho?' => 'product',
    'मलाई call गर्नुहोस्' => 'call_me', 'call me' => 'call_me', 'फोन गर्नुहोस्' => 'call_me', 'contact me' => 'call_me',
    'call garnus' => 'call_me', 'मलाई फोन गर्नु' => 'call_me', 'number पठाउँछु' => 'call_me',
    'ok' => 'ack', 'Thank you' => 'ack', 'धन्यवाद' => 'ack', 'I want to talk to a human' => 'human', 'पैसा फिर्ता' => 'human',
    'hello' => 'other',
];
foreach ($intents as $in => $want) ok(nb_intent($in) === $want, "intent '$in' → " . nb_intent($in) . " (want $want)");
ok(nb_detect_lang('How much is this?') === 'en', 'lang en');
ok(nb_detect_lang('price kati ho?') === 'ne', 'lang roman-nepali → ne');
ok(nb_detect_lang('यो कति पर्छ?') === 'ne', 'lang devanagari');
ok(nb_detect_lang('price?') === 'en', 'lang bare english word');

echo "Conversation engine\n";
$cfg = [];
$new = ['phone' => null, 'bot_replies' => 0, 'ask_count' => 0, 'last_ask_at' => null, 'invalid_count' => 0,
        'last_confirm_at' => null, 'human_until' => null, 'handover_sent' => 0, 'phone_at' => null, 'lang' => 'ne'];
$T = 1_800_000_000;
$apply = function (array $conv, array $d) { if ($d['reply']) $conv['bot_replies']++; return array_merge($conv, $d['set']); };
$t = fn($s) => ['kind' => 'text', 'text' => $s];

// Expected-result scenario from the brief
$d = nb_decide($new, $t('यो कति पर्छ?'), $T, $cfg);
ok($d['reply'] === 'greeting', 'first message (price q) → greeting (Flow 1 already asks for number)');
$c = $apply($new, $d);
$d = nb_decide($c, $t('9812345678'), $T + 60, $cfg);
ok($d['reply'] === 'phone_ok' && $d['lead'] === 'create' && $d['phone'] === '9812345678', 'valid number → Flow 4 + lead');
$c = $apply($c, $d);
$d = nb_decide($c, $t('delivery kati?'), $T + 120, $cfg);
ok($d['reply'] === 'already_have', 'after number → Flow 7, never asks again');
$c = $apply($c, $d);
$d = nb_decide($c, $t('price?'), $T + 180, $cfg);
ok($d['reply'] === null, 'Flow 7 throttled (no spam)');
$d = nb_decide($c, $t('ok'), $T + 200, $cfg);
ok($d['reply'] === null, 'ack after number → silent');
$d = nb_decide($c, $t('9812345678'), $T + 300, $cfg);
ok($d['reply'] === null && $d['lead'] === null, 'same number again within cooldown → silent, no duplicate lead');
$d = nb_decide($c, $t('9801111111'), $T + 400, $cfg);
ok($d['reply'] === 'phone_ok' && $d['lead'] === 'update_phone', 'new different number → update lead');
$d = nb_decide($c, $t('price?'), $T + 7 * 3600, $cfg);
ok($d['reply'] === 'already_have', 'Flow 7 again after 6h cooldown');
$d = nb_decide($c, $t('hi again'), $T + 40 * 86400, $cfg);
ok($d['reply'] === 'already_have' && $d['lead'] === 'reopen', 'returning after 30 days → lead reopened');

// Flow 2 and anti-spam burst
$c = $apply($new, nb_decide($new, $t('hello'), $T, $cfg));
$d = nb_decide($c, $t('price kati?'), $T + 5, $cfg);
ok($d['reply'] === null, 'burst within 45s → silent (greeting already asked)');
$d = nb_decide($c, $t('price kati?'), $T + 120, $cfg);
ok($d['reply'] === 'ask_phone' && $d['quick_phone'], 'Flow 2 price question → ask for number + native phone button');
$c = $apply($c, $d);
$d = nb_decide($c, $t('call me'), $T + 300, $cfg);
ok($d['reply'] === 'call_me', 'Flow 6 call me');
$c = $apply($c, $d);

// Flow 5 invalid → limited, then human
$d = nb_decide($c, $t('98123'), $T + 400, $cfg);
ok($d['reply'] === 'phone_invalid', 'Flow 5 invalid number');
$c = $apply($c, $d);
$d = nb_decide($c, $t('981234567'), $T + 500, $cfg);
ok($d['reply'] === 'phone_invalid', 'Flow 5 second time');
$c = $apply($c, $d);
$d = nb_decide($c, $t('98123456'), $T + 600, $cfg);
ok($d['handover'] && $d['reply'] === 'handover', 'third invalid → hand to human');
$c = $apply($c, $d);
$d = nb_decide($c, $t('price?'), $T + 700, $cfg);
ok($d['reply'] === null, 'human mode → bot silent');
$d = nb_decide($c, $t('9812345678'), $T + 800, $cfg);
ok($d['reply'] === null && $d['lead'] === 'create', 'human mode still captures a valid number silently');

// Prices / quantities are not phone attempts
$c = $apply($new, nb_decide($new, $t('hi'), $T, $cfg));
$d = nb_decide($c, $t('2 ota 1800 ma dinu hunchha?'), $T + 100, $cfg);
ok($d['reply'] === 'ask_phone', 'price-like numbers → Flow 2, not Flow 5');

// Native quick-reply phone
$d = nb_decide($c, ['kind' => 'text', 'text' => '+9779812345678', 'qr_payload' => '+9779812345678'], $T + 100, $cfg);
ok($d['reply'] === 'phone_ok' && $d['phone'] === '9812345678', 'user_phone_number quick reply accepted');

// English
$d = nb_decide($new, $t('How much is this?'), $T, $cfg);
ok($d['lang'] === 'en' && str_starts_with(nb_text($d['reply'], $d['lang']), 'Hello'), 'English greeting');

// Max asks → human
$c = $new;
for ($i = 0; $i < 4; $i++) $c = $apply($c, nb_decide($c, $t('info?'), $T + $i * 100, $cfg));
$d = nb_decide($c, $t('info?'), $T + 1000, $cfg);
ok($d['handover'] && $d['reply'] === null, 'after 4 unanswered asks → silent handover');

// Like sticker / human request
$c = $apply($new, nb_decide($new, $t('hi'), $T, $cfg));
ok(nb_decide($c, ['kind' => 'like'], $T + 100, $cfg)['reply'] === null, 'thumbs-up → silent');
ok(nb_decide($c, ['kind' => 'attachment'], $T + 100, $cfg)['reply'] === 'ask_phone', 'screenshot of product → Flow 2');
$d = nb_decide($c, $t('complaint: product aayena'), $T + 100, $cfg);
ok($d['handover'] && $d['reply'] === 'handover', 'complaint → handover');

// No price text ever in any template
foreach (nb_messages() as $k => $v) foreach ($v as $lang => $txt) ok(!preg_match('/(rs\.?\s*\d|रु|\d{3,})/iu', $txt), "template $k/$lang has no price");

// ---------------- End-to-end (DB) ----------------
if (in_array('--db', $argv, true)) {
    echo "Webhook end-to-end (DB)\n";
    require_once __DIR__ . '/../lib/handler.php';
    $cfg = nb_config(); $db = nb_db($cfg);
    $db->exec('DELETE FROM mb_messages; DELETE FROM mb_leads; DELETE FROM mb_conversations;');
    $sent = [];
    $io = ['send' => function ($tok, $psid, $txt, $qr) use (&$sent) { $sent[] = [$psid, $txt, $qr]; return ['ok' => true, 'data' => ['message_id' => 'out.' . count($sent)]]; },
           'name' => fn() => 'Ram Bahadur', 'pass' => fn() => null, 'seen' => fn() => null];
    $page = array_key_first($cfg['pages']); $psid = '7000000000001';
    $ev = fn($mid, $text, $extra = []) => ['object' => 'page', 'entry' => [['id' => $page, 'messaging' => [
        ['sender' => ['id' => $psid], 'recipient' => ['id' => $page], 'timestamp' => 1, 'message' => ['mid' => $mid, 'text' => $text] + $extra]]]]];

    nb_handle_payload($ev('m1', 'यो कति पर्छ?', ['referral' => ['source' => 'ADS', 'ad_id' => '123', 'ads_context_data' => ['ad_title' => 'Nabhi Oil 2+1 offer']]]), $db, $cfg, $io, $T);
    ok(count($sent) === 1 && str_contains($sent[0][1], 'नमस्कार'), 'e2e: greeting sent');
    nb_handle_payload($ev('m1', 'यो कति पर्छ?'), $db, $cfg, $io, $T + 1);
    ok(count($sent) === 1, 'e2e: duplicate delivery ignored');
    nb_handle_payload($ev('m2', '+977 981-234-5678'), $db, $cfg, $io, $T + 60);
    ok(count($sent) === 2 && str_contains($sent[1][1], 'प्राप्त भयो'), 'e2e: Flow 4 confirmation');
    $lead = $db->query("SELECT * FROM mb_leads")->fetch();
    ok($lead && $lead['phone'] === '9812345678' && $lead['customer_name'] === 'Ram Bahadur' && $lead['status'] === 'new', 'e2e: lead stored');
    ok($lead && str_contains($lead['context'], 'Nabhi Oil 2+1 offer') && str_contains($lead['context'], 'यो कति पर्छ?'), 'e2e: lead context has ad + question');
    nb_handle_payload($ev('m3', 'delivery kati?'), $db, $cfg, $io, $T + 120);
    ok(count($sent) === 3 && str_contains($sent[2][1], 'नम्बर प्राप्त भएको छ'), 'e2e: Flow 7');
    // Team member replies from the Inbox → bot pauses
    nb_handle_payload(['object' => 'page', 'entry' => [['id' => $page, 'messaging' => [['sender' => ['id' => $page], 'recipient' => ['id' => $psid],
        'message' => ['mid' => 'e1', 'is_echo' => true, 'app_id' => 263902037430900, 'text' => 'Namaste, hami call gardai chhau']]]]]], $db, $cfg, $io, $T + 130);
    nb_handle_payload($ev('m4', 'ok thik cha, price?'), $db, $cfg, $io, $T + 7 * 3600 + 200);
    ok(count($sent) === 3, 'e2e: bot silent while human is handling');
    // Our own echo must not pause the bot
    $psid = '7000000000002';
    $ev = fn($mid, $text, $extra = []) => ['object' => 'page', 'entry' => [['id' => $page, 'messaging' => [
        ['sender' => ['id' => $psid], 'recipient' => ['id' => $page], 'timestamp' => 1, 'message' => ['mid' => $mid, 'text' => $text] + $extra]]]]];
    nb_handle_payload(['object' => 'page', 'entry' => [['id' => $page, 'messaging' => [['sender' => ['id' => $page], 'recipient' => ['id' => $psid],
        'message' => ['mid' => 'e2', 'is_echo' => true, 'metadata' => 'nb_bot', 'text' => 'x']]]]]], $db, $cfg, $io, $T);
    nb_handle_payload($ev('n1', 'hello'), $db, $cfg, $io, $T);
    ok(count($sent) === 4, 'e2e: own echo ignored, new customer greeted');
    ok((int)$db->query("SELECT COUNT(*) FROM mb_leads")->fetchColumn() === 1, 'e2e: one lead total');
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
