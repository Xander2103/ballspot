import { apiClient } from './client';
import type { ActiveNoticeResponse, NoticePlacement } from '../types/notice';

export const noticesApi = {
  /** The admin-managed notice for a placement, or `{ notice: null }`. */
  active: (placement: NoticePlacement = 'home_daily_card') =>
    apiClient.request<ActiveNoticeResponse>(`/notices/active?placement=${encodeURIComponent(placement)}`),
};
