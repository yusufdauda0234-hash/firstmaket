import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Shared open/close state for header popovers and dropdowns: closes on
 * outside click and on Escape.
 */
export function usePopover<T extends HTMLElement>() {
    const [open, setOpen] = useState(false);
    const ref = useRef<T>(null);

    useEffect(() => {
        if (!open) return;

        function onClickOutside(e: MouseEvent) {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        }

        function onKey(e: KeyboardEvent) {
            if (e.key === 'Escape') setOpen(false);
        }

        document.addEventListener('mousedown', onClickOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onClickOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return { open, setOpen, ref };
}

/**
 * Popover that opens on hover (with a short close delay so the pointer can
 * travel into the panel) and still supports click-toggle, outside click,
 * and Escape. Used by the header menus (AliExpress/Temu interaction).
 */
export function useHoverPopover<T extends HTMLElement>(closeDelayMs = 150) {
    const { open, setOpen, ref } = usePopover<T>();
    const closeTimer = useRef<ReturnType<typeof setTimeout>>();

    const cancelClose = useCallback(() => clearTimeout(closeTimer.current), []);

    const openNow = useCallback(() => {
        cancelClose();
        setOpen(true);
    }, [cancelClose, setOpen]);

    const scheduleClose = useCallback(() => {
        cancelClose();
        closeTimer.current = setTimeout(() => setOpen(false), closeDelayMs);
    }, [cancelClose, closeDelayMs, setOpen]);

    useEffect(() => cancelClose, [cancelClose]);

    const hoverProps = {
        onMouseEnter: openNow,
        onMouseLeave: scheduleClose,
    };

    return { open, setOpen, ref, openNow, scheduleClose, hoverProps };
}
