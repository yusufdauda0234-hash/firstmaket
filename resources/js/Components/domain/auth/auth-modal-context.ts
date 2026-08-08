import { createContext, useContext } from 'react';

/**
 * Lets any component under the Inertia root open the sign-in/register modal
 * (hero CTAs, cart checkout button, account dropdown) without prop drilling.
 *
 * Pass `intended` when the click was really a request to go somewhere the
 * guest is not allowed yet — Checkout being the obvious one. Login then
 * lands them there instead of dumping them back where they started, which
 * is what every marketplace does and what shoppers expect.
 */
export type OpenAuthModal = (intended?: string) => void;

export const AuthModalContext = createContext<OpenAuthModal>(() => {});

export function useAuthModal(): OpenAuthModal {
    return useContext(AuthModalContext);
}
