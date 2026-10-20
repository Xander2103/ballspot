/**
 * Friends screen decisions — pure TypeScript (no React Native) so they run
 * under the plain ts-jest runner (see __tests__/friendsModel.test.ts). The
 * screen and components/friends only render what these return.
 */

import type { FriendRequestItem, FriendSummary } from '../types/friend';

export type FriendsTab = 'friends' | 'requests' | 'discover';

/** Same shape the backend accepts (FriendController@store: 4–12 chars), letters and digits only. */
const FRIEND_CODE = /^[A-Z0-9]{4,12}$/;

/** What the user typed, the way the backend compares it (trimmed, upper case, inner spaces dropped). */
export function normalizeFriendCode(input: string): string {
  return input.replace(/\s+/g, '').toUpperCase();
}

export type FriendCodeCheck =
  | { ok: true; code: string }
  | { ok: false; reason: 'empty' | 'format' | 'self' | 'alreadySent' };

/**
 * Client-side checks before POST /friends/requests. The backend repeats every
 * rule (self, duplicate, already friends, cooldown) — these only spare the user
 * a round trip and a second identical request.
 */
export function checkFriendCode(
  input: string,
  ownCode: string | null,
  sentCodes: ReadonlySet<string>,
): FriendCodeCheck {
  const code = normalizeFriendCode(input);
  if (!code) return { ok: false, reason: 'empty' };
  if (!FRIEND_CODE.test(code)) return { ok: false, reason: 'format' };
  if (ownCode && code === normalizeFriendCode(ownCode)) return { ok: false, reason: 'self' };
  if (sentCodes.has(code)) return { ok: false, reason: 'alreadySent' };
  return { ok: true, code };
}

/** Case-insensitive match on display name or username; the full list for an empty query. */
export function filterFriends<T extends Pick<FriendSummary, 'name' | 'username'>>(friends: T[], query: string): T[] {
  const q = query.trim().toLowerCase();
  if (!q) return friends;
  return friends.filter((f) => f.name.toLowerCase().includes(q) || f.username.toLowerCase().includes(q));
}

export type SuggestionState = 'add' | 'sending' | 'pending' | 'friend';

/**
 * A suggestion's action. "Pending" comes from either the server's open
 * outgoing requests or a request sent from this screen since the last load,
 * so the button never offers a second request to the same player.
 */
export function suggestionState(
  userId: number,
  outgoing: FriendRequestItem[],
  sentIds: ReadonlySet<number>,
  friendIds: ReadonlySet<number>,
  busyId: number | null,
): SuggestionState {
  if (friendIds.has(userId)) return 'friend';
  if (sentIds.has(userId) || outgoing.some((r) => r.user.id === userId)) return 'pending';
  if (busyId === userId) return 'sending';
  return 'add';
}

/** Only requests that are still open: an accepted, rejected or cancelled one never shows as pending. */
export function openRequests(items: FriendRequestItem[]): FriendRequestItem[] {
  return items.filter((r) => r.status === 'pending');
}
