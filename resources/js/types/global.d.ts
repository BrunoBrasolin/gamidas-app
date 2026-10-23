import type { Auth } from '@/types/auth';

export type SharedHousehold = {
    id: number;
    name: string;
    members: { user_id: number; name: string; first_name: string }[];
};

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            household: SharedHousehold | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
