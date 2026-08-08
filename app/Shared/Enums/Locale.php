<?php

namespace App\Shared\Enums;

/**
 * The languages the storefront is actually translated into.
 *
 * Deliberately a closed set rather than "every language of the chosen
 * country". The country picker knows ~250 territories and hundreds of
 * languages; offering one we have no strings for would switch the label and
 * leave the page in English, which is worse than not offering it at all.
 *
 * Staff-facing surfaces (admin workspace, vendor centre) stay in English:
 * they are operational tools for a small internal audience, and translating
 * financial controls badly is a real risk, not a nicety.
 */
enum Locale: string
{
    case English = 'en';
    case Hausa = 'ha';
    case Yoruba = 'yo';
    case Igbo = 'ig';
    case Pidgin = 'pcm';
    case French = 'fr';

    /** How the language names itself — what a speaker looks for in a menu. */
    public function endonym(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Hausa => 'Hausa',
            self::Yoruba => 'Yorùbá',
            self::Igbo => 'Igbo',
            self::Pidgin => 'Naijá (Pidgin)',
            self::French => 'Français',
        };
    }

    public function englishName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Hausa => 'Hausa',
            self::Yoruba => 'Yoruba',
            self::Igbo => 'Igbo',
            self::Pidgin => 'Nigerian Pidgin',
            self::French => 'French',
        };
    }

    /** Two-letter badge for the header button. */
    public function badge(): string
    {
        return strtoupper(substr($this->value, 0, 2));
    }

    /**
     * BCP-47 tag for Intl formatting. Pidgin has no CLDR data, so number and
     * date formatting borrows Nigerian English.
     */
    public function intlTag(): string
    {
        return match ($this) {
            self::Pidgin => 'en-NG',
            self::English => 'en-NG',
            self::Hausa => 'ha-NG',
            self::Yoruba => 'yo-NG',
            self::Igbo => 'ig-NG',
            self::French => 'fr',
        };
    }

    public static function default(): self
    {
        return self::English;
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /** @return array<int, array{code: string, endonym: string, english: string, badge: string}> */
    public static function options(): array
    {
        return array_map(fn (self $l) => [
            'code' => $l->value,
            'endonym' => $l->endonym(),
            'english' => $l->englishName(),
            'badge' => $l->badge(),
        ], self::cases());
    }
}
