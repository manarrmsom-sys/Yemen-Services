<?php

namespace Tests\Unit;

use App\Support\WhatsAppIntent;
use PHPUnit\Framework\TestCase;

class WhatsAppIntentTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function test_it_classifies_replies(string $input, string $expected): void
    {
        $this->assertSame($expected, WhatsAppIntent::parse($input));
    }

    public static function cases(): array
    {
        return [
            'arabic yes' => ['نعم', WhatsAppIntent::ACCEPT],
            'arabic yes w/ diacritics' => ['نَعَم', WhatsAppIntent::ACCEPT],
            'arabic accept verb' => ['اقبل الطلب', WhatsAppIntent::ACCEPT],
            'arabic ok slang' => ['تمام ماشي', WhatsAppIntent::ACCEPT],
            'franco yes' => ['na3am', WhatsAppIntent::ACCEPT],
            'english yes' => ['Yes please', WhatsAppIntent::ACCEPT],
            'digit 1' => ['1', WhatsAppIntent::ACCEPT],
            'arabic no' => ['لا', WhatsAppIntent::DECLINE],
            'arabic busy' => ['مشغول الآن', WhatsAppIntent::DECLINE],
            'english no' => ['no thanks', WhatsAppIntent::DECLINE],
            'digit 2' => ['٢', WhatsAppIntent::DECLINE],
            'decline beats stray ok' => ['ok بس مشغول', WhatsAppIntent::DECLINE],
            'positive phrase with negation word' => ['لا مشكلة، جاي', WhatsAppIntent::ACCEPT],
            'cant make it phrase' => ['ما اقدر اليوم', WhatsAppIntent::DECLINE],
            'status question' => ['وين الموقع؟', WhatsAppIntent::STATUS],
            'gibberish' => ['asdkjh', WhatsAppIntent::UNKNOWN],
            'empty' => ['', WhatsAppIntent::UNKNOWN],
        ];
    }
}
