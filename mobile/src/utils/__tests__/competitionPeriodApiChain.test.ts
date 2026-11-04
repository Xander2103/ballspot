/**
 * The whole app side of "View Weekly/Monthly Leaderboard", from the HTTP body
 * to the translated string: fetch → apiClient → dailyApi → competitionPeriodType
 * → competitionCopyKeys → translate, exactly as DailyResultScreen and
 * WeeklyLeaderboardScreen run it. Bodies are shaped like production's
 * DailyChallengeController::stats() / weeklyLeaderboard().
 *
 * What this pins: the app shows "Weekly" ONLY when the HTTP body itself says
 * period.period_type = "weekly". Missing, misplaced or unknown periods give the
 * neutral copy, never Weekly. So a device that shows "View Weekly Leaderboard"
 * received "weekly" from the server that answered the request.
 */
jest.mock('../../storage/tokenStorage', () => ({
  tokenStorage: { get: async () => 'test-token', save: async () => {}, remove: async () => {} },
}));

import * as fs from 'fs';
import * as path from 'path';
import { dailyApi } from '../../api/dailyApi';
import { registerTranslations, translate } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';
import { fr } from '../../i18n/locales/fr';
import { de } from '../../i18n/locales/de';
import { es } from '../../i18n/locales/es';
import type { LanguageCode } from '../language';
import { competitionCopyKeys, competitionPeriodLabel, competitionPeriodType } from '../competitionPeriod';
import { settleDailyResultLoad } from '../dailyResultLoad';

registerTranslations({ en, nl, fr, de, es });

const LANGS: LanguageCode[] = ['en', 'nl', 'fr', 'de', 'es'];

const COPY = {
  monthly: {
    button: { en: 'View Monthly Leaderboard', nl: 'Bekijk het maandklassement', fr: 'Voir le classement mensuel', de: 'Monatsrangliste ansehen', es: 'Ver clasificación mensual' },
    title: { en: 'Monthly Leaderboard', nl: 'Maandklassement', fr: 'Classement mensuel', de: 'Monatsrangliste', es: 'Clasificación mensual' },
    points: { en: '120 pts this month', nl: '120 ptn deze maand', fr: '120 pts ce mois-ci', de: '120 Pkt. diesen Monat', es: '120 pts este mes' },
  },
  weekly: {
    button: { en: 'View Weekly Leaderboard', nl: 'Bekijk het weekklassement', fr: 'Voir le classement hebdo', de: 'Wochenrangliste ansehen', es: 'Ver clasificación semanal' },
    title: { en: 'Weekly Leaderboard', nl: 'Weekklassement', fr: 'Classement hebdo', de: 'Wochenrangliste', es: 'Clasificación semanal' },
    points: { en: '120 pts this week', nl: '120 ptn deze week', fr: '120 pts cette semaine', de: '120 Pkt. diese Woche', es: '120 pts esta semana' },
  },
  neutral: {
    button: { en: 'View Leaderboard', nl: 'Bekijk het klassement', fr: 'Voir le classement', de: 'Rangliste ansehen', es: 'Ver clasificación' },
    title: { en: 'Leaderboard', nl: 'Klassement', fr: 'Classement', de: 'Rangliste', es: 'Clasificación' },
    points: { en: '120 pts', nl: '120 ptn', fr: '120 pts', de: '120 Pkt.', es: '120 pts' },
  },
} as const;

const PERIOD = {
  monthly: { period_type: 'monthly', period_label: 'Monthly', period_start: '2026-11-01', period_end: '2026-11-30' },
  weekly: { period_type: 'weekly', period_label: 'Weekly', period_start: '2026-11-02', period_end: '2026-11-08' },
};

/** /api/daily/stats as production builds it (no `data` wrapper). */
const statsBody = (extra: Record<string, unknown>) => ({
  current_streak: 2, best_streak: 4, total_played: 9, average_score: 58.3, best_score: 91, weekly_rank: 3, ...extra,
});

