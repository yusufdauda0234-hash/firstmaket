import { PropsWithChildren, useEffect, useRef, useState } from 'react';

interface RevealProps {
    /** Stagger delay in ms — pass index * 100 for one-after-another entrances. */
    delay?: number;
    className?: string;
    /** Anchor target, so in-page jump links can land on a revealed section. */
    id?: string;
}

/**
 * Scroll-reveal wrapper (AOS pattern without the dependency): children fade
 * and slide up the first time they enter the viewport. Respects
 * prefers-reduced-motion via Tailwind's motion-reduce variants.
 */
export default function Reveal({ delay = 0, className = '', id, children }: PropsWithChildren<RevealProps>) {
    const ref = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el || typeof IntersectionObserver === 'undefined') {
            setVisible(true);
            return;
        }
        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' },
        );
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            id={id}
            style={{ transitionDelay: `${delay}ms` }}
            className={`transition-all duration-700 ease-out motion-reduce:transition-none ${
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-8 opacity-0 motion-reduce:translate-y-0 motion-reduce:opacity-100'
            } ${className}`}
        >
            {children}
        </div>
    );
}
