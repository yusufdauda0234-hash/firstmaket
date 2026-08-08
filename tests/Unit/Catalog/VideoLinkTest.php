<?php

use App\Modules\Catalog\Support\VideoLink;

it('reads a standard YouTube watch link', function () {
    $link = VideoLink::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($link?->provider)->toBe('youtube')
        ->and($link?->id)->toBe('dQw4w9WgXcQ');
});

it('reads the share, shorts, embed and mobile forms', function (string $url) {
    expect(VideoLink::parse($url)?->id)->toBe('dQw4w9WgXcQ');
})->with([
    'short link' => 'https://youtu.be/dQw4w9WgXcQ',
    // What YouTube's own Share button produces today.
    'share button' => 'https://youtu.be/dQw4w9WgXcQ?si=Ry3o1--e7xNCnVal',
    'shorts' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'live' => 'https://www.youtube.com/live/dQw4w9WgXcQ',
    'mobile' => 'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'no www' => 'https://youtube.com/watch?v=dQw4w9WgXcQ',
    'http' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'extra params' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PLabc',
]);

it('reads Vimeo', function () {
    expect(VideoLink::parse('https://vimeo.com/123456789')?->provider)->toBe('vimeo')
        ->and(VideoLink::parse('https://player.vimeo.com/video/123456789')?->id)->toBe('123456789');
});

it('rejects a link to anywhere else', function (?string $url) {
    expect(VideoLink::parse($url))->toBeNull();
})->with([
    'empty' => '',
    'null' => null,
    'spaces' => '   ',
    'not a url' => 'my video',
    'no host' => 'youtu.be/dQw4w9WgXcQ',
    'another site' => 'https://example.com/watch?v=dQw4w9WgXcQ',
    // The check is on the host, not on the string containing "youtube".
    'lookalike host' => 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
    'subdomain trick' => 'https://evil.test/www.youtube.com/watch?v=dQw4w9WgXcQ',
    'a channel, not a video' => 'https://www.youtube.com/@somechannel',
    'bare watch' => 'https://www.youtube.com/watch',
    'id too short' => 'https://www.youtube.com/watch?v=abc',
    'id too long' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQextra',
    'vimeo profile' => 'https://vimeo.com/someuser',
]);

it('refuses a javascript: url', function () {
    // parse_url reads no host here, so it can never reach the iframe.
    expect(VideoLink::parse('javascript:alert(1)'))->toBeNull()
        ->and(VideoLink::parse('data:text/html,<script>alert(1)</script>'))->toBeNull();
});

it('drops the tracking parameter off a shared link', function () {
    // ?si= is a share-attribution token. It identifies whoever sent the link,
    // and there is no reason to forward it from a product page.
    expect(VideoLink::parse('https://youtu.be/dQw4w9WgXcQ?si=Ry3o1--e7xNCnVal')?->embedUrl())
        ->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

it('builds the embed url from the id rather than the input', function () {
    // The point of the whole class: whatever a vendor pastes, what reaches the
    // iframe is a template with an id substituted into it.
    $link = VideoLink::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1&feature=evil');

    expect($link?->embedUrl())->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

it('always embeds over https, even from an http link', function () {
    $link = VideoLink::parse('http://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($link?->embedUrl())->toStartWith('https://');
});

it('keeps the id case exactly as given', function () {
    // YouTube ids are case-sensitive, which is why video_url is one of the few
    // columns not uppercased on the way into the database.
    expect(VideoLink::parse('https://youtu.be/AbCdEfGhIjK')?->id)->toBe('AbCdEfGhIjK');
});

it('offers a still so the player is not loaded until it is wanted', function () {
    // The product page shows this image and only mounts the real iframe on a
    // click — the player itself is about a megabyte of third-party code that
    // every shopper would otherwise pay for whether or not they watch.
    expect(VideoLink::parse('https://youtu.be/dQw4w9WgXcQ')?->thumbnailUrl())
        ->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
});

it('has no still for a provider whose thumbnail needs an API call', function () {
    // Null rather than a request the product page would have to wait on.
    expect(VideoLink::parse('https://vimeo.com/123456789')?->thumbnailUrl())->toBeNull();
});

it('names the providers a vendor may use', function () {
    expect(VideoLink::supportedProviders())->toBe('YouTube or Vimeo');
});
