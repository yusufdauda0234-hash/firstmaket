import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import SelectMenu from '@/Components/ui/SelectMenu';
import { useForm } from '@inertiajs/react';
import { ImagePlus, Star, UploadCloud, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Category {
    id: number;
    name: string;
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
    priceNaira: number;
    stockQuantity: number;
    status: string;
    rejectionReason: string | null;
    images: ExistingImage[];
}

const MAX_IMAGES = 5;

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
    onClose,
}: {
    open: boolean;
    mode: 'create' | 'edit';
    editUuid?: string | null;
    categories: Category[];
    feeSettings: FeeSettings;
    onClose: () => void;
}) {
    const [loading, setLoading] = useState(false);
    const [product, setProduct] = useState<EditProduct | null>(null);
    const [previews, setPreviews] = useState<string[]>([]);
    const [dragOver, setDragOver] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const form = useForm({
        category_id: '' as number | '',
        name: '',
        description: '',
        price_naira: '' as number | '',
        stock_quantity: 1,
        images: [] as File[],
        submit: false,
        tier: 'free',
    });

    // Load the product when editing; reset when opening a create modal.
    useEffect(() => {
        if (!open) return;
        setPreviews([]);
        form.reset();
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
                        price_naira: body.product.priceNaira,
                        stock_quantity: body.product.stockQuantity,
                    }));
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

    const canSubmitForApproval =
        mode === 'create' || !product || product.status === 'draft' || product.status === 'rejected';

    const save = (submitForApproval: boolean) => {
        form.transform((data) => ({ ...data, submit: submitForApproval }));
        const opts = { forceFormData: true as const, onSuccess: () => onClose() };
        if (mode === 'edit' && product) {
            form.post(route('vendor.products.update', { product: product.uuid }), opts);
        } else {
            form.post(route('vendor.products.store'), opts);
        }
    };

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
                    {product?.status === 'rejected' && product.rejectionReason && (
                        <div className="rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-[13px] text-red-700">
                            <span className="font-semibold">Rejected:</span> {product.rejectionReason}
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="pf-category" className="text-[13px]">Category</Label>
                            <SelectMenu
                                ariaLabel="Category"
                                value={form.data.category_id === '' ? '' : String(form.data.category_id)}
                                options={[
                                    { value: '', label: <span className="text-gray-400">Select a category</span> },
                                    ...categories.map((c) => ({ value: String(c.id), label: c.name })),
                                ]}
                                onChange={(v) => form.setData('category_id', v === '' ? '' : Number(v))}
                            />
                            <InputError message={form.errors.category_id} />
                        </div>
                        <div>
                            <Label htmlFor="pf-name" className="text-[13px]">Product name</Label>
                            <input
                                id="pf-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="pf-desc" className="text-[13px]">Description</Label>
                        <textarea
                            id="pf-desc"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                            required
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="pf-price" className="text-[13px]">Price (₦)</Label>
                            <input
                                id="pf-price"
                                type="number"
                                min="100"
                                step="0.01"
                                value={form.data.price_naira}
                                onChange={(e) => form.setData('price_naira', e.target.value === '' ? '' : Number(e.target.value))}
                                className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
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
                            <Label htmlFor="pf-stock" className="text-[13px]">Stock quantity</Label>
                            <input
                                id="pf-stock"
                                type="number"
                                min="1"
                                value={form.data.stock_quantity}
                                onChange={(e) => form.setData('stock_quantity', Number(e.target.value))}
                                className="block w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                                required
                            />
                            <InputError message={form.errors.stock_quantity} />
                        </div>
                    </div>

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

                        {/* New selections */}
                        {previews.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {previews.map((url, i) => (
                                    <span key={url} className="group relative">
                                        <img src={url} alt="" className="h-14 w-14 rounded-lg object-cover ring-1 ring-black/5" />
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
                                        <img
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
                                                className="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500"
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
