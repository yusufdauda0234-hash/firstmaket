import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { useForm } from '@inertiajs/react';
import { Check, Tag, X } from 'lucide-react';
import { FormEvent, useState } from 'react';

export interface AppliedPromo {
    code: string;
    /** Plain English: "10% off", "₦500 off", "Free delivery". */
    label: string;
    discountKobo: number;
    deliveryDiscountKobo: number;
}

interface Props {
    promo: AppliedPromo | null;
    /** Pay Small Small locks a price over months; a code cannot cut that. */
    disabled?: boolean;
}

/**
 * The promo code field on checkout.
 *
 * Collapsed to a link by default. An always-open box with an empty field is a
 * standing advertisement that a discount exists, and shoppers who do not have
 * a code leave the page to go looking for one — an abandonment every large
 * marketplace has learnt to design around.
 *
 * Applying posts to a throttled endpoint that answers with a real reason
 * ("that code has expired") rather than a flat "invalid", because a vague
 * message on a code somebody was legitimately handed generates a support
 * contact. The code itself is printed on flyers, so it is not a secret the
 * error message needs to protect.
 */
export default function PromoCodeBox({ promo, disabled = false }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ promo_code: '' });
    const remove = useForm({});

    const apply = (event: FormEvent) => {
        event.preventDefault();
        // Preserving scroll: the box sits in the sticky rail, and jumping to
        // the top of a long checkout to read a one-line error is its own bug.
        form.post(route('cart.promo.apply'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('promo_code');
                setOpen(false);
            },
        });
    };

    if (promo) {
        return (
            <div className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                <span className="flex min-w-0 items-center gap-2 text-sm">
                    <Check className="h-4 w-4 shrink-0 text-emerald-600" />
                    <span className="truncate font-bold text-emerald-800">{promo.code}</span>
                    <span className="shrink-0 text-xs text-emerald-700">{promo.label}</span>
                </span>
                <button
                    type="button"
                    onClick={() =>
                        remove.delete(route('cart.promo.remove'), { preserveScroll: true })
                    }
                    className="shrink-0 rounded-lg p-1 text-emerald-700 transition hover:bg-emerald-100"
                    aria-label={`Remove promo code ${promo.code}`}
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
        );
    }

    if (disabled) {
        return (
            <p className="mt-4 rounded-xl bg-gray-50 px-3 py-2.5 text-xs leading-relaxed text-gray-500">
                Promo codes apply to card payments. A plan locks today&rsquo;s price instead.
            </p>
        );
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 transition hover:text-brand-700"
            >
                <Tag className="h-4 w-4" /> Have a promo code?
            </button>
        );
    }

    return (
        // Not a <form>: this sits inside the checkout form, and a nested form
        // is invalid HTML that submits the outer one on Enter — placing the
        // order when the shopper meant to apply a code.
        <div className="mt-4">
            {/* The shared Input and Button, not a bare <input>.
                This was hand-rolled with `border-gray-200 focus:ring-brand-500`
                and no `border` width, no padding and no `ring-2` — classes that
                only do anything with @tailwindcss/forms, which this project does
                not install. It rendered as a borderless 20px-tall browser
                default with a black focus outline. */}
            <label htmlFor="promo-code" className="mb-1.5 block text-xs font-bold text-gray-700">
                Promo code
            </label>
            <div className="flex items-stretch gap-2">
                <Input
                    id="promo-code"
                    type="text"
                    autoFocus
                    value={form.data.promo_code}
                    onChange={(event) => form.setData('promo_code', event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') apply(event);
                    }}
                    placeholder="Enter code"
                    maxLength={32}
                    aria-invalid={Boolean(form.errors.promo_code)}
                    className={`uppercase tracking-wide placeholder:normal-case placeholder:tracking-normal ${
                        form.errors.promo_code ? 'border-red-400 focus:border-red-500 focus:ring-red-500/20' : ''
                    }`}
                />
                <Button
                    type="button"
                    onClick={apply}
                    disabled={form.processing || form.data.promo_code.trim() === ''}
                    className="shrink-0 px-5"
                >
                    {form.processing ? 'Applying…' : 'Apply'}
                </Button>
            </div>
            <InputError message={form.errors.promo_code} className="mt-1.5" />
        </div>
    );
}
