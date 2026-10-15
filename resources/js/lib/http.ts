/** Laravel's CSRF token from the XSRF-TOKEN cookie, for plain fetch() calls. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A non-2xx JSON response. `message` is the server's (first validation) message. */
export class HttpError extends Error {
    constructor(
        message: string,
        public status: number,
        public data: Record<string, unknown>,
    ) {
        super(message);
    }
}

type Method = 'POST' | 'DELETE';

/**
 * Send JSON or FormData to a Laravel endpoint and parse the JSON reply.
 * Throws HttpError on a non-2xx status, TypeError when the network is down.
 */
export async function requestJson<T>(
    method: Method,
    url: string,
    body?: FormData | object,
): Promise<T> {
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(isForm || body === undefined
                ? {}
                : { 'Content-Type': 'application/json' }),
        },
    });

    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = json.errors
            ? (Object.values(json.errors)[0] as string[])[0]
            : undefined;

        throw new HttpError(
            firstError ?? json.message ?? 'Permintaan gagal. Coba lagi.',
            response.status,
            json,
        );
    }

    return json as T;
}

export const postJson = <T>(url: string, body: FormData | object) =>
    requestJson<T>('POST', url, body);

/** Human message for any error thrown by requestJson. */
export function errorMessage(error: unknown): string {
    if (error instanceof HttpError) {
        return error.message;
    }

    return 'Koneksi terputus. Cek jaringan lalu coba lagi.';
}
