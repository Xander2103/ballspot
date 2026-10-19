/** Play-session adapter: public packs stay exactly as before; club packs return to their club. */
import { hasTranslation } from '../../i18n/core';
import {
  backToOriginKey, detailRouteFor, exitLabelKey, exitRoutesFor, isClubSession, isCompetitionSession, resolvePlaySession, sessionClubId, withAttempt,
  type CompetitionPlaySession,
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
    for (const key of ['nav.exit.packs', 'nav.exit.club', 'nav.exit.competition', 'clubs.play.backToClub', 'clubs.play.backToCompetition', 'packs.result.backToPacks', 'packs.complete.backToPacks']) {
      expect({ key, has: hasTranslation(key, 'en') }).toEqual({ key, has: true });
    }
  });
});

describe('competition sessions', () => {
  const session: CompetitionPlaySession = {
    kind: 'competition', packSlug: 'autumn', clubId: 'C1', clubName: 'KFC', competitionId: 'K1', competitionName: 'Autumn Cup', attemptId: 91,
  };

  it('is a club session of its own kind', () => {
    expect(isCompetitionSession(session)).toBe(true);
    expect(isCompetitionSession({ kind: 'pack', packSlug: 'x', clubId: 'C1' })).toBe(false);
    expect(isClubSession(session)).toBe(true);
  });

  it('exit returns to the competition, with the club underneath', () => {
    expect(exitRoutesFor(session)).toEqual({
      index: 2,
      routes: [
        { name: 'Home', params: { screen: 'Clubs' } },
        { name: 'ClubDetail', params: { clubId: 'C1', name: 'KFC' } },
        { name: 'ClubCompetition', params: { clubId: 'C1', competitionId: 'K1', name: 'Autumn Cup', clubName: 'KFC' } },
      ],
    });
    expect(exitLabelKey(session)).toBe('nav.exit.competition');
    expect(backToOriginKey(session, 'packs.result.backToPacks')).toBe('clubs.play.backToCompetition');
  });

  it('the "detail" of a competition attempt is the competition screen, of a pack the pack detail', () => {
    expect(detailRouteFor(session, 'autumn', 'Autumn Pack').name).toBe('ClubCompetition');
    expect(detailRouteFor(undefined, 'derby', 'Derby')).toEqual({ name: 'PackDetail', params: { slug: 'derby', name: 'Derby' } });
    const club = { kind: 'pack' as const, packSlug: 'x', clubId: 'C1' };
    expect(detailRouteFor(club, 'x', 'X')).toEqual({ name: 'PackDetail', params: { slug: 'x', name: 'X', session: club } });
  });

  it('withAttempt binds the started attempt', () => {
    expect(withAttempt({ ...session, attemptId: null, attemptNumber: 2 }, 92)).toMatchObject({ attemptId: 92, attemptNumber: null });
  });
});
