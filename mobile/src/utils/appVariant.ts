/**
 * App variant (pure TypeScript, jest-tested).
 *
 * `app.config.js` builds a separate STAGING app identity when APP_VARIANT=staging
 * (own bundle id / package, name "BallPicker STG", extra.appVariant = 'staging').
 * Production builds keep app.json unchanged and carry no appVariant, so anything
 * unknown is treated as production — the staging-only behaviour (indicator,
 * refusal to fall back to the production API) can never switch on by accident.
 */

export type AppVariant = 'production' | 'staging';

export const STAGING_BUNDLE_ID = 'be.vanmalderstudio.ballpicker.staging';

/** Sentinel API base used by a staging build that has no staging URL configured. */
export const STAGING_UNCONFIGURED_API_BASE_URL = 'https://staging-api-not-configured.invalid/api';

/** Variant from the embedded app config's `extra` (expo-constants `expoConfig.extra`). */
export function variantFromExtra(extra: unknown): AppVariant {
  if (extra && typeof extra === 'object' && (extra as Record<string, unknown>).appVariant === 'staging') {
    return 'staging';
  }
  return 'production';
}

/** Host shown in the staging indicator ("api.example.com"), or null when unparsable. */
export function hostOf(url: string): string | null {
  const m = /^https?:\/\/([^/:?#]+)/i.exec(url.trim());
  return m ? m[1].toLowerCase() : null;
}
