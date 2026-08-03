import { apiClient } from './client';
import type {
  FriendRequestItem, FriendRequestsResponse, FriendSummary, PublicProfile,
} from '../types/friend';

export const friendsApi = {
  myCode: () =>
    apiClient.request<{ friend_code: string }>('/me/friend-code').then((r) => r.friend_code),

  list: () =>
    apiClient.request<{ data: FriendSummary[] }>('/friends').then((r) => r.data),

  requests: () =>
    apiClient.request<FriendRequestsResponse>('/friends/requests'),

  sendRequest: (friendCode: string) =>
    apiClient.request<{ data: FriendRequestItem }>('/friends/requests', {
      method: 'POST',
      body: JSON.stringify({ friend_code: friendCode.trim().toUpperCase() }),
    }).then((r) => r.data),

  accept: (requestId: number) =>
    apiClient.request<{ data: FriendSummary }>(`/friends/requests/${requestId}/accept`, { method: 'POST' })
      .then((r) => r.data),

  reject: (requestId: number) =>
    apiClient.request<{ message: string }>(`/friends/requests/${requestId}/reject`, { method: 'POST' }),

  remove: (userId: number) =>
    apiClient.request<void>(`/friends/${userId}`, { method: 'DELETE' }),

  publicProfile: (userId: number) =>
    apiClient.request<{ data: PublicProfile }>(`/users/${userId}/public-profile`).then((r) => r.data),
};
