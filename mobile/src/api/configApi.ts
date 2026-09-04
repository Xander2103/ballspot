import { apiClient } from './client';

/** Public, unauthenticated feature flags (GET /api/config). Booleans only. */
export interface AppConfig {
  app_name: string;
  /** True during the private beta (backend requires a beta code to register). */
  beta_gate: boolean;
  /** False when the backend no longer sends/expects a verification code. */
  email_verification_required: boolean;
  minimum_age: number;
  terms_version: string;
}

/** Safe defaults used while the request is in flight or when it fails. */
export const DEFAULT_APP_CONFIG: AppConfig = {
  app_name: 'BallPicker',
  beta_gate: false,
  email_verification_required: true,
  minimum_age: 16,
  terms_version: '',
};

export const configApi = {
  get: () => apiClient.request<AppConfig>('/config'),
};
