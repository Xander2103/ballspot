/**
 * Regressions from iOS build 9 on a real device (Daily Result + Home cards).
 * RN components cannot render under this runner (see jest.config.js), so these
 * run the screen's own pure functions on API responses shaped like production's,
 * then translate the result in all five languages.
 */
import * as fs from 'fs';
import * as path from 'path';
import * as zlib from 'zlib';
import { hasTranslation, registerTranslations, translate } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';
import { fr } from '../../i18n/locales/fr';
import { de } from '../../i18n/locales/de';
import { es } from '../../i18n/locales/es';
import { SUPPORTED_LANGUAGES, type LanguageCode } from '../language';
import { competitionCopyKeys, competitionPeriodType, visibleChallengeLabel } from '../competitionPeriod';
import { dailyResultDisplay } from '../dailyResultLoad';
import {
  INITIAL_SHARE_REWARD_STATE,
  dailyShareHint,
  splitAwardedShareXp,
  stateFromServerStatus,
  type ShareRewardStatus,
} from '../share';

registerTranslations({ en, nl, fr, de, es });

const ROOT = path.resolve(__dirname, '..', '..', '..');
const screen = fs.readFileSync(path.join(ROOT, 'src/screens/DailyResultScreen.tsx'), 'utf8');

/** GET /api/daily/stats, as CompetitionPeriodService::toArray() builds `period`. */
const stats = (type: string, start: string, end: string) => ({
  current_streak: 3, best_streak: 5, total_played: 12, average_score: 61.4, best_score: 93, weekly_rank: 4,
  period: { period_type: type, period_label: type[0].toUpperCase() + type.slice(1), period_start: start, period_end: end },
});

const BUTTON = {
  weekly: { en: 'View Weekly Leaderboard', nl: 'Bekijk het weekklassement', fr: 'Voir le classement hebdo', de: 'Wochenrangliste ansehen', es: 'Ver clasificación semanal' },
  monthly: { en: 'View Monthly Leaderboard', nl: 'Bekijk het maandklassement', fr: 'Voir le classement mensuel', de: 'Monatsrangliste ansehen', es: 'Ver clasificación mensual' },
  neutral: { en: 'View Leaderboard', nl: 'Bekijk het klassement', fr: 'Voir le classement', de: 'Rangliste ansehen', es: 'Ver clasificación' },
} as const;

const buttonText = (s: { period?: unknown } | null, code: LanguageCode) =>
  translate(competitionCopyKeys(competitionPeriodType(s?.period as never)).viewLeaderboard, undefined, code);

describe('leaderboard button follows the period in the API response', () => {
  it.each(SUPPORTED_LANGUAGES.map((l) => l.code as LanguageCode))('%s: weekly, monthly and unknown', (code) => {
    expect(buttonText(stats('weekly', '2026-11-02', '2026-11-08'), code)).toBe(BUTTON.weekly[code]);
    expect(buttonText(stats('monthly', '2026-11-01', '2026-11-30'), code)).toBe(BUTTON.monthly[code]);
    expect(buttonText(null, code)).toBe(BUTTON.neutral[code]);
    expect(buttonText({ period: { period_type: 'season' } }, code)).toBe(BUTTON.neutral[code]);
  });

  it('never falls back to weekly: "weekly" only comes from the API value itself', () => {
    expect(competitionPeriodType(undefined)).toBeNull();
    expect(competitionPeriodType({})).toBeNull();
    expect(screen).toMatch(/competitionCopyKeys\(competitionPeriodType\(stats\?\.period\)\)/);
    expect(screen).toMatch(/title=\{t\(leaderboardCopy\.viewLeaderboard\)\}/);
  });
});

