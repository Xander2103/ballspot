import { tokenStorage } from '../storage/tokenStorage';

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
    // Let the runtime set the multipart boundary itself for FormData uploads.
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    ...(options.headers as Record<string, string>),
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers,
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Request failed' }));

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
        message: `Too many attempts. Try again in ${retryAfter} seconds.`,
      };
    }

    throw { status: response.status, ...error };
  }

  if (response.status === 204) return undefined as T;

  return response.json();
}

export const apiClient = { request };
