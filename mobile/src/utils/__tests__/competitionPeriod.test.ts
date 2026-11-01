/**
 * Regression: production ran BALLPICKER_COMPETITION_PERIOD=monthly while Daily
 * Result, the leaderboard title and the position card said "Weekly"/"this week"
 * from hardcoded copy. RN components cannot render under this runner (see
 * jest.config.js), so the wiring is pinned by reading the sources, like
 * src/i18n/__tests__/screens.test.ts.
 */
import * as fs from 'fs';
import * as path from 'path';
import { flattenKeys, hasTranslation, registerTranslations, translate } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';
import { fr } from '../../i18n/locales/fr';
import { de } from '../../i18n/locales/de';
import { es } from '../../i18n/locales/es';
import { SUPPORTED_LANGUAGES, type LanguageCode } from '../language';
import { dailyCardModel } from '../homeModel';
import type { TodayResponse } from '../../types/daily';
import {
  competitionCopyKeys,
  competitionPeriodLabel,
  competitionPeriodType,
  visibleChallengeLabel,
  type CompetitionPeriodType,
} from '../competitionPeriod';

const SRC = path.resolve(__dirname, '..', '..');
const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

const ALL = { en, nl, fr, de, es };
const LANGUAGES = SUPPORTED_LANGUAGES.map((l) => l.code as LanguageCode);

/** Words for the OTHER period, per language: must not leak into a period's copy. */
const WEEK_WORDS = /week|hebdo|semaine|semana|woche/i;
const MONTH_WORDS = /month|maand|mensuel|mois|monat|mes\b|mensual/i;

beforeAll(() => registerTranslations(ALL, 'en'));

describe('competitionPeriodType', () => {
  it('accepts the two supported periods from the API', () => {
    expect(competitionPeriodType({ period_type: 'weekly' })).toBe('weekly');
    expect(competitionPeriodType({ period_type: 'monthly' })).toBe('monthly');
  });

  it('treats a missing or unknown period as unknown, never as weekly', () => {
    expect(competitionPeriodType(undefined)).toBeNull();
    expect(competitionPeriodType(null)).toBeNull();
    expect(competitionPeriodType({})).toBeNull();
    expect(competitionPeriodType({ period_type: 'fortnightly' })).toBeNull();
  });
});

describe.each(LANGUAGES)('competition copy in %s', (locale) => {
  const copy = (type: CompetitionPeriodType | null) => {
    const keys = competitionCopyKeys(type);
    return [translate(keys.title, undefined, locale), translate(keys.viewLeaderboard, undefined, locale), translate(keys.points, { score: 7 }, locale)];
  };

  it('has every key for weekly, monthly and neutral', () => {
    for (const type of ['weekly', 'monthly', null] as const) {
      for (const key of Object.values(competitionCopyKeys(type))) {
        expect({ key, found: hasTranslation(key, locale) }).toEqual({ key, found: true });
      }
    }
  });

  it('weekly copy never mentions a month', () => {
    for (const text of copy('weekly')) expect(text).not.toMatch(MONTH_WORDS);
  });

  it('monthly copy never mentions a week', () => {
    for (const text of copy('monthly')) {
      expect(text).not.toMatch(WEEK_WORDS);
      expect(text).toMatch(MONTH_WORDS);
    }
    expect(translate('daily.competition.monthly.periodName', undefined, locale)).toMatch(MONTH_WORDS);
  });

  it('neutral copy names no period at all', () => {
    for (const text of copy(null)) {
      expect(text).not.toMatch(WEEK_WORDS);
      expect(text).not.toMatch(MONTH_WORDS);
    }
  });

  it('the score line keeps the score', () => {
    for (const type of ['weekly', 'monthly', null] as const) {
      expect(translate(competitionCopyKeys(type).points, { score: 7 }, locale)).toContain('7');
    }
  });
});

describe('competitionPeriodLabel', () => {
  const t = (key: string) => translate(key, undefined, 'nl');

  it('translates the generic API label from the period type', () => {
    expect(competitionPeriodLabel(t, { period_type: 'monthly', period_label: 'Monthly' })).toBe('Maandelijks');
    expect(competitionPeriodLabel(t, { period_type: 'weekly', period_label: 'Weekly' })).toBe('Wekelijks');
  });

  it('never lets a stale generic label contradict the period type', () => {
    expect(competitionPeriodLabel(t, { period_type: 'monthly', period_label: 'Weekly' })).toBe('Maandelijks');
  });

  it('shows a custom admin label as is', () => {
    expect(competitionPeriodLabel(t, { period_type: 'monthly', period_label: 'Season' })).toBe('Season');
  });

  it('falls back to neutral copy without a usable period', () => {
    expect(competitionPeriodLabel(t, undefined)).toBe('Klassement');
    expect(competitionPeriodLabel(t, undefined, 'Weekly')).toBe('Klassement');
    expect(competitionPeriodLabel(t, undefined, 'Cup')).toBe('Cup');
  });
});

