import Modal from '@/Components/ui/Modal';
import { useMoney } from '@/Hooks/useI18n';
import { ChevronRight, Lock, RotateCcw, Truck } from 'lucide-react';
import { ReactNode, useState } from 'react';

type PolicyKey = 'shipping' | 'returns' | 'security';

interface Props {
    freeShippingThresholdKobo: number;
}

interface Policy {
    key: PolicyKey;
    icon: typeof Truck;
    accent: string;
    /** Row label, always visible. */
    label: string;
    /** Muted text after the label on the row. */
    hint?: string;
    title: string;
    body: ReactNode;
}

/**
 * The "Free shipping / Return & refund policy / Security & Privacy" rows that
 * sit under the price on a marketplace buy box. Each opens a dialog with the
 * full terms, so the box stays short while the detail is one tap away.
 *
 * The wording is deliberately specific to how FirstMaket actually works: a
 * plan is not a loan, money inside one is never paid out as cash, and a
 * cancelled plan's money moves to another product.
 */
export default function BuyBoxPolicies({ freeShippingThresholdKobo }: Props) {
    const { money } = useMoney();
    const [openPolicy, setOpenPolicy] = useState<PolicyKey | null>(null);

    const policies: Policy[] = [
        {
            key: 'shipping',
            icon: Truck,
            accent: 'text-emerald-600',
            label: `Free shipping over ${money(freeShippingThresholdKobo)}`,
            title: 'Delivery',
            body: (
                <>
                    <p>
                        Orders of {money(freeShippingThresholdKobo)} or more ship free
                        anywhere in Nigeria. Below that, the delivery fee is calculated from your state
                        and shown before you pay — never added afterwards.
                    </p>
                    <p>
                        Lagos and Abuja typically arrive in 1–3 working days. Other states take 3–7
                        working days. The courier calls the phone number on your order before
                        delivery, so please give one you actually answer.
                    </p>
                    <p>
                        On a Pay Small Small plan, delivery starts after your final instalment clears
                        — the item is reserved at the locked price until then.
                    </p>
                </>
            ),
        },
        {
            key: 'returns',
            icon: RotateCcw,
            accent: 'text-brand-600',
            label: 'Return & refund policy',
            title: 'Returns and refunds',
            body: (
                <>
                    <p>
                        You have <strong>7 days from delivery</strong> to report a problem. Items must
                        come back unused and in their original packaging, with everything they shipped
                        with.
                    </p>
                    <p>
                        If the item arrived damaged, faulty, or is not what was described, we cover
                        the return delivery and refund you in full. If you simply changed your mind,
                        the return delivery is yours to pay and the item must be unopened.
                    </p>
                    <p>
                        Refunds go back to the card you paid with, within 5–10 working days of the
                        item reaching the vendor.
                    </p>
                    <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs">
                        Money already paid into a Pay Small Small plan is never refunded as cash. If
                        you cancel a plan, what you have paid moves across as credit toward another
                        product.
                    </p>
                    <p className="text-xs text-gray-400">
                        Perishables, underwear, pierced jewellery and made-to-order items can only be
                        returned if they arrive faulty.
                    </p>
                </>
            ),
        },
        {
            key: 'security',
            icon: Lock,
            accent: 'text-gray-500',
            label: 'Security & Privacy',
            hint: 'Safe payments · protected data',
            title: 'Security and privacy',
            body: (
                <>
                    <p>
                        Card payments are handled entirely by <strong>Paystack</strong>. Your card
                        number never reaches FirstMaket's servers and we cannot see or store it.
                    </p>
                    <p>
                        Your order is only created after Paystack confirms the payment to us directly
                        through a signed webhook — not from your browser — so a failed or abandoned
                        payment can never leave a charge or a phantom order behind.
                    </p>
                    <p>
                        We use your address and phone number to deliver your order and nothing else.
                        We do not sell your data.
                    </p>
                </>
            ),
        },
    ];

    const active = policies.find((p) => p.key === openPolicy);

    return (
        <>
            <div className="mt-4 divide-y divide-gray-100 border-t border-gray-100">
                {policies.map((policy) => (
                    <button
                        key={policy.key}
                        type="button"
                        onClick={() => setOpenPolicy(policy.key)}
                        className="group flex w-full items-center gap-2.5 py-2.5 text-left transition-colors hover:text-brand-700"
                    >
                        <policy.icon className={`h-4 w-4 shrink-0 ${policy.accent}`} />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-gray-800 group-hover:text-brand-700">
                                {policy.label}
                            </span>
                            {policy.hint && (
                                <span className="block truncate text-xs text-gray-400">{policy.hint}</span>
                            )}
                        </span>
                        <ChevronRight className="h-4 w-4 shrink-0 text-gray-300 transition-transform group-hover:translate-x-0.5 group-hover:text-brand-600" />
                    </button>
                ))}
            </div>

            <Modal
                open={active !== undefined}
                onClose={() => setOpenPolicy(null)}
                title={active?.title}
                size="lg"
            >
                <div className="space-y-3 text-sm leading-relaxed text-gray-600">{active?.body}</div>
            </Modal>
        </>
    );
}
