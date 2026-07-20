import { createContext, useContext } from 'react';

/**
 * Lets any component under PublicLayout open the sign-in/register modal
 * (hero CTAs, cart icon, account dropdown) without prop drilling.
 */
export const AuthModalContext = createContext<() => void>(() => {});

export function useAuthModal(): () => void {
    return useContext(AuthModalContext);
}
