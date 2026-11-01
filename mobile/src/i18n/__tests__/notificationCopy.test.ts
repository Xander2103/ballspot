/**
 * The notification copy and the flow's wiring. Components cannot render under
 * this runner (see jest.config.js), so the wiring checks read the sources.
 */
import * as fs from 'fs';
import * as path from 'path';
import { flattenKeys } from '../core';
import { en } from '../locales/en';
import { nl } from '../locales/nl';
import { de } from '../locales/de';
import { fr } from '../locales/fr';
import { es } from '../locales/es';

const LOCALES = { en, nl, de, fr, es };
const SRC = path.resolve(__dirname, '..', '..');
const read = (file: string) => fs.readFileSync(path.join(SRC, file), 'utf8');

describe('invitation copy', () => {
  it('uses the agreed Dutch wording', () => {
    expect(nl.home.notifPrompt).toMatchObject({
      title: 'Mis geen enkele uitdaging',
      message: 'Ontvang een herinnering wanneer de dagelijkse uitdaging klaarstaat en blijf op de hoogte van je toernooien.',
      support: 'Zo mis je minder speelkansen en vergroot je je kans om mee te strijden voor de prijzen.',
      confirm: 'Herinneringen inschakelen',
      cancel: 'Misschien later',
    });
  });

  it.each(Object.entries(LOCALES))('%s has every invitation and settings string, filled in', (_, locale) => {
    const expected = [
      ...flattenKeys(en.home.notifPrompt).map((k) => ['home', 'notifPrompt', k]),
      ...flattenKeys(en.notifications).map((k) => ['notifications', k]),
    ];
    for (const parts of expected) {
      let node: unknown = locale;
      for (const part of parts.flatMap((p) => p.split('.'))) node = (node as Record<string, unknown>)?.[part];
      expect(typeof node).toBe('string');
      expect((node as string).trim()).not.toBe('');
    }
  });

  it('is really translated: the visible strings differ from English', () => {
    for (const locale of [nl, de, fr, es]) {
      for (const key of ['title', 'message', 'support', 'confirm', 'cancel'] as const) {
        expect(locale.home.notifPrompt[key]).not.toBe(en.home.notifPrompt[key]);
      }
    }
  });
});

describe('Dutch notification wording', () => {
  it('no longer says a tournament waits for your guess', () => {
    const copy = JSON.stringify([nl.home.notifPrompt, nl.notifications]);
    expect(copy).not.toMatch(/op je gok/);
    expect(copy).not.toMatch(/\bgok\b/);
    expect(copy).not.toMatch(/duwtje/);
  });
});

describe('one permission flow', () => {
  it('only the invitation hook and the settings card ask the OS', () => {
    const askers = ['screens/HomeScreen.tsx', 'screens/SettingsScreen.tsx', 'screens/ProfileScreen.tsx', 'app/signOut.ts']
      .filter((f) => read(f).includes('requestPermission'));
    expect(askers).toEqual([]);
    expect(read('app/useNotificationInvite.ts')).toContain('notifications.requestPermission()');
    expect(read('components/NotificationSettingsCard.tsx')).toContain('notifications.requestPermission()');
  });

  it('"Maybe later" does not reach the OS dialog', () => {
    const hook = read('app/useNotificationInvite.ts');
    expect(hook).toMatch(/const onLater = close;/);
    const close = hook.slice(hook.indexOf('const close ='), hook.indexOf('const onLater'));
    expect(close).not.toContain('requestPermission');
  });

  it('never creates the Android channel when the app only reads the permission', () => {
    const service = read('services/notifications.ts');
    const read_ = service.slice(service.indexOf('async function getPermissionStatus'), service.indexOf('async function requestPermission'));
    expect(read_).not.toContain('ensureAndroidChannel');
    const ask = service.slice(service.indexOf('async function requestPermission'), service.indexOf('async function readDeviceToken'));
    expect(ask.indexOf('ensureAndroidChannel')).toBeLessThan(ask.indexOf('requestPermissionsAsync'));
    expect(ask).toContain('ensureAndroidChannel');
  });

  it('signing out keeps the invitation record and resets the push registration', () => {
    const signOut = read('app/signOut.ts');
    expect(signOut).not.toContain('notif_invite');
    expect(signOut).not.toContain('notif_prompt_seen');
    expect(signOut).toContain('notifications.forgetDevice()');
  });

  it('settings re-read the OS on focus and on return from the background', () => {
    const card = read('components/NotificationSettingsCard.tsx');
    expect(card).toContain('useFocusEffect');
    expect(card).toContain("AppState.addEventListener('change'");
    expect(card).not.toContain('getPermissionStatus');
  });

  it('a blocked device opens the OS settings instead of asking again', () => {
    expect(read('components/NotificationSettingsCard.tsx')).toContain('Linking.openSettings()');
    expect(read('app/useNotificationInvite.ts')).toContain('Linking.openSettings()');
  });
});

describe('tournament leaderboard row copy', () => {
  it.each(Object.entries(LOCALES))('%s has singular and plural round counts', (_name, locale) => {
    const stats = (locale as any).game.leaderboard;
    expect(stats.rowStats_one).toContain('{{count}}');
    expect(stats.rowStats_other).toContain('{{count}}');
    expect(stats.rowStats_one).not.toBe(stats.rowStats_other);
  });
});
