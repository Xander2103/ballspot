export type NoticePlacement = 'home_daily_card';
export type NoticeType = 'info' | 'warning' | 'success';

/** GET /api/notices/active → { notice: AppNotice | null }. Message is already localized. */
export interface AppNotice {
  placement: NoticePlacement;
  type: NoticeType;
  message: string;
}

export interface ActiveNoticeResponse {
  notice: AppNotice | null;
}
