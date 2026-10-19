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

import { STAGING_UNCONFIGURED_API_BASE_URL, hostOf, variantFromExtra, type AppVariant } from './appVariant';

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

const PRODUCTION_HOST = hostOf(PRODUCTION_WEB_URL);

/**
 * Resolve both bases from an env-like object (process.env in the app).
 *
 * A STAGING build never falls back to production: a missing value, or one that
 * points at the production host, resolves to an unroutable `.invalid` sentinel
 * so the app fails closed (and the staging indicator says so) instead of
 * silently talking to production.
 */
export function resolveUrls(env: UrlEnv, variant: AppVariant = 'production'): { apiBaseUrl: string; webBaseUrl: string } {
  let apiBaseUrl = clean(env.EXPO_PUBLIC_API_BASE_URL) ?? PRODUCTION_API_BASE_URL;
  let webBaseUrl = clean(env.EXPO_PUBLIC_WEB_URL) ?? apiBaseUrl.replace(/\/api$/, '');

  if (variant === 'staging' && (hostOf(apiBaseUrl) === PRODUCTION_HOST || hostOf(webBaseUrl) === PRODUCTION_HOST)) {
    apiBaseUrl = STAGING_UNCONFIGURED_API_BASE_URL;
    webBaseUrl = STAGING_UNCONFIGURED_API_BASE_URL.replace(/\/api$/, '');
  }

  return { apiBaseUrl, webBaseUrl };
}

/**
 * The installed build's variant, from the embedded app config. Required lazily
 * so this module stays importable in the plain-TypeScript jest environment
 * (where expo-constants cannot load → production).
 */
function installedVariant(): AppVariant {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Constants = require('expo-constants').default;
    return variantFromExtra(Constants?.expoConfig?.extra);
  } catch {
    return 'production';
  }
}

export const APP_VARIANT: AppVariant = installedVariant();

const resolved = resolveUrls({
  EXPO_PUBLIC_API_BASE_URL: process.env.EXPO_PUBLIC_API_BASE_URL,
  EXPO_PUBLIC_WEB_URL: process.env.EXPO_PUBLIC_WEB_URL,
}, APP_VARIANT);

export const API_BASE_URL = resolved.apiBaseUrl;
export const WEB_BASE_URL = resolved.webBaseUrl;
