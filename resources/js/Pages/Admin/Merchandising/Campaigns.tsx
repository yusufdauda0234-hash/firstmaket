import TemplatePicker, { Template } from '@/Components/domain/admin/TemplatePicker';
import { Button } from '@/Components/ui/Button';
import { Card } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import PageHeader from '@/Components/ui/PageHeader';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface CampaignProduct { productId: number; salePriceKobo: number; stockCap: number | null }
interface Campaign { id: number; name: string; description: string | null; startsAt: string; endsAt: string; isActive: boolean; productCount: number; products: CampaignProduct[] }
interface Product { id: number; name: string; priceKobo: number }
interface Line { product_id: number; sale_price_kobo: number; stock_cap: number | null }
interface Props { campaigns: Campaign[]; products: Product[]; quickStartTemplates: Template[]; [key: string]: unknown }

const localDate = (value: string) => value.replace(' ', 'T').slice(0, 16);

export default function Campaigns() {
    const { campaigns = [], products = [], quickStartTemplates = [] } = usePage<Props>().props;
    const [editing, setEditing] = useState<Campaign | null>(null);
    const openCreate = () => setEditing({ id: 0, name: '', description: '', startsAt: '', endsAt: '', isActive: false, productCount: 0, products: [] });

    return <AdminLayout>
        <Head title="Merchandising" />
        <PageHeader
            title="Merchandising"
            description="Run time-boxed campaigns with real prices and bounded stock."
            actions={
                <div className="flex items-center gap-2">
                    <TemplatePicker
                        templates={quickStartTemplates}
                        action={route('admin.campaigns.quick-start')}
                        noun="campaign"
                        empty={campaigns.length === 0}
                    />
                    <Button onClick={openCreate}>Create campaign</Button>
                </div>
            }
        />
        {quickStartTemplates.length === 0 && campaigns.length === 0 && (
            <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                No approved products yet, so there is nothing to build a starter campaign from. Approve a
                product first, then a quick flash sale can be generated here in one click.
            </div>
        )}
        <div className="grid gap-4 lg:grid-cols-2">
            {campaigns.map((campaign) => <Card key={campaign.id}>
                <div className="flex items-start justify-between gap-3"><div><h2 className="font-bold text-gray-900">{campaign.name}</h2><p className="text-sm text-gray-500">{campaign.description || 'No description'}</p></div><span className={`rounded-full px-2 py-1 text-xs font-bold ${campaign.isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>{campaign.isActive ? 'Active' : 'Off'}</span></div>
                <p className="mt-4 text-xs text-gray-500">{campaign.startsAt} to {campaign.endsAt} · {campaign.productCount} products</p>
                <div className="mt-3 flex gap-2"><Button variant="secondary" onClick={() => setEditing(campaign)}>Edit</Button>{campaign.isActive && <Button variant="ghost" onClick={() => router.delete(route('admin.campaigns.destroy', campaign.id))}>Switch off</Button>}</div>
            </Card>)}
        </div>
        {editing && <CampaignForm campaign={editing.id ? editing : undefined} products={products} onClose={() => setEditing(null)} />}
    </AdminLayout>;
}

function CampaignForm({ campaign, products, onClose }: { campaign?: Campaign; products: Product[]; onClose: () => void }) {
    const form = useForm({
        name: campaign?.name ?? '', description: campaign?.description ?? '',
        starts_at: campaign ? localDate(campaign.startsAt) : '', ends_at: campaign ? localDate(campaign.endsAt) : '',
        is_active: campaign?.isActive ?? false,
        products: (campaign?.products ?? []).map((item) => ({ product_id: item.productId, sale_price_kobo: item.salePriceKobo, stock_cap: item.stockCap })) as Line[],
    });
    const [selected, setSelected] = useState<number | ''>('');
    const add = () => {
        if (selected === '') return;
        const product = products.find((item) => item.id === selected);
        if (!product || form.data.products.some((item) => item.product_id === product.id)) return;
        form.setData('products', [...form.data.products, { product_id: product.id, sale_price_kobo: Math.max(1, product.priceKobo - 100), stock_cap: null }]);
        setSelected('');
    };
    const remove = (index: number) => form.setData('products', form.data.products.filter((_, itemIndex) => itemIndex !== index));
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (campaign) form.put(route('admin.campaigns.update', campaign.id), options);
        else form.post(route('admin.campaigns.store'), options);
    };

    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4"><Card className="max-h-[90vh] w-full max-w-2xl overflow-y-auto"><form onSubmit={submit} className="space-y-4"><div className="flex items-center justify-between"><h2 className="text-lg font-bold">{campaign ? 'Edit campaign' : 'Create campaign'}</h2><Button type="button" variant="ghost" onClick={onClose}>Close</Button></div><Input placeholder="Campaign name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /><InputError message={form.errors.name} /><Input placeholder="Description" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /><div className="grid gap-3 sm:grid-cols-2"><Input type="datetime-local" value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} /><Input type="datetime-local" value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} /></div><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} /> Activate when saved</label><div className="flex gap-2"><select className="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm" value={selected} onChange={(event) => setSelected(event.target.value ? Number(event.target.value) : '')}><option value="">Add approved product</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select><Button type="button" variant="secondary" onClick={add}>Add</Button></div>{form.data.products.map((item, index) => { const product = products.find((entry) => entry.id === item.product_id); return <div key={item.product_id} className="grid grid-cols-[1fr_8rem_6rem_auto] items-center gap-2 text-sm"><span className="truncate">{product?.name}</span><Input type="number" value={item.sale_price_kobo} onChange={(event) => { const next = [...form.data.products]; next[index].sale_price_kobo = Number(event.target.value); form.setData('products', next); }} /><Input type="number" placeholder="Cap" value={item.stock_cap ?? ''} onChange={(event) => { const next = [...form.data.products]; next[index].stock_cap = event.target.value ? Number(event.target.value) : null; form.setData('products', next); }} /><Button type="button" variant="ghost" onClick={() => remove(index)}>Remove</Button></div>; })}<InputError message={form.errors.products} /><div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={onClose}>Cancel</Button><Button type="submit" disabled={form.processing}>{campaign ? 'Save changes' : 'Create campaign'}</Button></div></form></Card></div>;
}
