import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    AlertTriangle,
    BellRing,
    BriefcaseBusiness,
    CreditCard,
    HandCoins,
    ShieldAlert,
    Users,
    Wallet,
} from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type Campaign = {
    id: number;
    title: string;
    status: string;
    task_type: string;
    target_quantity: number;
    completed_quantity: number;
    client: { name: string } | null;
    pricing: {
        client_unit_price: string;
        freelancer_unit_payout: string;
    } | null;
};

type Submission = {
    id: number;
    status: string;
    submitted_at: string | null;
    assignment: {
        campaign: { title: string } | null;
    } | null;
    freelancer: { name: string } | null;
    client: { name: string } | null;
};

type FraudAlert = {
    id: number;
    type: string;
    severity: string;
    status: string;
    user: { name: string } | null;
    submission: {
        assignment: {
            campaign: { title: string } | null;
        } | null;
    } | null;
};

type AppNotification = {
    id: number;
    type: string;
    title: string;
    body: string;
    delivery_status: string;
    user: { name: string } | null;
};

type Props = {
    metrics?: {
        totals: {
            clients: number;
            freelancers: number;
            campaigns: number;
            pending_reviews: number;
        };
        finance: {
            approved_payouts: number;
            client_spend: number;
            platform_revenue: number;
            withdrawals_pending: number;
        };
    };
    recentCampaigns?: Campaign[];
    reviewQueue?: Submission[];
    fraudAlerts?: FraudAlert[];
    recentNotifications?: AppNotification[];
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

export default function Dashboard({
    metrics = {
        totals: {
            clients: 0,
            freelancers: 0,
            campaigns: 0,
            pending_reviews: 0,
        },
        finance: {
            approved_payouts: 0,
            client_spend: 0,
            platform_revenue: 0,
            withdrawals_pending: 0,
        },
    },
    recentCampaigns = [],
    reviewQueue = [],
    fraudAlerts = [],
    recentNotifications = [],
}: Props) {
    const statCards = [
        {
            title: 'Clients',
            value: metrics.totals.clients,
            icon: BriefcaseBusiness,
            tone: 'bg-orange-50 text-orange-600',
        },
        {
            title: 'Freelancers',
            value: metrics.totals.freelancers,
            icon: Users,
            tone: 'bg-emerald-50 text-emerald-600',
        },
        {
            title: 'Active Campaigns',
            value: metrics.totals.campaigns,
            icon: Wallet,
            tone: 'bg-sky-50 text-sky-600',
        },
        {
            title: 'Pending Reviews',
            value: metrics.totals.pending_reviews,
            icon: ShieldAlert,
            tone: 'bg-amber-50 text-amber-600',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Admin Dashboard" />

            <div className="flex flex-1 flex-col gap-6 rounded-2xl bg-stone-50 p-4 md:p-6">
                <section className="rounded-3xl bg-gradient-to-br from-stone-950 via-orange-950 to-stone-900 p-6 text-white shadow-xl">
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p className="text-sm font-medium uppercase tracking-[0.3em] text-orange-200">
                                TaskPoint Control Room
                            </p>
                            <h1 className="mt-3 text-3xl font-semibold tracking-tight md:text-4xl">
                                Monitor campaigns, payouts, reviews, and fraud signals in one place.
                            </h1>
                            <p className="mt-4 max-w-3xl text-sm leading-6 text-stone-300 md:text-base">
                                This dashboard is the operational hub for pricing client campaigns, reviewing freelancer
                                proof, approving payouts, and reacting to suspicious activity before it becomes a loss.
                            </p>
                        </div>

                        <div className="grid gap-3 rounded-3xl border border-white/10 bg-white/5 p-4 text-sm text-stone-200 sm:grid-cols-2">
                            <div>
                                <p className="text-xs uppercase tracking-[0.25em] text-stone-400">Client spend</p>
                                <p className="mt-2 text-2xl font-semibold">
                                    ₦{Number(metrics.finance.client_spend).toLocaleString()}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs uppercase tracking-[0.25em] text-stone-400">Approved payouts</p>
                                <p className="mt-2 text-2xl font-semibold">
                                    ₦{Number(metrics.finance.approved_payouts).toLocaleString()}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs uppercase tracking-[0.25em] text-stone-400">Platform revenue</p>
                                <p className="mt-2 text-2xl font-semibold">
                                    ₦{Number(metrics.finance.platform_revenue).toLocaleString()}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs uppercase tracking-[0.25em] text-stone-400">Withdrawals waiting</p>
                                <p className="mt-2 text-2xl font-semibold">{metrics.finance.withdrawals_pending}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="users" className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((card) => (
                        <div key={card.title} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-sm font-medium text-stone-500">{card.title}</p>
                                    <p className="mt-3 text-3xl font-semibold tracking-tight text-stone-900">{card.value}</p>
                                </div>
                                <div className={`rounded-2xl p-3 ${card.tone}`}>
                                    <card.icon className="size-5" />
                                </div>
                            </div>
                        </div>
                    ))}
                </section>

                <section className="grid gap-6 xl:grid-cols-[1.35fr_0.95fr]">
                    <div id="campaigns" className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                        <div className="mb-5 flex items-center justify-between">
                            <div>
                                <h2 className="text-xl font-semibold text-stone-900">Recent Campaigns</h2>
                                <p className="mt-1 text-sm text-stone-500">
                                    Live client demand with pricing and completion progress.
                                </p>
                            </div>
                            <div className="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700">
                                Campaign operations
                            </div>
                        </div>

                        <div className="space-y-4">
                            {recentCampaigns.map((campaign) => (
                                <div
                                    key={campaign.id}
                                    className="rounded-2xl border border-stone-200 bg-stone-50 p-4"
                                >
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="text-base font-semibold text-stone-900">
                                                    {campaign.title}
                                                </h3>
                                                <span className="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                                                    {campaign.status}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-sm text-stone-500">
                                                Client: {campaign.client?.name ?? 'Unknown'} · Type: {campaign.task_type}
                                            </p>
                                        </div>

                                        <div className="grid gap-2 text-sm sm:grid-cols-3">
                                            <MetricPill
                                                label="Progress"
                                                value={`${campaign.completed_quantity}/${campaign.target_quantity}`}
                                            />
                                            <MetricPill
                                                label="Client price"
                                                value={`₦${Number(campaign.pricing?.client_unit_price ?? 0).toLocaleString()}`}
                                            />
                                            <MetricPill
                                                label="Freelancer payout"
                                                value={`₦${Number(campaign.pricing?.freelancer_unit_payout ?? 0).toLocaleString()}`}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-6">
                        <Panel
                            id="reviews"
                            title="Review Queue"
                            description="Hybrid approvals that still need a human decision."
                            icon={HandCoins}
                        >
                            <div className="space-y-3">
                                {reviewQueue.map((submission) => (
                                    <div key={submission.id} className="rounded-2xl border border-stone-200 p-4">
                                        <p className="text-sm font-semibold text-stone-900">
                                            {submission.assignment?.campaign?.title ?? 'Campaign'}
                                        </p>
                                        <p className="mt-1 text-sm text-stone-500">
                                            Freelancer: {submission.freelancer?.name ?? 'Unknown'} · Client:{' '}
                                            {submission.client?.name ?? 'Unknown'}
                                        </p>
                                        <div className="mt-3 flex items-center justify-between text-xs font-medium">
                                            <span className="rounded-full bg-amber-50 px-2.5 py-1 text-amber-700">
                                                {submission.status}
                                            </span>
                                            <span className="text-stone-400">
                                                {submission.submitted_at ?? 'Awaiting timestamp'}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Panel>

                        <Panel
                            id="payouts"
                            title="Fraud Alerts"
                            description="Suspicious submissions, device overlap, or geo mismatches."
                            icon={AlertTriangle}
                        >
                            <div className="space-y-3">
                                {fraudAlerts.map((alert) => (
                                    <div key={alert.id} className="rounded-2xl border border-stone-200 p-4">
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-sm font-semibold text-stone-900">{alert.type}</p>
                                            <span className="rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700">
                                                {alert.severity}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm text-stone-500">
                                            {alert.user?.name ?? 'Unknown user'} ·{' '}
                                            {alert.submission?.assignment?.campaign?.title ?? 'No campaign'}
                                        </p>
                                        <p className="mt-2 text-xs text-stone-400">Status: {alert.status}</p>
                                    </div>
                                ))}
                            </div>
                        </Panel>

                        <Panel
                            id="notifications"
                            title="Notification Activity"
                            description="Recent messages sent to clients, freelancers, and admins."
                            icon={BellRing}
                        >
                            <div className="space-y-3">
                                {recentNotifications.map((item) => (
                                    <div key={item.id} className="rounded-2xl border border-stone-200 p-4">
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-sm font-semibold text-stone-900">{item.title}</p>
                                            <span className="rounded-full bg-sky-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                                {item.delivery_status}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm text-stone-500">{item.body}</p>
                                        <p className="mt-2 text-xs text-stone-400">
                                            Recipient: {item.user?.name ?? 'Unknown'} · {item.type}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    <QuickAction
                        title="Review submissions"
                        description="Work through the client and admin decision queue with proof evidence."
                        icon={ShieldAlert}
                    />
                    <QuickAction
                        title="Process payouts"
                        description="Approve freelancer withdrawals and monitor the ₦1000 threshold flow."
                        icon={CreditCard}
                    />
                    <QuickAction
                        title="Tune pricing"
                        description="Adjust campaign margin and payout strategy before activation."
                        icon={HandCoins}
                    />
                </section>
            </div>
        </AppLayout>
    );
}

function MetricPill({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-2xl border border-stone-200 bg-white px-3 py-2">
            <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-stone-400">{label}</p>
            <p className="mt-1 text-sm font-semibold text-stone-900">{value}</p>
        </div>
    );
}

function Panel({
    id,
    title,
    description,
    icon: Icon,
    children,
}: {
    id?: string;
    title: string;
    description: string;
    icon: typeof BellRing;
    children: ReactNode;
}) {
    return (
        <div id={id} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-start gap-3">
                <div className="rounded-2xl bg-stone-100 p-3 text-stone-700">
                    <Icon className="size-5" />
                </div>
                <div>
                    <h2 className="text-lg font-semibold text-stone-900">{title}</h2>
                    <p className="mt-1 text-sm text-stone-500">{description}</p>
                </div>
            </div>
            {children}
        </div>
    );
}

function QuickAction({
    title,
    description,
    icon: Icon,
}: {
    title: string;
    description: string;
    icon: typeof BellRing;
}) {
    return (
        <div className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
            <div className="rounded-2xl bg-orange-50 p-3 text-orange-600">
                <Icon className="size-5" />
            </div>
            <h3 className="mt-4 text-lg font-semibold text-stone-900">{title}</h3>
            <p className="mt-2 text-sm leading-6 text-stone-500">{description}</p>
        </div>
    );
}
