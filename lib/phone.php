<?php
/**
 * Nepal mobile number detection.
 *
 * Accepts:  98XXXXXXXX, 97XXXXXXXX, 96XXXXXXXX
 *           +97798XXXXXXXX, +977 98XXXXXXXX, 00977-98..., 977 98...
 *           with spaces / hyphens / dots / brackets, and Devanagari digits (९८...).
 * Rejects:  prices (1100, Rs 1800), quantities, order IDs, landlines,
 *           digit runs that are part of a longer number.
 */

const NP_MOBILE_RE = '/^9[678]\d{8}$/';

/** Convert Devanagari (०-९) digits to ASCII. */
function np_ascii_digits(string $s): string
{
    static $map = ['०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9'];
    return strtr($s, $map);
}

/** Normalise one candidate string to a 10-digit local mobile, or null. */
function np_normalize_mobile(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', np_ascii_digits($raw));
    if ($d === null || $d === '') return null;
    if (strlen($d) === 15 && str_starts_with($d, '00977')) $d = substr($d, 5);
    elseif (strlen($d) === 13 && str_starts_with($d, '977')) $d = substr($d, 3);
    elseif (strlen($d) === 11 && str_starts_with($d, '09'))   $d = substr($d, 1);
    return preg_match(NP_MOBILE_RE, $d) ? $d : null;
}

/**
 * Scan free text. Returns:
 *   ['phone' => '98XXXXXXXX'|null, 'attempt' => bool]
 * 'attempt' = the message looks like the customer TRIED to send a phone number
 * (a 7–15 digit run, or a run starting with 9 / +977) but it is not valid.
 * Short numbers like prices or quantities are NOT attempts.
 */
function np_extract_phone(string $text): array
{
    $t = np_ascii_digits($text);
    // Candidate: optional +/00, digits with common separators, bounded by non-digits.
    preg_match_all('/(?<![\d])(?:\+|00)?\d[\d\s\-\.\(\)]{5,20}\d(?![\d])/u', $t, $m);
    $attempt = false;
    foreach ($m[0] as $cand) {
        // Try every run of consecutive digit groups so that "98 1234 5678" joins up,
        // while "9812345678 1100" still finds the phone and ignores the price.
        preg_match_all('/\+?\d+/', $cand, $g);
        $groups = $g[0];
        $n = count($groups);
        for ($i = 0; $i < $n; $i++) {
            $joined = '';
            for ($j = $i; $j < $n; $j++) {
                $joined .= $groups[$j];
                $len = strlen(ltrim($joined, '+'));
                if ($len > 15) break;
                if ($len >= 10 && ($phone = np_normalize_mobile($joined))) {
                    return ['phone' => $phone, 'attempt' => true];
                }
            }
        }
        $digits = preg_replace('/\D+/', '', $cand);
        $len = strlen($digits);
        if ($len >= 9 && $len <= 15) $attempt = true;                 // phone-length but invalid
        elseif ($len >= 7 && preg_match('/^(\+|00)?(977|9)/', trim($cand))) $attempt = true; // truncated mobile
    }
    // Whole message is just a number (e.g. "98123", "+977 98123") → customer tried to send a phone.
    $bare = trim($t);
    if (!$attempt && preg_match('/^[\d\s\-\.\(\)\+]+$/', $bare)) {
        $dl = strlen(preg_replace('/\D+/', '', $bare));
        if ($dl >= 5 && preg_match('/^(\+|00)?(977)?[\s\-]*9[678]/', $bare)) $attempt = true;
    }
    // Plain 7–8 digit runs that start with 9 (e.g. "98123456") are truncated mobiles.
    if (!$attempt && preg_match('/(?<!\d)9[678]\d{5,6}(?!\d)/', $t)) $attempt = true;
    return ['phone' => null, 'attempt' => $attempt];
}

/** Pretty format for display: 98XXXXXXXX -> 981-234-5678 */
function np_format_mobile(string $p): string
{
    return substr($p, 0, 3) . '-' . substr($p, 3, 3) . '-' . substr($p, 6);
}
