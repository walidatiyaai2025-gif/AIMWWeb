// Safe status-only SEO API errors. Laravel/provider response bodies and
// transport exception text are untrusted and must never reach browser feedback.
export function safeSeoRequestError(status: number): string {
    switch (status) {
        case 401: return 'SEO request requires sign-in again (HTTP 401).';
        case 403: return 'SEO request is not authorized (HTTP 403).';
        case 404: return 'SEO record is unavailable for this tenant or site (HTTP 404).';
        case 409: return 'SEO source state changed; refresh before retrying (HTTP 409).';
        case 422: return 'SEO request validation failed (HTTP 422). Review selected fields.';
        case 429: return 'SEO request rate limit reached (HTTP 429). Try again later.';
        case 500: return 'SEO server error (HTTP 500). Review Runtime Inspector for sanitized diagnostics.';
        case 502: return 'SEO upstream service unavailable (HTTP 502). Review Runtime Inspector.';
        case 503: return 'SEO service temporarily unavailable (HTTP 503).';
        case 504: return 'SEO request timed out (HTTP 504).';
        default: return 'SEO request failed (HTTP ' + (Number.isSafeInteger(status) && status >= 100 && status <= 599 ? status : 'unknown') + '). Review Runtime Inspector.';
    }
}

export const SEO_NETWORK_ERROR = 'SEO network request failed. Check connectivity and Runtime Inspector.';