/** /api/daily/leaderboard/weekly for a player with or without a score this period. */
const leaderboardBody = (extra: Record<string, unknown>, withScore: boolean) => ({
  data: withScore ? [{ user_id: 7, username: 'kees', name: 'kees', total_score: 120, challenges_played: 2, avg_score: 60, rank: 1, is_current_user: true }] : [],
  week_start: '2026-11-01',
  week_end: '2026-11-30',
  meta: { total_players: withScore ? 1 : 0, current_user_rank: withScore ? 1 : null, current_user_score: withScore ? 120 : null, current_user_average: null, better_than_percentage: withScore ? 0 : null, top_users: [], nearby_users: [] },
  ...extra,
});

let lastUrl = '';
function serve(body: unknown, status = 200) {
  (global as { fetch: unknown }).fetch = jest.fn(async (url: string) => {
    lastUrl = url;
    return {
      ok: status >= 200 && status < 300,
      status,
      headers: { get: () => null },
      text: async () => JSON.stringify(body),
    };
  });
}

type Kind = keyof typeof COPY;
const kindOf = (period: unknown): Kind => competitionPeriodType(period as never) ?? 'neutral';

/** DailyResultScreen: button text from the settled stats call. */
async function dailyResultButton(body: unknown, code: LanguageCode, status = 200): Promise<string> {
  serve(body, status);
  const settled = await Promise.allSettled([Promise.resolve({ score: 80 }), dailyApi.stats()]);
  const { stats } = settleDailyResultLoad(settled as never);
  const keys = competitionCopyKeys(competitionPeriodType((stats as { period?: unknown } | null)?.period as never));
  return translate(keys.viewLeaderboard, undefined, code);
}

describe('GET /daily/stats → Daily Result button (real api client)', () => {
  it.each(LANGS)('%s: monthly → Monthly, weekly → Weekly', async (code) => {
    expect(await dailyResultButton(statsBody({ period: PERIOD.monthly }), code)).toBe(COPY.monthly.button[code]);
    expect(lastUrl).toMatch(/\/daily\/stats$/);
    expect(await dailyResultButton(statsBody({ period: PERIOD.weekly }), code)).toBe(COPY.weekly.button[code]);
  });

  it.each(LANGS)('%s: no period / wrong shape / failed call → neutral, never Weekly', async (code) => {
    // Server without a period block.
    expect(await dailyResultButton(statsBody({}), code)).toBe(COPY.neutral.button[code]);
    // `period` as a bare string, or period_type at the top level instead of nested.
    expect(await dailyResultButton(statsBody({ period: 'weekly' }), code)).toBe(COPY.neutral.button[code]);
    expect(await dailyResultButton(statsBody({ period_type: 'weekly' }), code)).toBe(COPY.neutral.button[code]);
    // Wrapped in `data` (a different response structure).
    expect(await dailyResultButton({ data: statsBody({ period: PERIOD.weekly }) }, code)).toBe(COPY.neutral.button[code]);
    // Unknown or differently cased values.
    expect(await dailyResultButton(statsBody({ period: { ...PERIOD.monthly, period_type: 'Weekly' } }), code)).toBe(COPY.neutral.button[code]);
    expect(await dailyResultButton(statsBody({ period: { ...PERIOD.monthly, period_type: 'season' } }), code)).toBe(COPY.neutral.button[code]);
    // A failing stats call (500) must not invent a period either.
    expect(await dailyResultButton({ message: 'Server Error' }, code, 500)).toBe(COPY.neutral.button[code]);
  });

  it('while loading, the screen renders a spinner and no button (no default period)', () => {
    const screen = fs.readFileSync(path.resolve(__dirname, '../../screens/DailyResultScreen.tsx'), 'utf8');
    // stats starts null → competitionPeriodType(undefined) → neutral; and the
    // button is below the `if (loading) return <ActivityIndicator/>` guard.
    expect(screen).toMatch(/useState<DailyStats \| null>\(null\)/);
    expect(screen.indexOf('if (loading)')).toBeLessThan(screen.indexOf('leaderboardCopy.viewLeaderboard'));
    expect(competitionPeriodType(undefined)).toBeNull();
    // Nothing in the app hardcodes the weekly copy keys.
    expect(screen).not.toMatch(/competition\.weekly|viewWeeklyLeaderboard/);
  });

  it('a historical result uses the same live stats call; its challenge_date plays no role', () => {
    const screen = fs.readFileSync(path.resolve(__dirname, '../../screens/DailyResultScreen.tsx'), 'utf8');
    expect(screen).toMatch(/dailyApi\.result\(dailyChallengeId\)/);
    expect(screen).toMatch(/dailyApi\.stats\(\)/);
    expect(screen).toMatch(/competitionPeriodType\(stats\?\.period\)/);
  });

  it('the button navigates to the leaderboard screen without a period parameter', () => {
    const screen = fs.readFileSync(path.resolve(__dirname, '../../screens/DailyResultScreen.tsx'), 'utf8');
    const nav = fs.readFileSync(path.resolve(__dirname, '../../app/AppNavigator.tsx'), 'utf8');
    expect(screen).toMatch(/navigation\.navigate\('WeeklyLeaderboard'\)/);
    // No route params, so no stale "weekly" can travel along the navigation.
    expect(nav).toMatch(/WeeklyLeaderboard: undefined;/);
  });
});

