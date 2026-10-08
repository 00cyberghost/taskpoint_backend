import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type FlashValue = string | undefined;

type NotifyPageProps = {
    flash?: {
        success?: FlashValue;
        error?: FlashValue;
        warning?: FlashValue;
        info?: FlashValue;
    };
    errors?: Record<string, string | string[]>;
};

type NotifyKind = 'success' | 'error' | 'warning' | 'info';

type Toast = {
    kind: NotifyKind;
    message: string;
};

function firstError(errors?: NotifyPageProps['errors']): string | undefined {
    const value = Object.values(errors ?? {})[0];

    return Array.isArray(value) ? value[0] : value;
}

export default function Notify({
    initialProps,
}: {
    initialProps: NotifyPageProps;
}) {
    const [toast, setToast] = useState<Toast | null>(null);

    useEffect(() => {
        const showToast = (kind: NotifyKind, message?: string) => {
            if (!message) {
                return;
            }

            setToast({
                kind,
                message,
            });
        };

        const showPageFeedback = (props: NotifyPageProps) => {
            const flash = props.flash;
            const error = firstError(props.errors);

            if (flash?.success) {
                showToast('success', flash.success);
            } else if (flash?.error) {
                showToast('error', flash.error);
            } else if (flash?.warning) {
                showToast('warning', flash.warning);
            } else if (flash?.info) {
                showToast('info', flash.info);
            } else if (error) {
                showToast('error', error);
            }
        };

        showPageFeedback(initialProps);

        const removeSuccessListener = router.on('success', (event) => {
            showPageFeedback(event.detail.page.props as NotifyPageProps);
        });
        const removeErrorListener = router.on('error', (event) => {
            showToast(
                'error',
                firstError(event.detail.errors as NotifyPageProps['errors']),
            );
        });

        return () => {
            removeSuccessListener();
            removeErrorListener();
        };
    }, [initialProps]);

    useEffect(() => {
        if (!toast) {
            return;
        }

        const timeout = window.setTimeout(() => setToast(null), 5000);

        return () => window.clearTimeout(timeout);
    }, [toast]);

    if (!toast) {
        return null;
    }

    const styles = {
        success: {
            icon: CheckCircle2,
            container: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        },
        error: {
            icon: AlertCircle,
            container: 'border-rose-200 bg-rose-50 text-rose-800',
        },
        warning: {
            icon: AlertCircle,
            container: 'border-amber-200 bg-amber-50 text-amber-800',
        },
        info: {
            icon: Info,
            container: 'border-sky-200 bg-sky-50 text-sky-800',
        },
    }[toast.kind];
    const Icon = styles.icon;

    return (
        <div
            className="pointer-events-none fixed inset-x-4 top-4 z-[100] flex justify-end sm:left-auto sm:max-w-md"
            aria-live="polite"
            aria-atomic="true"
        >
            <div
                role="status"
                className={`pointer-events-auto flex w-full items-start gap-3 rounded-2xl border px-4 py-3 text-sm font-medium shadow-lg shadow-stone-900/10 ${styles.container}`}
            >
                <Icon className="mt-0.5 size-5 shrink-0" />
                <p className="min-w-0 flex-1 leading-5">{toast.message}</p>
                <button
                    type="button"
                    className="shrink-0 rounded-lg p-1 opacity-70 transition hover:bg-black/5 hover:opacity-100"
                    onClick={() => setToast(null)}
                    aria-label="Dismiss notification"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
