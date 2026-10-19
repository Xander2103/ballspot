import type { ClubPackCard, CompetitionCard } from '../../types/club';
import {
  activeCompetitions, homePacks, nextUpcoming, otherCompetitionsSummary, packProgressFraction, rankDisplay, reservingCompetition,
} from '../clubDetailLayout';

const comp = (over: Partial<CompetitionCard>): CompetitionCard => ({
  id: 'c', name: 'C', description: null, phase: 'active', starts_at: '2026-10-01T10:00:00Z', ends_at: '2026-10-31T10:00:00Z',
  timezone: 'Europe/Brussels', rules: { replay_mode: 'single_attempt', max_attempts: 1, score_rule: 'first', normal_play_policy: 'blocked' },
  packs_count: 2, participants_count: 8, me: null, ...over,
} as CompetitionCard);

const pack = (over: Partial<ClubPackCard>): ClubPackCard => ({
  id: 1, slug: 's', name: 'P', description: null, sport: null, cover_image_url: null, difficulty: null, challenge_count: 4,
  is_featured: false, progress: null, best_score: null, reserved_for_competition: false, competitions: [], ...over,
} as ClubPackCard);

const preview = (active: CompetitionCard[], upcoming: CompetitionCard[], completed = 0) => ({
  competitions_preview: { active, upcoming, completed_count: completed },
  competitions: { active: active.length, upcoming: upcoming.length },
});

describe('club detail hierarchy', () => {
  it('shows only active competitions as primary cards', () => {
    const club = preview([comp({ id: 'a' }), comp({ id: 'x', phase: 'ended' })], [comp({ id: 'u', phase: 'upcoming' })]);
    expect(activeCompetitions(club).map((c) => c.id)).toEqual(['a']);
  });

  it('picks the earliest upcoming competition for the quiet "next up" line', () => {
    const club = preview([], [
      comp({ id: 'late', phase: 'upcoming', starts_at: '2026-12-01T10:00:00Z' }),
      comp({ id: 'soon', phase: 'upcoming', starts_at: '2026-11-01T10:00:00Z' }),
    ]);
    expect(nextUpcoming(club)?.id).toBe('soon');
    expect(nextUpcoming(preview([], []))).toBeNull();
  });

  it('summarises upcoming and finished competitions and opens the right tab', () => {
    expect(otherCompetitionsSummary({ ...preview([], [comp({ phase: 'upcoming' })], 3), competitions: { active: 0, upcoming: 5 } }))
      .toEqual({ upcoming: 5, finished: 3, openPhase: 'upcoming' });
    expect(otherCompetitionsSummary(preview([], [], 2))).toEqual({ upcoming: 0, finished: 2, openPhase: 'completed' });
    expect(otherCompetitionsSummary(preview([], [], 0)).openPhase).toBe('upcoming');
  });

  it('shows the rank only when the server has one', () => {
    expect(rankDisplay(comp({ me: { rank: 2, total_score: 900, packs_completed: 1 }, participants_count: 8 })))
      .toEqual({ kind: 'ranked', rank: 2, total: 8 });
    expect(rankDisplay(comp({ me: null }))).toEqual({ kind: 'unranked' });
    expect(rankDisplay(comp({ me: { rank: 0, total_score: 0, packs_completed: 0 } }))).toEqual({ kind: 'unranked' });
  });
});

describe('club packs on the homepage', () => {
  it('names the competition a reserved pack is played in (active before upcoming)', () => {
    const p = pack({ reserved_for_competition: true, competitions: [
      { id: 'u', name: 'Cup Final', phase: 'upcoming' }, { id: 'a', name: 'Derby Days', phase: 'active' },
    ] as ClubPackCard['competitions'] });
    expect(reservingCompetition(p)).toEqual({ id: 'a', name: 'Derby Days' });
    expect(reservingCompetition(pack({}))).toBeNull();
    expect(reservingCompetition(pack({ reserved_for_competition: true }))).toBeNull();
  });

  it('orders playable packs first and reserved ones last, stable within a group', () => {
    const list = [
      pack({ id: 1, reserved_for_competition: true }),
      pack({ id: 2, progress: { status: 'completed', completed_count: 4, total_challenges: 4, total_score: 300 } }),
      pack({ id: 3 }),
      pack({ id: 4, progress: { status: 'active', completed_count: 1, total_challenges: 4, total_score: 80 } }),
      pack({ id: 5 }),
    ];
    expect(homePacks(list, 10).map((p) => p.id)).toEqual([4, 3, 5, 2, 1]);
    expect(homePacks(list).length).toBe(4);
  });

  it('computes a progress fraction only for a run in progress', () => {
    expect(packProgressFraction(pack({ progress: { status: 'active', completed_count: 3, total_challenges: 12, total_score: 1 } }))).toBe(0.25);
    expect(packProgressFraction(pack({ progress: { status: 'completed', completed_count: 4, total_challenges: 4, total_score: 1 } }))).toBeNull();
    expect(packProgressFraction(pack({}))).toBeNull();
  });
});

describe('compact club header line', () => {
  it('shows place and member count, without the club type', () => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const { clubDetailParts } = require('../clubDisplay');
    expect(clubDetailParts({ city: 'Gent', country_code: 'BE', members_count: 18 }, 'en')).toEqual(['Gent, BE', '18 members']);
    expect(clubDetailParts({ city: null, country_code: null, members_count: 1 }, 'en')).toEqual(['1 member']);
  });
});
