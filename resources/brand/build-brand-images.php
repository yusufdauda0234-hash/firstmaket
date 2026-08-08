<?php

/**
 * Build the FirstMaket brand image set from the single supplied master.
 *
 * Run from the project root, and only when the logo itself changes:
 *
 *     php -d memory_limit=512M resources/brand/build-brand-images.php
 *
 * The master is a 3500x3500 PNG of the logo on solid white with a lot of empty
 * margin, so everything here starts by trimming to the artwork and turning the
 * white backdrop into real transparency. Every file it writes is overwritten
 * wholesale, so re-running is safe.
 *
 * It lives next to the master rather than in the build pipeline because it is
 * neither fast nor frequently needed — the outputs are committed.
 */
const SRC = 'resources/brand/logo-master.png';

/**
 * "Just Order. We Deliver", lifted out of the previous logo.
 *
 * The new master is the mark on its own, but the old set carried the strapline
 * under the full-size lockups and that is worth keeping. Reusing the original
 * artwork rather than re-typesetting it keeps the exact serif and letter-
 * spacing; the wording contains no "Market", so the rename does not touch it.
 * Stored as flat white ink plus alpha so it can be tinted to either colourway.
 */
const TAGLINE = 'resources/brand/tagline-master.png';

const OUT = 'public/images/brand/';

/** Brand blue, for the solid colourway. Measured from the master at runtime. */
const TAGLINE_GAP = 0.10;   // gap under the mark, as a fraction of mark height
const TAGLINE_WIDTH = 0.88; // tagline width, as a fraction of mark width

/** Anything at or above this on every channel is backdrop, not artwork. */
const WHITE_FLOOR = 250;

/**
 * How hard to pull the soft edges back into focus.
 *
 * The master is not a crisp export: a stroke edge takes about nine pixels to
 * go from paper to solid ink, where artwork straight out of a vector tool
 * takes one or two. Copied through untouched that reads as a blurry logo at
 * every size. Alpha is therefore put through a contrast curve — the middle of
 * the ramp is stretched to the full range and the tails clip — which pulls a
 * nine-pixel fade down to roughly two before anything is scaled down.
 *
 * Applied at master resolution on purpose: the later downscale then does the
 * anti-aliasing, so edges come out sharp but not stair-stepped. Raising this
 * much above 4 starts to nibble the thin serifs on "MAKET".
 */
const EDGE_CONTRAST = 3.6;

// ── Load ────────────────────────────────────────────────────────────────

$master = imagecreatefrompng(SRC);
imagepalettetotruecolor($master);

/**
 * The artwork's bounding box, so the margin around it is ours to choose
 * rather than whatever the master happened to have.
 *
 * @return array{0:int,1:int,2:int,3:int}
 */
function contentBox($im): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);

            if ((($c >> 16) & 255) < WHITE_FLOOR || (($c >> 8) & 255) < WHITE_FLOOR || ($c & 255) < WHITE_FLOOR) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
}

/** A blank truecolour canvas that keeps its alpha when saved. */
function canvas(int $w, int $h, ?array $fill = null)
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);

    $bg = $fill === null
        ? imagecolorallocatealpha($im, 255, 255, 255, 127)
        : imagecolorallocatealpha($im, $fill[0], $fill[1], $fill[2], 0);

    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    return $im;
}

/**
 * The two ink colours, read off the solid areas of the master.
 *
 * Measured rather than hardcoded so the set stays right if the logo is ever
 * re-exported in a slightly different shade. Yellow and navy are told apart by
 * which side of the spectrum they sit on, which is unambiguous here — there is
 * nothing else in the artwork.
 *
 * @return array{navy: array<int,int>, yellow: array<int,int>}
 */
function inkColours($src): array
{
    $sum = ['navy' => [0, 0, 0, 0], 'yellow' => [0, 0, 0, 0]];

    for ($y = 0; $y < imagesy($src); $y++) {
        for ($x = 0; $x < imagesx($src); $x++) {
            $c = imagecolorat($src, $x, $y);
            $r = ($c >> 16) & 255;
            $g = ($c >> 8) & 255;
            $b = $c & 255;

            // Deep in a stroke, not on a blended edge.
            $key = match (true) {
                $b > $r + 60 && $b < 220 => 'navy',
                $r > $b + 120 && $r > 200 => 'yellow',
                default => null,
            };

            if ($key === null) {
                continue;
            }

            $sum[$key][0] += $r;
            $sum[$key][1] += $g;
            $sum[$key][2] += $b;
            $sum[$key][3]++;
        }
    }

    $mean = function (array $s): array {
        $n = max(1, $s[3]);

        return [(int) round($s[0] / $n), (int) round($s[1] / $n), (int) round($s[2] / $n)];
    };

    return ['navy' => $mean($sum['navy']), 'yellow' => $mean($sum['yellow'])];
}

