import * as fs from 'fs';
import * as path from 'path';
import { dailyResultDisplay, formatChallengeDate, settleDailyResultLoad } from '../dailyResultLoad';
import type { DailyResultChallenge } from '../../types/daily';

const ok = <T>(value: T): PromiseFulfilledResult<T> => ({ status: 'fulfilled', value });
const failed: PromiseRejectedResult = { status: 'rejected', reason: { status: 500 } };

describe('daily result loading', () => {
  it('keeps the result when stats fail', () => {
    expect(settleDailyResultLoad([ok({ score: 91 }), failed]))
      .toEqual({ result: { score: 91 }, stats: null });
  });

  it('has no result only when the result request itself failed', () => {
    expect(settleDailyResultLoad([failed, ok({ current_streak: 3 })]))
      .toEqual({ result: null, stats: { current_streak: 3 } });
  });
});

describe('the date of a daily result', () => {
  it('names the challenge date in the player language', () => {
    expect(formatChallengeDate('2026-07-14', 'en')).toBe('July 14, 2026');
    expect(formatChallengeDate('2026-07-14', 'nl')).toBe('14 juli 2026');
  });

  it('does not move to the previous day west of Greenwich', () => {
    expect(formatChallengeDate('2026-07-01', 'en')).toContain('July 1,');
  });

  it('shows nothing for an older backend or a malformed date', () => {
    expect(formatChallengeDate(undefined, 'en')).toBeNull();
    expect(formatChallengeDate('', 'en')).toBeNull();
    expect(formatChallengeDate('yesterday', 'en')).toBeNull();
  });
});

describe('what a daily result shows', () => {
  const challenge = (over: Partial<DailyResultChallenge> = {}): DailyResultChallenge => ({
    id: 7,
    title: 'Goal Line',
    hidden_image_url: 'https://x/hidden.jpg',
    category: { id: 3, name: 'Headers', slug: 'headers' },
    ...over,
  });

  it('shows the title, category and image of the played challenge', () => {
    expect(dailyResultDisplay({ reveal_image_url: 'https://x/reveal.jpg', challenge: challenge(), sport: null })).toEqual({
      imageUrl: 'https://x/reveal.jpg',
      imageAspect: null, // older backend: no size sent
      isRevealImage: true,
      title: 'Goal Line',
      categoryName: 'Headers',
      sportSlug: null,
    });
  });

  it('falls back to the played challenge\'s own hidden image without a reveal image', () => {
    expect(dailyResultDisplay({ reveal_image_url: null, challenge: challenge(), sport: null }))
      .toMatchObject({ imageUrl: 'https://x/hidden.jpg', isRevealImage: false });
  });

  it('takes the aspect ratio of the image it actually shows', () => {
    const sized = challenge({ hidden_image_width: 2048, hidden_image_height: 1536 });
    expect(dailyResultDisplay({
      reveal_image_url: 'https://x/reveal.webp', reveal_image_width: 1536, reveal_image_height: 2048, challenge: sized, sport: null,
    }).imageAspect).toBeCloseTo(0.75);
    expect(dailyResultDisplay({ reveal_image_url: null, challenge: sized, sport: null }).imageAspect).toBeCloseTo(4 / 3);
  });

  it('keeps each result\'s own category', () => {
    const corners = challenge({ category: { id: 4, name: 'Corner Kicks', slug: 'corner-kicks' } });
    expect(dailyResultDisplay({ reveal_image_url: null, challenge: corners, sport: null }).categoryName).toBe('Corner Kicks');
    expect(dailyResultDisplay({ reveal_image_url: null, challenge: challenge(), sport: null }).categoryName).toBe('Headers');
  });

  it('shows nothing rather than another challenge when details are missing', () => {
    expect(dailyResultDisplay({ reveal_image_url: null, challenge: challenge({ category: null }), sport: null }).categoryName).toBeNull();
    expect(dailyResultDisplay({ reveal_image_url: null, sport: null })).toEqual({
      imageUrl: null,
      imageAspect: null,
      isRevealImage: false,
      title: null,
      categoryName: null,
      sportSlug: null,
    });
  });

  it('hides an internal category name', () => {
    const internal = challenge({ category: { id: 9, name: 'AboveGoalHeader', slug: 'abovegoalheader' } });
    expect(dailyResultDisplay({ reveal_image_url: null, challenge: internal, sport: null }).categoryName).toBeNull();
  });

  it('the screen never reads the current daily', () => {
    const source = fs.readFileSync(path.resolve(__dirname, '..', '..', 'screens', 'DailyResultScreen.tsx'), 'utf8');
    expect(source).not.toMatch(/dailyApi\.today/);
    expect(source).not.toMatch(/TodayResponse/);
    expect(source).toMatch(/dailyApi\.result\(dailyChallengeId\)/);
  });
});
