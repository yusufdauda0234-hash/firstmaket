import PromoCodeBox, { AppliedPromo } from '@/Components/domain/cart/PromoCodeBox';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import Modal from '@/Components/ui/Modal';
import { Radio } from '@/Components/ui/Radio';
import { Select } from '@/Components/ui/Select';
import PublicLayout from '@/Layouts/PublicLayout';
import { PageProps } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { useMoney } from '@/Hooks/useI18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CreditCard,
    Info,
    Lock,
    MapPin,
    PiggyBank,
    ShieldCheck,
    ShoppingBag,
    Store,
    Tag,
} from 'lucide-react';
import { FormEventHandler, ReactNode, useState } from 'react';

interface CheckoutItem {
    productUuid: string;
    productName: string;
    productSlug: string;
    productImage: string | null;
    categoryName: string;
    vendorName: string;
    priceKobo: number;
    quantity: number;
    lineTotalKobo: number;
}

interface Summary {
    itemsTotalKobo: number;
    discountKobo: number;
    subtotalKobo: number;
    shippingKobo: number;
    totalKobo: number;
    itemCount: number;
    freeShippingThresholdKobo: number;
}

interface PaymentMethod {
    value: string;
    label: string;
    description: string;
    available: boolean;
    unavailableReason: string | null;
}

interface PlanTerm {
    id: number;
    name: string;
    cadenceLabel: string;
    installments: number;
    installmentKobo: number;
    durationLabel: string;
    /** True when the first instalment is charged at checkout. */
    paysUpfront: boolean;
    firstPaymentDueDays: number;
    firstPaymentLabel: string;
}

interface Props extends PageProps {
    items: CheckoutItem[];
    summary: Summary;
    /** Re-quoted server-side each visit; null when nothing is applied. */
    promo: AppliedPromo | null;
    contact: { name: string; phone: string | null };
    paymentMethods: PaymentMethod[];
    countries: string[];
    states: string[];
    planTerms: PlanTerm[];
    /** Set when this is a Buy-now checkout for a single item, not the cart. */
    buyNow: { productUuid: string; quantity: number } | null;
    /** Carried over from a plan the customer cancelled. */
    planCreditKobo: number;
    /** What a plan locks: the goods, without delivery. */
    planTargetKobo: number;
    /** This customer's saved delivery address; null before the first one. */
    savedAddress: {
        recipient_name: string;
        recipient_phone: string;
        delivery_address: string;
        state: string;
        lga: string;
        landmark: string;
    } | null;
}

/**
 * Checkout in the two-column marketplace shape from the brief: shipping
 * address, then payment method, then the order itself on the left; a sticky
 * summary with the single Place order button on the right.
 *
 * The address is prefilled from the customer's saved default and can be
 * saved without placing an order, so a returning shopper confirms an address
 * instead of retyping one.
 *
 * Only reachable signed in (the route is behind auth) — the cart page sends
 * guests to the sign-in modal first and their session cart follows them.
 */
