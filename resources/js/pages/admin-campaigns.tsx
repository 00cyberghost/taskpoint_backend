import { Head, router } from '@inertiajs/react';
import { HandCoins, Target, WalletCards } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type CampaignItem = {
    id: number;
    title: string;
    status: string;
    task_type: string;
    review_mode: string;
    proof_mode: string;
    target_quantity: number;
    completed_quantity: number;
    target_url: string;
    client: { name: string; email: string } | null;
    pricing: {
        client_unit_price: string;
        freelancer_unit_payout: string;
        platform_margin: string;
        currency: string;
    } | null;
    funds: {
        total_funded: string;
        total_reserved: string;
        total_spent: string;
        total_refunded: string;
    } | null;
    targeting_rule: {
        allowed_countries: string[] | null;
        min_trust_score: string;
        max_assignments_per_freelancer: number;
    } | null;
    assignments: {
        id: number;
        freelancer: { id: number; name: string; email: string; registration_country: string | null } | null;
    }[];
};

type FreelancerItem = {
    id: number;
    name: string;
    email: string;
    registration_country: string | null;
    freelancer_profile?: {
        verification_status: string;
        preferred_countries: string[] | null;
        trust_score: string;
    } | null;
};

type Props = {
    campaigns: {
        data: CampaignItem[];
    };
    freelancers: FreelancerItem[];
    taskTypePricings: {
        id: number;
        task_type: string;
        client_unit_price: string;
        freelancer_unit_payout: string;
        currency: string;
        active: boolean;
    }[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Campaigns', href: '/admin/campaigns' },
];

export default function AdminCampaigns({ campaigns, freelancers, taskTypePricings }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Campaign Operations" />

            <div className="flex flex-1 flex-col gap-6 rounded-2xl bg-stone-50 p-4 md:p-6">
                <section className="rounded-3xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="rounded-2xl bg-orange-50 p-3 text-orange-600">
                            <WalletCards className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-stone-900">Campaign Operations</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-500">
                                Review client campaigns, adjust payout economics, and push campaigns through the
                                lifecycle from draft to active distribution.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                    <div className="mb-5">
                        <h2 className="text-lg font-semibold text-stone-900">Task Type Pricing Defaults</h2>
                        <p className="mt-2 text-sm text-stone-500">
                            Set the default client charge and freelancer payout for each task type. New campaigns
                            inherit these values automatically.
                        </p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {taskTypePricings.map((pricing) => (
                            <TaskTypePricingCard key={pricing.id} pricing={pricing} />
                        ))}
                    </div>
                </section>

                <div className="space-y-4">
                    {campaigns.data.map((campaign) => (
                        <div key={campaign.id} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-col gap-6 xl:flex-row xl:justify-between">
                                <div className="max-w-3xl">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold text-stone-900">{campaign.title}</h2>
                                        <span className="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                                            {campaign.status}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm text-stone-500">
                                        Client: {campaign.client?.name ?? 'Unknown'} · {campaign.client?.email ?? 'No email'}
                                    </p>
                                    <p className="mt-3 text-sm leading-6 text-stone-500">{campaign.target_url}</p>

                                    <div className="mt-5 grid gap-3 sm:grid-cols-3">
                                        <Metric icon={Target} label="Target" value={`${campaign.completed_quantity}/${campaign.target_quantity}`} />
                                        <Metric
                                            icon={HandCoins}
                                            label="Client price"
                                            value={`₦${Number(campaign.pricing?.client_unit_price ?? 0).toLocaleString()}`}
                                        />
                                        <Metric
                                            icon={HandCoins}
                                            label="Freelancer payout"
                                            value={`₦${Number(campaign.pricing?.freelancer_unit_payout ?? 0).toLocaleString()}`}
                                        />
                                    </div>

                                    <div className="mt-5 flex flex-wrap gap-2 text-xs font-medium text-stone-500">
                                        <span className="rounded-full bg-stone-100 px-3 py-1">
                                            Review: {campaign.review_mode}
                                        </span>
                                        <span className="rounded-full bg-stone-100 px-3 py-1">
                                            Proof: {campaign.proof_mode}
                                        </span>
                                        <span className="rounded-full bg-stone-100 px-3 py-1">
                                            Countries: {campaign.targeting_rule?.allowed_countries?.join(', ') || 'Global'}
                                        </span>
                                        <span className="rounded-full bg-stone-100 px-3 py-1">
                                            Assigned freelancers: {campaign.assignments.length}
                                        </span>
                                    </div>

                                    {campaign.assignments.length > 0 ? (
                                        <div className="mt-4 rounded-2xl border border-stone-200 bg-stone-50 p-3">
                                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-stone-500">
                                                Current Assignees
                                            </p>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {campaign.assignments.map((assignment) => (
                                                    <span
                                                        key={assignment.id}
                                                        className="rounded-full bg-white px-3 py-1 text-xs font-medium text-stone-700"
                                                    >
                                                        {assignment.freelancer?.name ?? assignment.freelancer?.email ?? 'Freelancer'}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                </div>

                                <div className="min-w-72 rounded-3xl border border-stone-200 bg-stone-50 p-4">
                                    <div className="grid gap-3">
                                        <ActionButton
                                            label="Move to priced"
                                            onClick={() => updateCampaign(campaign.id, { status: 'priced' })}
                                        />
                                        <ActionButton
                                            label="Approve for distribution"
                                            onClick={() => updateCampaign(campaign.id, { status: 'approved_for_distribution' })}
                                        />
                                        <ActionButton
                                            label="Activate campaign"
                                            onClick={() => updateCampaign(campaign.id, { status: 'active' })}
                                        />
                                        <ActionButton
                                            label="Assign freelancers now"
                                            tone="orange"
                                            onClick={() => updateCampaign(campaign.id, { status: 'active' })}
                                        />
                                        <ActionButton
                                            label="Pause campaign"
                                            tone="stone"
                                            onClick={() => updateCampaign(campaign.id, { status: 'paused' })}
                                        />
                                        <ActionButton
                                            label="Raise freelancer payout"
                                            tone="amber"
                                            onClick={() =>
                                                updateCampaign(campaign.id, {
                                                    freelancer_unit_payout:
                                                        Number(campaign.pricing?.freelancer_unit_payout ?? 0) + 50,
                                                })
                                            }
                                        />
                                    </div>

                                    <div className="mt-5">
                                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.2em] text-stone-500">
                                            Manual Assignment
                                        </p>
                                        <div className="grid gap-2">
                                            {freelancers.slice(0, 5).map((freelancer) => (
                                                <button
                                                    key={`${campaign.id}-${freelancer.id}`}
                                                    type="button"
                                                    className="rounded-2xl border border-stone-200 bg-white px-3 py-3 text-left text-sm transition hover:border-orange-300 hover:bg-orange-50"
                                                    onClick={() =>
                                                        updateCampaign(campaign.id, {
                                                            manual_freelancer_id: freelancer.id,
                                                        })
                                                    }
                                                >
                                                    <div className="font-semibold text-stone-900">{freelancer.name}</div>
                                                    <div className="mt-1 text-xs text-stone-500">
                                                        {freelancer.email} · {freelancer.registration_country ?? 'No country'} · trust{' '}
                                                        {Number(freelancer.freelancer_profile?.trust_score ?? 0).toFixed(0)}
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function TaskTypePricingCard({
    pricing,
}: {
    pricing: {
        id: number;
        task_type: string;
        client_unit_price: string;
        freelancer_unit_payout: string;
        currency: string;
        active: boolean;
    };
}) {
    const [clientPrice, setClientPrice] = useState(pricing.client_unit_price);
    const [freelancerPayout, setFreelancerPayout] = useState(pricing.freelancer_unit_payout);

    return (
        <div className="rounded-2xl border border-stone-200 bg-stone-50 p-4">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold uppercase tracking-[0.2em] text-stone-900">{pricing.task_type}</h3>
                <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide ${pricing.active ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-200 text-stone-600'}`}>
                    {pricing.active ? 'active' : 'inactive'}
                </span>
            </div>

            <label className="mt-4 block">
                <span className="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-stone-500">Client Price</span>
                <input
                    value={clientPrice}
                    onChange={(event) => setClientPrice(event.target.value)}
                    className="w-full rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
                />
            </label>

            <label className="mt-3 block">
                <span className="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-stone-500">Freelancer Payout</span>
                <input
                    value={freelancerPayout}
                    onChange={(event) => setFreelancerPayout(event.target.value)}
                    className="w-full rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
                />
            </label>

            <button
                type="button"
                className="mt-4 w-full rounded-2xl bg-stone-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-stone-700"
                onClick={() =>
                    router.patch(
                        '/admin/campaigns/task-type-pricing/defaults',
                        {
                            task_type: pricing.task_type,
                            client_unit_price: Number(clientPrice || 0),
                            freelancer_unit_payout: Number(freelancerPayout || 0),
                            currency: pricing.currency,
                            active: pricing.active,
                        },
                        { preserveScroll: true },
                    )
                }
            >
                Save {pricing.task_type} pricing
            </button>
        </div>
    );
}

function updateCampaign(campaignId: number, payload: Record<string, number | string>) {
    router.patch(`/admin/campaigns/${campaignId}`, payload, {
        preserveScroll: true,
    });
}

function Metric({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Target;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-2xl border border-stone-200 bg-white px-4 py-3">
            <div className="flex items-center gap-2 text-stone-500">
                <Icon className="size-4" />
                <span className="text-xs font-semibold uppercase tracking-[0.2em]">{label}</span>
            </div>
            <p className="mt-2 text-base font-semibold text-stone-900">{value}</p>
        </div>
    );
}

function ActionButton({
    label,
    onClick,
    tone = 'orange',
}: {
    label: string;
    onClick: () => void;
    tone?: 'orange' | 'amber' | 'stone';
}) {
    const toneClasses = {
        orange: 'bg-orange-500 text-white hover:bg-orange-600',
        amber: 'bg-amber-100 text-amber-900 hover:bg-amber-200',
        stone: 'bg-stone-200 text-stone-900 hover:bg-stone-300',
    };

    return (
        <button
            type="button"
            className={`rounded-2xl px-4 py-3 text-sm font-semibold transition ${toneClasses[tone]}`}
            onClick={onClick}
        >
            {label}
        </button>
    );
}
