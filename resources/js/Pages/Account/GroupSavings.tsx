import AccountLayout from '@/Layouts/AccountLayout';
import { formatNairaFromKobo } from '@/Utils/money';
import { Head, router, useForm } from '@inertiajs/react';
import { Copy, RotateCw, Users, Wallet } from 'lucide-react';
import { useState } from 'react';

interface PlanOption {
    uuid: string;
    targetKobo: number;
    savedKobo: number;
}

interface LedgerRow {
    userId: number;
    name: string;
    role: string;
    status: string;
    contributedKobo: number;
    sharePercent: number;
}

interface GroupPlanRow {
    uuid: string;
    name: string;
    description: string | null;
    status: string;
    organiser: string;
    isOrganiser: boolean;
    inviteCode: string | null;
    targetKobo: number;
    savedKobo: number;
    myShareKobo: number;
    myStatus: string | null;
    ledger: LedgerRow[];
}

interface FamilyRow {
    uuid: string;
    name: string;
    owner: string;
    isOwner: boolean;
    inviteCode: string | null;
    myStatus: string | null;
    iAmSharing: boolean;
    summary: {
        userId: number;
        name: string;
        sharing: boolean;
        activePlans: number;
        targetKobo: number;
        savedKobo: number;
        progressPercent: number;
    }[];
}

interface CooperativeRow {
    uuid: string;
    name: string;
    description: string | null;
    status: string;
    contributionKobo: number;
    cadence: string;
    organiser: string;
    isOrganiser: boolean;
    inviteCode: string | null;
    myStatus: string | null;
    rotation: { userId: number; name: string; position: number; status: string; hasReceived: boolean }[];
    openCycle: {
        id: number;
        number: number;
        beneficiary: string | null;
        isMyTurn: boolean;
        hasPlan: boolean;
        iHavePaid: boolean;
        paidCount: number;
        memberCount: number;
    } | null;
}

interface Props {
    groupPlans: GroupPlanRow[];
    eligiblePlans: PlanOption[];
    familyGroups: FamilyRow[];
    cooperatives: CooperativeRow[];
    myRunningPlans: PlanOption[];
}

type Tab = 'groups' | 'family' | 'cooperatives';

const TABS: { key: Tab; label: string; blurb: string }[] = [
    { key: 'groups', label: 'Group purchase', blurb: 'Several people funding one basket.' },
    { key: 'family', label: 'Family', blurb: 'See how the household is doing. No money moves.' },
    { key: 'cooperatives', label: 'Cooperative', blurb: 'Take turns. Ajo, without the cash.' },
];

function InviteCode({ code }: { code: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={async () => {
                await navigator.clipboard.writeText(code);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 1600);
            }}
            className="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 font-mono text-xs font-bold text-gray-700"
        >
            <Copy className="h-3 w-3" /> {copied ? 'Copied' : code}
        </button>
    );
}

