import InputError from '@/components/input-error';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck, ShieldX } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type SubmissionItem = {
    id: number;
    status: string;
    submitted_at: string | null;
    rejection_reason: string | null;
    client: { name: string; email: string } | null;
    freelancer: { name: string; email: string } | null;
    assignment: {
        campaign: {
            title: string;
            target_url: string;
            pricing?: {
                client_unit_price: string;
                freelancer_unit_payout: string;
                platform_margin: string;
            } | null;
        } | null;
    } | null;
    proofs: Array<{
        id: number;
        type: string;
        file_path: string;
        source: string;
        created_at: string | null;
    }>;
    review_decisions: Array<{
        id: number;
        actor_role: string;
        decision: string;
        note: string | null;
        created_at: string | null;
    }>;
};

type Props = {
    submissions: {
        data: SubmissionItem[];
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Reviews', href: '/admin/reviews' },
];

export default function AdminReviews({ submissions }: Props) {
    const { props } = usePage<{
        flash?: { success?: string };
        errors?: Record<string, string>;
    }>();
    const [processingSubmissionId, setProcessingSubmissionId] = useState<number | null>(null);

    function approveSubmission(submissionId: number) {
        setProcessingSubmissionId(submissionId);
        router.post(
            `/admin/reviews/${submissionId}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessingSubmissionId(null),
            },
        );
    }

    function rejectSubmission(submissionId: number) {
        setProcessingSubmissionId(submissionId);
        router.post(
            `/admin/reviews/${submissionId}/reject`,
            { reason: 'Proof does not satisfy task requirements.' },
            {
                preserveScroll: true,
                onFinish: () => setProcessingSubmissionId(null),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submission Review" />

            <div className="flex flex-1 flex-col gap-6 rounded-2xl bg-stone-50 p-4 md:p-6">
                {props.flash?.success ? (
                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                        {props.flash.success}
                    </div>
                ) : null}

                {props.errors?.submission || props.errors?.wallet ? (
                    <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3">
                        <InputError message={props.errors?.submission ?? props.errors?.wallet} className="text-sm text-rose-700" />
                    </div>
                ) : null}

                <section className="rounded-3xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="rounded-2xl bg-amber-50 p-3 text-amber-600">
                            <ClipboardCheck className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-stone-900">Submission Review Queue</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-500">
                                Hybrid approvals live here. Review proof evidence, inspect client/freelancer context,
                                and make the final decision that drives wallet movement.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="space-y-4">
                    {submissions.data.map((submission) => (
                        <div key={submission.id} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-col gap-6 xl:flex-row xl:justify-between">
                                <div className="max-w-4xl">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold text-stone-900">
                                            {submission.assignment?.campaign?.title ?? 'Campaign'}
                                        </h2>
                                        <span className="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                                            {submission.status}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm text-stone-500">
                                        Freelancer: {submission.freelancer?.name ?? 'Unknown'} · Client:{' '}
                                        {submission.client?.name ?? 'Unknown'}
                                    </p>
                                    <p className="mt-3 text-sm leading-6 text-stone-500">
                                        {submission.assignment?.campaign?.target_url ?? 'No campaign URL attached'}
                                    </p>

                                    <div className="mt-5 grid gap-3 md:grid-cols-2">
                                        <InfoPanel
                                            title="Proof evidence"
                                            items={[]}
                                            proofs={submission.proofs}
                                        />
                                        <InfoPanel
                                            title="Decision trail"
                                            items={submission.review_decisions.map(
                                                (decision) =>
                                                    `${decision.actor_role} · ${decision.decision}${decision.note ? ` · ${decision.note}` : ''}`
                                            )}
                                        />
                                    </div>
                                </div>

                                <div className="min-w-80 rounded-3xl border border-stone-200 bg-stone-50 p-4">
                                    <div className="grid gap-3">
                                        <button
                                            type="button"
                                            disabled={processingSubmissionId === submission.id}
                                            className="rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-600"
                                            onClick={() => approveSubmission(submission.id)}
                                        >
                                            {processingSubmissionId === submission.id ? 'Processing...' : 'Approve and post payout'}
                                        </button>
                                        <button
                                            type="button"
                                            disabled={processingSubmissionId === submission.id}
                                            className="rounded-2xl bg-rose-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-rose-600"
                                            onClick={() => rejectSubmission(submission.id)}
                                        >
                                            Reject submission
                                        </button>
                                    </div>

                                    <div className="mt-5 grid gap-3 text-sm">
                                        <SummaryMetric
                                            icon={CheckCircle2}
                                            label="Client charge"
                                            value={`₦${Number(
                                                submission.assignment?.campaign?.pricing?.client_unit_price ?? 0,
                                            ).toLocaleString()}`}
                                        />
                                        <SummaryMetric
                                            icon={ClipboardCheck}
                                            label="Freelancer payout"
                                            value={`₦${Number(
                                                submission.assignment?.campaign?.pricing?.freelancer_unit_payout ?? 0,
                                            ).toLocaleString()}`}
                                        />
                                        <SummaryMetric
                                            icon={ShieldX}
                                            label="Platform margin"
                                            value={`₦${Number(
                                                submission.assignment?.campaign?.pricing?.platform_margin ?? 0,
                                            ).toLocaleString()}`}
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

function InfoPanel({
    title,
    items,
    proofs,
}: {
    title: string;
    items: string[];
    proofs?: SubmissionItem['proofs'];
}) {
    return (
        <div className="rounded-2xl border border-stone-200 bg-stone-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-stone-400">{title}</p>
            {proofs && proofs.length > 0 ? (
                <div className="mt-3 grid gap-3">
                    {proofs.map((proof) => (
                        <div key={proof.id} className="overflow-hidden rounded-2xl border border-stone-200 bg-white">
                            <img
                                src={`/storage/${proof.file_path}`}
                                alt={`${proof.type} proof`}
                                className="h-48 w-full bg-stone-100 object-cover"
                            />
                            <div className="border-t border-stone-200 px-3 py-2 text-xs text-stone-500">
                                {proof.type} · {proof.source} · {proof.file_path}
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="mt-3 space-y-2">
                    {(items.length ? items : ['No records yet.']).map((item) => (
                        <p key={item} className="text-sm leading-6 text-stone-600">
                            {item}
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}

function SummaryMetric({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof CheckCircle2;
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
