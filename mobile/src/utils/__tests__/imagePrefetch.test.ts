import * as fs from 'fs';
import * as path from 'path';
import {
  createImagePrefetcher,
  dailyGuessImageToPrefetch,
  imageAspect,
  isRemoteImageUrl,
  nextPackGuessImage,
  revealImageAfterGuess,
} from '../imagePrefetch';
import type { TodayResponse } from '../../types/daily';

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('image prefetcher', () => {
  it('fetches each remote URL once', async () => {
    const calls: string[] = [];
    const prefetch = createImagePrefetcher(async (u) => { calls.push(u); return true; });

    expect(prefetch('https://bp.test/storage/a.webp')).toBe(true);
    expect(prefetch('https://bp.test/storage/a.webp')).toBe(false);
    await flush();
    expect(prefetch('https://bp.test/storage/a.webp')).toBe(false);
    expect(calls).toEqual(['https://bp.test/storage/a.webp']);
  });

  it('ignores empty, local and non-http values', () => {
    const calls: string[] = [];
    const prefetch = createImagePrefetcher(async (u) => { calls.push(u); return true; });
    [null, undefined, '', 'file:///x.jpg', 'data:image/png;base64,AA', 'javascript:alert(1)'].forEach((u) => prefetch(u as string));
    expect(calls).toEqual([]);
  });

  it('allows a retry after a failed or throwing prefetch, and never throws itself', async () => {
    let ok = false;
    const calls: string[] = [];
    const prefetch = createImagePrefetcher(async (u) => { calls.push(u); return ok; });
    prefetch('https://bp.test/a.webp');
    await flush();
    ok = true;
    prefetch('https://bp.test/a.webp');
    expect(calls).toHaveLength(2);

    const rejecting = createImagePrefetcher(() => Promise.reject(new Error('offline')));
    expect(() => rejecting('https://bp.test/b.webp')).not.toThrow();
    const throwing = createImagePrefetcher(() => { throw new Error('native missing'); });
    expect(throwing('https://bp.test/c.webp')).toBe(false);
  });

  it('keeps its bookkeeping bounded', () => {
    const calls: string[] = [];
    const prefetch = createImagePrefetcher(async (u) => { calls.push(u); return true; }, 2);
    prefetch('https://bp.test/1'); prefetch('https://bp.test/2'); prefetch('https://bp.test/3');
    prefetch('https://bp.test/1'); // evicted → may be warmed again (disk cache makes that cheap)
    expect(calls).toEqual(['https://bp.test/1', 'https://bp.test/2', 'https://bp.test/3', 'https://bp.test/1']);
  });
});

describe('what may be prefetched', () => {
  const today = (over: Partial<TodayResponse>): TodayResponse => ({
    has_daily: true,
    already_played: false,
    daily_challenge: {
      id: 1,
      challenge_date: '2026-11-06',
      challenge: { id: 9, title: 'x', difficulty: 'easy', hidden_image_url: 'https://bp.test/storage/challenges/hidden/display/a-w2048-v1.webp', category: null },
    },
    ...over,
  });

  it('Home warms the guess image of an unplayed daily only', () => {
    expect(dailyGuessImageToPrefetch(today({}))).toBe('https://bp.test/storage/challenges/hidden/display/a-w2048-v1.webp');
    expect(dailyGuessImageToPrefetch(today({ already_played: true }))).toBeNull();
    expect(dailyGuessImageToPrefetch(today({ has_daily: false }))).toBeNull();
    expect(dailyGuessImageToPrefetch(null)).toBeNull();
  });

  it('a pack guess warms the next step\'s guess image (never its reveal)', () => {
    const res = {
      result: { reveal_image_url: 'https://bp.test/r1.webp' },
      next_challenge: { hidden_image_url: 'https://bp.test/h2.webp', reveal_image_url: 'https://bp.test/SECRET.webp' },
    };
    expect(nextPackGuessImage(res)).toBe('https://bp.test/h2.webp');
    expect(revealImageAfterGuess(res.result)).toBe('https://bp.test/r1.webp');
    expect(nextPackGuessImage({ next_challenge: null })).toBeNull();
    expect(nextPackGuessImage(null)).toBeNull();
  });

  it('a daily/tournament guess warms its reveal', () => {
    expect(revealImageAfterGuess({ reveal_image_url: 'https://bp.test/r.webp' })).toBe('https://bp.test/r.webp');
    expect(revealImageAfterGuess({ reveal_image_url: null })).toBeNull();
    expect(revealImageAfterGuess(undefined)).toBeNull();
  });

  /**
   * SECURITY pin: before a guess is scored the app may only warm guess images.
   * Every prefetch call site must feed either a hidden_image_url or the output
   * of a helper that takes a scored-guess response. A new call site that reads
   * a reveal URL from anywhere else fails here.
   */
  it('no screen prefetches a reveal image outside a scored-guess response', () => {
    const root = path.join(__dirname, '..', '..');
    const files: string[] = [];
    const walk = (dir: string) => fs.readdirSync(dir, { withFileTypes: true }).forEach((e) => {
      const full = path.join(dir, e.name);
      if (e.isDirectory() && e.name !== '__tests__') walk(full);
      else if (/\.tsx?$/.test(e.name)) files.push(full);
    });
    walk(root);

    const callSites = files.flatMap((f) => {
      const src = fs.readFileSync(f, 'utf8');
      return [...src.matchAll(/prefetch(?:Imminent)?Images?\(([^;]*?)\);/g)].map((m) => ({ file: path.relative(root, f), arg: m[1] }));
    }).filter((c) => !c.file.startsWith(`services${path.sep}`) && !c.file.startsWith(`utils${path.sep}`));

    expect(callSites.length).toBeGreaterThanOrEqual(6);
    for (const { file, arg } of callSites) {
      const allowed = /^(dailyGuessImageToPrefetch\(|revealImageAfterGuess\((res\.data|result|result\.result)\)$|nextPackGuessImage\(result\)$|cr\.current_round\.challenge\?\.hidden_image_url$)/.test(arg.trim());
      expect({ file, arg, allowed }).toEqual({ file, arg, allowed: true });
    }
  });
});

describe('aspect ratio from API sizes', () => {
  it('uses valid sizes only', () => {
    expect(imageAspect(2048, 1536)).toBeCloseTo(4 / 3);
    expect(imageAspect(null, 1536)).toBeNull();
    expect(imageAspect(0, 10)).toBeNull();
    expect(imageAspect(NaN, 10)).toBeNull();
    expect(imageAspect('2048', 1536)).toBeNull();
    expect(imageAspect(100000, 1)).toBeNull();
  });

  it('recognises remote URLs', () => {
    expect(isRemoteImageUrl('https://x/y.webp')).toBe(true);
    expect(isRemoteImageUrl('http://127.0.0.1:8093/storage/a.jpg')).toBe(true);
    expect(isRemoteImageUrl('ftp://x')).toBe(false);
  });
});
