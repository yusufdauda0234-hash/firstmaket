import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import SelectMenu from '@/Components/ui/SelectMenu';
import VendorLayout from '@/Layouts/VendorLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ExistingProduct {
    uuid: string;
    categoryId: number;
    name: string;
    description: string;
    priceNaira: number;
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
    categories: { id: number; name: string }[];
    product: ExistingProduct | null;
    feeSettings: FeeSettings;
    [key: string]: unknown;
}

export default function VendorProductForm() {
    const { categories, product, feeSettings } = usePage<Props>().props;

    const form = useForm({
        category_id: product?.categoryId ?? ('' as number | ''),
        name: product?.name ?? '',
        description: product?.description ?? '',
        price_naira: product?.priceNaira ?? ('' as number | ''),
        stock_quantity: product?.stockQuantity ?? 1,
        images: [] as File[],
        submit: false,
        tier: 'free',
    });

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
                    <div>
                        <Label htmlFor="category_id">Category</Label>
                        <SelectMenu
                            ariaLabel="Category"
                            value={form.data.category_id === '' ? '' : String(form.data.category_id)}
                            options={[
                                { value: '', label: <span className="text-gray-400">Select a category</span> },
                                ...categories.map((category) => ({ value: String(category.id), label: category.name })),
                            ]}
                            onChange={(v) => form.setData('category_id', v === '' ? '' : Number(v))}
                        />
                        <InputError message={form.errors.category_id} />
                    </div>

                    <div>
                        <Label htmlFor="name">Product name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div>
                        <Label htmlFor="description">Description</Label>
                        <textarea
                            id="description"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            rows={5}
                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                            required
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="price_naira">Price (₦)</Label>
                            <Input
                                id="price_naira"
                                type="number"
                                min="100"
                                step="0.01"
                                value={form.data.price_naira}
                                onChange={(e) =>
                                    form.setData('price_naira', e.target.value === '' ? '' : Number(e.target.value))
                                }
                                required
                            />
                            <InputError message={form.errors.price_naira} />
                            {priceChangedOnApproved && (
                                <p className="mt-1 text-xs text-amber-600">
                                    Changing the price sends this approved product back to review.
                                </p>
                            )}
                        </div>
                        <div>
                            <Label htmlFor="stock_quantity">Stock quantity</Label>
                            <Input
                                id="stock_quantity"
                                type="number"
                                min="1"
                                value={form.data.stock_quantity}
                                onChange={(e) => form.setData('stock_quantity', Number(e.target.value))}
                                required
                            />
                            <InputError message={form.errors.stock_quantity} />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="images">Images (up to 5, first is the cover)</Label>
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
                                    <img
                                        key={image.id}
                                        src={image.url}
                                        alt=""
                                        className={`h-16 w-16 rounded object-cover ${image.isPrimary ? 'ring-2 ring-brand-600' : ''}`}
                                    />
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Posting tier — only relevant when submitting under paid mode */}
                    {feeSettings.postingMode === 'paid' && canSubmitForApproval && (
                        <div>
                            <Label htmlFor="tier">Posting tier</Label>
                            <p className="mb-2 text-xs text-gray-400">
                                Applies when you submit for approval. Fees are recorded now and payable
                                from your wallet once wallet payments launch.
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
                                            className="mt-0.5 h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500"
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