/**
 * Lift the artwork off its white backdrop, as flat ink plus an alpha channel.
 *
 * Each pixel is read as `ink over white`, so how far it has travelled from
 * white towards its own ink gives the alpha. Deriving it per-ink matters: a
 * single "distance from white" measure makes solid yellow only about 70%
 * opaque, because #F8D54C never reaches zero on any channel — the yellow would
 * have gone translucent everywhere it sits on a dark header.
 *
 * Repainting in flat ink also denoises. The master carries compression
 * speckle, and keeping each pixel's own recovered colour left the lockup at
 * 841KB on a page that loads it every time; a two-ink image with a smooth
 * alpha channel is what PNG is built to compress.
 */
function liftFromWhite($src, array $inks)
{
    $w = imagesx($src);
    $h = imagesy($src);
    $out = canvas($w, $h);

    foreach ($inks as $name => $ink) {
        // Channels far enough from white to measure coverage against.
        $inks[$name] = [
            'rgb' => $ink,
            'channels' => array_values(array_filter([0, 1, 2], fn ($i) => 255 - $ink[$i] > 40)),
        ];
    }

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($src, $x, $y);
            $px = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];

            if (min($px) >= WHITE_FLOOR) {
                continue; // backdrop; the canvas is already transparent here
            }

            $ink = $inks[$px[0] > $px[2] ? 'yellow' : 'navy'];

            $a = 0.0;
            foreach ($ink['channels'] as $i) {
                $a += (255 - $px[$i]) / (255 - $ink['rgb'][$i]);
            }
            $a /= max(1, count($ink['channels']));

            // Pull the master's nine-pixel fade back to a real edge.
            $a = max(0.0, min(1.0, ($a - 0.5) * EDGE_CONTRAST + 0.5));

            $colour = imagecolorallocatealpha(
                $out,
                $ink['rgb'][0],
                $ink['rgb'][1],
                $ink['rgb'][2],
                127 - (int) round($a * 127),
            );

            imagesetpixel($out, $x, $y, $colour);
            imagecolordeallocate($out, $colour);
        }
    }

    return $out;
}

/** Crop, keeping transparency. */
function crop($src, int $x, int $y, int $w, int $h)
{
    $out = canvas($w, $h);
    imagecopy($out, $src, 0, 0, $x, $y, $w, $h);

    return $out;
}

/** Resize to fit a box, keeping the aspect ratio and the alpha channel. */
function scaleToFit($src, int $boxW, int $boxH)
{
    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = min($boxW / $w, $boxH / $h);
    $newW = max(1, (int) round($w * $ratio));
    $newH = max(1, (int) round($h * $ratio));

    $out = canvas($newW, $newH);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

    return $out;
}

/**
 * Centre the artwork on a canvas of a given size, leaving a margin.
 *
 * $fill null keeps the canvas transparent; an [r,g,b] gives a solid backdrop,
 * which iOS home-screen icons and social previews both need — they composite
 * transparency onto black otherwise.
 */
function centreOn($art, int $w, int $h, float $inset = 0.0, ?array $fill = null)
{
    $out = canvas($w, $h, $fill);
    $fitted = scaleToFit($art, (int) round($w * (1 - $inset)), (int) round($h * (1 - $inset)));

    /*
     * On a filled canvas the artwork has to be blended onto the fill. With
     * blending off — which is what canvas() sets, so that transparency
     * survives a straight copy — imagecopy overwrites the destination alpha
     * too, punching the artwork's transparent areas straight back through the
     * backdrop. The icons came out see-through in the middle, which is the
     * one thing a solid backdrop exists to prevent.
     */
    imagealphablending($out, $fill !== null);

    imagecopy(
        $out,
        $fitted,
        (int) round(($w - imagesx($fitted)) / 2),
        (int) round(($h - imagesy($fitted)) / 2),
        0,
        0,
        imagesx($fitted),
        imagesy($fitted),
    );

    imagedestroy($fitted);

    imagealphablending($out, false);
    imagesavealpha($out, true);

    return $out;
}

/**
 * The version for dark backgrounds.
 *
 * The navy carries the shape, so on a dark header it simply vanishes — it is
 * repainted near-white. The yellow is the one part that already reads well on
 * dark, and it is what makes the logo recognisable, so it stays.
 */
function lightVariant($src)
{
    $w = imagesx($src);
    $h = imagesy($src);
    $out = canvas($w, $h);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($src, $x, $y);
            $alpha = ($c >> 24) & 127;

            if ($alpha === 127) {
                continue;
            }

            $r = ($c >> 16) & 255;
            $g = ($c >> 8) & 255;
            $b = $c & 255;

            // Yellow reads warm (red above blue); the navy is the opposite.
            $isYellow = $r > $b + 40;

            $colour = $isYellow
                ? imagecolorallocatealpha($out, $r, $g, $b, $alpha)
                : imagecolorallocatealpha($out, 247, 247, 242, $alpha);

            imagesetpixel($out, $x, $y, $colour);
            imagecolordeallocate($out, $colour);
        }
    }

    return $out;
}

/** Repaint every visible pixel one flat colour, keeping its alpha. */
function tint($src, array $rgb)
{
    $w = imagesx($src);
    $h = imagesy($src);
    $out = canvas($w, $h);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $a = (imagecolorat($src, $x, $y) >> 24) & 127;

            if ($a >= 127) {
                continue;
            }

            $c = imagecolorallocatealpha($out, $rgb[0], $rgb[1], $rgb[2], $a);
            imagesetpixel($out, $x, $y, $c);
            imagecolordeallocate($out, $c);
        }
    }

    return $out;
}

