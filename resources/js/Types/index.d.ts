export interface AuthenticatedUser {
    uuid: string;
    name: string;
    email: string;
    phone: string;
    roles: string[];
    permissions: string[];
}

export interface PageProps {
    auth: {
        user: AuthenticatedUser | null;
    };
    flash: {
        success?: string;
        error?: string;
    };
    [key: string]: unknown;
}