describe('screens take the period from the API', () => {
  it('Daily Result labels its button from the /daily/stats period', () => {
    const source = read('screens/DailyResultScreen.tsx');
    expect(source).toMatch(/competitionCopyKeys\(competitionPeriodType\(stats\?\.period\)\)/);
    expect(source).toMatch(/title=\{t\(leaderboardCopy\.viewLeaderboard\)\}/);
  });

  it('the Daily Result button opens the competition leaderboard screen and endpoint', () => {
    expect(read('screens/DailyResultScreen.tsx')).toMatch(/navigation\.navigate\('WeeklyLeaderboard'\)/);
    expect(read('app/AppNavigator.tsx')).toMatch(/<Stack\.Screen name="WeeklyLeaderboard" component=\{WeeklyLeaderboardScreen\}/);
    expect(read('screens/WeeklyLeaderboardScreen.tsx')).toMatch(/dailyApi\.weeklyLeaderboard\(\)/);
    expect(read('api/dailyApi.ts')).toMatch(/'\/daily\/leaderboard\/weekly'/);
  });

  it('the leaderboard header title, period bar and score line follow the response', () => {
    const source = read('screens/WeeklyLeaderboardScreen.tsx');
    expect(source).toMatch(/competitionPeriodType\(leaderboard\?\.period\)/);
    expect(source).toMatch(/navigation\.setOptions\(\{ title: t\(competitionCopyKeys\(periodType\)\.title\) \}\)/);
    expect(source).toMatch(/competitionPeriodLabel\(t, leaderboard\.period, leaderboard\.period_label\)/);
    expect(source).toMatch(/periodType=\{periodType\}/);
  });

  it('the navigator title stays neutral until the period is known', () => {
    expect(read('app/AppNavigator.tsx')).toMatch(/name="WeeklyLeaderboard"[^\n]*title: t\('nav\.titles\.leaderboard'\)/);
  });

  it('a league leaderboard never claims a competition period', () => {
    expect(read('screens/LeaderboardScreen.tsx')).not.toMatch(/periodType/);
    expect(read('components/YourPositionCard.tsx')).toMatch(/t\(competitionCopyKeys\(periodType\)\.points/);
  });

  it('the hardcoded weekly keys are gone from every language', () => {
    const removed = ['nav.titles.weeklyLeaderboard', 'daily.result.viewWeeklyLeaderboard', 'game.position.ptsThisWeek'];
    for (const [code, table] of Object.entries(ALL)) {
      const keys = new Set(flattenKeys(table));
      for (const key of removed) expect({ code, key, present: keys.has(key) }).toEqual({ code, key, present: false });
    }
  });
});

describe('visibleChallengeLabel', () => {
  it.each(['AboveGoalHeader', 'aboveGoalHeader', 'above_goal_header', 'game.result.title'])('hides the internal name %s', (value) => {
    expect(visibleChallengeLabel(value)).toBeNull();
  });

  it.each(['Corner Kicks', 'Headers', 'General', 'Goal Line', 'Hard Mode', 'Free-kick', 'PSG', 'Above Goal Header', 'Tir au but'])('keeps the real name %s', (value) => {
    expect(visibleChallengeLabel(value)).toBe(value);
  });

  it('treats empty values as absent', () => {
    expect(visibleChallengeLabel(null)).toBeNull();
    expect(visibleChallengeLabel(undefined)).toBeNull();
    expect(visibleChallengeLabel('   ')).toBeNull();
  });

  it('is applied everywhere a category or title is rendered', () => {
    for (const file of ['utils/dailyResultLoad.ts', 'screens/DailyChallengeScreen.tsx', 'screens/GuessScreen.tsx', 'screens/ResultScreen.tsx', 'components/ResultImageSection.tsx', 'utils/homeModel.ts']) {
      expect({ file, guarded: /visibleChallengeLabel\(/.test(read(file)) }).toEqual({ file, guarded: true });
    }
  });

  it('leaves the internal name out of the Home card but keeps the difficulty', () => {
    const today = {
      has_daily: true,
      already_played: false,
      daily_challenge: { id: 1, challenge: { category: { name: 'AboveGoalHeader' }, difficulty: 'hard' } },
    } as unknown as TodayResponse;
    expect(dailyCardModel(false, today, null)).toMatchObject({ kind: 'playable', info: 'hard' });
  });
});
