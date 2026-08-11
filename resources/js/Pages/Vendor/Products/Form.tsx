import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import SelectMenu from '@/Components/ui/SelectMenu';
import VendorLayout from '@/Layouts/VendorLayout';
import DynamicFields, { AttributeField, AttributeValues } from '@/Components/domain/catalog/DynamicFields';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { MoneyInput } from '@/Components/ui/MoneyInput';

interface ExistingProduct {
    uuid: string;
    categoryId: number;
    name: string;
    description: string;
    videoUrl: string | null;
    priceNaira: number;
    compareAtNaira: number | null;
    stockQuantity: number;
    status: string;
    rejectionReason: string | null;
    images: { id: number; url: string; isPrimary: boolean }[];
}

interface FeeSettings {
    postingMode: 'free' | 'paid';
    basicFeeNaira: number;
    premiumFeeNaira: number;
    featuredFeeNaira: number;
}

interface Props {
    categories: { id: number; name: string; children: { id: number; name: string }[] }[];
    product: ExistingProduct | null;
    feeSettings: FeeSettings;
    /** Admin-defined form fields, keyed by category id. */
    attributeFieldsByCategory: Record<string, AttributeField[]>;
    /** Wording for the fields every product has, keyed by system key. */
    builtInFields: Record<string, { label: string; helpText: string | null; isRequired: boolean }>;
    /** Answers already saved against this product, keyed by field. */
    attributeValues: AttributeValues;
    [key: string]: unknown;
}

