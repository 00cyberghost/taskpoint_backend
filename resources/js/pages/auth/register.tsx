import { Head } from '@inertiajs/react';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import TextLink from '@/components/text-link';

export default function Register() {
    return (
        <AuthLayout
            title="Registration disabled"
            description="Admin access is created manually for this panel."
        >
            <Head title="Registration disabled" />
            <div className="space-y-6 text-center">
                <p className="text-sm text-muted-foreground">
                    Use the mobile apps for client and freelancer onboarding.
                    Admin users are seeded or promoted explicitly.
                </p>

                <TextLink href={login()} className="inline-flex justify-center">
                    Return to login
                </TextLink>
            </div>
        </AuthLayout>
    );
}