/**
 * The mark with the strapline set beneath it, in the given ink.
 *
 * Proportions are taken from the old lockup — the gap and the strapline width
 * are expressed against the mark, so the pair keeps its relationship whatever
 * size the mark is built at.
 */
function withTagline($mark, array $rgb)
{
    $markW = imagesx($mark);
    $markH = imagesy($mark);

    $raw = imagecreatefrompng(TAGLINE);
    $tinted = tint($raw, $rgb);
    imagedestroy($raw);

    $tag = scaleToFit($tinted, (int) round($markW * TAGLINE_WIDTH), $markH);
    imagedestroy($tinted);

    $gap = (int) round($markH * TAGLINE_GAP);
    $out = canvas($markW, $markH + $gap + imagesy($tag));

    imagecopy($out, $mark, 0, 0, 0, 0, $markW, $markH);
    imagecopy(
        $out,
        $tag,
        (int) round(($markW - imagesx($tag)) / 2),
        $markH + $gap,
        0,
        0,
        imagesx($tag),
        imagesy($tag),
    );

    imagedestroy($tag);

    return $out;
}

function save($im, string $name): void
{
    imagepng($im, OUT.$name, 9);
    $size = filesize(OUT.$name);
    printf("  %-30s %4dx%-4d %6.1f KB\n", $name, imagesx($im), imagesy($im), $size / 1024);
}

// ── Build ───────────────────────────────────────────────────────────────

[$bx, $by, $bw, $bh] = contentBox($master);
echo "artwork found at ({$bx},{$by}) {$bw}x{$bh}\n\n";

// Crop before lifting the backdrop: the master is 3500x3500, which is ~49MB
// as a truecolour buffer, and holding two of those at once blows the memory
// limit. The artwork itself is a fifth of that.
$cropped = crop($master, $bx, $by, $bw, $bh);
imagedestroy($master);

// Inks are measured from the master, before anything is made transparent —
// the alpha of every edge pixel is then worked out relative to its own ink.
$inks = inkColours($cropped);
printf(
    "inks: navy #%02X%02X%02X, yellow #%02X%02X%02X\n\n",
    ...array_merge($inks['navy'], $inks['yellow'])
);

$art = liftFromWhite($cropped, $inks);
imagedestroy($cropped);

/*
 * Sized against how it is actually used, not "as big as the master allows".
 * The headers draw this at 36-40px tall, so 800 on the long edge is still
 * about five times what a 3x screen asks for — and a quarter of the bytes of
 * the 1600 version, on an image every page loads.
 */
$lockup = scaleToFit($art, 800, 800);
$light = lightVariant($lockup);

$cream = [247, 247, 242];

/*
 * Only what the app actually references.
 *
 * Earlier runs also wrote a plain light mark, a dark strapline lockup and a
 * square solid "primary". Nothing used any of them, and generating files
 * nobody reads just means the next person cannot tell which logo is the real
 * one. Anything needed later can be added back here in a line.
 */
echo "lockups\n";
save($lockup, 'logo-mark-dark.png');

/*
 * The strapline version, for the places with room to show it: sign-in panels
 * and the footer. Kept separate from the plain lockup because headers draw the
 * logo about 40px tall, where the strapline is an illegible smudge and
 * squeezing it in only shrinks the mark to make room.
 */
save(withTagline($light, $cream), 'logo-full-light.png');

// Square transparent mark: the faded watermark behind empty states, and the
// collapsed sidebar icon, both of which are square slots a wide lockup would
// letterbox into.
$square = centreOn($art, 512, 512, 0.06);
save($square, 'logo-mark-blue.png');

// Only the sizes app.blade.php actually links. Android home-screen icons
// (192/512) would need a web app manifest to mean anything, and there is not
// one — generating them would just leave files nothing reads.
echo "\nicons\n";
foreach ([16, 32, 48] as $px) {
    $icon = centreOn($art, $px, $px, 0.04);
    save($icon, "favicon-{$px}.png");
    imagedestroy($icon);
}

// iOS composites a transparent icon onto black, so this one gets a backdrop.
$apple = centreOn($art, 180, 180, 0.16, [255, 255, 255]);
save($apple, 'apple-touch-icon.png');

/*
 * Social preview. 1200x630 is the size Facebook, X, WhatsApp and LinkedIn all
 * crop towards; a square image gets cut off at the top and bottom.
 *
 * Brand blue with the strapline, because a share card is exactly the place
 * that should say what FirstMaket does rather than showing a bare mark.
 */
$ogArt = withTagline(scaleToFit($light, 900, 900), $cream);
$og = centreOn($ogArt, 1200, 630, 0.16, $inks['navy']);
imagedestroy($ogArt);
save($og, 'og-image.png');

imagedestroy($art);
imagedestroy($lockup);
imagedestroy($light);
imagedestroy($square);
imagedestroy($apple);
imagedestroy($og);

echo "\ndone\n";
