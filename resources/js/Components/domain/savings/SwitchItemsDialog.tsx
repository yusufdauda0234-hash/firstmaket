import Modal from '@/Components/ui/Modal';
import { Select } from '@/Components/ui/Select';
import QuantityStepper from '@/Components/ui/QuantityStepper';
import { formatNairaFromKobo } from '@/Utils/money';
import { useForm } from '@inertiajs/react';
import { Check, Search, ShoppingBag, Store, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export interface SwitchOption {
    uuid: string;
    name: string;
    image: string | null;
    priceKobo: number;
    stock: number;
    vendorName: string | null;
}

interface SwitchCategory {
    id: number;
    name: string;
}

interface PlanItem {
    productUuid: string;
    productName: string;
    productImage: string | null;
    quantity: number;
    lockedUnitPriceKobo: number;
}

interface PlanTermOption {
    id: number;
    name: string;
    cadenceLabel: string;
    installments: number;
    durationLabel: string;
    minTargetKobo: number;
}

/** One row of the basket the customer is assembling. */
interface Chosen {
    uuid: string;
    name: string;
    image: string | null;
    /** The price this line will be charged at. */
    unitPriceKobo: number;
    quantity: number;
    stock: number;
    vendorName: string | null;
    /** True for an item already on the plan, which keeps its locked price. */
    kept: boolean;
}

/**
 * Rebuild a plan's basket: keep what you want, drop what you do not, and add
 * from the catalogue.
 *
 * The old dialog let you pick exactly one replacement and silently threw away
 * everything else on the plan. A plan can hold several items, and a customer
 * usually wants to change one of them — so this starts from what they already
 * have, with every line removable, and adds a proper browser beside it.
 *
 * A kept line shows its locked price and keeps it. Only what is newly added is
 * priced at today's rate, which is the rule the server enforces too.
 */
export default function SwitchItemsDialog({
    goalUuid,
    items,
    paidKobo,
    terms,
    onClose,
}: {
    goalUuid: string;
    items: PlanItem[];
    paidKobo: number;
    terms: PlanTermOption[];
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [categoryId, setCategoryId] = useState<number>(0);
    const [options, setOptions] = useState<SwitchOption[]>([]);
    const [categories, setCategories] = useState<SwitchCategory[]>([]);
    const [loading, setLoading] = useState(false);

    // Seeded with the plan as it stands, so doing nothing changes nothing and
    // removing one line is the smallest possible edit.
    const [chosen, setChosen] = useState<Chosen[]>(() =>
        items.map((item) => ({
            uuid: item.productUuid,
            name: item.productName,
            image: item.productImage,
            unitPriceKobo: item.lockedUnitPriceKobo,
            quantity: item.quantity,
            stock: Number.MAX_SAFE_INTEGER,
            vendorName: null,
            kept: true,
        })),
    );

    const form = useForm<{
        items: { product_uuid: string; quantity: number }[];
        plan_term_id: number | '';
    }>({ items: [], plan_term_id: '' });

    // Debounced so typing does not fire a request per keystroke.
    useEffect(() => {
        setLoading(true);
        const timer = setTimeout(() => {
            fetch(route('savings.switch-options', { query, category: categoryId }), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.json())
                .then((data) => {
                    setOptions(data.products ?? []);
                    if (data.categories) setCategories(data.categories);
                })
                .finally(() => setLoading(false));
        }, 300);

        return () => clearTimeout(timer);
    }, [query, categoryId]);

    const newTargetKobo = useMemo(
        () => chosen.reduce((sum, line) => sum + line.unitPriceKobo * line.quantity, 0),
        [chosen],
    );

    function add(option: SwitchOption) {
        setChosen((current) => {
            const existing = current.find((line) => line.uuid === option.uuid);

            if (existing) {
                return current.map((line) =>
                    line.uuid === option.uuid
                        ? { ...line, quantity: Math.min(line.quantity + 1, line.stock) }
                        : line,
                );
            }

            return [
                ...current,
                {
                    uuid: option.uuid,
                    name: option.name,
                    image: option.image,
                    unitPriceKobo: option.priceKobo,
                    quantity: 1,
                    stock: option.stock,
                    vendorName: option.vendorName,
                    kept: false,
                },
            ];
        });
    }

    const remove = (uuid: string) => setChosen((current) => current.filter((line) => line.uuid !== uuid));

    const setQuantity = (uuid: string, quantity: number) =>
        setChosen((current) =>
            current.map((line) => (line.uuid === uuid ? { ...line, quantity } : line)),
        );

    // A term is only needed when the new basket falls below the current
    // plan's minimum; the server has the last word, but the form has to be
    // able to offer one.
    const eligibleTerms = terms.filter((term) => newTargetKobo >= term.minTargetKobo);
    const shortfall = Math.max(0, newTargetKobo - paidKobo);
    const excess = Math.max(0, paidKobo - newTargetKobo);
    const swappedOut = items.filter((item) => !chosen.some((line) => line.uuid === item.productUuid));

    const submit = () => {
        if (chosen.length === 0) return;

        form.transform(() => ({
            items: chosen.map((line) => ({ product_uuid: line.uuid, quantity: line.quantity })),
            ...(form.data.plan_term_id !== '' ? { plan_term_id: form.data.plan_term_id } : {}),
        }));

        form.post(route('savings.goals.switch', goalUuid), { preserveScroll: true });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title="Change what this plan is for"
            description="Keep what you want, remove what you do not, and add anything else from the marketplace."
            size="2xl"
            footer={
                <>
                    <span className="mr-auto text-xs text-gray-500">
                        {chosen.length} item{chosen.length === 1 ? '' : 's'} ·{' '}
                        <strong className="text-gray-900">{formatNairaFromKobo(newTargetKobo)}</strong>
                    </span>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-full px-5 py-2.5 text-xs font-bold text-gray-600 transition hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={chosen.length === 0 || form.processing}
                        className="rounded-full bg-brand-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-700 disabled:bg-gray-200 disabled:text-gray-400"
                    >
                        {form.processing ? 'Saving…' : 'Save these items'}
                    </button>
                </>
            }
        >
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                {/* ── Browse ── */}
                <div className="min-w-0">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input
                            type="search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search the marketplace…"
                            className="w-full rounded-xl border border-gray-200 py-2.5 pl-9 pr-3.5 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        />
                    </div>

                    {categories.length > 0 && (
                        <div className="mt-2.5 flex flex-wrap gap-1.5">
                            <button
                                type="button"
                                onClick={() => setCategoryId(0)}
                                className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                                    categoryId === 0
                                        ? 'bg-brand-600 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                }`}
                            >
                                All
                            </button>
                            {categories.map((category) => (
                                <button
                                    key={category.id}
                                    type="button"
                                    onClick={() => setCategoryId(category.id)}
                                    className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                                        categoryId === category.id
                                            ? 'bg-brand-600 text-white'
                                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    }`}
                                >
                                    {category.name}
                                </button>
                            ))}
                        </div>
                    )}

                    {/* Taller and wider than before: with the dialog at page
                        width there is room to see a screenful of catalogue
                        rather than scrolling three at a time. */}
                    <div className="mt-3 grid max-h-[28rem] grid-cols-2 gap-2.5 overflow-y-auto pr-1 sm:grid-cols-3 xl:grid-cols-4">
                        {loading && options.length === 0 && (
                            <p className="col-span-full py-8 text-center text-xs text-gray-400">Looking…</p>
                        )}
                        {!loading && options.length === 0 && (
                            <p className="col-span-full py-8 text-center text-xs text-gray-400">
                                Nothing matches that.
                            </p>
                        )}

                        {options.map((option) => {
                            const inBasket = chosen.some((line) => line.uuid === option.uuid);

                            return (
                                <button
                                    key={option.uuid}
                                    type="button"
                                    onClick={() => add(option)}
                                    className={`flex flex-col rounded-xl border-2 p-2 text-left transition ${
                                        inBasket
                                            ? 'border-brand-600 bg-brand-50/60'
                                            : 'border-gray-100 hover:border-brand-300'
                                    }`}
                                >
                                    <span className="flex aspect-square w-full items-center justify-center overflow-hidden rounded-lg bg-gray-50">
                                        {option.image ? (
                                            <img
                                                src={option.image}
                                                alt=""
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            <ShoppingBag className="h-6 w-6 text-gray-300" />
                                        )}
                                    </span>
                                    <span className="mt-1.5 line-clamp-2 min-h-[2rem] text-xs font-medium leading-snug text-gray-900">
                                        {option.name}
                                    </span>
                                    {option.vendorName && (
                                        <span className="mt-0.5 flex items-center gap-1 truncate text-[10px] text-gray-400">
                                            <Store className="h-2.5 w-2.5 shrink-0" />
                                            <span className="truncate">{option.vendorName}</span>
                                        </span>
                                    )}
                                    <span className="mt-1 flex items-center justify-between gap-1">
                                        <span className="text-xs font-bold text-brand-700">
                                            {formatNairaFromKobo(option.priceKobo)}
                                        </span>
                                        {inBasket && <Check className="h-3.5 w-3.5 text-brand-600" />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* ── The basket being assembled ── */}
                <div className="min-w-0 rounded-xl border border-gray-200 bg-slate-50/60 p-3">
                    <p className="text-xs font-bold text-gray-900">This plan will be for</p>

                    {chosen.length === 0 ? (
                        <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            You have removed everything. Add at least one item, or close this and cancel the
                            plan instead.
                        </p>
                    ) : (
                        <ul className="mt-2 max-h-72 space-y-2 overflow-y-auto pr-1">
                            {chosen.map((line) => (
                                <li key={line.uuid} className="rounded-lg border border-gray-100 bg-white p-2">
                                    <div className="flex items-start gap-2">
                                        <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-50">
                                            {line.image ? (
                                                <img
                                                    src={line.image}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <ShoppingBag className="h-4 w-4 text-gray-300" />
                                            )}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-xs font-semibold text-gray-900">
                                                {line.name}
                                            </span>
                                            <span className="text-[11px] text-gray-500">
                                                {formatNairaFromKobo(line.unitPriceKobo)} each
                                            </span>
                                            {line.kept && (
                                                <span className="ml-1 rounded bg-emerald-50 px-1 py-0.5 text-[10px] font-bold text-emerald-700">
                                                    price locked
                                                </span>
                                            )}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => remove(line.uuid)}
                                            aria-label={`Remove ${line.name}`}
                                            className="rounded p-1 text-gray-300 transition hover:bg-red-50 hover:text-red-600"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                    <div className="mt-1.5 flex justify-end">
                                        <QuantityStepper
                                            value={line.quantity}
                                            max={line.stock}
                                            onChange={(quantity) => setQuantity(line.uuid, quantity)}
                                            label={`Quantity of ${line.name}`}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {/* Only what is actually being swapped loses its lock, so
                        the warning names those items instead of implying the
                        whole plan is re-priced. */}
                    {swappedOut.length > 0 && (
                        <p className="mt-2.5 rounded-lg bg-amber-50 px-2.5 py-2 text-[11px] leading-relaxed text-amber-800">
                            Removing <strong>{swappedOut.map((item) => item.productName).join(', ')}</strong>{' '}
                            gives up the price locked on {swappedOut.length === 1 ? 'it' : 'them'}. Anything
                            you keep stays at its locked price.
                        </p>
                    )}

                    <dl className="mt-3 space-y-1 border-t border-gray-200 pt-2.5 text-xs">
                        <div className="flex justify-between">
                            <dt className="text-gray-500">New total</dt>
                            <dd className="font-bold tabular-nums text-gray-900">
                                {formatNairaFromKobo(newTargetKobo)}
                            </dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-gray-500">Already paid</dt>
                            <dd className="tabular-nums text-emerald-700">
                                {formatNairaFromKobo(paidKobo)}
                            </dd>
                        </div>
                        <div className="flex justify-between border-t border-gray-200 pt-1">
                            <dt className="font-semibold text-gray-700">
                                {excess > 0 ? 'Left over as credit' : 'Still to pay'}
                            </dt>
                            <dd className="font-bold tabular-nums text-gray-900">
                                {formatNairaFromKobo(excess > 0 ? excess : shortfall)}
                            </dd>
                        </div>
                    </dl>

                    {excess > 0 && (
                        <p className="mt-2 rounded-lg bg-emerald-50 px-2.5 py-2 text-[11px] text-emerald-800">
                            This costs less than you have paid. The plan completes straight away and the
                            difference becomes credit for your next one.
                        </p>
                    )}

                    {/* Offered whenever the current schedule no longer fits the
                        new basket — the server refuses the switch otherwise. */}
                    {eligibleTerms.length > 0 && (
                        <label className="mt-3 block">
                            <span className="mb-1 block text-[11px] font-bold text-gray-700">
                                Payment schedule
                            </span>
                            <Select
                                value={String(form.data.plan_term_id)}
                                onChange={(e) =>
                                    form.setData(
                                        'plan_term_id',
                                        e.target.value === '' ? '' : Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">Keep my current schedule</option>
                                {eligibleTerms.map((term) => (
                                    <option key={term.id} value={term.id}>
                                        {term.installments} × {term.cadenceLabel.toLowerCase()} ·{' '}
                                        {term.durationLabel}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    )}

                    {form.errors.items && (
                        <p className="mt-2 text-[11px] font-medium text-red-600">{form.errors.items}</p>
                    )}
                    {form.errors.plan_term_id && (
                        <p className="mt-2 text-[11px] font-medium text-red-600">
                            {form.errors.plan_term_id}
                        </p>
                    )}
                </div>
            </div>
        </Modal>
    );
}
