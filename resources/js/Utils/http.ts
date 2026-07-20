/**
 * Minimal JSON POST helper for the auth modal's step endpoints. Inertia v2
 * no longer bundles axios, so this wraps fetch with the CSRF token from the
 * page's meta tag. Laravel validation errors (422) are surfaced as
 * `ValidationError` with the same `errors` shape Inertia forms use.
 */

export class ValidationError extends Error {
    constructor(public errors: Record<string, string[]>) {
        super('Validation failed');
    }
}

export async function postJson<T = Record<string, unknown>>(
    url: string,
    data: Record<string, unknown>,
): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(data),
    });

    if (response.status === 422) {
        const body = (await response.json()) as { errors?: Record<string, string[]> };
        throw new ValidationError(body.errors ?? {});
    }

    if (response.status === 429) {
        throw new ValidationError({ identifier: ['Too many attempts. Please wait a minute and try again.'] });
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
}

export function firstError(error: unknown): string {
    if (error instanceof ValidationError) {
        const first = Object.values(error.errors)[0];
        if (first && first.length > 0) return first[0];
    }

    return 'Something went wrong. Please try again.';
}
