/**
 * Small angled notch that points from a dropdown panel back at its trigger
 * (Amazon/Temu style). Position it with a `left-*`/`right-*` class; the
 * parent panel must be `relative` (Tailwind's `absolute` panel works) and
 * sit `mt-3`+ below the trigger so the caret has room to breathe.
 */
export default function PopoverCaret({ className = 'right-8' }: { className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={`absolute -top-[7px] h-3.5 w-3.5 rotate-45 rounded-[2px] border-l border-t border-gray-200 bg-white ${className}`}
        />
    );
}
