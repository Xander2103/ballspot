import { tokenStorage } from '../storage/tokenStorage';
import { getLocale, translate } from '../i18n/core';
import {
  emitSessionInvalid, isSessionInvalidResponse, sessionInvalidReasonFor,
  parseJsonBody, describeApiFailure, formatApiFailure, MALFORMED_RESPONSE,
} from '../utils/session';
import { devLog } from '../utils/devLog';

/**
 * Read a body as JSON. Tolerates a leading BOM and an empty body (see
 * parseJsonBody); anything else unreadable resolves to `null` for error
 * responses (the status is what matters there) and rejects with a stable
 * `malformed_response` code for successful ones.
 */
async function readBody(response: Response, path: string, method: string): Promise<unknown> {
  const text = await response.text();
  try {
    return parseJsonBody(text);
  } catch {
    if (!response.ok) return null;
    devLog(`[api] ${method} ${path} -> ${response.status} ${MALFORMED_RESPONSE} (unreadable body, ${text.length} bytes)`);
    throw { status: response.status, code: MALFORMED_RESPONSE, message: translate('errors.server') };
  }
}

import { API_BASE_URL } from '../utils/urls';

// For physical device testing set EXPO_PUBLIC_API_BASE_URL in mobile/.env to
// your computer's LAN IP, e.g. http://192.168.1.x:8000/api (see .env.example).

/** A request that gets no answer at all must fail like a network error, not spin forever. */
const REQUEST_TIMEOUT_MS = 20000;

async function fetchWithTimeout(input: string, init: RequestInit): Promise<Response> {
  if (typeof AbortController === 'undefined') return fetch(input, init);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    return await fetch(input, { ...init, signal: controller.signal });
  } catch (e: unknown) {
    // An abort surfaces as the same network-style failure the app already handles.
    if ((e as { name?: string })?.name === 'AbortError') throw new TypeError('Network request failed');
    throw e;
  } finally {
    clearTimeout(timer);
  }
}

async function request<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const token = await tokenStorage.get();
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    // The backend localizes validation/auth messages from this header (the
    // signed-in user's preferred_language wins server-side).
    'Accept-Language': getLocale(),
    // Let the runtime set the multipart boundary itself for FormData uploads.
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    ...(options.headers as Record<string, string>),
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const method = (options.method ?? 'GET').toUpperCase();
  let response: Response;
  try {
    response = await fetchWithTimeout(`${API_BASE_URL}${path}`, {
      ...options,
      headers,
    });
  } catch (e: unknown) {
    devLog(formatApiFailure(describeApiFailure(e, `${method} ${path}`)));
    throw e;
  }

  if (!response.ok) {
    const parsed = await readBody(response, path, method);
    const error: Record<string, unknown> =
      parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : { message: 'Request failed' };
    devLog(formatApiFailure(describeApiFailure({ status: response.status, ...error }, `${method} ${path}`)));

    // Rate limited: surface a clear, actionable message with the wait time
    // (server sends Retry-After + a retry_after JSON field).
    if (response.status === 429) {
      const headerRetry = Number(response.headers.get('Retry-After'));
      const retryAfter =
        Number.isFinite(headerRetry) && headerRetry > 0
          ? headerRetry
          : typeof error?.retry_after === 'number'
            ? error.retry_after
            : 60;
      throw {
        status: 429,
        retry_after: retryAfter,
        message: translate('errors.rateLimited', { seconds: retryAfter }),
      };
    }

    // A dead session (401, or the backend's account_deleted / session_invalid
    // codes) on any authenticated call: tell the navigator, which clears the
    // stored token and returns to Login with a clear message. The caller still
    // gets its rejection so in-flight screens settle normally.
    if (isSessionInvalidResponse(response.status, error, path, !!token)) {
      emitSessionInvalid(sessionInvalidReasonFor(error));
    }

    throw { status: response.status, ...error };
  }

  if (response.status === 204) return undefined as T;

  return (await readBody(response, path, method)) as T;
}

export const apiClient = { request };