describe('internal names on Daily Result', () => {
  const result = (title: string, category: string) => ({
    reveal_image_url: 'https://example.test/r.jpg', sport: { slug: 'football' } as never,
    challenge: { id: 1, title, difficulty: 'easy', hidden_image_url: null, category: { id: 1, name: category, slug: 'x' } },
  });

  it('hides the exact production text as category; the title is guarded by ResultImageSection', () => {
    expect(dailyResultDisplay(result('Corner', 'AboveGoalHeader') as never).categoryName).toBeNull();
    expect(visibleChallengeLabel(dailyResultDisplay(result('AboveGoalHeader', 'Headers') as never).title)).toBeNull();
  });

  it.each(['AboveGoalHeader.jpg', 'AboveGoalHeader.PNG', 'AboveGoalHeader 2', 'AboveGoalHeader-2', 'AboveGoalHeader (1)', 'above_goal_header.webp'])(
    'hides the variant %s', (value) => {
      expect(visibleChallengeLabel(value)).toBeNull();
    },
  );

  it.each(['Round 2', 'Top 10', 'World Cup 2026', 'Header 1', 'Goal.', 'PSG 2'])('keeps the real name %s', (value) => {
    expect(visibleChallengeLabel(value)).toBe(value);
  });
});

describe('share XP shown on Daily Result matches what the backend grants', () => {
  /** GET /api/share/status for an account that never shared, today on the server. */
  const fresh: ShareRewardStatus = {
    day: '2026-11-02', total_shares: 0,
    first_share_available: true, daily_share_available: true, daily_high_score_available: true,
    high_score_threshold: 80, social_striker_shares: 5,
    xp: { first_share: 200, daily_share: 10, daily_high_score_share: 15 },
  };
  const state = stateFromServerStatus(fresh);

  const partsText = (hint: NonNullable<ReturnType<typeof dailyShareHint>>, code: LanguageCode) =>
    hint.parts.map((p) => translate(`daily.share.rewardParts.${p.reward}`, { xp: p.xp }, code));

  it('210 is 200 first share + 10 daily share, and says so in every language', () => {
    const hint = dailyShareHint(state, 42)!;
    expect(hint.xp).toBe(210);
    expect(hint.parts).toEqual([{ reward: 'first_share', xp: 200 }, { reward: 'daily_share', xp: 10 }]);
    for (const { code } of SUPPORTED_LANGUAGES) {
      const lang = code as LanguageCode;
      expect(translate('daily.share.xpAvailable', { xp: hint.xp }, lang)).toContain('210');
      const [first, daily] = partsText(hint, lang);
      expect(first).toContain('+200');
      expect(daily).toContain('+10');
    }
  });

  it('a score of 80+ adds the high-score bonus: 225', () => {
    const hint = dailyShareHint(state, 85)!;
    expect(hint.xp).toBe(225);
    expect(hint.parts.map((p) => p.xp)).toEqual([200, 10, 15]);
  });

  it('after the first share only the daily amount is promised', () => {
    const hint = dailyShareHint(stateFromServerStatus({ ...fresh, total_shares: 1, first_share_available: false }), 42)!;
    expect(hint.xp).toBe(10);
  });

  it('uses amounts from the server, not numbers in the app', () => {
    const hint = dailyShareHint(stateFromServerStatus({ ...fresh, xp: { first_share: 75, daily_share: 5, daily_high_score_share: 8 } }), 42)!;
    expect(hint.xp).toBe(80);
  });

  it('promises nothing when the server has not answered', () => {
    expect(dailyShareHint(null, 85)).toBeNull();
    expect(dailyShareHint(INITIAL_SHARE_REWARD_STATE, 85)).toBeNull();
    expect(dailyShareHint({ ...state, serverDay: null }, 85)).toBeNull();
  });

  it('applies the server day: already rewarded stays rewarded after local midnight', () => {
    // 00:30 in Brussels on the 3rd is still the 2nd on the UTC server.
    const claimed = stateFromServerStatus({ ...fresh, first_share_available: false, daily_share_available: false });
    expect(dailyShareHint(claimed, 42)).toBeNull();
    expect(screen).not.toMatch(/localDateString/);
  });

  it('explains a total above the announced reward: 310 = 210 + the 100 XP First Share trophy', () => {
    expect(splitAwardedShareXp(310, ['first_share', 'daily_share'], state)).toEqual({ share: 210, trophy: 100 });
    expect(splitAwardedShareXp(10, ['daily_share'], state)).toEqual({ share: 10, trophy: 0 });
    expect(splitAwardedShareXp(0, [], state)).toEqual({ share: 0, trophy: 0 });
    for (const { code } of SUPPORTED_LANGUAGES) {
      const text = translate('daily.share.xpEarnedWithTrophy', { xp: 310, share: 210, trophy: 100 }, code as LanguageCode);
      for (const n of ['310', '210', '100']) expect(text).toContain(n);
    }
  });

  it('every new key exists in every language', () => {
    for (const { code } of SUPPORTED_LANGUAGES) {
      for (const key of ['daily.share.rewardParts.first_share', 'daily.share.rewardParts.daily_share', 'daily.share.rewardParts.daily_high_score_share', 'daily.share.xpEarnedWithTrophy']) {
        expect({ code, key, ok: hasTranslation(key, code as LanguageCode) }).toEqual({ code, key, ok: true });
      }
    }
  });
});

