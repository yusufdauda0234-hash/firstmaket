<?php

namespace App\Modules\Auth\Services;

use App\Shared\Enums\OtpChannel;

/**
 * Parses the single "email or phone" input used across registration, login,
 * and password reset (Sprint 2 Addendum). The OTP channel always matches the
 * identifier type: email → email code, phone → SMS code.
 */
class AuthIdentifier
{
    public function __construct(
        public readonly string $value,
        public readonly OtpChannel $channel,
    ) {}

    public static function parse(string $raw): self
    {
        $raw = trim($raw);

        if (filter_var($raw, FILTER_VALIDATE_EMAIL) !== false) {
            return new self(mb_strtolower($raw), OtpChannel::Email);
        }

        return new self(self::normalizePhone($raw), OtpChannel::Sms);
    }

    public function isEmail(): bool
    {
        return $this->channel === OtpChannel::Email;
    }

    /** The users-table column this identifier lives in. */
    public function column(): string
    {
        return $this->isEmail() ? 'email' : 'phone';
    }

    /** Masked form safe to echo back to the browser. */
    public function masked(): string
    {
        if ($this->isEmail()) {
            [$local, $domain] = explode('@', $this->value, 2);
            $keep = min(3, strlen($local));

            return substr($local, 0, $keep).str_repeat('*', max(strlen($local) - $keep, 2)).'@'.$domain;
        }

        return substr($this->value, 0, 7).'****'.substr($this->value, -2);
    }

    /**
     * Normalize Nigerian numbers to +234 form; leave other international
     * numbers as entered (minus spacing/dashes).
     */
    private static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/[\s\-().]/', '', $raw) ?? $raw;

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+234'.substr($digits, 1);
        }

        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            return '+'.$digits;
        }

        return $digits;
    }
}
