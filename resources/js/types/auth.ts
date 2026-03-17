export type User = {
    id: number;
    name: string;
    email: string;
    role?: string;
    status?: string;
    phone?: string | null;
    registration_country?: string | null;
    last_login_country?: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
