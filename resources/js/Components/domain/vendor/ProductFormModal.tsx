import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import SelectMenu from '@/Components/ui/SelectMenu';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, ImagePlus, Star, UploadCloud, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { MoneyInput } from '@/Components/ui/MoneyInput';
import DynamicFields, { AttributeField, AttributeValues } from '@/Components/domain/catalog/DynamicFields';

interface Category {
    id: number;
    name: string;
    children: { id: number; name: string }[];
}

interface FeeSettings {
    postingMode: 'free' | 'paid';
    basicFeeNaira: number;
    premiumFeeNaira: number;
    featuredFeeNaira: number;
}

interface ExistingImage {
    id: number;
    url: string;
    isPrimary: boolean;
}

interface EditProduct {
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
    images: ExistingImage[];
    /** Answers to the admin-defined fields for this listing's category. */
    attributes?: AttributeValues;
}

const MAX_IMAGES = 5;

/**
 * An empty listing.
 *
 * Kept as one named shape because the modal is never unmounted between opens,
 * so "start again" has to be an explicit act rather than something React does
 * for us. Spread on every use — sharing the object would let one open's edits
 * leak into the next.
 */
const blankProduct = {
    category_id: '' as number | '',
    name: '',
    description: '',
    video_url: '',
    price_naira: '' as number | '',
    compare_at_naira: '' as number | '',
    stock_quantity: 1,
    images: [] as File[],
    submit: false,
    tier: 'free',
    attributes: {} as AttributeValues,
};

/**
 * Add / edit a product in a modal with a guided drag-and-drop image uploader
 * that shows small previews. Create mode offers save-as-draft or
 * submit-for-approval; edit mode saves changes (and resubmits drafts).
 */
