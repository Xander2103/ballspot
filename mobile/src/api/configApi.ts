import { apiClient } from './client';

/** Public, unauthenticated feature flags (GET /api/config). Booleans only. */
export interface AppConfig {
  app_name: string;
  /** True during the private beta (backend requires a beta code to register). */
  beta_gate: boolean;
  /** False when the backend no longer sends/expects a verification code. */
  email_verification_required: boolean;
  /**
   * True when users may switch the emailed login code (2FA) on in Profile.
   * False for launch: the toggle is hidden and the backend refuses new
   * opt-ins. Absent on older backends — treated as false (fail closed).
   */
  login_two_factor_opt_in?: boolean;
  minimum_age: number;
  terms_version: string;
  /** Language codes the backend accepts for preferred_language. */
  supported_languages?: string[];
  default_language?: string;
  /**
   * Registration CAPTCHA (v1.9.13). False for launch. When true the backend
   * requires captcha_token on /register; the app does not render a widget yet
   * (see docs/security-hardening.md) so this must stay false until it does.
   */
  captcha_required?: boolean;
  captcha_provider?: string;
  /** Public widget key when captcha_required; null otherwise. */
  captcha_site_key?: string | null;
  /**
   * Feature flags. `clubs` shows the Clubs tab. Absent on older backends —
   * treated as off (fail closed, see utils/features.ts).
   */
  features?: { clubs?: boolean };
}

/** Safe defaults used while the request is in flight or when it fails. */
export const DEFAULT_APP_CONFIG: AppConfig = {
  app_name: 'BallPicker',
  beta_gate: false,
  email_verification_required: true,
  login_two_factor_opt_in: false,
  minimum_age: 16,
  terms_version: '',
  supported_languages: ['nl', 'en', 'fr', 'de', 'es'],
  default_language: 'en',
  captcha_required: false,
  captcha_site_key: null,
  features: { clubs: false },
};

export const configApi = {
  get: () => apiClient.request<AppConfig>('/config'),
};
