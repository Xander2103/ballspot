/** Play-session adapter: public packs stay exactly as before; club packs return to their club. */
import { hasTranslation } from '../../i18n/core';
import {
  backToOriginKey, exitLabelKey, exitRoutesFor, isClubSession, resolvePlaySession, sessionClubId,
} from '../playSession';

describe('resolvePlaySession', () => {
  it('no session = public pack for the route slug (old navigate() calls keep working)', () => {
    expect(resolvePlaySession({ slug: 'derby' })).toEqual({ kind: 'pack', packSlug: 'derby' });
    expect(resolvePlaySession({ slug: 'derby', session: null })).toEqual({ kind: 'pack', packSlug: 'derby' });
  });

  it('keeps a club session as given', () => {
    const session = { kind: 'pack' as const, packSlug: 'kfc-1', clubId: '01JCLUB', clubName: 'KFC' };
    expect(resolvePlaySession({ slug: 'kfc-1', session })).toBe(session);
    expect(sessionClubId(session)).toBe('01JCLUB');
    expect(isClubSession(session)).toBe(true);
  });

  it('a session without a club id is public', () => {
    expect(isClubSession({ kind: 'pack', packSlug: 'x', clubId: null })).toBe(false);
    expect(isClubSession(undefined)).toBe(false);
  });
});

describe('return to origin', () => {
  it('public pack → Home + Packs (the old goPacks reset)', () => {
    expect(exitRoutesFor(undefined)).toEqual({ index: 1, routes: [{ name: 'Home' }, { name: 'Packs' }] });
    expect(exitRoutesFor({ kind: 'pack', packSlug: 'x' })).toEqual({ index: 1, routes: [{ name: 'Home' }, { name: 'Packs' }] });
  });

  it('club pack → Clubs tab + that club', () => {
    expect(exitRoutesFor({ kind: 'pack', packSlug: 'x', clubId: 'C1', clubName: 'KFC' })).toEqual({
      index: 1,
      routes: [
        { name: 'Home', params: { screen: 'Clubs' } },
        { name: 'ClubDetail', params: { clubId: 'C1', name: 'KFC' } },
      ],
    });
    expect(exitRoutesFor({ kind: 'pack', packSlug: 'x', clubId: 'C1' }).routes[1]).toEqual({ name: 'ClubDetail', params: { clubId: 'C1' } });
  });

  it('labels point at keys that exist', () => {
    expect(exitLabelKey(undefined)).toBe('nav.exit.packs');
    expect(exitLabelKey({ kind: 'pack', packSlug: 'x', clubId: 'C1' })).toBe('nav.exit.club');
    expect(backToOriginKey(undefined, 'packs.result.backToPacks')).toBe('packs.result.backToPacks');
    expect(backToOriginKey({ kind: 'pack', packSlug: 'x', clubId: 'C1' }, 'packs.result.backToPacks')).toBe('clubs.play.backToClub');
    for (const key of ['nav.exit.packs', 'nav.exit.club', 'clubs.play.backToClub', 'packs.result.backToPacks', 'packs.complete.backToPacks']) {
      expect({ key, has: hasTranslation(key, 'en') }).toEqual({ key, has: true });
    }
  });
});
