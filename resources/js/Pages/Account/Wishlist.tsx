import { ProductCard } from '@/Components/domain/catalog/ProductCard';
import AccountLayout from '@/Layouts/AccountLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { ProductSummary } from '@/Types';
import { productLinkProps } from '@/Utils/links';
import { formatNairaFromKobo } from '@/Utils/money';

interface Recommendation {
    uuid: string;
    name: string;
    slug: string;
    priceKobo: number;
    imageUrl: string | null;
    /** Why this was suggested — always shown, never implied. */
    reason: string;
}

interface Props {
    products: ProductSummary[];
    recommendations: Recommendation[];
    [key: string]: unknown;
}

export default function Wishlist() {
    const { products, recommendations = [] } = usePage<Props>().props;

    return (
        <AccountLayout title="Saved items">
            <Head title="Saved Items" />
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-extrabold text-gray-900">Saved Items</h1>
                    <p className="mt-1 text-sm text-gray-500">Keep products here while you decide.</p>
                </div>
                <Heart className="h-6 w-6 text-rose-500" />
            </div>

            {products.length > 0 ? (
                <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {products.map((product) => (
                        <div key={product.uuid}>
                            <ProductCard product={product} wishlistMode="remove" />
                            <label className="mt-2 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs text-gray-500">
                                Price-drop alert
                                <select
                                    aria-label={`Price-drop alert for ${product.name}`}
                                    value={product.priceAlertPercent ?? 10}
                                    onChange={(event) =>
                                        router.put(
                                            route('wishlist.price-alert.update', product.uuid),
                                            { threshold_percent: Number(event.target.value) },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="border rounded-lg border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 shadow-sm"
                                >
                                    <option value="5">5% drop</option>
                                    <option value="10">10% drop</option>
                                    <option value="20">20% drop</option>
                                </select>
                            </label>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="mt-5 rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
                    <Heart className="mx-auto h-10 w-10 text-gray-300" />
                    <p className="mt-3 text-sm font-semibold text-gray-700">Your wishlist is empty</p>
                    <p className="mt-1 text-sm text-gray-500">Tap the heart on a product to save it here.</p>
                </div>
            )}

            {/* ── You might also like ──
                Every card states why it is here. An unexplained
                recommendation on a savings product reads as a nudge to spend;
                "because you saved a phone" is something the shopper can
                check and disagree with. */}
            {recommendations.length > 0 && (
                <section className="mt-8">
                    <h2 className="text-lg font-extrabold tracking-tight text-gray-900">
                        You might also like
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Based on what you have saved and saved towards.
                    </p>

                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {recommendations.map((item) => (
                            <a
                                key={item.uuid}
                                {...productLinkProps(item.slug)}
                                className="group flex flex-col overflow-hidden rounded-xl border border-gray-100 bg-white transition-shadow hover:shadow-lg"
                            >
                                <span className="flex aspect-square items-center justify-center overflow-hidden bg-gray-50">
                                    {item.imageUrl ? (
                                        <img
                                            loading="lazy"
                                            decoding="async"
                                            src={item.imageUrl}
                                            alt={item.name}
                                            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        />
                                    ) : (
                                        <Heart className="h-8 w-8 text-gray-200" />
                                    )}
                                </span>

                                <span className="flex flex-1 flex-col p-3">
                                    <span className="line-clamp-2 text-xs font-semibold leading-snug text-gray-900">
                                        {item.name}
                                    </span>
                                    <span className="mt-1 text-sm font-extrabold tabular-nums text-gray-900">
                                        {formatNairaFromKobo(item.priceKobo)}
                                    </span>
                                    <span className="mt-1.5 line-clamp-2 text-[11px] leading-snug text-gray-400">
                                        {item.reason}
                                    </span>
                                </span>
                            </a>
                        ))}
                    </div>
                </section>
            )}
        </AccountLayout>
    );
}