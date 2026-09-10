/**
 * Static checks over the screen/component sources: every translation key the
 * UI asks for exists in English (so no raw key can render on a launch-critical
 * screen), the register payload carries the chosen language, and the profile
 * language picker switches the active locale.
 *
 * RN components cannot be rendered under this runner (see jest.config.js), so
 * these assertions read the source files instead.
 */
import * as fs from 'fs';
import * as path from 'path';
import { flattenKeys } from '../core';
import { en } from '../locales/en';

const SRC = path.resolve(__dirname, '..', '..');

const LAUNCH_CRITICAL = [
  'screens/LoginScreen.tsx',
  'screens/RegisterScreen.tsx',
  'screens/EmailVerificationScreen.tsx',
  'screens/LoginVerificationScreen.tsx',
  'screens/ForgotPasswordScreen.tsx',
  'screens/ResetPasswordScreen.tsx',
  'screens/HomeScreen.tsx',
  'screens/DailyChallengeScreen.tsx',
  'screens/DailyResultScreen.tsx',
  'screens/PacksScreen.tsx',
  'screens/PackDetailScreen.tsx',
  'screens/PackGuessScreen.tsx',
  'screens/PackResultScreen.tsx',
  'screens/PackCompleteScreen.tsx',
  'screens/TournamentsScreen.tsx',
  'screens/CreateLeagueScreen.tsx',
  'screens/JoinLeagueScreen.tsx',
  'screens/LeagueDetailScreen.tsx',
  'screens/LeaderboardScreen.tsx',
  'screens/FriendsScreen.tsx',
  'screens/FriendProfileScreen.tsx',
  'screens/ProfileScreen.tsx',
  'screens/GuessScreen.tsx',
  'screens/ResultScreen.tsx',
  'screens/WeeklyLeaderboardScreen.tsx',
  'screens/SportSelectionScreen.tsx',
  'screens/RankOverviewScreen.tsx',
  'screens/ScanFriendCodeScreen.tsx',
  'components/ConfirmModal.tsx',
  'components/LanguagePicker.tsx',
  'components/NotificationSettingsCard.tsx',
  'components/TrophyRoom.tsx',
  'app/AppNavigator.tsx',
  'app/MainTabs.tsx',
];

function read(rel: string): string {
  return fs.readFileSync(path.join(SRC, rel), 'utf8');
}

/** Every literal key passed to t(...) / translate(...). Dynamic keys (template literals) are skipped. */
function literalKeys(source: string): string[] {
  const out: string[] = [];
  const re = /\b(?:t|translate)\(\s*'([^']+)'/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(source))) out.push(m[1]);
  return out;
}

/** Template-literal keys like t(`profile.themes.${name}.label`) → their static prefix. */
function dynamicPrefixes(source: string): string[] {
  const out: string[] = [];
  const re = /\b(?:t|translate)\(\s*`([^`$]+)\$\{/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(source))) out.push(m[1]);
  return out;
}

const englishKeys = new Set(flattenKeys(en));
const englishBranches = new Set(flattenKeys(en).flatMap((k) => k.split('.').map((_, i, parts) => parts.slice(0, i + 1).join('.'))));

