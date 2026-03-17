import { Head, router, usePage } from '@inertiajs/react';
import { BellRing, CheckCheck, CircleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type NotificationItem = {
    id: number;
    type: string;
    title: string;
    body: string;
    image_path?: string | null;
    image_url?: string | null;
    channel: string;
    delivery_status: string;
    created_at: string;
    read_at?: string | null;
    user: {
        name: string;
        email: string;
        role: string;
    } | null;
};

type Props = {
    notifications: {
        data: NotificationItem[];
    };
    users: {
        id: number;
        name: string;
        email: string;
        role: string;
    }[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Notifications', href: '/admin/notifications' },
];

export default function AdminNotifications({ notifications, users }: Props) {
    const [audience, setAudience] = useState<'clients' | 'freelancers' | 'both' | 'individual'>('freelancers');
    const [userId, setUserId] = useState('');
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [image, setImage] = useState<File | null>(null);
    const page = usePage<{ props: { errors?: Record<string, string> } }>();
    const errors = page.props.errors ?? {};

    const userOptions = useMemo(
        () =>
            users.map((user) => ({
                value: String(user.id),
                label: `${user.name} (${user.role})`,
            })),
        [users],
    );

    function sendNotification() {
        router.post(
            '/admin/notifications/send',
            {
                audience,
                user_id: audience === 'individual' ? Number(userId) : undefined,
                title,
                body,
                image,
            },
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setTitle('');
                    setBody('');
                    setImage(null);
                    if (audience !== 'individual') {
                        setUserId('');
                    }
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Delivery" />

            <div className="flex flex-1 flex-col gap-6 rounded-2xl bg-stone-50 p-4 md:p-6">
                <section className="rounded-3xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="rounded-2xl bg-sky-50 p-3 text-sky-600">
                            <BellRing className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-stone-900">Notification Delivery</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-500">
                                Inspect recent platform notifications, see delivery state, and monitor which events are
                                reaching clients and freelancers.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="rounded-3xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div className="mb-5">
                        <h2 className="text-lg font-semibold text-stone-900">Send Push Notification</h2>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-500">
                            Send a push and in-app notification to all freelancers, all clients, both groups, or a single user.
                        </p>
                    </div>

                    <div className="grid gap-5 lg:grid-cols-2">
                        <div>
                            <label className="block">
                                <span className="mb-2 block text-sm font-medium text-stone-700">Audience</span>
                                <select
                                    value={audience}
                                    onChange={(event) => setAudience(event.target.value as typeof audience)}
                                    className="w-full rounded-2xl border border-stone-200 px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
                                >
                                    <option value="freelancers">All freelancers</option>
                                    <option value="clients">All clients</option>
                                    <option value="both">Clients and freelancers</option>
                                    <option value="individual">Individual user</option>
                                </select>
                            </label>
                            {errors.audience ? <p className="mt-2 text-sm text-red-600">{errors.audience}</p> : null}
                        </div>

                        {audience === 'individual' ? (
                            <div>
                                <label className="block">
                                    <span className="mb-2 block text-sm font-medium text-stone-700">User</span>
                                    <select
                                        value={userId}
                                        onChange={(event) => setUserId(event.target.value)}
                                        className="w-full rounded-2xl border border-stone-200 px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
                                    >
                                        <option value="">Select a user</option>
                                        {userOptions.map((user) => (
                                            <option key={user.value} value={user.value}>
                                                {user.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {errors.user_id ? <p className="mt-2 text-sm text-red-600">{errors.user_id}</p> : null}
                            </div>
                        ) : (
                            <div className="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-500">
                                This notification will fan out to every active user in the selected audience.
                            </div>
                        )}
                    </div>

                    <div className="mt-5">
                        <label className="block">
                            <span className="mb-2 block text-sm font-medium text-stone-700">Title</span>
                            <input
                                value={title}
                                onChange={(event) => setTitle(event.target.value)}
                                className="w-full rounded-2xl border border-stone-200 px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
                                placeholder="Important platform update"
                            />
                        </label>
                        {errors.title ? <p className="mt-2 text-sm text-red-600">{errors.title}</p> : null}
                    </div>

                    <div className="mt-5">
                        <label className="block">
                            <span className="mb-2 block text-sm font-medium text-stone-700">Message</span>
                            <textarea
                                value={body}
                                onChange={(event) => setBody(event.target.value)}
                                rows={5}
                                className="w-full rounded-2xl border border-stone-200 px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
                                placeholder="Write the push notification message here..."
                            />
                        </label>
                        {errors.body ? <p className="mt-2 text-sm text-red-600">{errors.body}</p> : null}
                    </div>

                    <div className="mt-5">
                        <label className="block">
                            <span className="mb-2 block text-sm font-medium text-stone-700">Image Attachment (optional)</span>
                            <input
                                type="file"
                                accept="image/*"
                                onChange={(event) => setImage(event.target.files?.[0] ?? null)}
                                className="w-full rounded-2xl border border-stone-200 px-4 py-3 text-sm text-stone-900 outline-none transition file:mr-4 file:rounded-xl file:border-0 file:bg-stone-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white focus:border-stone-400"
                            />
                        </label>
                        {errors.image ? <p className="mt-2 text-sm text-red-600">{errors.image}</p> : null}
                    </div>

                    <div className="mt-5">
                        <button
                            type="button"
                            className="rounded-2xl bg-stone-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-stone-700"
                            onClick={sendNotification}
                        >
                            Send Notification
                        </button>
                    </div>
                </section>

                <div className="space-y-4">
                    {notifications.data.map((notification) => (
                        <div key={notification.id} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                                <div className="max-w-4xl">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold text-stone-900">{notification.title}</h2>
                                        <span className="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                                            {notification.type}
                                        </span>
                                        <span className="rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-700">
                                            {notification.delivery_status}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm text-stone-500">
                                        {notification.user?.name ?? 'Unknown user'} · {notification.user?.email ?? 'No email'} ·{' '}
                                        {notification.user?.role ?? 'unknown role'}
                                    </p>
                                    {notification.image_url ? (
                                        <img
                                            src={notification.image_url}
                                            alt={notification.title}
                                            className="mt-4 h-40 w-full rounded-2xl object-cover"
                                        />
                                    ) : null}
                                    <p className="mt-3 text-sm leading-6 text-stone-600">{notification.body}</p>
                                </div>

                                <div className="min-w-80 rounded-3xl border border-stone-200 bg-stone-50 p-4">
                                    <div className="grid gap-3 text-sm">
                                        <SummaryMetric icon={BellRing} label="Channel" value={notification.channel} />
                                        <SummaryMetric icon={CircleAlert} label="Delivery" value={notification.delivery_status} />
                                        <SummaryMetric
                                            icon={CheckCheck}
                                            label="Read state"
                                            value={notification.read_at ? 'Read' : 'Unread'}
                                        />
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

function SummaryMetric({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof BellRing;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-2xl border border-stone-200 bg-white px-4 py-3">
            <div className="flex items-center gap-2 text-stone-500">
                <Icon className="size-4" />
                <span className="text-xs font-semibold uppercase tracking-[0.2em]">{label}</span>
            </div>
            <p className="mt-2 text-base font-semibold capitalize text-stone-900">{value}</p>
        </div>
    );
}
