import { PageProps } from '@/Types';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Loads the live-chat widget staff selected in admin.
 *
 * The script URL is built here from a known provider name and an id, rather
 * than injecting a snippet stored in the database. A pasted snippet would be
 * arbitrary third-party JavaScript on pages where customers are entering
 * payment details, so the set of providers is closed and the id is validated
 * to `[A-Za-z0-9_-]` on the way in — the worst a wrong value can do is fail to
 * load a widget.
 *
 * Rendered on the storefront only. The staff and vendor portals do not get a
 * customer support widget, and neither does the checkout — see PublicLayout.
 */
export default function ChatWidget() {
    const { supportChat, auth } = usePage<PageProps>().props;

    const provider = supportChat?.provider ?? 'none';
    const propertyId = supportChat?.propertyId ?? '';
    const widgetId = supportChat?.widgetId ?? '';
    const allowGuests = supportChat?.forGuests ?? true;

    const shouldLoad =
        provider !== 'none' && propertyId !== '' && (allowGuests || auth.user !== null);

    useEffect(() => {
        if (!shouldLoad) return;

        const src =
            provider === 'tawk'
                ? `https://embed.tawk.to/${propertyId}/${widgetId || 'default'}`
                : provider === 'crisp'
                  ? 'https://client.crisp.chat/l.js'
                  : null;

        if (src === null) return;

        if (provider === 'crisp') {
            // Crisp reads its site id from a global rather than the URL.
            (window as unknown as Record<string, unknown>).$crisp = [];
            (window as unknown as Record<string, unknown>).CRISP_WEBSITE_ID = propertyId;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        // The widget is a convenience, not part of the page: never let it
        // block or delay anything the customer is actually doing.
        script.defer = true;
        document.head.appendChild(script);

        return () => {
            script.remove();
        };
    }, [shouldLoad, provider, propertyId, widgetId]);

    return null;
}
