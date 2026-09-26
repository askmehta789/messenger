<?php
/**
 * Customer-facing reply texts. Edit wording here only.
 * Rule: no prices, delivery charges, discounts or product claims — ever.
 */
function nb_messages(): array
{
    return [
        // FLOW 1 — first message
        'greeting' => [
            'ne' => "नमस्कार 🙏❤️\n\nहामीलाई मेसेज गर्नुभएकोमा धन्यवाद!\n\nउत्पादनको मूल्य, अफर, डेलिभरी, उपलब्धता वा अन्य कुनै जानकारीको लागि कृपया आफ्नो मोबाइल नम्बर पठाइदिनुहोस्। 📱\n\nहाम्रो टिमले तपाईंलाई फोनमार्फत सम्पूर्ण जानकारी दिनेछ। ☎️\n\nकृपया आफ्नो मोबाइल नम्बर पठाउनुहोस्। 🙏",
            'en' => "Hello 🙏❤️\n\nThank you for messaging us!\n\nFor price, offers, delivery, availability or any other information, please send your mobile number. 📱\n\nOur team will call you with all the details. ☎️🙏",
        ],
        // FLOW 2 — price / product question
        'ask_phone' => [
            'ne' => "मूल्य तथा अन्य जानकारीको लागि कृपया आफ्नो मोबाइल नम्बर पठाइदिनुहोस्। 📱\n\nहाम्रो टिमले तपाईंलाई फोनमार्फत सम्पूर्ण जानकारी दिनेछ। ☎️🙏",
            'en' => "For price and other details, please send your mobile number. 📱\n\nOur team will call you with all the information. ☎️🙏",
        ],
        // FLOW 4 — valid number received
        'phone_ok' => [
            'ne' => "धन्यवाद! 🙏❤️\n\nतपाईंको मोबाइल नम्बर प्राप्त भयो।\n\nहाम्रो टिमले तपाईंलाई छिट्टै फोन गरेर उत्पादनको मूल्य, डेलिभरी तथा अन्य आवश्यक जानकारी उपलब्ध गराउनेछ। ☎️\n\nकृपया फोन उपलब्ध राख्नुहोला। 🙏",
            'en' => "Thank you! 🙏❤️\n\nWe have received your mobile number.\n\nOur team will call you shortly with the price, delivery and other details. ☎️\n\nPlease keep your phone available. 🙏",
        ],
        // FLOW 5 — invalid number
        'phone_invalid' => [
            'ne' => "कृपया सही मोबाइल नम्बर पठाइदिनुहोस्। 📱\n\nहाम्रो टिमले तपाईंलाई फोनमार्फत आवश्यक जानकारी उपलब्ध गराउनेछ। ☎️",
            'en' => "Please send a valid mobile number (e.g. 98XXXXXXXX). 📱\n\nOur team will call you with the details. ☎️",
        ],
        // FLOW 6 — "call me"
        'call_me' => [
            'ne' => "अवश्य 🙏\n\nकृपया आफ्नो मोबाइल नम्बर पठाइदिनुहोस्। 📱\n\nहाम्रो टिमले तपाईंलाई फोन गरेर सम्पूर्ण जानकारी दिनेछ। ☎️",
            'en' => "Sure 🙏\n\nPlease send your mobile number. 📱\n\nOur team will call you with all the details. ☎️",
        ],
        // FLOW 7 — number already on file
        'already_have' => [
            'ne' => "धन्यवाद! 🙏 तपाईंको नम्बर प्राप्त भएको छ।\n\nहाम्रो टिमले तपाईंलाई छिट्टै फोन गर्नेछ। ☎️\n\nकृपया फोन उपलब्ध राख्नुहोला।",
            'en' => "Thank you! 🙏 We have received your number.\n\nOur team will call you shortly. ☎️\n\nPlease keep your phone available.",
        ],
        // Rule 14 — handed to a human (no internal details exposed)
        'handover' => [
            'ne' => "हाम्रो टिमले तपाईंलाई छिट्टै जवाफ दिनेछ। 🙏",
            'en' => "Our team will get back to you shortly. 🙏",
        ],
    ];
}

function nb_text(string $key, string $lang): string
{
    $m = nb_messages();
    return $m[$key][$lang] ?? $m[$key]['ne'];
}