describe('GET /daily/leaderboard/weekly → leaderboard title, period bar, points', () => {
  async function leaderboardCopy(body: unknown, code: LanguageCode) {
    serve(body);
    const lb = await dailyApi.weeklyLeaderboard();
    expect(lastUrl).toMatch(/\/daily\/leaderboard\/weekly$/);
    const keys = competitionCopyKeys(competitionPeriodType(lb.period));
    return {
      title: translate(keys.title, undefined, code),
      points: translate(keys.points, { score: '120' }, code),
      bar: competitionPeriodLabel((k) => translate(k, undefined, code), lb.period, lb.period_label),
    };
  }

  it.each(LANGS)('%s: monthly and weekly, with and without a score', async (code) => {
    for (const withScore of [true, false]) {
      for (const type of ['monthly', 'weekly'] as const) {
        const copy = await leaderboardCopy(leaderboardBody({ period: PERIOD[type], period_label: PERIOD[type].period_label }, withScore), code);
        expect(copy.title).toBe(COPY[type].title[code]);
        expect(copy.points).toBe(COPY[type].points[code]);
        expect(copy.bar).toBe(translate(`daily.competition.${type}.periodName`, undefined, code));
      }
    }
  });

  it.each(LANGS)('%s: a stale "Weekly" label next to a monthly period never wins', async (code) => {
    const copy = await leaderboardCopy(leaderboardBody({ period: PERIOD.monthly, period_label: 'Weekly' }, true), code);
    expect(copy.title).toBe(COPY.monthly.title[code]);
    expect(copy.bar).toBe(translate('daily.competition.monthly.periodName', undefined, code));
  });

  it.each(LANGS)('%s: no period block → neutral title', async (code) => {
    const copy = await leaderboardCopy(leaderboardBody({}, false), code);
    expect(copy.title).toBe(COPY.neutral.title[code]);
    expect(copy.points).toBe(COPY.neutral.points[code]);
  });

  it('the copy keys exist in every language (no fallback to English or to Weekly)', () => {
    expect(kindOf(PERIOD.monthly)).toBe('monthly');
    for (const code of LANGS) {
      for (const kind of ['monthly', 'weekly', 'neutral'] as const) {
        const keys = competitionCopyKeys(kind === 'neutral' ? null : kind);
        expect(translate(keys.viewLeaderboard, undefined, code)).toBe(COPY[kind].button[code]);
      }
    }
  });
});
