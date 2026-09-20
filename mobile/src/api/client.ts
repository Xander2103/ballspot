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

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api';

// For physical device testing, replace 127.0.0.1 with your computer's LAN IP address
// e.g. http://192.168.1.x:8000/api

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
    response = await fetch(`${API_BASE_URL}${path}`, {
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
