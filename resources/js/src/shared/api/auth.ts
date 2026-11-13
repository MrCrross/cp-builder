export type Role = 'administrator' | 'manager' | 'viewer';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Разрешения текущего пользователя (spatie permission names). */
    permissions: string[];
};

export type Flash = {
    status?: string | null;
    toast?: import('./ui').FlashToast | null;
};
