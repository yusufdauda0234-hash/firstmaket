<?php

namespace App\Shared\Casts;

use App\Shared\Support\TitleCase;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a value in upper case and reads it back in title case.
 *
 * Applied per field rather than globally, because "everything upper case" is
 * the wrong rule for most columns: an email is compared case-insensitively but
 * displayed as the person typed it, a slug and a URL are case-sensitive, and a
 * paragraph of product description in capitals is unreadable. Names, business
 * names and addresses are the fields where consistent casing genuinely helps —
 * they get searched, sorted and printed on labels.
 *
 * Upper case is the storage format, not the display format: writing it that
 * way keeps matching and sorting predictable, and reading it back through
 * TitleCase means nothing in the UI has to remember to reformat. Anything
 * that needs the raw stored value can reach for getRawOriginal().
 *
 * Multibyte-aware, so an accented or non-Latin name is not corrupted.
 */
class Uppercase implements CastsAttributes
{
    /** Stored SHOUTING, handed back presentable. */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : TitleCase::format((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_strtoupper($text, 'UTF-8');
    }
}
