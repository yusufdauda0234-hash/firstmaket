<?php

namespace App\Modules\Support\Models;

use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A public page whose wording lives in the database rather than in a .tsx
 * file, so the terms and the privacy policy can be corrected without a
 * deploy (docs/firstmaket_Implementation_Plan.md Sprint 10).
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property array<int, array{heading: string|null, body: string}> $sections
 * @property bool $is_published
 * @property bool $show_in_footer
 * @property int $sort_order
 * @property bool $is_system
 * @property Carbon|null $effective_at
 * @property int|null $updated_by
 */
class ContentPage extends Model
{
    use HasUuid;

    /**
     * The pages an outside service has been told the URL of.
     *
     * Google's consent screen holds the terms and privacy URLs; Meta refuses
     * to review a login app without a reachable data-deletion URL. Renaming
     * one of these slugs breaks sign-in for everybody, and it breaks it in
     * the provider's console rather than anywhere visible from here — hence
     * the slug lock rather than a warning in the UI.
     */
    public const SYSTEM_SLUGS = ['terms', 'privacy-policy', 'data-deletion'];

    /** Footer links, cached in HandleInertiaRequests and cleared below. */
    public const FOOTER_CACHE_KEY = 'content_pages.footer_links';

    /**
     * System pages keep a top-level URL, because that is what is registered
     * with the providers. Everything else lives under /legal/{slug}.
     *
     * @var array<string, string>
     */
    public const CANONICAL_ROUTES = [
        'terms' => 'legal.terms',
        'privacy-policy' => 'legal.privacy',
        'data-deletion' => 'legal.data-deletion',
    ];

    protected $fillable = [
        'slug',
        'title',
        'summary',
        'sections',
        'is_published',
        'show_in_footer',
        'sort_order',
        'effective_at',
        'updated_by',
    ];

    /**
     * Drop the footer cache whenever a page changes.
     *
     * On the model rather than in the controller so the seeder, a tinker
     * session and a future admin action all clear it too — the version that
     * only fires from the controller is the one that leaves a stale link in
     * the footer of every page on the site.
     */
    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(self::FOOTER_CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'is_published' => 'boolean',
            'show_in_footer' => 'boolean',
            'is_system' => 'boolean',
            'effective_at' => 'datetime',
        ];
    }

    /** @param  Builder<ContentPage>  $query
     * @return Builder<ContentPage> */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function isSystem(): bool
    {
        return $this->is_system || in_array($this->slug, self::SYSTEM_SLUGS, true);
    }

    /**
     * The one URL this page should be linked and indexed under.
     *
     * Pinned to the storefront host rather than left to route(), which builds
     * on whatever origin is rendering. The admin portal renders this screen,
     * so route() produced admin.firstmaket.com/privacy-policy — a URL that
     * 404s, and that an admin would paste into Meta's app review because this
     * screen is the place they go to find it.
     */
    public function url(): string
    {
        $named = self::CANONICAL_ROUTES[$this->slug] ?? null;

        $path = $named !== null
            ? route($named, absolute: false)
            : route('legal.show', $this->slug, absolute: false);

        // Lower-cased so the address shown to an admin, copied into a
        // provider console, and rendered in the footer are one string.
        return Str::lower(rtrim((string) config('app.url'), '/')).$path;
    }

    /**
     * The body, turned into blocks a template can render without deciding
     * anything.
     *
     * Done here rather than in React so the rules are covered by the PHP
     * test suite, and so the two would-be implementations cannot drift.
     *
     * @return array<int, array{heading: string|null, blocks: array<int, array<string, mixed>>}>
     */
    public function renderedSections(): array
    {
        return collect($this->sections ?? [])
            ->map(fn (array $section) => [
                'heading' => $this->trimmedOrNull($section['heading'] ?? null),
                'blocks' => self::blocks((string) ($section['body'] ?? '')),
            ])
            // A section with neither a heading nor any text is an empty row
            // the admin left behind; it should not render as blank space.
            ->reject(fn (array $section) => $section['heading'] === null && $section['blocks'] === [])
            ->values()
            ->all();
    }

    /**
     * Paragraphs and lists out of plain text.
     *
     * Deliberately not Markdown. Markdown would mean either shipping raw HTML
     * to the browser or a sanitiser to strip it again, and the only formatting
     * a policy page actually needs is a paragraph and a bulleted list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(string $body): array
    {
        $blocks = [];
        $paragraph = [];
        $list = [];
        $listType = null;

        $flushParagraph = function () use (&$blocks, &$paragraph): void {
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph)];
                $paragraph = [];
            }
        };

        $flushList = function () use (&$blocks, &$list, &$listType): void {
            if ($list !== []) {
                $blocks[] = ['type' => $listType, 'items' => $list];
                $list = [];
                $listType = null;
            }
        };

        foreach (preg_split('/\R/u', $body) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                $flushParagraph();
                $flushList();

                continue;
            }

            // The trailing \s is not optional. Without it "1. Wrong" and
            // "45.7MP sensor" are the same shape, and the second one loses
            // its first four characters — a bug this codebase has already
            // shipped once, in the product bullet-list field.
            if (preg_match('/^(?:[-*•])\s+(.*)$/u', $line, $matches) === 1) {
                $flushParagraph();

                if ($listType === 'numbers') {
                    $flushList();
                }

                $listType = 'bullets';
                $list[] = $matches[1];

                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.*)$/u', $line, $matches) === 1) {
                $flushParagraph();

                if ($listType === 'bullets') {
                    $flushList();
                }

                $listType = 'numbers';
                $list[] = $matches[1];

                continue;
            }

            $flushList();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushList();

        return $blocks;
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
