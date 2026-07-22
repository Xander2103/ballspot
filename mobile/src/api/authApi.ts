import { apiClient } from './client';
import { User, ProfileStats, AuthResponse, LoginResult } from '../types/auth';

export const authApi = {
  register: (data: { name: string; username: string; email: string; password: string }) =>
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

  // Email verification (registration step 2). Requires the auth token.
  verifyEmail: (data: { code: string }) =>
    apiClient.request<{ email_verified: boolean; user: User; message: string }>(
      '/email/verify', { method: 'POST', body: JSON.stringify(data) }
    ),

  resendEmailVerification: () =>
    apiClient.request<{ email_verified: boolean; message: string }>(
      '/email/verification-notification', { method: 'POST' }
    ),

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

  deleteAccount: () =>
    apiClient.request<{ message: string }>('/account', { method: 'DELETE' }),
};
