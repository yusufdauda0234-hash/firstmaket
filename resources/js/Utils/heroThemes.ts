/**
 * Hero slide color presets, keyed by the `theme` value admins pick on
 * Admin/Merchandising/HeroSlides.
 *
 * Deliberately a closed set of literal Tailwind class strings rather than
 * free text from the database: Tailwind only generates CSS for class names
 * it can see in the source at build time, so a gradient typed into a form
 * field would compile to nothing and render unstyled. Every string here is
 * written out in full so the build always picks it up.
 */
export interface HeroTheme {
    label: string;
    bg: string;
    btnClass: string;
}

export const HERO_THEMES: Record<string, HeroTheme> = {
    brand: {
        label: 'Brand blue',
        bg: 'from-brand-600 via-brand-700 to-brand-900',
        btnClass: 'bg-brand-yellow text-brand-900 hover:bg-yellow-300',
    },
    'brand-reverse': {
        label: 'Brand blue, light button',
        bg: 'from-brand-800 via-brand-600 to-brand-900',
        btnClass: 'bg-white text-brand-700 hover:bg-brand-50',
    },
    'brand-deep': {
        label: 'Deep blue',
        bg: 'from-brand-900 via-brand-700 to-brand-600',
        btnClass: 'bg-brand-yellow text-brand-900 hover:bg-yellow-300',
    },
    sunset: {
        label: 'Sunset orange',
        bg: 'from-orange-600 via-amber-600 to-orange-800',
        btnClass: 'bg-white text-orange-700 hover:bg-orange-50',
    },
    emerald: {
        label: 'Emerald green',
        bg: 'from-emerald-700 via-emerald-600 to-emerald-900',
        btnClass: 'bg-white text-emerald-700 hover:bg-emerald-50',
    },
};

export const DEFAULT_HERO_THEME = 'brand';

export function heroTheme(key: string): HeroTheme {
    return HERO_THEMES[key] ?? HERO_THEMES[DEFAULT_HERO_THEME];
}
