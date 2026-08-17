import { Head, router } from '@inertiajs/react';
import { CreditCard, Landmark, Wallet } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type WithdrawalItem = {
    id: number;
    amount: string;
    destination_type: string;
    status: string;
    requested_at: string | null;
    freelancer: { name: string; email: string } | null;
};

type FundingRequestItem = {
    id: number;
    amount: string;
    payment_method: string;
    status: string;
    submitted_at: string | null;
    client: { name: string; email: string } | null;
    note: string | null;
};

type TransactionItem = {
    id: number;
    transaction_type: string;
    direction: string;
    amount: string;
    status: string;
    description: string | null;
    user: { name: string; email: string } | null;
};

type PaymentSetting = {
    active_method: string;
    manual_enabled?: boolean;
    stripe_enabled?: boolean;
    paystack_enabled?: boolean;
    flutterwave_enabled?: boolean;
    manual_bank_name: string | null;
    manual_account_name: string | null;
    manual_account_number: string | null;
    stripe_public_key?: string | null;
    stripe_secret_key?: string | null;
    paystack_public_key?: string | null;
    paystack_secret_key?: string | null;
    flutterwave_public_key?: string | null;
    flutterwave_secret_key?: string | null;
} | null;

type Props = {
    paymentSetting: PaymentSetting;
    withdrawals: {
        data: WithdrawalItem[];
    };
    fundingRequests: FundingRequestItem[];
    transactions: TransactionItem[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Finance', href: '/admin/finance' },
];

export default function AdminFinance({ paymentSetting, withdrawals, fundingRequests, transactions }: Props) {
    const [manualEnabled, setManualEnabled] = useState(paymentSetting?.manual_enabled ?? true);
    const [stripeEnabled, setStripeEnabled] = useState(paymentSetting?.stripe_enabled ?? false);
    const [paystackEnabled, setPaystackEnabled] = useState(paymentSetting?.paystack_enabled ?? false);
    const [flutterwaveEnabled, setFlutterwaveEnabled] = useState(paymentSetting?.flutterwave_enabled ?? false);
    const [bankName, setBankName] = useState(paymentSetting?.manual_bank_name ?? '');
    const [accountName, setAccountName] = useState(paymentSetting?.manual_account_name ?? '');
    const [accountNumber, setAccountNumber] = useState(paymentSetting?.manual_account_number ?? '');
    const [stripePublicKey, setStripePublicKey] = useState(paymentSetting?.stripe_public_key ?? '');
    const [stripeSecretKey, setStripeSecretKey] = useState(paymentSetting?.stripe_secret_key ?? '');
    const [paystackPublicKey, setPaystackPublicKey] = useState(paymentSetting?.paystack_public_key ?? '');
    const [paystackSecretKey, setPaystackSecretKey] = useState(paymentSetting?.paystack_secret_key ?? '');
    const [flutterwavePublicKey, setFlutterwavePublicKey] = useState(paymentSetting?.flutterwave_public_key ?? '');
    const [flutterwaveSecretKey, setFlutterwaveSecretKey] = useState(paymentSetting?.flutterwave_secret_key ?? '');

    const enabledMethods = useMemo(() => {
        return [
            manualEnabled ? 'Manual' : null,
            stripeEnabled ? 'Stripe' : null,
            paystackEnabled ? 'Paystack' : null,
            flutterwaveEnabled ? 'Flutterwave' : null,
        ].filter(Boolean);
    }, [flutterwaveEnabled, manualEnabled, paystackEnabled, stripeEnabled]);

    function savePaymentSetting() {
        router.patch(
            '/admin/finance/payment-setting',
            {
                manual_enabled: manualEnabled,
                stripe_enabled: stripeEnabled,
                paystack_enabled: paystackEnabled,
                flutterwave_enabled: flutterwaveEnabled,
                manual_bank_name: bankName,
                manual_account_name: accountName,
                manual_account_number: accountNumber,
                stripe_public_key: stripePublicKey,
                stripe_secret_key: stripeSecretKey,
                paystack_public_key: paystackPublicKey,
                paystack_secret_key: paystackSecretKey,
                flutterwave_public_key: flutterwavePublicKey,
                flutterwave_secret_key: flutterwaveSecretKey,
            },
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance & Payouts" />

            <div className="flex flex-1 flex-col gap-6 rounded-2xl bg-stone-50 p-4 md:p-6">
                <section className="rounded-3xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="rounded-2xl bg-emerald-50 p-3 text-emerald-600">
                            <Wallet className="size-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold text-stone-900">Finance & Payout Operations</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-500">
                                Configure manual and automatic funding options, approve manual wallet top-ups, and manage payouts.
                            </p>
                            <p className="mt-3 text-xs font-semibold uppercase tracking-[0.24em] text-stone-400">
                                Enabled now: {enabledMethods.length > 0 ? enabledMethods.join(', ') : 'None'}
                            </p>
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <div className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                        <div className="mb-5 flex items-center gap-3">
                            <div className="rounded-2xl bg-blue-50 p-3 text-blue-600">
                                <Landmark className="size-5" />
                            </div>
                            <div>
                                <h2 className="text-lg font-semibold text-stone-900">Payment Method Setup</h2>
                                <p className="text-sm text-stone-500">
                                    Toggle any client funding method on or off and manage each provider credential here.
                                </p>
                            </div>
                        </div>

                        <div className="space-y-5">
                            <GatewayCard
                                title="Manual Transfer"
                                description="Bank transfer with admin approval."
                                enabled={manualEnabled}
                                onToggle={setManualEnabled}
                            >
                                <Input label="Bank Name" value={bankName} onChange={setBankName} />
                                <Input label="Account Name" value={accountName} onChange={setAccountName} />
                                <Input label="Account Number" value={accountNumber} onChange={setAccountNumber} />
                            </GatewayCard>

                            <GatewayCard
                                title="Stripe"
                                description="Checkout-based automatic funding."
                                enabled={stripeEnabled}
                                onToggle={setStripeEnabled}
                            >
                                <Input label="Stripe Public Key" value={stripePublicKey} onChange={setStripePublicKey} />
                                <Input label="Stripe Secret Key" value={stripeSecretKey} onChange={setStripeSecretKey} />
                            </GatewayCard>

                            <GatewayCard
                                title="Paystack"
                                description="NGN-friendly hosted checkout."
                                enabled={paystackEnabled}
                                onToggle={setPaystackEnabled}
                            >
                                <Input label="Paystack Public Key" value={paystackPublicKey} onChange={setPaystackPublicKey} />
                                <Input label="Paystack Secret Key" value={paystackSecretKey} onChange={setPaystackSecretKey} />
                            </GatewayCard>

                            <GatewayCard
                                title="Flutterwave"
                                description="Hosted checkout with local rails."
                                enabled={flutterwaveEnabled}
                                onToggle={setFlutterwaveEnabled}
                            >
                                <Input
                                    label="Flutterwave Public Key"
                                    value={flutterwavePublicKey}
                                    onChange={setFlutterwavePublicKey}
                                />
                                <Input
                                    label="Flutterwave Secret Key"
                                    value={flutterwaveSecretKey}
                                    onChange={setFlutterwaveSecretKey}
                                />
                            </GatewayCard>

                            <button
                                type="button"
                                className="w-full rounded-2xl bg-stone-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-stone-700"
                                onClick={savePaymentSetting}
                            >
                                Save payment settings
                            </button>
                        </div>
                    </div>

                    <div className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                        <div className="mb-5 flex items-center gap-3">
                            <div className="rounded-2xl bg-orange-50 p-3 text-orange-600">
                                <CreditCard className="size-5" />
                            </div>
                            <div>
                                <h2 className="text-lg font-semibold text-stone-900">Client Funding Requests</h2>
                                <p className="text-sm text-stone-500">Manual transfers stay here for approval. Automatic ones self-settle after verification.</p>
                            </div>
                        </div>

                        <div className="space-y-4">
                            {fundingRequests.map((request) => (
                                <div key={request.id} className="rounded-2xl border border-stone-200 p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-stone-900">
                                                {request.client?.name ?? 'Unknown client'}
                                            </p>
                                            <p className="mt-1 text-sm text-stone-500">
                                                {request.client?.email ?? 'No email'} · {request.payment_method}
                                            </p>
                                        </div>
                                        <span className="rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-700">
                                            {request.status}
                                        </span>
                                    </div>

                                    <p className="mt-4 text-xl font-semibold text-stone-900">
                                        ₦{Number(request.amount).toLocaleString()}
                                    </p>
                                    {request.note ? <p className="mt-2 text-sm text-stone-500">{request.note}</p> : null}

                                    <div className="mt-4 flex gap-2">
                                        <ActionButton
                                            label="Approve"
                                            onClick={() =>
                                                router.patch(
                                                    `/admin/finance/funding-requests/${request.id}`,
                                                    { status: 'approved' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                        <ActionButton
                                            label="Reject"
                                            tone="stone"
                                            onClick={() =>
                                                router.patch(
                                                    `/admin/finance/funding-requests/${request.id}`,
                                                    { status: 'rejected' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[1fr_1.2fr]">
                    <div className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-stone-900">Withdrawal Queue</h2>
                        <div className="mt-5 space-y-4">
                            {withdrawals.data.map((withdrawal) => (
                                <div key={withdrawal.id} className="rounded-2xl border border-stone-200 p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-stone-900">
                                                {withdrawal.freelancer?.name ?? 'Unknown freelancer'}
                                            </p>
                                            <p className="mt-1 text-sm text-stone-500">
                                                {withdrawal.freelancer?.email ?? 'No email'} · {withdrawal.destination_type}
                                            </p>
                                        </div>
                                        <span className="rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-700">
                                            {withdrawal.status}
                                        </span>
                                    </div>

                                    <div className="mt-4 flex items-center justify-between">
                                        <p className="text-xl font-semibold text-stone-900">
                                            ₦{Number(withdrawal.amount).toLocaleString()}
                                        </p>
                                        <div className="flex gap-2">
                                            {['under_review', 'approved', 'paid'].map((status) => (
                                                <button
                                                    key={status}
                                                    type="button"
                                                    className="rounded-xl bg-stone-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-stone-700"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/admin/finance/withdrawals/${withdrawal.id}`,
                                                            { status },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {status}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-stone-900">Recent Ledger Activity</h2>

                        <div className="mt-5 space-y-3">
                            {transactions.map((transaction) => (
                                <div key={transaction.id} className="rounded-2xl border border-stone-200 p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-stone-900">
                                                {transaction.transaction_type}
                                            </p>
                                            <p className="mt-1 text-sm text-stone-500">
                                                {transaction.user?.name ?? 'Unknown user'} · {transaction.direction}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-base font-semibold text-stone-900">
                                                ₦{Number(transaction.amount).toLocaleString()}
                                            </p>
                                            <p className="mt-1 text-xs text-stone-400">{transaction.status}</p>
                                        </div>
                                    </div>
                                    {transaction.description ? (
                                        <p className="mt-3 text-sm leading-6 text-stone-500">{transaction.description}</p>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

function GatewayCard({
    title,
    description,
    enabled,
    onToggle,
    children,
}: {
    title: string;
    description: string;
    enabled: boolean;
    onToggle: (value: boolean) => void;
    children: ReactNode;
}) {
    return (
        <div className="rounded-3xl border border-stone-200 p-4">
            <div className="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 className="text-sm font-semibold text-stone-900">{title}</h3>
                    <p className="mt-1 text-sm leading-6 text-stone-500">{description}</p>
                </div>
                <button
                    type="button"
                    onClick={() => onToggle(!enabled)}
                    className={`rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-wide transition ${
                        enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-200 text-stone-600'
                    }`}
                >
                    {enabled ? 'Enabled' : 'Disabled'}
                </button>
            </div>
            <div className="space-y-4">{children}</div>
        </div>
    );
}

function Input({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="block">
            <span className="mb-2 block text-sm font-medium text-stone-700">{label}</span>
            <input
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="w-full rounded-2xl border border-stone-200 px-4 py-3 text-sm text-stone-900 outline-none transition focus:border-stone-400"
            />
        </label>
    );
}

function ActionButton({
    label,
    onClick,
    tone = 'orange',
}: {
    label: string;
    onClick: () => void;
    tone?: 'orange' | 'stone';
}) {
    const toneClasses = {
        orange: 'bg-orange-500 text-white hover:bg-orange-600',
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
