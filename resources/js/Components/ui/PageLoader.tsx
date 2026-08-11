import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Navigation progress: a thin bar along the very top of the window.
 *
 * This used to be a full-screen scrim with a blur and a centred spinner card.
 * That was wrong twice over. It read as a modal — as though the page had been
 * taken away from you — for waits usually measured in a couple of hundred
 * milliseconds. And because Inertia fires `start` for *every* request, it also
 * appeared for things that are not navigations at all: a cart quantity change,
 * a background reload, and most visibly a link being **hovered**, since the
 * sidebars prefetch on hover. Hovering a menu item should not dim the page.
 *
 * A bar is the right shape for this. It says "working" without claiming the
 * screen, it cannot obscure what you were reading, and it sits above the
 * sticky headers rather than behind them — which is the one real complaint
 * against the NProgress bar this originally replaced.
 *
 * Three rules decide whether it shows at all:
 *
 * - **Never for a prefetch.** Those are speculative and happen on hover.
 * - **Never when Inertia says not to** (`showProgress: false`), or for an
 *   async visit, which by definition is meant to be invisible.
 * - **Never for anything that finishes quickly.** Below SHOW_AFTER_MS a visit
 *   is perceived as instant, and a bar that flashes for one frame reads as a
 *   glitch rather than as speed.
 */

/** Below this, a visit is perceived as instant — showing anything hurts. */
const SHOW_AFTER_MS = 120;

/** How often the bar creeps forward while waiting on the server. */
const TRICKLE_MS = 220;

/** How long the finished bar stays at 100% before it fades away. */
const SETTLE_MS = 240;

/**
 * The bar never reaches the end on its own.
 *
 * Real progress is unknowable for a navigation — the server has not answered
 * yet. Creeping towards a ceiling and only completing when the response
 * actually lands keeps the bar honest: it never sits full while the page is
 * still waiting, which is the tell that a progress indicator is lying.
 */
const CEILING = 92;

/**
 * Read once at module load. Inline styles beat a stylesheet media query, so
 * the preference has to be honoured in JS rather than CSS — otherwise the bar
 * would keep animating for somebody who has asked the system for less motion.
 */
const REDUCED_MOTION =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export default function PageLoader() {
    const [value, setValue] = useState(0);
    const [visible, setVisible] = useState(false);
    const [settling, setSettling] = useState(false);

    const showTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const hideTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const trickle = useRef<ReturnType<typeof setInterval> | null>(null);
    /** True while a real upload is reporting bytes, so the trickle stands aside. */
    const measured = useRef(false);
    /**
     * Whether the bar is on screen, mirrored outside React state.
     *
     * `finish` needs to know this to decide between completing the bar and
     * doing nothing, and reading it from a state updater would mean calling
     * setState inside one — updaters have to stay pure or they misbehave
     * under StrictMode's double invocation.
     */
    const showing = useRef(false);

    const clearAll = useCallback(() => {
        if (showTimer.current) clearTimeout(showTimer.current);
        if (hideTimer.current) clearTimeout(hideTimer.current);
        if (trickle.current) clearInterval(trickle.current);
        showTimer.current = null;
        hideTimer.current = null;
        trickle.current = null;
    }, []);

    useEffect(() => {
        const begin = () => {
            showing.current = true;
            setVisible(true);
            setSettling(false);
            setValue(10);

            // Eases towards the ceiling: big steps early, barely moving near
            // the top. A linear crawl makes a slow request feel stuck.
            trickle.current = setInterval(() => {
                setValue((current) => {
                    if (measured.current || current >= CEILING) return current;

                    return current + Math.max(0.6, (CEILING - current) * 0.14);
                });
            }, TRICKLE_MS);
        };

        const start = (event: CustomEvent<{ visit: { prefetch?: boolean; async?: boolean; showProgress?: boolean } }>) => {
            const visit = event.detail?.visit;

            // A hovered link, a background refresh, or a visit Inertia has
            // explicitly marked silent. None of these are the user waiting.
            if (visit?.prefetch || visit?.async || visit?.showProgress === false) {
                return;
            }

            clearAll();
            measured.current = false;
            showTimer.current = setTimeout(begin, SHOW_AFTER_MS);
        };

        const progress = (event: CustomEvent<{ progress?: { percentage?: number } }>) => {
            const percentage = event.detail?.progress?.percentage;

            if (typeof percentage !== 'number') return;

            // A file upload knows exactly how far along it is, so stop
            // guessing and show the truth.
            measured.current = true;
            showing.current = true;
            setVisible(true);
            setValue(Math.min(CEILING, Math.max(10, percentage)));
        };

        const finish = () => {
            clearAll();

            // Nothing was ever shown — a visit that beat the delay — so there
            // is nothing to complete and nothing to fade.
            if (!showing.current) {
                return;
            }

            setValue(100);
            setSettling(true);

            hideTimer.current = setTimeout(() => {
                showing.current = false;
                setVisible(false);
                setSettling(false);
                setValue(0);
            }, SETTLE_MS);
        };

        const offStart = router.on('start', start as EventListener);
        const offProgress = router.on('progress', progress as EventListener);
        const offFinish = router.on('finish', finish);

        return () => {
            clearAll();
            offStart();
            offProgress();
            offFinish();
        };
    }, [clearAll]);

    if (!visible) return null;

    return (
        <div
            role="progressbar"
            aria-label="Loading"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(value)}
            // z-index above every sticky header and drawer in the app, so the
            // one thing that must always be visible always is.
            className="pointer-events-none fixed inset-x-0 top-0 z-[9999] h-[3px]"
        >
            <div
                className="h-full bg-gradient-to-r from-brand-500 via-brand-600 to-brand-400"
                style={{
                    width: `${value}%`,
                    // Snappy while creeping, snappier still when completing —
                    // the finish should feel like an arrival, not a glide.
                    transition: REDUCED_MOTION
                        ? 'opacity 120ms linear'
                        : settling
                          ? 'width 160ms ease-out, opacity 200ms ease-in 120ms'
                          : 'width 320ms cubic-bezier(0.22, 1, 0.36, 1)',
                    opacity: settling ? 0 : 1,
                    // A soft leading edge reads as motion even when the width
                    // is barely changing on a slow request.
                    boxShadow: '0 0 8px 1px rgb(0 73 173 / 0.45)',
                }}
            />
        </div>
    );
}
