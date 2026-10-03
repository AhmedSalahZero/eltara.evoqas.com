<?php

namespace App\Support\Concerns;

// ══════════════════════════════════════════════════════════════════
//  El Tara — HasPreferences (language + theme)
//  Location: app/Support/Concerns/HasPreferences.php
//
//  Every account chooses its own language (ar / en) and theme
//  (dark / light). When an account has not chosen a theme yet, it
//  follows its company's default (Scope §2 "company sets the
//  default"). The Super Admin, who has no company, defaults to dark.
// ══════════════════════════════════════════════════════════════════

trait HasPreferences
{
    public const LANGUAGES = ['ar', 'en'];

    public const THEMES = ['dark', 'light'];

    public function preferredLanguage(): string
    {
        return in_array($this->language, self::LANGUAGES, true) ? $this->language : 'ar';
    }

    public function preferredTheme(): string
    {
        if (in_array($this->theme, self::THEMES, true)) {
            return $this->theme;
        }

        $companyTheme = $this->company_id ? $this->company?->default_theme : null;

        return in_array($companyTheme, self::THEMES, true) ? $companyTheme : 'dark';
    }

    /** Initials for the round avatar, from the first and last word of the name. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
