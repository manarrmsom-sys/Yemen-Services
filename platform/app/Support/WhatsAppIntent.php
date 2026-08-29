<?php

namespace App\Support;

/**
 * Classifies a free-text WhatsApp reply from a tradesperson into a dispatch intent.
 *
 * Handles Arabic (with/without diacritics, alef/ya/ta-marbuta variants, Arabic-Indic
 * digits), English, and Franco-Arabic ("na3am", "aywa"). Deliberately conservative:
 * anything ambiguous returns `UNKNOWN` so the platform never auto-assigns on a guess.
 *
 * Matching is token-based (the text is normalized then split on whitespace), with a
 * small curated list of multi-word phrases checked first. If a reply contains BOTH an
 * accept and a decline token, decline wins.
 */
final class WhatsAppIntent
{
    public const ACCEPT = 'accept';
    public const DECLINE = 'decline';
    public const STATUS = 'status';
    public const UNKNOWN = 'unknown';

    /** Phrases that look negative but mean "yes" — checked before anything else. */
    private const POSITIVE_PHRASES = [
        'لا مشكل', 'لا مانع', 'ما في مشكل', 'مافي مشكل', 'ولا يهمك', 'من عيوني',
        'no problem', 'no worries',
    ];

    /** Phrases that force a decline regardless of other tokens. */
    private const NEGATIVE_PHRASES = [
        'ما اقدر', 'ما استطيع', 'مش قادر', 'مو قادر', 'ما عندي وقت', 'مشغول حاليا',
        'cant make it', 'can not', 'not available', 'not now',
    ];

    /** @var string[] normalized whole-token accept markers */
    private const ACCEPT_TOKENS = [
        'نعم', 'اجل', 'ايوه', 'ايوا', 'موافق', 'موافقه', 'اقبل', 'قبلت', 'قبول', 'اقبلها',
        'تمام', 'ماشي', 'حاضر', 'اوكي', 'اوك', 'اكيد', 'يلا', 'جاهز', 'استلمت', 'اخذها', 'اخذته',
        'yes', 'y', 'ok', 'okay', 'accept', 'accepted', 'sure', 'confirm', 'confirmed',
        'deal', 'coming', 'naam', 'na3am', 'aywa', 'aiwa', 'tmam', '1',
    ];

    /** @var string[] normalized whole-token decline markers */
    private const DECLINE_TOKENS = [
        'لا', 'كلا', 'رفض', 'ارفض', 'مرفوض', 'مشغول', 'معتذر', 'اعتذر',
        'no', 'n', 'nope', 'decline', 'declined', 'reject', 'rejected', 'busy',
        'pass', 'skip', 'la2', '2',
    ];

    /** @var string[] normalized whole-token "tell me more" markers */
    private const STATUS_TOKENS = [
        'وين', 'اين', 'حاله', 'تفاصيل', 'العنوان', 'الموقع', 'كم', 'السعر', 'المبلغ', 'معلومات',
        'status', 'where', 'details', 'address', 'location', 'price', 'info',
    ];

    public static function parse(?string $raw): string
    {
        $text = self::normalize((string) $raw);
        if ($text === '') {
            return self::UNKNOWN;
        }

        foreach (self::POSITIVE_PHRASES as $p) {
            if (str_contains($text, self::normalize($p))) {
                return self::ACCEPT;
            }
        }
        foreach (self::NEGATIVE_PHRASES as $p) {
            if (str_contains($text, self::normalize($p))) {
                return self::DECLINE;
            }
        }

        $tokens = preg_split('/\s+/', $text) ?: [];
        $hasAccept = (bool) array_intersect($tokens, self::ACCEPT_TOKENS);
        $hasDecline = (bool) array_intersect($tokens, self::DECLINE_TOKENS);
        $hasStatus = (bool) array_intersect($tokens, self::STATUS_TOKENS);

        return match (true) {
            $hasDecline => self::DECLINE,   // decline always wins over a co-occurring "ok"
            $hasAccept => self::ACCEPT,
            $hasStatus => self::STATUS,
            default => self::UNKNOWN,
        };
    }

    /** Lower-case, strip diacritics/tatweel, unify Arabic letter variants, map Arabic digits. */
    public static function normalize(string $text): string
    {
        $text = trim(mb_strtolower($text));

        $digitMap = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];
        $text = strtr($text, $digitMap);

        // Remove Arabic diacritics (harakat) + tatweel.
        $text = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;

        // Unify letter variants.
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه', 'گ' => 'ك',
        ]);

        // Drop emoji / punctuation; keep Arabic + latin + digits + spaces.
        $text = preg_replace('/[^\p{Arabic}a-z0-9\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
