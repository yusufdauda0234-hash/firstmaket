import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Centred loading spinner, shown while Inertia is fetching.
 *
 * Replaces Inertia's bundled NProgress bar, which lived as a 2px line at the
 * very top of the document — behind the sticky header on most pages, so the
 * one moment the app most needs to say "I heard you" was the moment nothing
 * visible happened.
 *
 * Two deliberate choices:
 *
 * - Nothing renders for the first {@link SHOW_AFTER_MS}. A cached Inertia
 *   visit can land in under 50ms, and a spinner that appears and vanishes
 *   inside a single frame reads as a glitch rather than as speed.
 * - The overlay never takes pointer events. Inertia fires start/finish for
 *   *every* visit, including small partial reloads like a cart quantity
 *   change, and freezing the page behind a modal for those would be far more
 *   intrusive than the wait itself.
 */

/** Below this, a visit is perceived as instant — showing anything hurts. */
const SHOW_AFTER_MS = 180;

export default function PageLoader() {
    const [visible, setVisible] = useState(false);
    // Real upload percentage when Inertia reports one (file uploads); null for
    // an ordinary navigation, where any number would be invented.
    const [uploaded, setUploaded] = useState<number | null>(null);

    const showTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const clearShowTimer = () => {
            if (showTimer.current) {
                clearTimeout(showTimer.current);
                showTimer.current = null;
            }
        };

        const start = () => {
            clearShowTimer();
            setUploaded(null);
            showTimer.current = setTimeout(() => setVisible(true), SHOW_AFTER_MS);
        };

        const finish = () => {
            clearShowTimer();
            setVisible(false);
            setUploaded(null);
        };

        const offStart = router.on('start', start);
        const offProgress = router.on('progress', (event) => {
            const percentage = event.detail.progress?.percentage;
            if (typeof percentage === 'number') {
                setUploaded(Math.round(percentage));
            }
        });
        const offFinish = router.on('finish', finish);

        return () => {
            clearShowTimer();
            offStart();
            offProgress();
            offFinish();
        };
    }, []);

    if (!visible) return null;

    return (
        <div
            role="status"
            aria-live="polite"
            className="pointer-events-none fixed inset-0 z-[200] flex items-center justify-center bg-white/45 backdrop-blur-[1.5px]"
        >
            <div className="flex flex-col items-center gap-3 rounded-2xl bg-white/95 px-7 py-6 shadow-xl shadow-slate-900/10 ring-1 ring-black/5">
                {/* Two stacked rings rather than an image: the track stays put
                    while only the arc spins, which reads as motion even on the
                    slow frames of a page that is busy parsing a new chunk. */}
                <span className="relative flex h-10 w-10" aria-hidden="true">
                    <span className="absolute inset-0 rounded-full border-[3px] border-brand-100" />
                    <span className="absolute inset-0 animate-spin rounded-full border-[3px] border-transparent border-t-brand-600" />
                </span>

                <span className="text-xs font-semibold tabular-nums text-gray-600">
                    {uploaded === null ? 'Loading…' : `Uploading ${uploaded}%`}
                </span>
            </div>
        </div>
    );
}
