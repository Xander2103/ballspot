import { apiClient } from './client';
import { User, ProfileStats, AuthResponse, LoginResult, XpEventsResponse } from '../types/auth';

export const authApi = {
  register: (data: {
    name: string;
    username: string;
    email: string;
    password: string;
    /** Server records the consent moment; both are required by the API. */
    terms_accepted: boolean;
    age_confirmed: boolean;
    beta_code?: string;
  }) =>
    apiClient.request<AuthResponse>('/register', { method: 'POST', body: JSON.stringify(data) }),

  // Step 1: returns { requires_2fa, verification_id, ... } on success — no token yet.
  login: (data: { email: string; password: string }) =>
    apiClient.request<LoginResult>('/login', { method: 'POST', body: JSON.stringify(data) }),

  // Step 2: exchange the emailed code for a token.
  verifyLoginCode: (data: { verification_id: string; code: string }) =>
    apiClient.request<AuthResponse>('/login/verify', { method: 'POST', body: JSON.stringify(data) }),

  // Request a fresh code for an in-flight verification (cooldown-limited server-side).
  resendLoginCode: (data: { verification_id: string }) =>
    apiClient.request<{ message: string }>('/login/resend-code', { method: 'POST', body: JSON.stringify(data) }),

  // Email verification (registration step 2). Requires the auth token. The
  // optional email is a hint: the server answers 409 session_mismatch when the
  // token belongs to a different account than the one being verified.
  verifyEmail: (data: { code: string; email?: string }) =>
    apiClient.request<{ email_verified: boolean; user: User; message: string }>(
      '/email/verify', { method: 'POST', body: JSON.stringify(data) }
    ),

  resendEmailVerification: () =>
    apiClient.request<{ email_verified: boolean; email?: string; message: string }>(
      '/email/verification-notification', { method: 'POST' }
    ),

  // Which account the stored token belongs to + whether a code is live.
  verificationStatus: () =>
    apiClient.request<{
      email: string;
      email_verified: boolean;
      has_usable_code: boolean;
      can_resend: boolean;
      resend_available_in_seconds: number;
      code_expires_in_seconds: number;
    }>('/email/verification-status'),

  logout: () =>
    apiClient.request<{ message: string }>('/logout', { method: 'POST' }),

  forgotPassword: (data: { email: string }) =>
    apiClient.request<{ message: string }>('/forgot-password', { method: 'POST', body: JSON.stringify(data) }),

  resetPassword: (data: { email: string; token: string; password: string; password_confirmation: string }) =>
    apiClient.request<{ message: string }>('/reset-password', { method: 'POST', body: JSON.stringify(data) }),

  // GET /me returns a JsonResource → wrapped in { data: User }
  me: () =>
    apiClient.request<{ data: User }>('/me').then(r => r.data),

  stats: () =>
    apiClient.request<ProfileStats>('/profile/stats'),

  xpEvents: (limit = 10) =>
    apiClient.request<XpEventsResponse>(`/me/xp-events?limit=${limit}`),

  deleteAccount: () =>
    apiClient.request<{ message: string }>('/account', { method: 'DELETE' }),
};