describe('Home mode card artwork', () => {
  /** Decodes an 8-bit, non-interlaced RGB(A) PNG into rows of bytes. */
  function decodePng(file: string) {
    const data = fs.readFileSync(file);
    const width = data.readUInt32BE(16);
    const height = data.readUInt32BE(20);
    const channels = data[25] === 6 ? 4 : 3;
    const idat: Buffer[] = [];
    for (let i = 8; i < data.length;) {
      const length = data.readUInt32BE(i);
      if (data.toString('latin1', i + 4, i + 8) === 'IDAT') idat.push(data.subarray(i + 8, i + 8 + length));
      i += 12 + length;
    }
    const raw = zlib.inflateSync(Buffer.concat(idat));
    const stride = width * channels;
    const rows: Uint8Array[] = [];
    let prev = new Uint8Array(stride);
    for (let y = 0; y < height; y++) {
      const offset = y * (stride + 1);
      const filter = raw[offset];
      const row = new Uint8Array(stride);
      for (let x = 0; x < stride; x++) {
        const a = x >= channels ? row[x - channels] : 0;
        const b = prev[x];
        const c = x >= channels ? prev[x - channels] : 0;
        const p = a + b - c;
        const pa = Math.abs(p - a);
        const pb = Math.abs(p - b);
        const pc = Math.abs(p - c);
        const predictor = [0, a, b, (a + b) >> 1, pa <= pb && pa <= pc ? a : pb <= pc ? b : c][filter];
        row[x] = raw[offset + 1 + x] + predictor;
      }
      rows.push(row);
      prev = row;
    }
    return { width, height, channels, rows };
  }

  // The light margin baked into the source files was this cream, ±14 per channel.
  const isCream = (r: number, g: number, b: number) => Math.abs(r - 235) < 14 && Math.abs(g - 222) < 14 && Math.abs(b - 200) < 14;

  it.each(['ballpicker_packs_rustig.png', 'ballpicker_tournaments_rustig.png'])('%s has no light margin on any edge', (name) => {
    const { width, height, channels, rows } = decodePng(path.join(ROOT, 'assets/home', name));
    const pixel = (x: number, y: number) => isCream(rows[y][x * channels], rows[y][x * channels + 1], rows[y][x * channels + 2]);
    const share = (count: number, test: (i: number) => boolean) => {
      let hits = 0;
      for (let i = 0; i < count; i++) if (test(i)) hits++;
      return hits / count;
    };
    for (const depth of [0, 1, 2]) {
      expect(share(width, (x) => pixel(x, depth))).toBeLessThan(0.2);
      expect(share(width, (x) => pixel(x, height - 1 - depth))).toBeLessThan(0.2);
      expect(share(height, (y) => pixel(depth, y))).toBeLessThan(0.2);
      expect(share(height, (y) => pixel(width - 1 - depth, y))).toBeLessThan(0.2);
    }
  });
});
