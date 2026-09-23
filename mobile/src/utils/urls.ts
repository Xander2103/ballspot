/**
 * Backend URLs. Pure TypeScript (jest-tested) so the fallback policy is pinned:
 *
 *  - EAS profiles set EXPO_PUBLIC_API_BASE_URL / EXPO_PUBLIC_WEB_URL
 *    (mobile/eas.json), and the local .env sets them for development.
 *  - When a build is produced WITHOUT them (a local release build, a stray
 *    `expo export`, an edited profile) the app must fail SAFE, not fail LOCAL:
 *    the fallback is the production https origin, never http://127.0.0.1 —
 *    a release binary pointing at localhost "just doesn't load" (iOS ATS
 *    blocks the plaintext call) with no error to act on.
 *  - The web origin (legal pages, deep-link prefix) is derived from the same
 *    value in ONE place so Profile, Register and the navigator can never
 *    disagree.
 */

export const PRODUCTION_WEB_URL = 'https://ballpicker.vanmalderstudio.be';
export const PRODUCTION_API_BASE_URL = `${PRODUCTION_WEB_URL}/api`;

export interface UrlEnv {
  EXPO_PUBLIC_API_BASE_URL?: string;
  EXPO_PUBLIC_WEB_URL?: string;
}

function clean(value: string | undefined): string | null {
  const v = (value ?? '').trim().replace(/\/+$/, '');
  return v ? v : null;
}

/** Resolve both bases from an env-like object (process.env in the app). */
export function resolveUrls(env: UrlEnv): { apiBaseUrl: string; webBaseUrl: string } {
  const apiBaseUrl = clean(env.EXPO_PUBLIC_API_BASE_URL) ?? PRODUCTION_API_BASE_URL;
  const webBaseUrl = clean(env.EXPO_PUBLIC_WEB_URL) ?? apiBaseUrl.replace(/\/api$/, '');
  return { apiBaseUrl, webBaseUrl };
}

const resolved = resolveUrls({
  EXPO_PUBLIC_API_BASE_URL: process.env.EXPO_PUBLIC_API_BASE_URL,
  EXPO_PUBLIC_WEB_URL: process.env.EXPO_PUBLIC_WEB_URL,
});

export const API_BASE_URL = resolved.apiBaseUrl;
export const WEB_BASE_URL = resolved.webBaseUrl;
