import { useToast } from '@/Components/ui/Toast';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

interface AddOptions {
    quantity?: number;
    /** Shown in the toast instead of the generic wording. */
    productName?: string;
    onDone?: () => void;
}

/**
 * The one way anything on the storefront puts a product in the cart, so a
 * card, the quick-look modal and the product page all behave identically:
 * post, confirm with a top-centre toast, leave the shopper exactly where
 * they were.
 *
 * No sign-in gate here on purpose — guests get a session cart on the server
 * (App\Modules\Cart\Services\GuestCart) which is merged into their real cart
 * when they log in. Signing in is only forced at checkout.
 */
export function useAddToCart() {
    const toast = useToast();
    const [adding, setAdding] = useState(false);

    const addToCart = useCallback(
        (productUuid: string, { quantity = 1, productName, onDone }: AddOptions = {}) => {
            setAdding(true);

            router.post(
                route('cart.items.store'),
                { product_uuid: productUuid, quantity },
                {
                    preserveScroll: true,
                    preserveState: true,
                    // The badge lives in shared props; nothing else on the
                    // page needs to change, so only re-resolve those.
                    onSuccess: () => {
                        toast(productName ? `Saved to cart · ${productName}` : 'Saved to cart');
                        onDone?.();
                    },
                    onError: (errors) => {
                        toast(
                            errors.quantity ?? errors.product ?? 'Could not add this item to your cart.',
                            'error',
                        );
                    },
                    onFinish: () => setAdding(false),
                },
            );
        },
        [toast],
    );

    return { addToCart, adding };
}
