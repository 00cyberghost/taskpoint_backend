import { Head } from '@inertiajs/react';
import { BadgeCheck, Building2, ShieldCheck, UserCircle2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type UserItem = {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
    phone?: string | null;
    registration_country?: string | null;
    last_login_country?: string | null;
    created_at: string;
    clientProfile?: {
        verification_status: string;
    } | null;
    freelancerProfile?: {
        verification_status: string;
        payout_status: string;
        success_rate: string;
        total_completed: number;
    } | null;
};

type Props = {
    users: {
        data: UserItem[];
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Users', href: '/admin/users' },
];

export default function AdminUsers({ users }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />

            <div className="flex flex-1 flex-col gap-6 rounded-2xl bg-stone-50 p-4 md:p-6">
                <section className="rounded-3xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="rounded-2xl bg-orange-50 p-3 text-orange-600">
                            <UserCircle2 className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-stone-900">User Management</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-500">
                                Review clients, freelancers, and admins in one place, including verification state,
                                location signals, and freelancer performance.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid gap-4">
                    {users.data.map((user) => (
                        <div key={user.id} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                                <div className="max-w-3xl">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold text-stone-900">{user.name}</h2>
                                        <span className="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                                            {user.role}
                                        </span>
                                        <span className="rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-700">
                                            {user.status}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm text-stone-500">{user.email}</p>

                                    <div className="mt-5 grid gap-3 md:grid-cols-3">
                                        <Metric
                                            icon={ShieldCheck}
                                            label="Verification"
                                            value={
                                                user.freelancerProfile?.verification_status ??
                                                user.clientProfile?.verification_status ??
                                                'pending'
                                            }
                                        />
                                        <Metric
                                            icon={BadgeCheck}
                                            label="Country"
                                            value={user.last_login_country ?? user.registration_country ?? 'Unknown'}
                                        />
                                        <Metric
                                            icon={Building2}
                                            label="Phone"
                                            value={user.phone ?? 'Not provided'}
                                        />
                                    </div>
                                </div>

                                <div className="min-w-80 rounded-3xl border border-stone-200 bg-stone-50 p-4">
                                    <dl className="grid gap-3 text-sm text-stone-600">
                                        <div className="flex items-center justify-between gap-4">
                                            <dt>Freelancer payout status</dt>
                                            <dd className="font-semibold text-stone-900">
                                                {user.freelancerProfile?.payout_status ?? 'n/a'}
                                            </dd>
                                        </div>
                                        <div className="flex items-center justify-between gap-4">
                                            <dt>Success rate</dt>
                                            <dd className="font-semibold text-stone-900">
                                                {user.freelancerProfile?.success_rate ?? '0.00'}%
                                            </dd>
                                        </div>
                                        <div className="flex items-center justify-between gap-4">
                                            <dt>Total completed</dt>
                                            <dd className="font-semibold text-stone-900">
                                                {user.freelancerProfile?.total_completed ?? 0}
                                            </dd>
                                        </div>
                                        <div className="flex items-center justify-between gap-4">
                                            <dt>Registration country</dt>
                                            <dd className="font-semibold text-stone-900">
                                                {user.registration_country ?? 'Unknown'}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function Metric({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof UserCircle2;
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
