<?php

namespace App\Shared\Enums;

use Illuminate\Validation\Rule;

/**
 * The kinds of field staff can add to the vendor product form.
 *
 * Deliberately a small, closed set: each case has to render as an input, cast
 * to something storable, and produce validation rules. Adding a case means
 * teaching all three, so the list stays short rather than growing a type per
 * product category.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Boolean = 'boolean';
    case Url = 'url';
    case BulletList = 'bullet_list';
    case NumberedList = 'numbered_list';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::Textarea => 'Long text',
            self::Number => 'Number',
            self::Select => 'Choose one',
            self::Multiselect => 'Choose several',
            self::Boolean => 'Yes / No',
            self::Url => 'Link (image or video)',
            self::BulletList => 'Bullet list',
            self::NumberedList => 'Numbered list',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Text => 'One line — a model number, a material.',
            self::Textarea => 'Several lines — care instructions, what is in the box.',
            self::Number => 'Digits only, with an optional unit like kg or W.',
            self::Select => 'One option from a list you define.',
            self::Multiselect => 'Any number of options from a list you define.',
            self::Boolean => 'A single yes/no toggle.',
            self::Url => 'A web address — a YouTube demo, a spec sheet.',
            self::BulletList => 'One point per line. Shows as a bulleted list — key features, what is in the box.',
            self::NumberedList => 'One step per line. Shows as a numbered list — setup or usage steps.',
        };
    }

    /** Stored as several items rather than one value. */
    public function isList(): bool
    {
        return in_array($this, [self::BulletList, self::NumberedList], true);
    }

    /** Only these ask the admin for a list of choices. */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::Multiselect], true);
    }

    /**
     * Validation for one vendor-submitted value of this type.
     *
     * @param  array<int, string>  $options
     * @return array<int, mixed>
     */
    public function rulesFor(bool $required, array $options = []): array
    {
        $presence = $required ? 'required' : 'nullable';

        return match ($this) {
            self::Text => [$presence, 'string', 'max:255'],
            self::Textarea => [$presence, 'string', 'max:2000'],
            self::Number => [$presence, 'numeric'],
            self::Boolean => [$presence, 'boolean'],
            self::Url => [$presence, 'url', 'max:2048'],
            self::Select => [$presence, 'string', Rule::in($options)],
            // The array itself is required; each entry must be a known option.
            self::Multiselect => [$presence, 'array'],
            // Long enough for a real feature list, short enough that the
            // specifications table stays a table.
            self::BulletList, self::NumberedList => [$presence, 'array', 'max:30'],
        };
    }

    /**
     * Extra rule set applied to `field.*`, for the types that store several
     * values rather than one.
     *
     * @param  array<int, string>  $options
     * @return array<int, mixed>|null
     */
    public function eachRulesFor(array $options = []): ?array
    {
        return match (true) {
            $this === self::Multiselect => ['string', Rule::in($options)],
            $this->isList() => ['string', 'max:300'],
            default => null,
        };
    }

    /** Normalise a validated value into what gets stored. */
    public function cast(mixed $value): mixed
    {
        return match (true) {
            $this === self::Number => $value === null || $value === '' ? null : (float) $value,
            $this === self::Boolean => (bool) $value,
            $this === self::Multiselect => array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== '')),
            $this->isList() => self::listItems($value),
            default => $value === null ? null : (string) $value,
        };
    }

    /**
     * One list item per line, however it arrived.
     *
     * A string is accepted as well as an array so that switching an existing
     * "Long text" field to a list does not throw away what vendors already
     * wrote — a paragraph of " - " separated points becomes the list it was
     * always trying to be. Bullet characters people paste in are stripped,
     * because the page draws its own.
     *
     * @return array<int, string>
     */
    private static function listItems(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $lines = is_array($value)
            ? $value
            : (preg_split('/\R|\s+-\s+|\s+•\s+/u', (string) $value) ?: []);

        $items = [];

        foreach ($lines as $line) {
            // Leading "-", "*", "•" or "1." from a paste. The marker has to be
            // followed by a space, or "45.7MP sensor" reads as item 45 and
            // loses its first digits.
            $line = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', (string) $line);
            $line = trim((string) $line);

            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    }

    /** How a stored value reads on the product page. */
    public function display(mixed $value): string
    {
        return match (true) {
            $this === self::Boolean => $value ? 'Yes' : 'No',
            $this === self::Multiselect => implode(', ', (array) $value),
            $this === self::Number => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.'),
            // Only reached where a list has nowhere to be a list — an export,
            // a plain-text email. The product page renders items() instead.
            $this->isList() => implode(' • ', self::listItems($value)),
            default => (string) $value,
        };
    }

    /**
     * The individual points, for somewhere that can draw a real list.
     *
     * @return array<int, string>
     */
    public function items(mixed $value): array
    {
        return $this->isList() ? self::listItems($value) : [];
    }
}
