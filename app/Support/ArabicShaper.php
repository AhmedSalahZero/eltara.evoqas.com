<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  El Tara — ArabicShaper (joins Arabic letters correctly in PDF exports)
//  Location: app/Support/ArabicShaper.php
//
//  The PDF maker (dompdf) draws letters one by one, left to right. Arabic
//  needs two things it does not do:
//    1. JOINED letters: each letter takes its isolated, first, middle or
//       last shape (and لا, لأ, لإ, لآ become one sign);
//    2. RIGHT-TO-LEFT order, while numbers and English words inside the
//       Arabic keep reading left to right.
//
//      ArabicShaper::line('المحاسبون 2411')   → ready to print in a PDF
//
//  Text without Arabic letters is returned unchanged. Short marks (tashkeel)
//  are dropped: they are not needed to read a report.
// ══════════════════════════════════════════════════════════════════

class ArabicShaper
{
    /** letter => [isolated, final, initial, medial]; null = the letter has no such shape */
    private const FORMS = [
        'ء' => ["\u{FE80}", null, null, null],
        'آ' => ["\u{FE81}", "\u{FE82}", null, null], 'أ' => ["\u{FE83}", "\u{FE84}", null, null],
        'ؤ' => ["\u{FE85}", "\u{FE86}", null, null], 'إ' => ["\u{FE87}", "\u{FE88}", null, null],
        'ئ' => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"], 'ا' => ["\u{FE8D}", "\u{FE8E}", null, null],
        'ب' => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"], 'ة' => ["\u{FE93}", "\u{FE94}", null, null],
        'ت' => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"], 'ث' => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"],
        'ج' => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"], 'ح' => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"],
        'خ' => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"], 'د' => ["\u{FEA9}", "\u{FEAA}", null, null],
        'ذ' => ["\u{FEAB}", "\u{FEAC}", null, null], 'ر' => ["\u{FEAD}", "\u{FEAE}", null, null],
        'ز' => ["\u{FEAF}", "\u{FEB0}", null, null], 'س' => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"],
        'ش' => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"], 'ص' => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"],
        'ض' => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"], 'ط' => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"],
        'ظ' => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"], 'ع' => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"],
        'غ' => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"], 'ف' => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"],
        'ق' => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"], 'ك' => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"],
        'ل' => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"], 'م' => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"],
        'ن' => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"], 'ه' => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"],
        'و' => ["\u{FEED}", "\u{FEEE}", null, null], 'ى' => ["\u{FEEF}", "\u{FEF0}", null, null],
        'ي' => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"], 'ـ' => ['ـ', 'ـ', 'ـ', 'ـ'],
    ];

    /** لا and its hamza / madda forms: [isolated, final] */
    private const LAM_ALEF = ['آ' => ["\u{FEF5}", "\u{FEF6}"], 'أ' => ["\u{FEF7}", "\u{FEF8}"], 'إ' => ["\u{FEF9}", "\u{FEFA}"], 'ا' => ["\u{FEFB}", "\u{FEFC}"]];

    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '«' => '»', '»' => '«', '<' => '>', '>' => '<'];

    public static function hasArabic(?string $text): bool
    {
        return $text !== null && (bool) preg_match('/\p{Arabic}/u', $text);
    }

    /** One line of text, shaped and in the order it is drawn. */
    public static function line(?string $text): string
    {
        $text = (string) $text;
        if (! self::hasArabic($text)) {
            return $text;
        }

        return self::visual(self::shape($text));
    }

    /** Letters in their joined shapes (still in reading order). */
    public static function shape(string $text): string
    {
        $chars = preg_split('//u', preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text) ?? $text, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($chars);
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if (! isset(self::FORMS[$c])) {
                $out .= $c;
                continue;
            }
            $prevJoins = $i > 0 && self::joinsForward($chars[$i - 1]);
            // لا
            if ($c === 'ل' && isset($chars[$i + 1], self::LAM_ALEF[$chars[$i + 1]])) {
                $out .= self::LAM_ALEF[$chars[$i + 1]][$prevJoins ? 1 : 0];
                $i++;
                continue;
            }
            $nextJoins = isset($chars[$i + 1], self::FORMS[$chars[$i + 1]]) && self::FORMS[$c][2] !== null && self::FORMS[$chars[$i + 1]][1] !== null;
            $f = self::FORMS[$c];
            $out .= match (true) {
                $prevJoins && $nextJoins => $f[3] ?? $f[1] ?? $f[0],
                $prevJoins               => $f[1] ?? $f[0],
                $nextJoins               => $f[2] ?? $f[0],
                default                  => $f[0],
            };
        }

        return $out;
    }

    /** Does this letter join the letter after it (does it have a first / middle shape)? */
    private static function joinsForward(string $c): bool
    {
        return isset(self::FORMS[$c]) && self::FORMS[$c][2] !== null;
    }

    /** Right-to-left: the runs in reverse order; Arabic runs reversed inside, numbers and Latin words kept. */
    private static function visual(string $text): string
    {
        // A run of Latin letters / digits (with the signs that sit inside numbers and codes) stays as it is.
        preg_match_all('/[A-Za-z0-9\x{0660}-\x{0669}][A-Za-z0-9\x{0660}-\x{0669} .,:\/%+\-–·@_&]*[A-Za-z0-9\x{0660}-\x{0669}%]|[A-Za-z0-9\x{0660}-\x{0669}]|./us', $text, $m);
        $parts = array_reverse($m[0]);
        $out = '';
        foreach ($parts as $p) {
            if (preg_match('/^[A-Za-z0-9\x{0660}-\x{0669}]/u', $p)) {
                $out .= $p;
            } else {
                $out .= self::MIRROR[$p] ?? $p;
            }
        }

        return $out;
    }
}
