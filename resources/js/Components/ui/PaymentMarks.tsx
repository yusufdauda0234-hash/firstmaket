import { CalendarClock } from 'lucide-react';

/**
 * The "Pay with" row.
 *
 * Card-scheme marks are drawn inline as SVG rather than pulled from a CDN:
 * the artwork is a handful of shapes, and a checkout page should not depend
 * on a third-party host being up — or tell that host who is checking out.
 * Each mark carries its own name as an accessible label, so the row still
 * reads correctly to a screen reader.
 */

function Mark({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <span
            title={title}
            className="flex h-7 w-10 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white px-1 shadow-sm"
        >
            <svg viewBox="0 0 48 30" className="h-full w-full" role="img" aria-label={title}>
                {children}
            </svg>
        </span>
    );
}

function Visa() {
    return (
        <Mark title="Visa">
            <text
                x="24"
                y="21"
                textAnchor="middle"
                fontFamily="Georgia, 'Times New Roman', serif"
                fontSize="15"
                fontStyle="italic"
                fontWeight="700"
                letterSpacing="0.5"
                fill="#1A1F71"
            >
                VISA
            </text>
        </Mark>
    );
}

function Mastercard() {
    return (
        <Mark title="Mastercard">
            <circle cx="19" cy="15" r="9" fill="#EB001B" />
            <circle cx="29" cy="15" r="9" fill="#F79E1B" />
            {/* The overlap reads orange-on-red in the real mark. */}
            <path
                d="M24 8.2a9 9 0 0 0 0 13.6 9 9 0 0 0 0-13.6Z"
                fill="#FF5F00"
            />
        </Mark>
    );
}

function Verve() {
    return (
        <Mark title="Verve">
            <path d="M6 9h6l4 9 4-9h6L18 24h-4L6 9Z" fill="#00425F" />
            <text
                x="34"
                y="20"
                textAnchor="middle"
                fontFamily="Arial, Helvetica, sans-serif"
                fontSize="10"
                fontWeight="700"
                fill="#EE3124"
            >
                erve
            </text>
        </Mark>
    );
}

function Paystack() {
    return (
        <Mark title="Paystack">
            <rect x="9" y="7" width="30" height="3.6" rx="1.2" fill="#00C3F7" />
            <rect x="9" y="12.2" width="30" height="3.6" rx="1.2" fill="#00C3F7" />
            <rect x="9" y="17.4" width="18" height="3.6" rx="1.2" fill="#00C3F7" />
            <rect x="9" y="22.6" width="10" height="3.6" rx="1.2" fill="#011B33" />
        </Mark>
    );
}

/**
 * @param compact  Drops the top margin and the Pay Small Small chip, for the
 *                 footer strip where the row sits inline with other content.
 */
export default function PaymentMarks({ compact = false }: { compact?: boolean }) {
    return (
        // One row, never wrapped: five marks read as "these are your options",
        // where a second line reads as a separate thought.
        <div className={`flex flex-nowrap items-center gap-1.5 ${compact ? '' : 'mt-3'}`}>
            <Paystack />
            <Visa />
            <Mastercard />
            <Verve />

            {/* Not a card scheme — FirstMaket's own instalment plan, so it
                gets the wordmark treatment rather than a fake logo. */}
            {!compact && (
                <span
                    title="Pay Small Small — pay in instalments"
                    className="flex h-7 min-w-0 flex-1 items-center justify-center gap-1 rounded-md border border-brand-200 bg-brand-50 px-1.5 text-[10px] font-bold leading-none text-brand-700 shadow-sm"
                >
                    <CalendarClock className="h-3 w-3 shrink-0" aria-hidden="true" />
                    <span className="truncate">Pay Small Small</span>
                </span>
            )}
        </div>
    );
}
