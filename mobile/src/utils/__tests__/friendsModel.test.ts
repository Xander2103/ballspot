import { checkFriendCode, filterFriends, normalizeFriendCode, openRequests, suggestionState } from '../friendsModel';
import type { FriendRequestItem, FriendSummary } from '../../types/friend';

function person(id: number, name: string, username: string): FriendSummary {
  return { id, name, username, avatar_url: null, rank_name: 'Rookie', level: 1, total_xp: 0 };
}

function request(id: number, user: FriendSummary, status: FriendRequestItem['status'] = 'pending'): FriendRequestItem {
  return { id, status, created_at: null, user };
}

describe('checkFriendCode', () => {
  const none = new Set<string>();

  it('normalizes what the user typed the way the backend compares it', () => {
    expect(normalizeFriendCode('  xgue 8269 ')).toBe('XGUE8269');
    expect(checkFriendCode(' xgue8269 ', null, none)).toEqual({ ok: true, code: 'XGUE8269' });
  });

  it('rejects empty and malformed codes before calling the API', () => {
    expect(checkFriendCode('   ', null, none)).toEqual({ ok: false, reason: 'empty' });
    expect(checkFriendCode('ab', null, none)).toEqual({ ok: false, reason: 'format' });
    expect(checkFriendCode('ABCDEFGHIJKLM', null, none)).toEqual({ ok: false, reason: 'format' });
    expect(checkFriendCode('ABC-1234', null, none)).toEqual({ ok: false, reason: 'format' });
  });

  it('refuses your own code and a code you already sent to', () => {
    expect(checkFriendCode('q7yp7cb7', 'Q7YP7CB7', none)).toEqual({ ok: false, reason: 'self' });
    expect(checkFriendCode('ABCD2345', 'Q7YP7CB7', new Set(['ABCD2345']))).toEqual({ ok: false, reason: 'alreadySent' });
  });
});

describe('filterFriends', () => {
  const list = [person(1, 'Anna Peeters', 'anna'), person(2, 'Bram', 'goalkeeper_b')];

  it('matches name or username, case-insensitively', () => {
    expect(filterFriends(list, 'PEET').map((f) => f.id)).toEqual([1]);
    expect(filterFriends(list, 'keeper').map((f) => f.id)).toEqual([2]);
    expect(filterFriends(list, '  ')).toBe(list);
    expect(filterFriends(list, 'zzz')).toEqual([]);
  });

  it('stays fast on a few hundred friends', () => {
    const many = Array.from({ length: 500 }, (_, i) => person(i, `Player ${i}`, `p${i}`));
    expect(filterFriends(many, 'player 49').map((f) => f.id)).toEqual([49, ...Array.from({ length: 10 }, (_, i) => 490 + i)]);
  });
});

describe('suggestionState', () => {
  const sam = person(7, 'Sam', 'sam');

  it('offers Add by default and a spinner while sending', () => {
    expect(suggestionState(7, [], new Set(), new Set(), null)).toBe('add');
    expect(suggestionState(7, [], new Set(), new Set(), 7)).toBe('sending');
  });

  it('turns into Pending after sending, from this screen or from the server', () => {
    expect(suggestionState(7, [], new Set([7]), new Set(), null)).toBe('pending');
    expect(suggestionState(7, [request(1, sam)], new Set(), new Set(), null)).toBe('pending');
  });

  it('never offers Add to an existing friend', () => {
    expect(suggestionState(7, [], new Set(), new Set([7]), null)).toBe('friend');
  });
});

describe('openRequests', () => {
  it('drops accepted, rejected and cancelled requests', () => {
    const sam = person(7, 'Sam', 'sam');
    const items = [request(1, sam), request(2, sam, 'accepted'), request(3, sam, 'rejected'), request(4, sam, 'cancelled')];
    expect(openRequests(items).map((r) => r.id)).toEqual([1]);
  });
});
