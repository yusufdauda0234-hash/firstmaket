# FirstMarket Brand Assets

Version: 1.0
Source file: `logo-1.PNG` (project root, 2000×2000, blue background master).
Generated variants live in `public/images/brand/`.

## 1. Brand Colors

Sampled from the master logo:

| Token | Hex | Usage |
| --- | --- | --- |
| Brand Blue | `#0049AD` | Primary background, buttons, links, header |
| Brand Yellow | `#FFDF58` | Accent — "FIRST" wordmark, highlights, CTAs |
| Brand Cream | `#F8F8F0` | Light text/mark on blue, light surfaces |
| Brand Navy | `#102A5E` | Dark logo variant, dark text on light surfaces |

Add these to `tailwind.config.js` as `brand.blue`, `brand.yellow`, `brand.cream`, `brand.navy`.

## 2. Logo Variants

| File | Description | Use on |
| --- | --- | --- |
| `logo-primary.png` | Full logo with tagline on brand blue (master copy) | Social cards, print, splash |
| `logo-light-transparent.png` | Full logo with tagline, transparent background, light (cream/yellow) colors | Any dark or blue background |
| `logo-mark-blue.png` | Bag mark + FIRST MARKET only, **no tagline**, on brand blue | Square placements, avatars |
| `logo-mark-transparent.png` | Bag mark + FIRST MARKET only, **no tagline**, transparent, light colors | Dark/blue headers, footer |
| `logo-dark.png` | Full logo with tagline, transparent, navy + yellow | White/light backgrounds |
| `logo-mark-dark.png` | Bag mark only, no tagline, transparent, navy + yellow | White/light headers (main site header) |

Rules:

- On light backgrounds always use a dark variant; the light variants are nearly invisible on white.
- Tagline is "Just Order. We Deliver" — only the full variants carry it; never re-typeset it manually.
- Do not stretch, recolor, or add effects; regenerate from `logo-1.PNG` if a new size or format is needed.

## 3. Regenerating / Still Needed

Variants were produced programmatically from the flat-color master (background keying + tagline band crop + recolor).

Recommended follow-ups when a designer is available:

- Vector (SVG) redraw of the bag mark for crisp scaling and favicon generation.
- Favicon/app-icon set (16–512 px) from `logo-mark-blue.png`.
- Verify the dark-variant navy `#102A5E` against final UI palette.