describe('launch-critical screens only use keys that exist in English', () => {
  it.each(LAUNCH_CRITICAL)('%s', (file) => {
    const source = read(file);
    const keys = literalKeys(source);
    const missing = keys.filter((k) => !englishKeys.has(k) && !(englishKeys.has(`${k}_one`) && englishKeys.has(`${k}_other`)));
    expect({ file, missing }).toEqual({ file, missing: [] });

    for (const prefix of dynamicPrefixes(source)) {
      // Keep whole segments only: 'a.b.m' + ${n} → branch 'a.b'.
      const branch = prefix.endsWith('.') ? prefix.slice(0, -1) : prefix.slice(0, prefix.lastIndexOf('.'));
      expect({ file, dynamicPrefix: prefix, known: englishBranches.has(branch) }).toEqual({ file, dynamicPrefix: prefix, known: true });
    }
  });

  it('every launch-critical screen actually uses the translator', () => {
    for (const file of LAUNCH_CRITICAL) {
      // LanguagePicker shows native language names only; LeaderboardScreen has no copy of its own.
      if (file === 'components/LanguagePicker.tsx' || file === 'screens/LeaderboardScreen.tsx') continue;
      const source = read(file);
      expect({ file, usesI18n: /useI18n\(\)|from '\.\.\/i18n(\/core)?'/.test(source) }).toEqual({ file, usesI18n: true });
    }
  });

  it('has no leftover English JSX text nodes on launch-critical screens', () => {
    // A JSX text node with two or more English words: ">Some words<". Numbers,
    // punctuation and single tokens (emoji, "·", "@") are fine.
    const offenders: string[] = [];
    for (const file of LAUNCH_CRITICAL) {
      const source = read(file);
      const re = />\s*([A-Z][a-z]+(?: [a-z]+){1,})\s*</g;
      let m: RegExpExecArray | null;
      while ((m = re.exec(source))) offenders.push(`${file}: ${m[1]}`);
    }
    expect(offenders).toEqual([]);
  });
});

describe('language wiring', () => {
  it('register sends the selected language to the backend and switches the copy immediately', () => {
    const source = read('screens/RegisterScreen.tsx');
    expect(source).toMatch(/preferred_language:\s*language/);
    expect(source).toMatch(/setLocale\(/);
    expect(source).toMatch(/rememberConfigDefault\(/);
  });

  it('profile language change updates the active locale', () => {
    const source = read('screens/ProfileScreen.tsx');
    expect(source).toMatch(/preferencesApi\.update\(\{\s*preferred_language:\s*code\s*\}\)/);
    expect(source).toMatch(/setLocale\(code\)/);
  });

  it('the API client sends the active language and the navigator initialises it', () => {
    expect(read('api/client.ts')).toMatch(/'Accept-Language':\s*getLocale\(\)/);
    expect(read('app/AppNavigator.tsx')).toMatch(/initLocale\(user\.preferred_language\)/);
    expect(read('app/authFlow.ts')).toMatch(/setLocale\(me\.preferred_language\)/);
  });
});

describe('guess marker follows the challenge sport', () => {
  const read2 = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

  it('no marker component hardcodes the football any more', () => {
    for (const file of ['components/ImageGuessPicker.tsx', 'components/FullscreenImageViewer.tsx', 'components/ResultImageSection.tsx']) {
      const source = read2(file);
      expect({ file, hardcodedFootball: /<Text[^>]*>⚽<\/Text>/.test(source) }).toEqual({ file, hardcodedFootball: false });
      expect(source).toMatch(/getSportObjectIcon\(/);
    }
  });

  it('PackGuessScreen uses the challenge sport, never the pack sport', () => {
    const source = read2('screens/PackGuessScreen.tsx');
    expect(source).toMatch(/sportSlug=\{challenge\.sport\?\.slug\}/);
    expect(source).not.toMatch(/pack\.sport/);
    expect(source).toMatch(/sportSlug: result\.result\.sport\?\.slug \?\? challenge\.sport\?\.slug/);
  });

  it('Daily and Tournament guess/result screens pass the challenge sport through', () => {
    expect(read2('screens/DailyChallengeScreen.tsx')).toMatch(/sportSlug=\{challenge\.challenge\.sport\?\.slug\}/);
    expect(read2('screens/GuessScreen.tsx')).toMatch(/sportSlug=\{round\.challenge\.sport\?\.slug\}/);
    expect(read2('screens/ResultScreen.tsx')).toMatch(/sportSlug=\{result\.sport\?\.slug \?\? routeSportSlug\}/);
    expect(read2('screens/DailyResultScreen.tsx')).toMatch(/sportSlug=\{sportSlug\}/);
    expect(read2('screens/PackResultScreen.tsx')).toMatch(/sportSlug=\{sportSlug\}/);
  });
});