export default function GroupSavings({
    groupPlans = [],
    eligiblePlans = [],
    familyGroups = [],
    cooperatives = [],
    myRunningPlans = [],
}: Props) {
    const [tab, setTab] = useState<Tab>('groups');

    const groupForm = useForm({ plan_uuid: eligiblePlans[0]?.uuid ?? '', name: '', description: '' });
    const familyForm = useForm({ name: '' });
    const coopForm = useForm({ name: '', description: '', contribution_naira: '', cadence: 'monthly' });
    const joinForm = useForm({ invite_code: '' });

    const joinRoute: Record<Tab, string> = {
        groups: 'savings.together.groups.join',
        family: 'savings.together.family.join',
        cooperatives: 'savings.together.cooperatives.join',
    };

    const invite = (routeName: string, uuid: string) => {
        const identifier = window.prompt('Email or phone number of the person to invite:');
        if (identifier && identifier.trim() !== '') {
            router.post(route(routeName, uuid), { identifier: identifier.trim() }, { preserveScroll: true });
        }
    };

    return (
        <AccountLayout title="Saving together">
            <Head title="Saving together" />

            <section className="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-brand-900 p-6 text-white shadow-lg sm:p-8">
                <p className="text-xs font-bold uppercase tracking-[0.18em] text-brand-200">Saving together</p>
                <h1 className="mt-2 text-3xl font-extrabold tracking-tight">Save with people you trust.</h1>
                <p className="mt-2 max-w-2xl text-sm text-slate-300">
                    Every naira still belongs to one plan and one person — there is no shared pot, and nothing here
                    can be withdrawn as cash. What changes is who is paying in alongside you.
                </p>
            </section>

            <div className="mt-6 flex flex-wrap gap-2">
                {TABS.map((entry) => (
                    <button
                        key={entry.key}
                        onClick={() => setTab(entry.key)}
                        className={`rounded-full px-4 py-2 text-sm font-bold transition ${
                            tab === entry.key ? 'bg-brand-600 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200'
                        }`}
                    >
                        {entry.label}
                    </button>
                ))}
            </div>
            <p className="mt-2 text-sm text-gray-500">{TABS.find((entry) => entry.key === tab)?.blurb}</p>

            {/* Join by code — same shape for all three. */}
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    joinForm.post(route(joinRoute[tab]), { preserveScroll: true, onSuccess: () => joinForm.reset() });
                }}
                className="mt-4 flex flex-col gap-2 rounded-2xl border border-gray-200 bg-white p-4 sm:flex-row"
            >
                <input
                    value={joinForm.data.invite_code}
                    onChange={(event) => joinForm.setData('invite_code', event.target.value.toUpperCase())}
                    placeholder="Have an invite code?"
                    className="min-h-11 flex-1 rounded-lg border border-gray-300 px-3 font-mono text-sm uppercase"
                />
                <button disabled={joinForm.processing} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                    Join
                </button>
            </form>
            {joinForm.errors.invite_code && <p className="mt-1 text-xs text-red-600">{joinForm.errors.invite_code}</p>}

            {/* ── Group purchase ── */}
            {tab === 'groups' && (
                <>
                    {groupPlans.map((group) => (
                        <section key={group.uuid} className="mt-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-bold text-gray-900">{group.name}</h2>
                                    <p className="text-sm text-gray-500">{group.description || `Organised by ${group.organiser}`}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-bold capitalize text-gray-600">{group.status}</span>
                                    {group.inviteCode && <InviteCode code={group.inviteCode} />}
                                </div>
                            </div>

                            <div className="mt-4 h-2 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    className="h-full rounded-full bg-brand-600"
                                    style={{ width: `${Math.min(100, Math.round((group.savedKobo / Math.max(1, group.targetKobo)) * 100))}%` }}
                                />
                            </div>
                            <p className="mt-2 text-sm text-gray-600">
                                {formatNairaFromKobo(group.savedKobo)} of {formatNairaFromKobo(group.targetKobo)} · your share{' '}
                                <strong className="text-gray-900">{formatNairaFromKobo(group.myShareKobo)}</strong>
                            </p>

                            <div className="mt-4 overflow-hidden rounded-xl border border-gray-100">
                                <table className="min-w-full divide-y divide-gray-100 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            {['Member', 'Put in', 'Share', 'Status'].map((heading) => (
                                                <th key={heading} className="px-3 py-2 text-left text-xs font-semibold text-gray-600">{heading}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-50">
                                        {group.ledger.map((row) => (
                                            <tr key={row.userId}>
                                                <td className="px-3 py-2">
                                                    {row.name}
                                                    {row.role === 'organiser' && <span className="ml-1.5 text-xs text-gray-400">organiser</span>}
                                                </td>
                                                <td className="px-3 py-2 font-mono">{formatNairaFromKobo(row.contributedKobo)}</td>
                                                <td className="px-3 py-2">{row.sharePercent}%</td>
                                                <td className="px-3 py-2 capitalize text-gray-500">{row.status}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                The goods are delivered to {group.organiser}, who owns the plan. Contributions cannot be
                                paid back out in cash — that is true everywhere in FirstMaket, including here.
                            </p>

                            <div className="mt-3 flex flex-wrap gap-2">
                                {group.isOrganiser && group.status === 'open' && (
                                    <>
                                        <button
                                            onClick={() => invite('savings.together.groups.invite', group.uuid)}
                                            className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white"
                                        >
                                            Invite someone
                                        </button>
                                        <button
                                            onClick={() => {
                                                if (!confirm('Cancel this group? Contributions already made stay recorded.')) return;
                                                router.post(route('savings.together.groups.cancel', group.uuid), {}, { preserveScroll: true });
                                            }}
                                            className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600"
                                        >
                                            Cancel group
                                        </button>
                                    </>
                                )}
                                {group.myStatus === 'invited' && (
                                    <button
                                        onClick={() => router.post(route('savings.together.groups.accept', group.uuid), {}, { preserveScroll: true })}
                                        className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white"
                                    >
                                        Accept invitation
                                    </button>
                                )}
                                {!group.isOrganiser && group.myStatus === 'active' && (
                                    <button
                                        onClick={() => {
                                            if (!confirm('Leave this group? What you already put in stays recorded but cannot be returned.')) return;
                                            router.post(route('savings.together.groups.exit', group.uuid), {}, { preserveScroll: true });
                                        }}
                                        className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600"
                                    >
                                        Leave group
                                    </button>
                                )}
                            </div>
                        </section>
                    ))}

                    {eligiblePlans.length > 0 && (
                        <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex items-center gap-3">
                                <Users className="h-5 w-5 text-brand-600" />
                                <h2 className="font-bold text-gray-900">Open a group on one of your plans</h2>
                            </div>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    groupForm.post(route('savings.together.groups.store'), { preserveScroll: true, onSuccess: () => groupForm.reset() });
                                }}
                                className="mt-4 grid gap-2 sm:grid-cols-2"
                            >
                                <select
                                    value={groupForm.data.plan_uuid}
                                    onChange={(event) => groupForm.setData('plan_uuid', event.target.value)}
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                >
                                    {eligiblePlans.map((plan) => (
                                        <option key={plan.uuid} value={plan.uuid}>
                                            {formatNairaFromKobo(plan.savedKobo)} / {formatNairaFromKobo(plan.targetKobo)}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    value={groupForm.data.name}
                                    onChange={(event) => groupForm.setData('name', event.target.value)}
                                    placeholder="Group name"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                                />
                                <input
                                    value={groupForm.data.description}
                                    onChange={(event) => groupForm.setData('description', event.target.value)}
                                    placeholder="What is it for? (optional)"
                                    className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm sm:col-span-2"
                                />
                                <button disabled={groupForm.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60 sm:col-span-2">
                                    Create group
                                </button>
                            </form>
                        </section>
                    )}
                </>
            )}

            {/* ── Family ── */}
            {tab === 'family' && (
                <>
                    {familyGroups.map((family) => (
                        <section key={family.uuid} className="mt-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-bold text-gray-900">{family.name}</h2>
                                    <p className="text-sm text-gray-500">Owned by {family.owner}</p>
                                </div>
                                {family.inviteCode && <InviteCode code={family.inviteCode} />}
                            </div>

                            <div className="mt-4 space-y-2">
                                {family.summary.map((row) => (
                                    <div key={row.userId} className="rounded-xl bg-gray-50 p-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-sm font-bold text-gray-900">{row.name}</p>
                                            {row.sharing ? (
                                                <p className="text-xs text-gray-500">
                                                    {row.activePlans} plan{row.activePlans === 1 ? '' : 's'} ·{' '}
                                                    {formatNairaFromKobo(row.savedKobo)} of {formatNairaFromKobo(row.targetKobo)}
                                                </p>
                                            ) : (
                                                <p className="text-xs italic text-gray-400">Not sharing figures</p>
                                            )}
                                        </div>
                                        {row.sharing && (
                                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                                <div className="h-full rounded-full bg-brand-600" style={{ width: `${row.progressPercent}%` }} />
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>

                            <p className="mt-3 text-xs text-gray-400">
                                This is a summary only. Nobody here can see what you are buying, pay into your plan, or
                                move a naira of yours.
                            </p>

                            <div className="mt-3 flex flex-wrap gap-2">
                                {family.isOwner && (
                                    <button
                                        onClick={() => invite('savings.together.family.invite', family.uuid)}
                                        className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white"
                                    >
                                        Invite someone
                                    </button>
                                )}
                                <button
                                    onClick={() =>
                                        router.post(
                                            route('savings.together.family.sharing', family.uuid),
                                            { shares_progress: !family.iAmSharing },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600"
                                >
                                    {family.iAmSharing ? 'Stop sharing my figures' : 'Share my figures'}
                                </button>
                                {!family.isOwner && (
                                    <button
                                        onClick={() => {
                                            if (!confirm('Leave this family group?')) return;
                                            router.post(route('savings.together.family.leave', family.uuid), {}, { preserveScroll: true });
                                        }}
                                        className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600"
                                    >
                                        Leave
                                    </button>
                                )}
                            </div>
                        </section>
                    ))}

                    <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 className="font-bold text-gray-900">Start a family group</h2>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                familyForm.post(route('savings.together.family.store'), { preserveScroll: true, onSuccess: () => familyForm.reset() });
                            }}
                            className="mt-4 flex flex-col gap-2 sm:flex-row"
                        >
                            <input
                                value={familyForm.data.name}
                                onChange={(event) => familyForm.setData('name', event.target.value)}
                                placeholder="Group name"
                                className="min-h-11 flex-1 rounded-lg border border-gray-300 px-3 text-sm"
                            />
                            <button disabled={familyForm.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                                Create
                            </button>
                        </form>
                    </section>
                </>
            )}

            {/* ── Cooperative ── */}
            {tab === 'cooperatives' && (
                <>
                    <div className="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <strong className="font-bold">How this differs from a normal ajo.</strong> When your turn comes,
                        everyone's contribution goes into <em>your Pay Small Small plan</em> — it brings your goods
                        closer, but it never becomes cash you can spend elsewhere. If you need cash, this is not the
                        right tool.
                    </div>

                    {cooperatives.map((group) => (
                        <section key={group.uuid} className="mt-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-bold text-gray-900">{group.name}</h2>
                                    <p className="text-sm text-gray-500">
                                        {formatNairaFromKobo(group.contributionKobo)} {group.cadence} · organised by {group.organiser}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-bold capitalize text-gray-600">{group.status}</span>
                                    {group.inviteCode && <InviteCode code={group.inviteCode} />}
                                </div>
                            </div>

                            <ol className="mt-4 space-y-1.5">
                                {group.rotation.map((row) => (
                                    <li key={row.userId} className="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-gray-600 ring-1 ring-gray-200">
                                            {row.position}
                                        </span>
                                        <span className="flex-1 text-gray-800">{row.name}</span>
                                        {row.hasReceived && <span className="text-xs font-bold text-emerald-600">had their turn</span>}
                                        {row.status !== 'active' && <span className="text-xs capitalize text-gray-400">{row.status}</span>}
                                    </li>
                                ))}
                            </ol>

                            {group.openCycle && (
                                <div className="mt-4 rounded-xl border border-brand-100 bg-brand-50/60 p-4">
                                    <p className="text-sm font-bold text-brand-900">
                                        Turn {group.openCycle.number}: {group.openCycle.beneficiary}
                                        {group.openCycle.isMyTurn && ' — that is you'}
                                    </p>
                                    <p className="mt-0.5 text-xs text-brand-700">
                                        {group.openCycle.paidCount} of {group.openCycle.memberCount} have paid this turn.
                                    </p>

                                    {group.openCycle.isMyTurn && !group.openCycle.hasPlan && (
                                        <NominateForm cycleId={group.openCycle.id} plans={myRunningPlans} />
                                    )}

                                    {group.isOrganiser && group.openCycle.paidCount >= group.openCycle.memberCount && (
                                        <button
                                            onClick={() => router.post(route('savings.together.cooperatives.cycles.close', group.openCycle!.id), {}, { preserveScroll: true })}
                                            className="mt-3 rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white"
                                        >
                                            Close this turn
                                        </button>
                                    )}
                                </div>
                            )}

                            <div className="mt-3 flex flex-wrap gap-2">
                                {group.isOrganiser && group.status === 'forming' && (
                                    <>
                                        <button
                                            onClick={() => invite('savings.together.cooperatives.invite', group.uuid)}
                                            className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white"
                                        >
                                            Invite someone
                                        </button>
                                        <button
                                            onClick={() => {
                                                if (!confirm('Start the rotation? The order is fixed after this and nobody else can join.')) return;
                                                router.post(route('savings.together.cooperatives.start', group.uuid), {}, { preserveScroll: true });
                                            }}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-3 py-2 text-xs font-bold text-white"
                                        >
                                            <RotateCw className="h-3.5 w-3.5" /> Start rotation
                                        </button>
                                    </>
                                )}
                            </div>
                        </section>
                    ))}

                    <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center gap-3">
                            <Wallet className="h-5 w-5 text-brand-600" />
                            <h2 className="font-bold text-gray-900">Start a cooperative</h2>
                        </div>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                coopForm.post(route('savings.together.cooperatives.store'), { preserveScroll: true, onSuccess: () => coopForm.reset() });
                            }}
                            className="mt-4 grid gap-2 sm:grid-cols-2"
                        >
                            <input
                                value={coopForm.data.name}
                                onChange={(event) => coopForm.setData('name', event.target.value)}
                                placeholder="Group name"
                                className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                            />
                            <input
                                type="number"
                                value={coopForm.data.contribution_naira}
                                onChange={(event) => coopForm.setData('contribution_naira', event.target.value)}
                                placeholder="Contribution per turn (₦)"
                                className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                            />
                            <select
                                value={coopForm.data.cadence}
                                onChange={(event) => coopForm.setData('cadence', event.target.value)}
                                className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                            >
                                <option value="monthly">Monthly</option>
                                <option value="weekly">Weekly</option>
                            </select>
                            <input
                                value={coopForm.data.description}
                                onChange={(event) => coopForm.setData('description', event.target.value)}
                                placeholder="Description (optional)"
                                className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm"
                            />
                            <button disabled={coopForm.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60 sm:col-span-2">
                                Create cooperative
                            </button>
                        </form>
                        {coopForm.errors.contribution_naira && (
                            <p className="mt-1 text-xs text-red-600">{coopForm.errors.contribution_naira}</p>
                        )}
                    </section>
                </>
            )}
        </AccountLayout>
    );
}

function NominateForm({ cycleId, plans }: { cycleId: number; plans: PlanOption[] }) {
    const form = useForm({ plan_uuid: plans[0]?.uuid ?? '' });

    if (plans.length === 0) {
        return (
            <p className="mt-3 text-xs text-brand-800">
                You need a running plan for this turn to land on. Start one first, then come back.
            </p>
        );
    }

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('savings.together.cooperatives.cycles.nominate', cycleId), { preserveScroll: true });
            }}
            className="mt-3 flex flex-col gap-2 sm:flex-row"
        >
            <select
                value={form.data.plan_uuid}
                onChange={(event) => form.setData('plan_uuid', event.target.value)}
                className="min-h-11 flex-1 rounded-lg border border-brand-200 px-3 text-sm"
            >
                {plans.map((plan) => (
                    <option key={plan.uuid} value={plan.uuid}>
                        {formatNairaFromKobo(plan.savedKobo)} / {formatNairaFromKobo(plan.targetKobo)}
                    </option>
                ))}
            </select>
            <button disabled={form.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                This turn funds that plan
            </button>
        </form>
    );
}
