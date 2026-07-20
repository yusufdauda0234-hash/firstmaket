/** "home-appliances" -> "Home Appliances" — for showing category slugs as labels. */
export function humanizeSlug(slug: string): string {
    return slug
        .split('-')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}
