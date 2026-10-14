/**
 * Regression pins for the October 2026 pre-release audit.
 *
 * RN components cannot be rendered under this runner (see jest.config.js), so
 * the screen-level fixes are pinned the same way src/i18n/__tests__/screens.test.ts
 * does it: by reading the source. Each check names the user-facing defect it
 * prevents from coming back.
 */
import * as fs from 'fs';
import * as path from 'path';

const SRC = path.resolve(__dirname, '..', '..');
const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

describe('lobby screen never spams alerts while offline', () => {
  it('LeagueDetailScreen polls silently and only alerts on loads the user asked for', () => {
    const source = read('screens/LeagueDetailScreen.tsx');
    expect(source).toMatch(/shouldAlertOnLoadFailure\(trigger\)/);
    expect(source).toMatch(/load\('poll'\)/);
    expect(source).toMatch(/LOBBY_POLL_INTERVAL_MS/);
    // The old shape: every poll tick ran the same alerting loader.
    expect(source).not.toMatch(/setInterval\(load,/);
  });
});

describe('no screen traps the user without Retry / Logout', () => {
  it('SportSelectionScreen offers Retry and a way out when the sport list fails to load', () => {
    const source = read('screens/SportSelectionScreen.tsx');
    expect(source).toMatch(/<EmptyState/);
    expect(source).toMatch(/common\.buttons\.retry/);
    expect(source).toMatch(/common\.buttons\.logout/);
    expect(source).toMatch(/logoutLocally\(/);
    expect(source).toMatch(/routes: \[\{ name: 'Login' \}\]/);
  });
});

describe('guess submits cannot double-fire', () => {
  it.each([
    'screens/GuessScreen.tsx',
    'screens/DailyChallengeScreen.tsx',
    'screens/PackGuessScreen.tsx',
  ])('%s checks the in-flight flag synchronously before submitting', (file) => {
    // `if (submitting) return;` must be the first statement (comments allowed).
    expect(read(file)).toMatch(/async function handleSubmit\(\) \{\s*(\/\/[^\n]*\n\s*)*if \(submitting\) return;/);
  });

  it('GuessScreen opens the stored result when the backend reports an already-submitted round', () => {
    expect(read('screens/GuessScreen.tsx')).toMatch(/code === 'already_submitted'/);
  });
});

describe('store build config', () => {
  const app = JSON.parse(fs.readFileSync(path.resolve(SRC, '..', 'app.json'), 'utf8'));
  const pluginConfig = (name: string): Record<string, unknown> => {
    const entry = (app.expo.plugins as unknown[]).find((p) => Array.isArray(p) && p[0] === name) as [string, Record<string, unknown>] | undefined;
    if (!entry) throw new Error(`plugin ${name} not configured`);
    return entry[1];
  };

  it('does not declare microphone usage the app never needs (iOS purpose strings, Android permission)', () => {
    expect(pluginConfig('expo-camera').microphonePermission).toBe(false);
    expect(pluginConfig('expo-image-picker').microphonePermission).toBe(false);
    expect(app.expo.android.blockedPermissions).toContain('android.permission.RECORD_AUDIO');
  });

  it('keeps the camera and photo purpose strings reviewers read', () => {
    expect(typeof app.expo.ios.infoPlist.NSCameraUsageDescription).toBe('string');
    expect(typeof app.expo.ios.infoPlist.NSPhotoLibraryUsageDescription).toBe('string');
  });
});

describe('auth error copy', () => {
  it.each(['en/errors.ts', 'nl.ts', 'fr.ts', 'de.ts', 'es.ts'])('%s has copy for two_factor_send_failed', (file) => {
    expect(read(path.join('i18n/locales', file))).toMatch(/two_factor_send_failed:\s*['"]/);
  });
});