export default function CartCheckout() {
    const {
        items,
        summary,
        contact,
        paymentMethods,
        countries,
        states,
        planTerms,
        planCreditKobo,
        buyNow,
        savedAddress,
        planTargetKobo,
        // Defaulted: Inertia restores props from browser history, so a visit
        // restored from before this prop existed would arrive without it.
        promo = null,
    } = usePage<Props>().props;
    // Line items follow the shopper's chosen currency; anything that states
    // what will actually be charged stays in naira, because that is what
    // Paystack will take. Showing only a converted figure on the pay button
    // would be quoting a price we cannot honour.
    const { money, naira, isConverted, currency } = useMoney();
    const [editingAddress, setEditingAddress] = useState(false);
    const [choosingTerm, setChoosingTerm] = useState(false);

    const form = useForm({
        // The saved address wins where there is one, so a returning customer
        // confirms rather than retypes. Falling back to the account details
        // only for the recipient: an order is often sent to someone else, and
        // an account name is not always a person's name (email-only signups
        // default it to the address).
        recipient_name: savedAddress?.recipient_name || contact.name || '',
        recipient_phone: savedAddress?.recipient_phone || contact.phone || '',
        delivery_address: savedAddress?.delivery_address ?? '',
        state: savedAddress?.state ?? '',
        lga: savedAddress?.lga ?? '',
        landmark: savedAddress?.landmark ?? '',
        payment_method: 'card',
        plan_term_id: '' as number | '',
        // How many instalments to settle at checkout, on a term that charges
        // up front. Ignored by terms that give the customer days to pay.
        upfront_installments: 1,
        // Echoed straight back so the POST buys the same single item the page
        // rendered. Absent for an ordinary cart checkout.
        buy_now_product: buyNow?.productUuid ?? '',
        buy_now_quantity: buyNow?.quantity ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('cart.checkout.store'));
    };

    /**
     * Persist the address on its own, so it is still there next time even if
     * this checkout is abandoned. The page keeps its state — only the stored
     * default changes — so the form is untouched either way.
     */
    const saveAddress = () => {
        router.post(
            route('cart.checkout.address'),
            {
                recipient_name: form.data.recipient_name,
                recipient_phone: form.data.recipient_phone,
                delivery_address: form.data.delivery_address,
                state: form.data.state,
                lga: form.data.lga,
                landmark: form.data.landmark,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setEditingAddress(false),
            },
        );
    };

    const selectedMethod = paymentMethods.find((method) => method.value === form.data.payment_method);
    const payingSmallSmall = form.data.payment_method === 'pay_small_small';
    const chosenTerm = planTerms.find((term) => term.id === form.data.plan_term_id);
    // A plan cannot start without a term; paying by card never needs one.
    const termMissing = payingSmallSmall && chosenTerm === undefined;

    // On an up-front term the customer pays instalments now rather than the
    // whole basket, so the button quotes that, not the order total.
    const payingUpfront = payingSmallSmall && (chosenTerm?.paysUpfront ?? false);
    const upfrontCount = Math.min(
        Math.max(1, form.data.upfront_installments),
        chosenTerm?.installments ?? 1,
    );
    const dueNowKobo = chosenTerm
        ? Math.min(chosenTerm.installmentKobo * upfrontCount, planTargetKobo)
        : 0;
    // A promo code discounts a card payment only. A plan locks a price and is
    // paid off over months; letting a code cut that target would mean the
    // discount is still being honoured long after the campaign ended.
    const promoDiscountKobo = payingSmallSmall ? 0 : (promo?.discountKobo ?? 0);
    const deliveryDiscountKobo = payingSmallSmall ? 0 : (promo?.deliveryDiscountKobo ?? 0);
    // One saving line, whether the code cut the goods or the delivery.
    const promoSavingKobo = promoDiscountKobo + deliveryDiscountKobo;
    const payableKobo = Math.max(0, summary.totalKobo - promoSavingKobo);

    const chargedNowKobo = payingUpfront ? dueNowKobo : payingSmallSmall ? 0 : payableKobo;
    const addressComplete =
        form.data.recipient_name.trim() !== '' &&
        form.data.recipient_phone.trim() !== '' &&
        form.data.delivery_address.trim() !== '' &&
        form.data.state.trim() !== '' &&
        form.data.lga.trim() !== '';

    return (
        <PublicLayout>
            <Head title="Checkout" />

            <form onSubmit={submit} className="mx-auto max-w-7xl px-3 py-6 sm:px-4">
                <Link
                    href={route('cart.index')}
                    className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 transition hover:text-brand-700"
                >
                    <ArrowLeft className="h-4 w-4" /> Back to cart
                </Link>

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="space-y-4">
                        {/* ── Delivery address ── */}
                        <section className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5 sm:p-6">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h2 className="flex items-center gap-2 text-base font-extrabold text-gray-900">
                                        <StepNumber n={1} /> Delivery address
                                    </h2>
                                    <p className="mt-1 pl-8 text-xs text-gray-400">
                                        Where the courier should bring it, and who to call on arrival.
                                    </p>
                                </div>
                                {addressComplete && (
                                    <button
                                        type="button"
                                        onClick={() => setEditingAddress(true)}
                                        className="shrink-0 text-sm font-bold text-brand-600 transition hover:text-brand-700"
                                    >
                                        Change
                                    </button>
                                )}
                            </div>

                            {addressComplete ? (
                                <div className="mt-4 flex gap-3 rounded-xl border border-gray-100 bg-slate-50 p-4">
                                    <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                                    <div className="min-w-0 text-sm">
                                        <p className="font-bold text-gray-900">
                                            {form.data.recipient_name}
                                            <span className="ml-2.5 font-normal text-gray-500">
                                                {form.data.recipient_phone}
                                            </span>
                                        </p>
                                        <p className="mt-0.5 leading-relaxed text-gray-600">
                                            {form.data.delivery_address}
                                            <br />
                                            {form.data.lga}, {form.data.state}
                                        </p>
                                        {form.data.landmark && (
                                            <p className="mt-1 text-xs italic text-gray-400">
                                                Landmark: {form.data.landmark}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setEditingAddress(true)}
                                    className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 py-5 text-sm font-bold text-gray-600 transition hover:border-brand-400 hover:bg-brand-50/40 hover:text-brand-700"
                                >
                                    <MapPin className="h-4 w-4" /> Add a delivery address
                                </button>
                            )}

                            {/* The dialog lives inside the checkout <form> (Modal
                                renders inline, not through a portal), so Enter
                                in any field would otherwise submit the order
                                from here. Swallow it and let the footer button
                                be the only way out. */}
                            <Modal
                                open={editingAddress}
                                onClose={() => setEditingAddress(false)}
                                title="Delivery address"
                                description="Where the courier should bring it, and who to call on arrival."
                                size="xl"
                                footer={
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setEditingAddress(false)}
                                            className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="button"
                                            onClick={saveAddress}
                                            disabled={!addressComplete}
                                            className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 active:scale-95 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400"
                                        >
                                            Save address
                                        </button>
                                    </>
                                }
                            >
                                <div
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !(e.target instanceof HTMLTextAreaElement)) {
                                            e.preventDefault();
                                        }
                                    }}
                                    className="grid gap-4 sm:grid-cols-2"
                                >
                                    <Field
                                        label="Recipient full name"
                                        htmlFor="recipient_name"
                                        error={form.errors.recipient_name}
                                    >
                                        <Input
                                            id="recipient_name"
                                            type="text"
                                            autoComplete="name"
                                            placeholder="e.g. Yakubu Dauda"
                                            value={form.data.recipient_name}
                                            onChange={(e) => form.setData('recipient_name', e.target.value)}
                                        />
                                    </Field>

                                    <Field
                                        label="Phone number"
                                        htmlFor="recipient_phone"
                                        hint="The courier calls this on arrival"
                                        error={form.errors.recipient_phone}
                                    >
                                        <Input
                                            id="recipient_phone"
                                            type="tel"
                                            inputMode="tel"
                                            autoComplete="tel"
                                            placeholder="08031234567"
                                            value={form.data.recipient_phone}
                                            onChange={(e) => form.setData('recipient_phone', e.target.value)}
                                        />
                                    </Field>

                                    <div className="sm:col-span-2">
                                        <Field
                                            label="Street address"
                                            htmlFor="delivery_address"
                                            error={form.errors.delivery_address}
                                        >
                                            <Input
                                                id="delivery_address"
                                                type="text"
                                                autoComplete="street-address"
                                                placeholder="House number, street name, area"
                                                value={form.data.delivery_address}
                                                onChange={(e) => form.setData('delivery_address', e.target.value)}
                                            />
                                        </Field>
                                    </div>

                                    <Field label="Country" htmlFor="country" error={form.errors.country}>
                                        <Select
                                            id="country"
                                            disabled
                                            className="text-gray-900"
                                            defaultValue={countries[0] || ''}
                                        >
                                            {countries.map((country) => (
                                                <option key={country} value={country} className="text-gray-900">
                                                    {country}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>

                                    <Field label="State" htmlFor="state" error={form.errors.state}>
                                        <Select
                                            id="state"
                                            value={form.data.state}
                                            onChange={(e) => form.setData('state', e.target.value)}
                                            className={form.data.state === '' ? 'text-gray-400' : ''}
                                        >
                                            <option value="">Select a state</option>
                                            {states.map((state) => (
                                                <option key={state} value={state} className="text-gray-900">
                                                    {state}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>

                                    <Field label="City / LGA" htmlFor="lga" error={form.errors.lga}>
                                        <Input
                                            id="lga"
                                            type="text"
                                            placeholder="e.g. Eti-Osa"
                                            value={form.data.lga}
                                            onChange={(e) => form.setData('lga', e.target.value)}
                                        />
                                    </Field>

                                    <div className="sm:col-span-2">
                                        <Field
                                            label="Nearest landmark"
                                            htmlFor="landmark"
                                            optional
                                            hint="Helps the courier find you faster"
                                            error={form.errors.landmark}
                                        >
                                            <Input
                                                id="landmark"
                                                type="text"
                                                placeholder="e.g. Opposite Zenith Bank, beside the filling station"
                                                value={form.data.landmark}
                                                onChange={(e) => form.setData('landmark', e.target.value)}
                                            />
                                        </Field>
                                    </div>

                                </div>
                            </Modal>
                        </section>

                        {/* ── Payment methods ── */}
                        <section className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
                            <h2 className="flex items-center gap-2 text-base font-extrabold text-gray-900">
                                <StepNumber n={2} /> Payment method
                            </h2>

                            <div className="mt-3 divide-y divide-gray-100">
                                {paymentMethods.map((method) => (
                                    <PaymentOption
                                        key={method.value}
                                        method={method}
                                        checked={form.data.payment_method === method.value}
                                        onSelect={() => {
                                            form.setData('payment_method', method.value);

                                            // Choosing Pay Small Small is only
                                            // half a decision — the schedule is
                                            // the other half, so ask for it now.
                                            if (method.value === 'pay_small_small' && planTerms.length > 0) {
                                                setChoosingTerm(true);
                                            }
                                        }}
                                    />
                                ))}
                            </div>

                            <InputError message={form.errors.payment_method} className="mt-2" />

                            {/* Once Pay Small Small is chosen, the schedule is
                                picked in a dialog rather than unfolding four
                                more choices inline; what stays on the page is
                                the one line saying what was chosen. */}
                            {payingSmallSmall && (
                                <div className="mt-4 rounded-xl border border-brand-100 bg-brand-50/60 p-4">
                                    {chosenTerm ? (
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-sm font-extrabold text-brand-700">
                                                    {naira(chosenTerm.installmentKobo)}
                                                    <span className="text-xs font-medium text-gray-500">
                                                        {' '}
                                                        / {cadenceUnit(chosenTerm.cadenceLabel)}
                                                    </span>
                                                </p>
                                                <p className="mt-0.5 text-xs text-gray-600">
                                                    {chosenTerm.installments} payments ·{' '}
                                                    {chosenTerm.durationLabel} · {naira(planTargetKobo)}{' '}
                                                    locked at today's price
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setChoosingTerm(true)}
                                                className="shrink-0 text-sm font-bold text-brand-600 transition hover:text-brand-700"
                                            >
                                                Change
                                            </button>

                                            {/* Paying more than one instalment
                                                now is common — clearing two of
                                                three months in one go. */}
                                            {chosenTerm.paysUpfront ? (
                                                <div className="w-full border-t border-brand-100 pt-3">
                                                    <label
                                                        htmlFor="upfront_installments"
                                                        className="text-xs font-bold text-gray-900"
                                                    >
                                                        How much do you want to pay today?
                                                    </label>
                                                    <Select
                                                        id="upfront_installments"
                                                        className="mt-1.5"
                                                        value={String(upfrontCount)}
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'upfront_installments',
                                                                Number(e.target.value),
                                                            )
                                                        }
                                                    >
                                                        {Array.from(
                                                            { length: chosenTerm.installments },
                                                            (_, i) => i + 1,
                                                        ).map((count) => {
                                                            const amount = Math.min(
                                                                chosenTerm.installmentKobo * count,
                                                                summary.totalKobo,
                                                            );
                                                            const left = chosenTerm.installments - count;

                                                            return (
                                                                <option key={count} value={count}>
                                                                    {naira(amount)} — {count} payment
                                                                    {count === 1 ? '' : 's'} now
                                                                    {left > 0
                                                                        ? `, ${left} left`
                                                                        : ', paid in full'}
                                                                </option>
                                                            );
                                                        })}
                                                    </Select>
                                                </div>
                                            ) : (
                                                <p className="w-full border-t border-brand-100 pt-3 text-xs text-gray-600">
                                                    Nothing to pay today — your first payment is due
                                                    within {chosenTerm.firstPaymentDueDays} day
                                                    {chosenTerm.firstPaymentDueDays === 1 ? '' : 's'}.
                                                    Miss it and the plan is cancelled, with anything
                                                    paid kept as credit.
                                                </p>
                                            )}
                                        </div>
                                    ) : planTerms.length === 0 ? (
                                        <p className="text-xs text-gray-500">
                                            No plans are available for this order total right now.
                                        </p>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => setChoosingTerm(true)}
                                            className="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-brand-300 py-3.5 text-sm font-bold text-brand-700 transition hover:border-brand-500 hover:bg-white/60"
                                        >
                                            <PiggyBank className="h-4 w-4" /> Choose how you want to pay
                                        </button>
                                    )}

                                    <InputError message={form.errors.plan_term_id} className="mt-2" />

                                    {planCreditKobo > 0 && (
                                        <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700">
                                            {naira(planCreditKobo)} credit from a cancelled plan will
                                            be applied automatically.
                                        </p>
                                    )}
                                </div>
                            )}

                            {/* Inside the checkout <form> (Modal renders inline,
                                not through a portal), so a stray Enter would
                                submit the order from here. */}
                            <Modal
                                open={choosingTerm}
                                onClose={() => setChoosingTerm(false)}
                                title="Choose how you want to pay"
                                description={`${naira(planTargetKobo)} locked at today's price, delivery included. Delivered once the last instalment lands.`}
                                size="xl"
                                footer={
                                    <button
                                        type="button"
                                        onClick={() => setChoosingTerm(false)}
                                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                                    >
                                        Close
                                    </button>
                                }
                            >
                                <div
                                    className="grid gap-2.5 sm:grid-cols-2"
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') e.preventDefault();
                                    }}
                                >
                                    {planTerms.map((term) => {
                                        const active = form.data.plan_term_id === term.id;

                                        return (
                                            <button
                                                key={term.id}
                                                type="button"
                                                onClick={() => {
                                                    form.setData('plan_term_id', term.id);
                                                    // Picking one is the whole
                                                    // job — no second click.
                                                    setChoosingTerm(false);
                                                }}
                                                className={`rounded-xl border-2 p-4 text-left transition ${
                                                    active
                                                        ? 'border-brand-600 bg-brand-50/60 shadow-sm'
                                                        : 'border-gray-200 bg-white hover:border-brand-300 hover:bg-brand-50/30'
                                                }`}
                                            >
                                                <span className="block text-lg font-extrabold text-brand-700">
                                                    {naira(term.installmentKobo)}
                                                    <span className="text-xs font-medium text-gray-500">
                                                        {' '}
                                                        / {cadenceUnit(term.cadenceLabel)}
                                                    </span>
                                                </span>
                                                <span className="mt-1 block text-xs text-gray-600">
                                                    {term.installments} payments · {term.durationLabel}
                                                </span>
                                                <span
                                                    className={`mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${
                                                        term.paysUpfront
                                                            ? 'bg-amber-50 text-amber-700'
                                                            : 'bg-emerald-50 text-emerald-700'
                                                    }`}
                                                >
                                                    {term.paysUpfront
                                                        ? `${naira(term.installmentKobo)} due today`
                                                        : term.firstPaymentLabel}
                                                </span>
                                                {active && (
                                                    <span className="mt-2 inline-flex items-center gap-1 rounded-full bg-brand-600 px-2 py-0.5 text-[11px] font-bold text-white">
                                                        Selected
                                                    </span>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            </Modal>
                        </section>

                        {/* ── Order ──
                            One flat list with the same line anatomy as the
                            cart, not a box per vendor: who sells an item is a
                            property of the line, shown as a chip beside its
                            category, and the shopper has just read exactly
                            this layout on the previous page. */}
                        <section className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                            <h2 className="flex items-center gap-2 border-b border-gray-100 px-4 py-4 text-base font-extrabold text-gray-900 sm:px-5">
                                <StepNumber n={3} /> Your order ({summary.itemCount})
                            </h2>
                            <ul className="divide-y divide-gray-100">
                                {items.map((item) => (
                                    /* Same wrapping rule as the cart line: the
                                       money column cannot shrink, so below `sm`
                                       it drops to its own row instead of
                                       pushing the page into a sideways scroll. */
                                    <li
                                        key={item.productUuid}
                                        className="flex flex-wrap gap-x-3 gap-y-2 px-3 py-4 sm:flex-nowrap sm:gap-x-4 sm:px-5"
                                    >
                                        <a
                                            {...productLinkProps(item.productSlug)}
                                            className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50 sm:h-24 sm:w-24"
                                        >
                                            {item.productImage ? (
                                                <img loading="lazy" decoding="async"
                                                    src={item.productImage}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <ShoppingBag className="h-7 w-7 text-gray-300" />
                                            )}
                                        </a>

                                        <div className="flex min-w-0 flex-1 flex-col">
                                            <a
                                                {...productLinkProps(item.productSlug)}
                                                className="line-clamp-2 text-sm font-medium leading-snug text-gray-900 hover:text-brand-600"
                                            >
                                                {item.productName}
                                            </a>

                                            <div className="mt-1.5 flex min-w-0 flex-wrap items-center gap-1.5">
                                                <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                                    <Tag className="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    <span className="truncate">{item.categoryName}</span>
                                                </span>
                                                <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700 ring-1 ring-inset ring-brand-100">
                                                    <Store className="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    <span className="truncate">Verified vendor</span>
                                                </span>
                                                <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-600">
                                                    Qty {item.quantity}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex w-full flex-row items-baseline justify-end gap-2 text-right sm:w-auto sm:shrink-0 sm:flex-col sm:items-end sm:gap-0">
                                            <span className="text-base font-bold tracking-tight text-gray-900">
                                                {money(item.lineTotalKobo)}
                                            </span>
                                            {item.quantity > 1 && (
                                                <span className="text-[11px] text-gray-400 sm:mt-0.5">
                                                    {money(item.priceKobo)} each
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>

                    {/* ── Summary rail ── */}
                    <div className="space-y-4 lg:sticky lg:top-24">
                        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
                            <h2 className="text-lg font-extrabold text-gray-900">Summary</h2>

                            <dl className="mt-4 space-y-2.5 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-gray-500">Subtotal ({summary.itemCount} items)</dt>
                                    <dd className="tabular-nums text-gray-700">
                                        {money(summary.subtotalKobo)}
                                    </dd>
                                </div>
                                {summary.discountKobo > 0 && (
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-gray-500">Items discount</dt>
                                        <dd className="font-semibold tabular-nums text-red-600">
                                            −{money(summary.discountKobo)}
                                        </dd>
                                    </div>
                                )}
                                <div className="flex justify-between gap-3">
                                    <dt className="text-gray-500">Delivery fee</dt>
                                    <dd className="tabular-nums text-gray-700">
                                        {summary.shippingKobo - deliveryDiscountKobo === 0 ? (
                                            <span className="font-semibold text-emerald-600">Free</span>
                                        ) : (
                                            money(summary.shippingKobo)
                                        )}
                                    </dd>
                                </div>
                                {promoSavingKobo > 0 && (
                                    <div className="flex justify-between gap-3">
                                        <dt className="font-semibold text-emerald-700">
                                            {promo?.code} ({promo?.label})
                                        </dt>
                                        <dd className="font-semibold tabular-nums text-emerald-700">
                                            −{money(promoSavingKobo)}
                                        </dd>
                                    </div>
                                )}
                            </dl>

                            <PromoCodeBox promo={promo} disabled={payingSmallSmall} />

                            {/* Wraps rather than clips — the amount is the one
                                thing on this card that must stay readable. */}
                            <div className="mt-4 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-t border-gray-100 pt-4">
                                <span className="text-base font-bold text-gray-900">Total</span>
                                <span className="text-xl font-extrabold tabular-nums tracking-tight text-gray-900 sm:text-2xl">
                                    {naira(payableKobo)}
                                </span>
                            </div>

                            {/* Browsing in another currency: name the amount we
                                will actually charge, so the figure on the card
                                statement is never a surprise. */}
                            {isConverted && (
                                <p className="mt-1.5 text-right text-xs text-gray-500">
                                    ≈ {money(payableKobo)} {currency.code} · charged in ₦ NGN
                                </p>
                            )}

                            <InputError
                                message={(form.errors as Record<string, string>).cart}
                                className="mt-2"
                            />

                            <button
                                type="submit"
                                disabled={form.processing || !addressComplete || termMissing}
                                className="mt-4 w-full rounded-full bg-brand-600 py-3.5 text-sm font-bold text-white transition hover:bg-brand-700 hover:shadow-lg hover:shadow-brand-600/25 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 disabled:shadow-none"
                            >
                                {form.processing
                                    ? payingSmallSmall && ! payingUpfront
                                        ? 'Starting your plan…'
                                        : 'Taking you to Paystack…'
                                    : payingSmallSmall && ! payingUpfront
                                      ? 'Start my plan'
                                      : `Pay ${naira(chargedNowKobo)}`}
                            </button>

                            {!addressComplete && (
                                <p className="mt-2 text-center text-xs text-gray-400">
                                    Enter a delivery address to continue.
                                </p>
                            )}

                            {payingSmallSmall && chosenTerm && (
                                <p className="mt-2 text-center text-xs text-gray-500">
                                    {chosenTerm.paysUpfront ? (
                                        <>
                                            {naira(chargedNowKobo)} now,{' '}
                                            {naira(planTargetKobo - chargedNowKobo)} across the
                                            remaining {chosenTerm.installments - upfrontCount} payment
                                            {chosenTerm.installments - upfrontCount === 1 ? '' : 's'}.
                                        </>
                                    ) : (
                                        <>
                                            Nothing is charged now. Your first{' '}
                                            {naira(chosenTerm.installmentKobo)} is due within{' '}
                                            {chosenTerm.firstPaymentDueDays} day
                                            {chosenTerm.firstPaymentDueDays === 1 ? '' : 's'}.
                                        </>
                                    )}
                                </p>
                            )}
                            {termMissing && (
                                <p className="mt-2 text-center text-xs text-gray-400">
                                    Pick how often you want to pay.
                                </p>
                            )}

                            <p className="mt-3 text-center text-xs leading-relaxed text-gray-400">
                                By continuing you accept our{' '}
                                <Link href={route('faq')} className="underline underline-offset-2">
                                    terms and policies
                                </Link>
                                . Using {selectedMethod?.label ?? 'card'}.
                            </p>
                        </div>

                        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
                            <h3 className="flex items-center gap-1.5 text-sm font-bold text-gray-900">
                                <Lock className="h-4 w-4 text-brand-600" /> FirstMaket keeps you safe
                            </h3>
                            <ul className="mt-3 space-y-2 text-xs leading-relaxed text-gray-500">
                                <li className="flex gap-2">
                                    <ShieldCheck className="h-4 w-4 shrink-0 text-emerald-600" />
                                    Payment is held until you confirm delivery.
                                </li>
                                <li className="flex gap-2">
                                    <ShieldCheck className="h-4 w-4 shrink-0 text-emerald-600" />
                                    Full refund if the item is not as described.
                                </li>
                                <li className="flex gap-2">
                                    <ShieldCheck className="h-4 w-4 shrink-0 text-emerald-600" />
                                    Vendors never see your card or contact details.
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </PublicLayout>
    );
}

/**
 * "Weekly" → "week", for reading a rate as "₦38,000 / week".
 *
 * Spelled out rather than stripping "ly", which quietly turns Daily into
 * "dai".
 */
function cadenceUnit(cadenceLabel: string): string {
    const units: Record<string, string> = {
        daily: 'day',
        weekly: 'week',
        fortnightly: 'fortnight',
        monthly: 'month',
        quarterly: 'quarter',
    };

    const key = cadenceLabel.toLowerCase();

    return units[key] ?? key;
}

function PaymentOption({
    method,
    checked,
    onSelect,
}: {
    method: PaymentMethod;
    checked: boolean;
    onSelect: () => void;
}) {
    const Icon = METHOD_ICONS[method.value] ?? CreditCard;
    const disabled = !method.available;

    return (
        /*
         * `items-start`, and every muted state spelled out per element rather
         * than an `opacity-50` on the row.
         *
         * The reason a method is unavailable used to be a `shrink-0` pill at
         * the end of this row. It is a full sentence, so it claimed the whole
         * width and squeezed the label column — which could shrink, being
         * `min-w-0 flex-1` — down to one word per line, with the pill printed
         * over the top of it. It now sits inside the text column, under the
         * description, where it wraps like the prose it is.
         *
         * Dropping the row-wide opacity matters too: it was dimming the very
         * explanation the shopper needs to read.
         */
        <label
            className={`flex items-start gap-3 py-3.5 ${
                disabled ? 'cursor-not-allowed' : 'cursor-pointer'
            }`}
        >
            {/* A plain radio, not the text-field <Input>: that component's
                base style is a full-width box with padding and a shadow, and
                layering `h-4 w-4` over it left a padded, rounded control that
                matched no other radio in the app. */}
            <Radio
                name="payment_method"
                value={method.value}
                checked={checked}
                disabled={disabled}
                onChange={onSelect}
                className="mt-0.5"
            />
            <span
                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                    disabled ? 'bg-gray-100 text-gray-300' : 'bg-gray-50 text-gray-600'
                }`}
            >
                <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={`block text-sm font-semibold ${
                        disabled ? 'text-gray-400' : 'text-gray-900'
                    }`}
                >
                    {method.label}
                </span>
                <span className={`block text-xs ${disabled ? 'text-gray-400' : 'text-gray-500'}`}>
                    {method.description}
                </span>

                {disabled && method.unavailableReason && (
                    <span className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] font-semibold leading-relaxed text-amber-800 ring-1 ring-inset ring-amber-100">
                        <Info className="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
                        <span className="min-w-0">{method.unavailableReason}</span>
                    </span>
                )}
            </span>
        </label>
    );
}

const METHOD_ICONS: Record<string, typeof CreditCard> = {
    card: CreditCard,
    pay_small_small: PiggyBank,
    opay: CreditCard,
};

/** Numbered badge that walks the shopper down the page. */
function StepNumber({ n }: { n: number }) {
    return (
        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
            {n}
        </span>
    );
}

/**
 * Labelled form field. Placeholders alone are not labels — they vanish the
 * moment you type, which is exactly when you most want to know what a half
 * filled box was asking for.
 */
function Field({
    label,
    htmlFor,
    hint,
    optional,
    error,
    children,
}: {
    label: string;
    htmlFor: string;
    hint?: string;
    optional?: boolean;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div>
            <label htmlFor={htmlFor} className="mb-1.5 flex items-baseline gap-2 text-xs font-bold text-gray-700">
                {label}
                {optional && <span className="font-normal text-gray-400">Optional</span>}
            </label>
            {children}
            {error ? (
                <InputError message={error} className="mt-1" />
            ) : (
                hint && <p className="mt-1 text-xs text-gray-400">{hint}</p>
            )}
        </div>
    );
}
