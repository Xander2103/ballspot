/**
 * Turn whatever the API client threw into one clean sentence for the user.
 *
 * The client (src/api/client.ts) throws `{ status, ...jsonBody }` for HTTP
 * errors and a raw `TypeError` for network failures. Laravel's bodies look
 * like `{ message }` or `{ message, errors: { field: [msg] } }`. A 500 with
 * APP_DEBUG=false is `{ message: "Server Error" }` — technical noise we never
 * want on screen. Nothing here ever surfaces a stack trace or exception class.
 */

export type ApiErrorLike = {
  status?: number;
  message?: unknown;
  errors?: Record<string, unknown>;
  retry_after?: number;
};

export const NETWORK_ERROR_MESSAGE =
  'Could not reach BallPicker. Check your connection and try again.';

export const SERVER_ERROR_MESSAGE =
  'Something went wrong on our side. Please try again in a moment.';

export const UNAUTHORIZED_MESSAGE = 'Your session has expired. Please log in again.';

/** Messages Laravel/fetch produce that mean nothing to a player. */
const TECHNICAL_MESSAGES = [
  'server error',
  'request failed',
  'network request failed',
  'failed to fetch',
  'load failed',
  'the given data was invalid.',
  'unauthenticated.',
];

function firstValidationError(errors: unknown): string | null {
  if (!errors || typeof errors !== 'object') return null;
  for (const value of Object.values(errors as Record<string, unknown>)) {
    if (Array.isArray(value) && typeof value[0] === 'string' && value[0].trim()) {
      return value[0].trim();
    }
    if (typeof value === 'string' && value.trim()) return value.trim();
  }
  return null;
}

function looksTechnical(message: string): boolean {
  const lower = message.toLowerCase().trim();
  if (TECHNICAL_MESSAGES.includes(lower)) return true;
  // Exception dumps / stack traces / HTML error pages.
  return (
    /exception|stack trace|sqlstate|\bat\s+\S+\.(php|js|ts):\d+/i.test(message) ||
    /^<!doctype|^<html/i.test(lower) ||
    message.length > 300
  );
}

export function isNetworkError(e: unknown): boolean {
  if (e instanceof TypeError) return true;
  const msg = (e as { message?: unknown })?.message;
  if (typeof msg !== 'string') return false;
  const lower = msg.toLowerCase();
  return lower.includes('network request failed') || lower.includes('failed to fetch') || lower === 'load failed';
}

/**
 * @param e        whatever was caught
 * @param fallback shown when the error carries no usable message
 */
export function getApiErrorMessage(e: unknown, fallback: string): string {
  if (isNetworkError(e)) return NETWORK_ERROR_MESSAGE;

  const err = (e && typeof e === 'object' ? e : {}) as ApiErrorLike;
  const status = typeof err.status === 'number' ? err.status : undefined;

  // Validation errors: the first field message is the most useful thing we have.
  const validation = firstValidationError(err.errors);
  if (validation) return validation;

  if (status === 401) return UNAUTHORIZED_MESSAGE;
  if (status !== undefined && status >= 500) return SERVER_ERROR_MESSAGE;

  const message = typeof err.message === 'string' ? err.message.trim() : '';
  if (message && !looksTechnical(message)) return message;

  if (typeof e === 'string' && e.trim() && !looksTechnical(e)) return e.trim();

  return fallback;
}
