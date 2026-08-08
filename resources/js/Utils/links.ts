/**
 * Anchor props for a product page, opened in a new tab.
 *
 * The pattern AliExpress, Alibaba and Jumia all use on their grids: a
 * shopper scanning results keeps their place — scroll position, filters,
 * page number, and anything already in the cart tab — instead of bouncing
 * back and forth. Applied to the browsing surfaces and to cart/checkout
 * lines (where navigating away would cost the shopper their form state);
 * order and savings screens stay in-tab, since those are read-through flows
 * rather than browsing.
 *
 * Spread onto a plain <a>, not an Inertia <Link> — Link always navigates the
 * SPA in place and ignores target.
 */
export function productLinkProps(slug: string) {
    return {
        href: route('catalog.product', { product: slug }),
        target: '_blank',
        rel: 'noopener noreferrer',
    } as const;
}

/** Imperative equivalent, for handlers that cannot render an anchor. */
export function openProductInNewTab(slug: string): void {
    window.open(route('catalog.product', { product: slug }), '_blank', 'noopener,noreferrer');
}
