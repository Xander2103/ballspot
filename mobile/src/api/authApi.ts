import { apiClient } from './client';
import { User, ProfileStats } from '../types/auth';

interface AuthResponse {
  user: User;
  token: string;
}

export const authApi = {
  register: (data: { name: string; username: string; email: string; password: string }) =>
    apiClient.request<AuthResponse>('/register', { method: 'POST', body: JSON.stringify(data) }),

  login: (data: { email: string; password: string }) =>
    apiClient.request<AuthResponse>('/login', { method: 'POST', body: JSON.stringify(data) }),

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
