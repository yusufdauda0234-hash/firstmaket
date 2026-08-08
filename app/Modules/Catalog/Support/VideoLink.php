<?php

namespace App\Modules\Catalog\Support;

/**
 * A vendor-supplied video link, reduced to a provider and an id.
 *
 * The player embeds this in an iframe, so the raw string a vendor typed must
 * never reach the page. Instead the id is extracted here and a canonical embed
 * URL is rebuilt from a fixed template — a link to somewhere unexpected, or
 * carrying an extra query string, cannot survive the round trip. Anything this
 * class does not recognise is rejected at validation rather than embedded
 * hopefully.
 */
final class VideoLink
{
    private function __construct(
        public readonly string $provider,
        public readonly string $id,
    ) {}

    /** Hosts a vendor may link to, and what to call them. */
    private const PROVIDERS = [
        'youtube' => 'YouTube',
        'vimeo' => 'Vimeo',
    ];

    /**
     * Parse a link, or null if it is not a video this system can play.
     */
    public static function parse(?string $url): ?self
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        // A bare "youtu.be/xyz" has no scheme, so parse_url reads the whole
        // thing as a path and there is no host to match on.
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        // Only ever embed over https, whatever the vendor pasted.
        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = trim($parts['path'] ?? '', '/');

        parse_str($parts['query'] ?? '', $query);

        return match (true) {
            $host === 'youtube.com', $host === 'm.youtube.com', $host === 'youtube-nocookie.com' => self::youtube(
                // /watch?v=ID, or the id is the last path segment for
                // /embed/ID, /shorts/ID and /live/ID.
                isset($query['v']) && is_string($query['v'])
                    ? $query['v']
                    : self::lastSegment($path, ['embed', 'shorts', 'live', 'v']),
            ),
            $host === 'youtu.be' => self::youtube($path),
            $host === 'vimeo.com', $host === 'player.vimeo.com' => self::vimeo($path),
            default => null,
        };
    }

    /**
     * The id sitting after a known prefix, so a bare /watch or a profile URL
     * does not get read as an id.
     *
     * @param  array<int, string>  $prefixes
     */
    private static function lastSegment(string $path, array $prefixes): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if (count($segments) < 2 || ! in_array($segments[0], $prefixes, true)) {
            return '';
        }

        return $segments[1];
    }

    private static function youtube(string $id): ?self
    {
        // Ids are exactly 11 characters and case-sensitive — this is why the
        // column is never uppercased like the rest of the product.
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1
            ? new self('youtube', $id)
            : null;
    }

    private static function vimeo(string $path): ?self
    {
        // vimeo.com/123456789 or player.vimeo.com/video/123456789
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $id = end($segments);

        return $id !== false && preg_match('/^\d{6,12}$/', $id) === 1
            ? new self('vimeo', $id)
            : null;
    }

    public static function isValid(?string $url): bool
    {
        return self::parse($url) !== null;
    }

    /** The only URL the page is ever allowed to put in an iframe. */
    public function embedUrl(): string
    {
        return match ($this->provider) {
            // nocookie so a shopper who never plays the video is not tracked.
            'youtube' => 'https://www.youtube-nocookie.com/embed/'.$this->id,
            'vimeo' => 'https://player.vimeo.com/video/'.$this->id,
        };
    }

    /**
     * A still to show in place of the player until somebody presses play.
     *
     * Null for providers whose thumbnail needs an API call — the page falls
     * back to a plain poster rather than making the product page wait on a
     * third party. YouTube's is a predictable URL, so it costs nothing.
     */
    public function thumbnailUrl(): ?string
    {
        return $this->provider === 'youtube'
            ? 'https://i.ytimg.com/vi/'.$this->id.'/hqdefault.jpg'
            : null;
    }

    /** Where "open in a new tab" goes. */
    public function watchUrl(): string
    {
        return match ($this->provider) {
            'youtube' => 'https://www.youtube.com/watch?v='.$this->id,
            'vimeo' => 'https://vimeo.com/'.$this->id,
        };
    }

    public function providerName(): string
    {
        return self::PROVIDERS[$this->provider];
    }

    /**
     * What the product page needs to show a player.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'providerName' => $this->providerName(),
            'embedUrl' => $this->embedUrl(),
            'watchUrl' => $this->watchUrl(),
            'thumbnailUrl' => $this->thumbnailUrl(),
        ];
    }

    /** Named in the error a vendor sees when their link is not recognised. */
    public static function supportedProviders(): string
    {
        return implode(' or ', array_values(self::PROVIDERS));
    }
}