export default function VendorProductForm() {
    const {
        categories,
        product,
        feeSettings,
        attributeFieldsByCategory,
        attributeValues,
        builtInFields = {},
    } = usePage<Props>().props;

    /**
     * What to call a built-in field.
     *
     * Staff can reword these in the admin field manager, and the form has to
     * honour that or the screen is describing something it does not control.
     * The fallback keeps the original wording when the row is missing.
     */
    const labelFor = (key: string, fallback: string) => builtInFields[key]?.label ?? fallback;

    /** The guidance staff wrote against this field, if they wrote any. */
    const Hint = ({ field }: { field: string }) => {
        const text = builtInFields[field]?.helpText;

        return text ? <p className="mt-1 text-xs text-gray-400">{text}</p> : null;
    };

    // Only the leaf is stored, so editing an existing listing has to find its
    // parent to reopen the pair on the right pages.
    const [parentId, setParentId] = useState<number | ''>(() => {
        const chosen = product?.categoryId;

        if (chosen == null) {
            return '';
        }

        return (
            categories.find(
                (c) => c.id === chosen || c.children.some((child) => child.id === chosen),
            )?.id ?? ''
        );
    });

    const subCategories = categories.find((c) => c.id === parentId)?.children ?? [];

    const form = useForm({
        category_id: product?.categoryId ?? ('' as number | ''),
        name: product?.name ?? '',
        description: product?.description ?? '',
        video_url: product?.videoUrl ?? '',
        price_naira: product?.priceNaira ?? ('' as number | ''),
        compare_at_naira: product?.compareAtNaira ?? ('' as number | ''),
        stock_quantity: product?.stockQuantity ?? 1,
        images: [] as File[],
        submit: false,
        tier: 'free',
        attributes: (attributeValues ?? {}) as AttributeValues,
    });

    // Which extra fields apply depends entirely on the chosen category, and
    // every category's list is already on the page, so switching the dropdown
    // swaps the fields with no round trip.
    const activeFields =
        form.data.category_id === ''
            ? []
            : (attributeFieldsByCategory[String(form.data.category_id)] ?? []);

    const tierOptions = [
        { value: 'free', label: 'Free', fee: 0, blurb: 'Standard listing.' },
        { value: 'basic', label: 'Basic', fee: feeSettings.basicFeeNaira, blurb: 'Standard placement.' },
        { value: 'premium', label: 'Premium', fee: feeSettings.premiumFeeNaira, blurb: 'Higher placement in category pages.' },
        { value: 'featured', label: 'Featured', fee: feeSettings.featuredFeeNaira, blurb: 'Eligible for the home page featured strip.' },
    ];

    const canSubmitForApproval = !product || product.status === 'draft' || product.status === 'rejected';

    function save(e: FormEvent, submitForApproval: boolean) {
        e.preventDefault();
        form.transform((data) => ({ ...data, submit: submitForApproval }));

        if (product) {
            form.post(route('vendor.products.update', { product: product.uuid }), { forceFormData: true });
        } else {
            form.post(route('vendor.products.store'), { forceFormData: true });
        }
    }

    const priceChangedOnApproved =
        product?.status === 'approved' && Number(form.data.price_naira) !== product.priceNaira;

    return (
        <VendorLayout>
            <Head title={product ? 'Edit product' : 'Add product'} />

            <h1 className="mb-6 text-2xl font-extrabold tracking-tight text-gray-900">
                {product ? 'Edit product' : 'Add product'}
            </h1>

            {product?.status === 'rejected' && product.rejectionReason && (
                <Card className="mb-6 border-red-200 bg-red-50">
                    <p className="text-sm font-medium text-red-700">
                        Rejected: {product.rejectionReason}
                    </p>
                    <p className="mt-1 text-sm text-red-600">
                        Fix the issues and submit again.
                    </p>
                </Card>
            )}

            <form onSubmit={(e) => save(e, false)}>
                <Card className="max-w-2xl space-y-5">
                    {/* Category then sub-category, rather than one flat list.
                        A flat list put "Electronics" and "Smartphones" side by
                        side as if they were alternatives, and a phone filed on
                        the parent never showed up when a shopper narrowed to
                        Smartphones. */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="category_id">{labelFor('category', 'Category')}</Label>
                            <SelectMenu
                                ariaLabel="Category"
                                value={parentId === '' ? '' : String(parentId)}
                                options={[
                                    { value: '', label: <span className="text-gray-400">Select a category</span> },
                                    ...categories.map((category) => ({
                                        value: String(category.id),
                                        label: category.name,
                                    })),
                                ]}
                                onChange={(v) => {
                                    const next = v === '' ? '' : Number(v);
                                    setParentId(next);

                                    // A parent with sub-categories is a heading,
                                    // not a shelf, so nothing is chosen until
                                    // the sub-category is. One without any is
                                    // the answer on its own.
                                    const chosen = categories.find((c) => c.id === next);

                                    form.setData(
                                        'category_id',
                                        chosen && chosen.children.length === 0 ? chosen.id : '',
                                    );
                                }}
                            />
                            <InputError message={form.errors.category_id} />
                        </div>

                        {subCategories.length > 0 && (
                            <div>
                                <Label htmlFor="sub_category_id">Sub-category</Label>
                                <SelectMenu
                                    ariaLabel="Sub-category"
                                    value={
                                        form.data.category_id === '' ? '' : String(form.data.category_id)
                                    }
                                    options={[
                                        {
                                            value: '',
                                            label: <span className="text-gray-400">Select one</span>,
                                        },
                                        ...subCategories.map((child) => ({
                                            value: String(child.id),
                                            label: child.name,
                                        })),
                                    ]}
                                    onChange={(v) =>
                                        form.setData('category_id', v === '' ? '' : Number(v))
                                    }
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    Shoppers filter by this, so pick the closest one.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-5 max-[639px]:grid-cols-1">
                        <div>
                            <Label htmlFor="name">{labelFor('name', 'Product name')}</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.name} />
                            <Hint field="name" />
                        </div>

                        <div>
                            <Label htmlFor="stock_quantity">{labelFor('stock_quantity', 'Stock quantity')}</Label>
                            <Input
                                id="stock_quantity"
                                type="number"
                                min="1"
                                value={form.data.stock_quantity}
                                onChange={(e) => form.setData('stock_quantity', Number(e.target.value))}
                                required
                            />
                            <InputError message={form.errors.stock_quantity} />
                            <Hint field="stock_quantity" />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="description">{labelFor('description', 'Description')}</Label>
                        <textarea
                            id="description"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            rows={5}
                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600 shadow-sm"
                            required
                        />
                        <InputError message={form.errors.description} />
                        <Hint field="description" />
                    </div>

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="price_naira">{labelFor('price_naira', 'Price (₦)')}</Label>
                            <MoneyInput
                                id="price_naira"
                                min={100}
                                allowDecimals
                                value={form.data.price_naira}
                                onChange={(value) => form.setData('price_naira', value)}
                                required
                            />
                            <InputError message={form.errors.price_naira} />
                        <Hint field="price_naira" />
                            {priceChangedOnApproved && (
                                <p className="mt-1 text-xs text-amber-600">
                                    Changing the price sends this approved product back to review.
                                </p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="compare_at_naira">
                                {labelFor('compare_at_naira', 'Regular price (₦)')}{' '}
                                <span className="font-normal text-gray-400">(optional)</span>
                            </Label>
                            <MoneyInput
                                id="compare_at_naira"
                                min={100}
                                allowDecimals
                                value={form.data.compare_at_naira}
                                onChange={(value) => form.setData('compare_at_naira', value)}
                            />
                            <InputError message={form.errors.compare_at_naira} />
                            <Hint field="compare_at_naira" />
                        </div>
                    </div>

                    {/* Whatever staff decided this category needs described.
                        Empty for a category with no fields defined yet, so the
                        form simply looks the way it always did. */}
                    {activeFields.length > 0 && (
                        <div className="border-t border-gray-100 pt-5">
                            <h2 className="text-sm font-bold text-gray-900">
                                {categories.find((c) => c.id === form.data.category_id)?.name} details
                            </h2>
                            <p className="mb-4 mt-0.5 text-xs text-gray-400">
                                Buyers filter and compare on these, so fill in what you can.
                            </p>
                            <DynamicFields
                                fields={activeFields}
                                values={form.data.attributes}
                                errors={form.errors as Record<string, string>}
                                onChange={(key, value) =>
                                    form.setData('attributes', { ...form.data.attributes, [key]: value })
                                }
                            />
                        </div>
                    )}

                    <div>
                        <Label htmlFor="images">{labelFor('images', 'Images (up to 5, first is the cover)')}</Label>
                        <input
                            id="images"
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []))}
                            className="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                        />
                        <InputError message={form.errors.images} />
                        {product && product.images.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-2">
                                {product.images.map((image) => (
                                    <img loading="lazy" decoding="async"
                                        key={image.id}
                                        src={image.url}
                                        alt=""
                                        className={`h-16 w-16 rounded object-cover ${image.isPrimary ? 'ring-2 ring-brand-600' : ''}`}
                                    />
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Sits with the images because it is the same job: showing
                        the shopper the thing. */}
                    <div>
                        <Label htmlFor="video_url">
                            {labelFor('video_url', 'Video link')}{' '}
                            <span className="font-normal text-gray-400">(optional)</span>
                        </Label>
                        <input
                            id="video_url"
                            type="url"
                            inputMode="url"
                            value={form.data.video_url}
                            onChange={(e) => form.setData('video_url', e.target.value)}
                            placeholder="https://www.youtube.com/watch?v=..."
                            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                        />
                        <InputError message={form.errors.video_url} />
                        <Hint field="video_url" />
                    </div>

                    {/* Posting tier — only relevant when submitting under paid mode */}
                    {feeSettings.postingMode === 'paid' && canSubmitForApproval && (
                        <div>
                            <Label htmlFor="tier">Posting tier</Label>
                            <p className="mb-2 text-xs text-gray-400">
                                Applies when you submit for approval. Fees are recorded now and payable
                                from your savings once paid listings launch.
                            </p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {tierOptions.map((option) => (
                                    <label
                                        key={option.value}
                                        className={`flex cursor-pointer items-start gap-2.5 rounded-xl border-2 p-3 transition ${
                                            form.data.tier === option.value
                                                ? 'border-brand-600 bg-brand-50'
                                                : 'border-gray-200 hover:border-gray-300'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="tier"
                                            value={option.value}
                                            checked={form.data.tier === option.value}
                                            onChange={() => form.setData('tier', option.value)}
                                            className="mt-0.5 h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500/20"
                                        />
                                        <span className="min-w-0">
                                            <span className="flex items-baseline gap-2 text-sm font-semibold text-gray-900">
                                                {option.label}
                                                <span className="text-xs font-bold text-brand-700">
                                                    {option.fee === 0
                                                        ? '₦0'
                                                        : `₦${option.fee.toLocaleString()}`}
                                                </span>
                                            </span>
                                            <span className="block text-xs text-gray-500">
                                                {option.blurb}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.tier} />
                        </div>
                    )}

                    <div className="flex flex-wrap gap-3 pt-2">
                        <Button type="submit" variant="secondary" disabled={form.processing}>
                            {product ? 'Save changes' : 'Save as draft'}
                        </Button>
                        {canSubmitForApproval && (
                            <Button type="button" onClick={(e) => save(e, true)} disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Submit for approval'}
                            </Button>
                        )}
                    </div>
                </Card>
            </form>
        </VendorLayout>
    );
}