export default function ProductFormModal({
    open,
    mode,
    editUuid,
    categories,
    feeSettings,
    attributeFieldsByCategory = {},
    builtInFields = {},
    onClose,
}: {
    open: boolean;
    mode: 'create' | 'edit';
    editUuid?: string | null;
    categories: Category[];
    feeSettings: FeeSettings;
    /** Admin-defined fields, keyed by category id. */
    attributeFieldsByCategory?: Record<string, AttributeField[]>;
    /** Wording for the fields every product has, keyed by system key. */
    builtInFields?: Record<string, { label: string; helpText: string | null }>;
    onClose: () => void;
}) {
    const [loading, setLoading] = useState(false);
    const [product, setProduct] = useState<EditProduct | null>(null);
    const [previews, setPreviews] = useState<string[]>([]);
    const [dragOver, setDragOver] = useState(false);
    const [parentId, setParentId] = useState<number | ''>('');
    const fileRef = useRef<HTMLInputElement>(null);

    /**
     * What to call a built-in field.
     *
     * Staff reword these in the admin field manager. The labels used to be
     * hardcoded here, so that screen was describing a form it did not control.
     */
    const labelFor = (key: string, fallback: string) => builtInFields[key]?.label ?? fallback;
    const hintFor = (key: string) => builtInFields[key]?.helpText ?? null;

    const form = useForm({ ...blankProduct });

    // Load the product when editing; wipe the form when opening a create one.
    useEffect(() => {
        if (!open) return;
        setPreviews([]);
        setParentId('');

        /*
         * Deliberately not form.reset().
         *
         * Inertia's useForm overwrites its defaults with whatever was
         * submitted after every successful save, so reset() restores the
         * product just added rather than an empty form — clicking "Add
         * product" a second time opened the previous listing's details.
         * setDefaults puts the blank shape back, so later resets behave too.
         */
        form.setDefaults({ ...blankProduct });
        form.setData({ ...blankProduct });
        form.clearErrors();

        if (mode === 'edit' && editUuid) {
            setLoading(true);
            setProduct(null);
            fetch(route('vendor.products.details', editUuid), { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error('failed'))))
                .then((body: { product: EditProduct }) => {
                    setProduct(body.product);
                    form.setData((data) => ({
                        ...data,
                        category_id: body.product.categoryId,
                        name: body.product.name,
                        description: body.product.description,
                        video_url: body.product.videoUrl ?? '',
                        price_naira: body.product.priceNaira,
                        compare_at_naira: body.product.compareAtNaira ?? '',
                        stock_quantity: body.product.stockQuantity,
                        attributes: body.product.attributes ?? {},
                    }));

                    // Only the leaf is stored, so reopen the pair by finding
                    // whichever parent owns it.
                    setParentId(
                        categories.find(
                            (c) =>
                                c.id === body.product.categoryId ||
                                c.children.some((child) => child.id === body.product.categoryId),
                        )?.id ?? '',
                    );
                })
                .catch(() => {
                    /* ignore */
                })
                .finally(() => setLoading(false));
        } else {
            setProduct(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode, editUuid]);

    // Keep object-URL previews in sync with the selected files.
    useEffect(() => {
        const urls = form.data.images.map((file) => URL.createObjectURL(file));
        setPreviews(urls);
        return () => urls.forEach((u) => URL.revokeObjectURL(u));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.images]);

    const addFiles = (files: FileList | null) => {
        if (!files) return;
        const incoming = Array.from(files).filter((f) => f.type.startsWith('image/'));
        form.setData('images', [...form.data.images, ...incoming].slice(0, MAX_IMAGES));
    };

    const removeFile = (index: number) => {
        form.setData(
            'images',
            form.data.images.filter((_, i) => i !== index),
        );
    };

    const subCategories = categories.find((c) => c.id === parentId)?.children ?? [];

    // Which extra fields apply is decided entirely by the chosen category, and
    // every category's list is already on the page — so changing the dropdown
    // swaps them with no round trip.
    const activeFields =
        form.data.category_id === ''
            ? []
            : (attributeFieldsByCategory[String(form.data.category_id)] ?? []);

    const canSubmitForApproval =
        mode === 'create' || !product || product.status === 'draft' || product.status === 'rejected';

    const save = (submitForApproval: boolean) => {
        form.transform((data) => ({ ...data, submit: submitForApproval }));

        const opts = {
            forceFormData: true as const,
            onSuccess: () => onClose(),
            // Scroll the first problem into view. Without this a rejection
            // below the fold reads as the button doing nothing at all.
            onError: () => {
                requestAnimationFrame(() => {
                    document
                        .querySelector('[data-form-error]')
                        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            },
        };

        if (mode === 'edit' && product) {
            form.post(route('vendor.products.update', { product: product.uuid }), opts);
        } else {
            form.post(route('vendor.products.store'), opts);
        }
    };

    /**
     * Anything the server rejected that no field on this form renders.
     *
     * The failure that prompted this: submissions were rejected on keys the
     * form had no input for, so nothing appeared and the button looked dead.
     * Whatever the server objects to now, it gets said out loud.
     */
    const shownKeys = [
        'category_id',
        'name',
        'description',
        'video_url',
        'price_naira',
        'compare_at_naira',
        'stock_quantity',
        'images',
        'tier',
    ];

    const unshownErrors = Object.entries(form.errors)
        .filter(([key]) => !shownKeys.includes(key) && !key.startsWith('attributes.'))
        .map(([, message]) => message as string);

    const tierOptions = [
        { value: 'free', label: 'Free', fee: 0 },
        { value: 'basic', label: 'Basic', fee: feeSettings.basicFeeNaira },
        { value: 'premium', label: 'Premium', fee: feeSettings.premiumFeeNaira },
        { value: 'featured', label: 'Featured', fee: feeSettings.featuredFeeNaira },
    ];

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="xl"
            title={mode === 'edit' ? 'Edit product' : 'Add a product'}
            description={
                mode === 'edit'
                    ? 'Update your listing. Changing the price on a live product sends it back for review.'
                    : 'Fill in the details and add photos. Save a draft, or submit it for review.'
            }
        >
            {loading ? (
                <div className="space-y-3">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-12 animate-pulse rounded-xl bg-gray-100" />
                    ))}
                </div>
            ) : (
                <form onSubmit={(e) => { e.preventDefault(); save(false); }} className="space-y-3">
                    {unshownErrors.length > 0 && (
                        <div
                            data-form-error
                            role="alert"
                            className="flex gap-2.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-[13px] text-red-800"
                        >
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                <span className="font-semibold">This could not be saved.</span>
                                <ul className="mt-1 list-disc space-y-0.5 pl-4">
                                    {unshownErrors.map((message, i) => (
                                        <li key={i}>{message}</li>
                                    ))}
                                </ul>
                            </span>
                        </div>
                    )}

                    {product?.status === 'rejected' && product.rejectionReason && (
                        <div className="rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-[13px] text-red-700">
                            <span className="font-semibold">Rejected:</span> {product.rejectionReason}
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="pf-category" className="text-[13px]">
                                {labelFor('category', 'Category')}
                            </Label>
                            <SelectMenu
                                ariaLabel="Category"
                                value={parentId === '' ? '' : String(parentId)}
                                options={[
                                    { value: '', label: <span className="text-gray-400">Select a category</span> },
                                    ...categories.map((c) => ({ value: String(c.id), label: c.name })),
                                ]}
                                onChange={(v) => {
                                    const next = v === '' ? '' : Number(v);
                                    setParentId(next);

                                    // A parent with sub-categories is a heading,
                                    // not a shelf. One without any is the answer
                                    // on its own.
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
                                <Label htmlFor="pf-subcategory" className="text-[13px]">
                                    Sub-category
                                </Label>
                                <SelectMenu
                                    ariaLabel="Sub-category"
                                    value={form.data.category_id === '' ? '' : String(form.data.category_id)}
                                    options={[
                                        { value: '', label: <span className="text-gray-400">Select one</span> },
                                        ...subCategories.map((c) => ({
                                            value: String(c.id),
                                            label: c.name,
                                        })),
                                    ]}
                                    onChange={(v) =>
                                        form.setData('category_id', v === '' ? '' : Number(v))
                                    }
                                />
                               
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-3 max-[639px]:grid-cols-1">
                        <div>
                            <Label htmlFor="pf-name" className="text-[13px]">{labelFor('name', 'Product name')}</Label>
                            <input
                                id="pf-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div>
                            <Label htmlFor="pf-stock" className="text-[13px]">{labelFor('stock_quantity', 'Stock quantity')}</Label>
                            <input
                                id="pf-stock"
                                type="number"
                                min="1"
                                value={form.data.stock_quantity}
                                onChange={(e) => form.setData('stock_quantity', Number(e.target.value))}
                                className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                                required
                            />
                            <InputError message={form.errors.stock_quantity} />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="pf-desc" className="text-[13px]">{labelFor('description', 'Description')}</Label>
                        <textarea
                            id="pf-desc"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                            required
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="pf-price" className="text-[13px]">{labelFor('price_naira', 'Price (₦)')}</Label>
                            <MoneyInput
                                id="pf-price"
                                min={100}
                                allowDecimals
                                value={form.data.price_naira}
                                onChange={(value) => form.setData('price_naira', value)}
                                required
                            />
                            <InputError message={form.errors.price_naira} />
                            {mode === 'edit' && product?.status === 'approved' &&
                                Number(form.data.price_naira) !== product.priceNaira && (
                                    <p className="mt-1 text-xs text-amber-600">
                                        Changing the price sends this product back to review.
                                    </p>
                                )}
                        </div>
                        <div>
                            <Label htmlFor="pf-compare" className="text-[13px]">
                                {labelFor('compare_at_naira', 'Regular price (₦)')}{' '}
                                <span className="font-normal text-gray-400">(optional)</span>
                            </Label>
                            <MoneyInput
                                id="pf-compare"
                                min={100}
                                allowDecimals
                                value={form.data.compare_at_naira}
                                onChange={(value) => form.setData('compare_at_naira', value)}
                            />
                            <InputError message={form.errors.compare_at_naira} />
                            <p className="mt-1 text-[11px] text-gray-400">
                                Shown struck through beside your price.
                            </p>
                        </div>
                    </div>

                    {/* Whatever staff decided this category needs described.
                        These never rendered in this modal at all, so a field
                        added in the admin form builder appeared on the
                        standalone page and nowhere a vendor actually looked. */}
                    {activeFields.length > 0 && (
                        <div className="rounded-lg border border-gray-100 bg-gray-50/60 p-3">
                            <p className="mb-2 text-[13px] font-semibold text-gray-700">
                                {categories
                                    .flatMap((c) => [c, ...c.children])
                                    .find((c) => c.id === form.data.category_id)?.name}{' '}
                                details
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

                    {/* Guided image uploader */}
                    <div>
                        <Label className="text-[13px]">Photos <span className="font-normal text-gray-400">(up to {MAX_IMAGES}, first is the cover)</span></Label>
                        <div
                            role="button"
                            tabIndex={0}
                            onClick={() => fileRef.current?.click()}
                            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && fileRef.current?.click()}
                            onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                            onDragLeave={() => setDragOver(false)}
                            onDrop={(e) => { e.preventDefault(); setDragOver(false); addFiles(e.dataTransfer.files); }}
                            className={`flex cursor-pointer items-center justify-center gap-3 rounded-xl border-2 border-dashed px-4 py-3 text-center transition ${
                                dragOver ? 'border-brand-500 bg-brand-50' : 'border-gray-300 hover:border-brand-400 hover:bg-brand-50/40'
                            }`}
                        >
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                                <UploadCloud className="h-4 w-4" />
                            </span>
                            <span className="text-left">
                                <p className="text-[13px] font-semibold text-brand-600">Click to upload or drag images here</p>
                                <p className="text-[11px] text-gray-400">PNG, JPG or WebP · up to 4MB each</p>
                            </span>
                        </div>
                        <input
                            ref={fileRef}
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/webp"
                            className="sr-only"
                            onChange={(e) => addFiles(e.target.files)}
                        />
                        <InputError message={form.errors.images} />
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('images.'))
                            .map(([key, message]) => (
                                <InputError
                                    key={key}
                                    // "images.0" is the first photo, and saying
                                    // which one is the difference between a
                                    // fixable error and a mystery.
                                    message={`Photo ${Number(key.split('.')[1]) + 1}: ${message}`}
                                />
                            ))}

                        {/* New selections */}
                        {previews.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {previews.map((url, i) => (
                                    <span key={url} className="group relative">
                                        <img loading="lazy" decoding="async" src={url} alt="" className="h-14 w-14 rounded-lg object-cover ring-1 ring-black/5" />
                                        {i === 0 && (
                                            <span className="absolute left-1 top-1 flex items-center gap-0.5 rounded bg-brand-600 px-1 py-0.5 text-[8px] font-bold text-white">
                                                <Star className="h-2 w-2" /> Cover
                                            </span>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => removeFile(i)}
                                            aria-label="Remove image"
                                            className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-white opacity-0 shadow transition group-hover:opacity-100"
                                        >
                                            <X className="h-3 w-3" />
                                        </button>
                                    </span>
                                ))}
                                {form.data.images.length < MAX_IMAGES && (
                                    <button
                                        type="button"
                                        onClick={() => fileRef.current?.click()}
                                        className="flex h-14 w-14 items-center justify-center rounded-lg border-2 border-dashed border-gray-200 text-gray-400 transition hover:border-brand-300 hover:text-brand-500"
                                        aria-label="Add more images"
                                    >
                                        <ImagePlus className="h-5 w-5" />
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Existing images (edit) */}
                        {mode === 'edit' && product && product.images.length > 0 && (
                            <div className="mt-2">
                                <p className="mb-1 text-xs font-medium text-gray-400">Current photos</p>
                                <div className="flex flex-wrap gap-2">
                                    {product.images.map((img) => (
                                        <img loading="lazy" decoding="async"
                                            key={img.id}
                                            src={img.url}
                                            alt=""
                                            className={`h-14 w-14 rounded-lg object-cover ${img.isPrimary ? 'ring-2 ring-brand-600' : 'ring-1 ring-black/5'}`}
                                        />
                                    ))}
                                </div>
                                <p className="mt-1 text-[11px] text-gray-400">New uploads are added to these.</p>
                            </div>
                        )}
                    </div>

                    {/* Sits with the photos because it is the same job:
                        showing the shopper the thing. Only links the product
                        page can actually play are accepted, so a vendor is
                        told at the point of pasting rather than discovering a
                        dead field later. */}
                    <div>
                        <Label htmlFor="pf-video" className="text-[13px]">
                            {labelFor('video_url', 'Video link')}{' '}
                            <span className="font-normal text-gray-400">(optional)</span>
                        </Label>
                        <input
                            id="pf-video"
                            type="url"
                            inputMode="url"
                            value={form.data.video_url}
                            onChange={(e) => form.setData('video_url', e.target.value)}
                            placeholder="https://www.youtube.com/watch?v=..."
                            className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 shadow-sm"
                        />
                        <InputError message={form.errors.video_url} />
                        <p className="mt-1 text-[11px] text-gray-400">
                            {hintFor('video_url') ??
                                'YouTube or Vimeo. Shoppers watch it on the product page.'}
                        </p>
                    </div>

                    {/* Posting tier (paid mode only, when submittable) */}
                    {feeSettings.postingMode === 'paid' && canSubmitForApproval && (
                        <div>
                            <Label className="text-[13px]">Posting tier</Label>
                            <div className="grid gap-1.5 sm:grid-cols-2">
                                {tierOptions.map((option) => (
                                    <label
                                        key={option.value}
                                        className={`flex cursor-pointer items-center justify-between rounded-lg border-2 px-2.5 py-1.5 text-[13px] transition ${
                                            form.data.tier === option.value
                                                ? 'border-brand-600 bg-brand-50'
                                                : 'border-gray-200 hover:border-gray-300'
                                        }`}
                                    >
                                        <span className="flex items-center gap-2 font-semibold capitalize text-gray-900">
                                            <input
                                                type="radio"
                                                name="tier"
                                                value={option.value}
                                                checked={form.data.tier === option.value}
                                                onChange={() => form.setData('tier', option.value)}
                                                className="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500/20"
                                            />
                                            {option.label}
                                        </span>
                                        <span className="text-xs font-bold text-brand-700">
                                            {option.fee === 0 ? '₦0' : `₦${option.fee.toLocaleString()}`}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-3">
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" variant="secondary" disabled={form.processing}>
                            {mode === 'edit' ? 'Save changes' : 'Save as draft'}
                        </Button>
                        {canSubmitForApproval && (
                            <Button type="button" onClick={() => save(true)} disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Submit for approval'}
                            </Button>
                        )}
                    </div>
                </form>
            )}
        </Modal>
    );
}
