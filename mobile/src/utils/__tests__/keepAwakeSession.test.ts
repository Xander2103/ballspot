import { KEEP_AWAKE_TAGS, startKeepAwakeSession, type KeepAwakeApi } from '../keepAwakeSession';
import * as fs from 'fs';
import * as path from 'path';

const flush = () => new Promise((r) => setImmediate(r));

function fakeApi(opts: { activateFails?: boolean; deactivateThrows?: boolean } = {}) {
  const calls: string[] = [];
  let resolveActivate: (() => void) | null = null;
  const api: KeepAwakeApi = {
    activate: (tag) => {
      calls.push(`on:${tag}`);
      if (opts.activateFails) return Promise.reject(new Error('unsupported'));
      return new Promise<void>((r) => { resolveActivate = r; });
    },
    deactivate: (tag) => {
      calls.push(`off:${tag}`);
      if (opts.deactivateThrows) throw new Error('boom');
      return Promise.resolve();
    },
  };
  return { api, calls, resolve: () => resolveActivate?.() };
}

describe('gameplay keep-awake session', () => {
  it('activates with the screen tag and releases on stop', async () => {
    const f = fakeApi();
    const s = startKeepAwakeSession(f.api, KEEP_AWAKE_TAGS.daily);
    f.resolve();
    await flush();
    s.stop();
    expect(f.calls).toEqual(['on:bp-gameplay-daily', 'off:bp-gameplay-daily']);
  });

  it('a stop before activation resolved still releases the lock (fast replace / back)', async () => {
    const f = fakeApi();
    const s = startKeepAwakeSession(f.api, KEEP_AWAKE_TAGS.pack);
    s.stop();
    f.resolve();
    await flush();
    expect(f.calls).toEqual(['on:bp-gameplay-pack', 'off:bp-gameplay-pack']);
  });

  it('stop is idempotent (blur then unmount releases once)', async () => {
    const f = fakeApi();
    const s = startKeepAwakeSession(f.api, KEEP_AWAKE_TAGS.tournament);
    f.resolve();
    await flush();
    s.stop();
    s.stop();
    expect(f.calls.filter((c) => c.startsWith('off:'))).toHaveLength(1);
  });

  it('never throws when the device does not support it', async () => {
    const f = fakeApi({ activateFails: true });
    const s = startKeepAwakeSession(f.api, KEEP_AWAKE_TAGS.daily);
    await flush();
    expect(() => s.stop()).not.toThrow();
    expect(f.calls).toEqual(['on:bp-gameplay-daily']); // nothing to release

    const g = fakeApi({ deactivateThrows: true });
    const t = startKeepAwakeSession(g.api, KEEP_AWAKE_TAGS.daily);
    g.resolve();
    await flush();
    expect(() => t.stop()).not.toThrow();
  });

  it('uses a distinct tag per gameplay screen', () => {
    expect(new Set(Object.values(KEEP_AWAKE_TAGS)).size).toBe(3);
  });
});

describe('gameplay screens hold the lock only while focused', () => {
  const SRC = path.resolve(__dirname, '..', '..');
  const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

  it('the hook is focus-scoped (blur / unmount release it)', () => {
    const hook = read('app/useGameplayKeepAwake.ts');
    expect(hook).toMatch(/useFocusEffect\(/);
    expect(hook).toMatch(/return \(\) => session\.stop\(\);/);
    expect(hook).toMatch(/from 'expo-keep-awake'/);
  });

  it.each([
    ['screens/DailyChallengeScreen.tsx', 'daily', '!!challenge'],
    ['screens/GuessScreen.tsx', 'tournament', '!!round'],
    ['screens/PackGuessScreen.tsx', 'pack', '!!challenge'],
  ])('%s keeps the screen awake during play', (file, tag, cond) => {
    expect(read(file)).toContain(`useGameplayKeepAwake(KEEP_AWAKE_TAGS.${tag}, ${cond});`);
  });

  it('no non-gameplay screen keeps the device awake', () => {
    for (const f of ['screens/HomeScreen.tsx', 'screens/ProfileScreen.tsx', 'screens/FriendsScreen.tsx',
      'screens/DailyResultScreen.tsx', 'screens/ResultScreen.tsx', 'screens/PackResultScreen.tsx']) {
      expect(read(f)).not.toMatch(/KeepAwake/);
    }
  });
});
