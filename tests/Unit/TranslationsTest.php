<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

// ══════════════════════════════════════════════════════════════════
//  El Tara — Test: Arabic and English have the same words
//  Location: tests/Unit/TranslationsTest.php
//  Fails when a label exists in one language but not the other —
//  so no screen ever shows a raw key like "users.add".
// ══════════════════════════════════════════════════════════════════

class TranslationsTest extends TestCase
{
    public function test_server_language_files_have_the_same_keys(): void
    {
        $root = dirname(__DIR__, 2).'/lang';

        foreach (glob("{$root}/en/*.php") as $file) {
            $name = basename($file);
            $en = require $file;
            $ar = require "{$root}/ar/{$name}";

            // English validation messages come from Laravel itself; only
            // our field names (attributes) must match there.
            if ($name === 'validation.php') {
                $en = ['attributes' => $en['attributes']];
                $ar = ['attributes' => $ar['attributes']];
            }

            $en = array_keys(Arr::dot($en));
            $ar = array_keys(Arr::dot($ar));

            $this->assertSame([], array_values(array_diff($en, $ar)), "Missing in lang/ar/{$name}");
            $this->assertSame([], array_values(array_diff($ar, $en)), "Missing in lang/en/{$name}");
        }
    }

    public function test_screen_language_files_have_the_same_keys(): void
    {
        $root = dirname(__DIR__, 2).'/resources/js/lang';
        $keys = function (string $file): array {
            preg_match_all('/^\s*(?:(\w+)\s*:)/m', file_get_contents($file), $lines);
            preg_match_all('/[{,]\s*(\w+)\s*:/', file_get_contents($file), $inline);
            $all = array_unique(array_merge($lines[1], $inline[1]));
            sort($all);

            return $all;
        };

        $this->assertSame($keys("{$root}/en.js"), $keys("{$root}/ar.js"));
    }
}
