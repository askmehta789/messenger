<?php
/**
 * Lightweight intent + language detection (Nepali, Romanized Nepali, English).
 * Price/product questions don't need their own branch — every unknown message is
 * treated as a product enquiry — but they are tagged for the lead context.
 */

function nb_norm(string $s): string
{
    $s = mb_strtolower(np_ascii_digits($s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{M}\p{N}\s\?]+/u', ' ', $s);
    return ' ' . trim(preg_replace('/\s+/u', ' ', $s)) . ' ';
}

function nb_has_any(string $norm, array $needles): bool
{
    foreach ($needles as $n) {
        // Latin needles must match whole words; Devanagari ones can match inside words.
        if (preg_match('/^[a-z0-9 ]+$/', $n)) {
            if (str_contains($norm, ' ' . $n . ' ')) return true;
        } elseif (mb_strpos($norm, $n) !== false) {
            return true;
        }
    }
    return false;
}

const NB_CALL_ME = [
    'call me', 'call garnu', 'call garnus', 'call garnuhos', 'call garnuhola', 'call gara', 'call garidinu',
    'call garidinus', 'phone garnu', 'phone garnus', 'phone gara', 'contact me', 'call back', 'callback',
    'malai call', 'malai phone', 'number pathauchu', 'number pathaunchu', 'number dinchu', 'number dinxu',
    'please call', 'give me a call', 'call garnuhos na',
    'call गर्नु', 'फोन गर्नु', 'कल गर्नु', 'मलाई फोन', 'मलाई call', 'मलाई कल', 'नम्बर पठाउँछु', 'नम्बर दिन्छु',
    'number पठाउँछु', 'सम्पर्क गर्नु',
];

const NB_HUMAN = [
    'human', 'agent', 'real person', 'manche', 'manxe', 'admin', 'complain', 'complaint', 'refund', 'return',
    'cancel', 'not received', 'aayena', 'aaena', 'bigriyo', 'damaged', 'broken', 'wrong product', 'thagi',
    'मान्छे', 'गुनासो', 'फिर्ता', 'रद्द', 'आएन', 'पाएको छैन', 'बिग्रियो', 'ठगी', 'क्यान्सल',
];

const NB_ACK = [
    'ok', 'okay', 'okey', 'k', 'thanks', 'thank you', 'thank u', 'thanku', 'ty', 'dhanyabad', 'dhanyabaad',
    'dhanybad', 'hajur', 'huncha', 'hunxa', 'la', 'la hunxa', 'la huncha', 'ओके', 'धन्यवाद', 'हजुर', 'हुन्छ', 'ल',
];

const NB_PRODUCT = [
    'price', 'rate', 'cost', 'kati', 'parcha', 'parchha', 'parxa', 'offer', 'discount', 'delivery', 'charge',
    'available', 'stock', 'order', 'kasari', 'details', 'detail', 'info', 'location', 'kaha', 'where',
    'original', 'warranty', 'guarantee', 'size', 'color', 'colour', 'cod', 'how much', 'mulya',
    'कति', 'मूल्य', 'मोल', 'दाम', 'पर्छ', 'अफर', 'छुट', 'डेलिभरी', 'उपलब्ध', 'अर्डर', 'कसरी', 'जानकारी',
    'कहाँ', 'ओरिजिनल', 'वारेन्टी', 'साइज', 'रङ', 'रंग',
];

/** Romanized-Nepali markers: if present the reply stays in Nepali even though the script is Latin. */
const NB_ROMAN_NEPALI = [
    'kati', 'ho', 'cha', 'chha', 'xa', 'parcha', 'parchha', 'parxa', 'garne', 'garnu', 'garnus', 'malai',
    'kasari', 'hajur', 'tapai', 'tapaii', 'kaha', 'ma', 'ni', 'ra', 'ko', 'lai', 'ta', 'hola', 'hunxa',
    'huncha', 'chaiyo', 'chahiyo', 'dinus', 'pathau', 'bhai', 'dai', 'didi', 'sanga', 'ota', 'wota',
];

const NB_ENGLISH = [
    'price', 'how', 'what', 'please', 'want', 'need', 'the', 'is', 'are', 'can', 'you', 'i', 'my', 'me',
    'much', 'available', 'delivery', 'order', 'cost', 'hello', 'hi', 'thanks', 'send', 'do', 'does', 'have',
];

/** Returns 'en' only when the message is clearly English; default 'ne'. */
function nb_detect_lang(string $text, string $default = 'ne'): string
{
    if (trim($text) === '') return $default;
    if (preg_match('/\p{Devanagari}/u', $text)) return 'ne';
    if (!preg_match('/[a-z]/i', $text)) return $default;
    $words = array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($text, 'UTF-8')));
    $en = 0; $np = 0;
    foreach ($words as $w) {
        if (in_array($w, NB_ROMAN_NEPALI, true)) $np++;
        if (in_array($w, NB_ENGLISH, true)) $en++;
    }
    return ($en > 0 && $np === 0) ? 'en' : 'ne';
}

/** Classify one inbound message (phone handled separately). */
function nb_intent(string $text): string
{
    $n = nb_norm($text);
    if (trim($n) === '') return 'empty';
    if (nb_has_any($n, NB_CALL_ME)) return 'call_me';
    if (nb_has_any($n, NB_HUMAN)) return 'human';
    if (nb_has_any($n, NB_PRODUCT)) return 'product';
    // Pure acknowledgement ("ok", "thanks") — whole message only.
    $bare = trim($n);
    if (in_array($bare, NB_ACK, true) || preg_match('/^(ok|thanks?|dhanya\w*|धन्यवाद|हुन्छ)( \w+)?$/u', $bare) && mb_strlen($bare) <= 20) {
        return 'ack';
    }
    return 'other';
}
