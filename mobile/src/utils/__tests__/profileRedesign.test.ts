/**
 * Regression pins for Profile 2.0 (October 2026): the Profile tab became a
 * compact player profile and its settings moved to a dedicated Settings
 * screen. RN components cannot render under this runner (see jest.config.js),
 * so — like src/i18n/__tests__/screens.test.ts — these read the sources and
 * pin the behaviour that must not silently disappear in a later restyle.
 */
import * as fs from 'fs';
import * as path from 'path';

const SRC = path.resolve(__dirname, '..', '..');
const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

describe('Profile 2.0 navigation', () => {
  it('registers the Settings and stats routes on the root stack', () => {
    const nav = read('app/AppNavigator.tsx');
    expect(nav).toMatch(/Settings: undefined;/);
    expect(nav).toMatch(/ProfileStats: \{ section\?: StatsSection \} \| undefined;/);
    expect(nav).toMatch(/<Stack\.Screen name="Settings" component=\{SettingsScreen\}/);
    expect(nav).toMatch(/<Stack\.Screen name="ProfileStats" component=\{ProfileStatsScreen\}/);
  });

  it('opens Settings from a gear in the Profile tab header', () => {
    const tabs = read('app/MainTabs.tsx');
    expect(tabs).toMatch(/icon="settings"/);
    expect(tabs).toMatch(/testID="profile-settings"/);
    expect(tabs).toMatch(/navigate\('Settings'\)/);
  });

  it('Profile links every achievement surface and no longer stacks accordions', () => {
    const profile = read('screens/ProfileScreen.tsx');
    expect(profile).toMatch(/navigate\('TrophyRoom'\)/);
    expect(profile).toMatch(/navigate\('RankOverview'\)/);
    expect(profile).toMatch(/navigate\('ProfileStats', \{ section: 'overall' \}\)/);
    expect(profile).toMatch(/navigate\('ProfileStats', \{ section: 'daily' \}\)/);
    expect(profile).toMatch(/navigate\('ProfileStats', \{ section: 'history' \}\)/);
    expect(profile).not.toMatch(/CollapsibleSection/);
  });
});

describe('Profile keeps its own behaviour', () => {
  const profile = read('screens/ProfileScreen.tsx');

  it('still loads /me, stats and finishes independently (allSettled) and retries on focus', () => {
    expect(profile).toMatch(/Promise\.allSettled\(\[\s*authApi\.me\(\),\s*authApi\.stats\(\),\s*badgeApi\.finishes\(\),?\s*\]\)/);
    expect(profile).toMatch(/navigation\.addListener\('focus'/);
  });

  it('keeps the photo change with its permission check and friendly error', () => {
    expect(profile).toMatch(/ImagePicker\.requestMediaLibraryPermissionsAsync\(\)/);
    expect(profile).toMatch(/profile\.screen\.photoPermission/);
    expect(profile).toMatch(/avatarApi\.upload\(/);
    expect(profile).toMatch(/getApiErrorMessage\(e, t\('profile\.screen\.photoError'\)\)/);
  });

  it('keeps Share (rewarded, bounded) and Rate (never rewarded) with the same growth calls', () => {
    expect(profile).toMatch(/growth\.shareApp\(\)/);
    expect(profile).toMatch(/growth\.rateApp\(\)/);
    expect(profile).toMatch(/availableShareRewards\(shareState, 'app', null, localDateString\(new Date\(\)\)\)\.xp/);
    expect(profile).toMatch(/outcome\.xpAwarded > 0 \? t\('profile\.growth\.shareXpEarned'/);
    expect(profile).toMatch(/action === 'unavailable'/);
    // Rating never touches XP or the profile; sharing refreshes the rank after a reward.
    expect(profile).toMatch(/if \(outcome\.xpAwarded > 0\) loadProfile\(\);/);
  });

  it('renders the XP card from the server rank through the shared model (no client-side XP maths)', () => {
    expect(profile).toMatch(/<XpProgressCard rank=\{stats\.rank\}/);
    const card = read('components/profile/XpProgressCard.tsx');
    expect(card).toMatch(/xpProgress\(rank\)/);
    expect(card).not.toMatch(/next_rank_xp|current_rank_min_xp/);
    const model = read('utils/profileModel.ts');
    expect(model).toMatch(/rank\.is_max_rank \? 100 : Math\.max\(0, Math\.min\(100, rank\.progress_to_next_rank_pct\)\)/);
  });
});

describe('Settings keeps every moved setting and its safeguards', () => {
  const settings = read('screens/SettingsScreen.tsx');

  it('contains sport, theme, language, notifications, 2FA, logout, legal links, deletion and version', () => {
    expect(settings).toMatch(/navigate\('SportSelection', \{ mode: 'change', currentSportId: sport\?\.id \?\? null \}\)/);
    expect(settings).toMatch(/THEME_META\.map\(/);
    expect(settings).toMatch(/setTheme\(meta\.name\)/);
    expect(settings).toMatch(/<LanguagePicker/);
    expect(settings).toMatch(/<NotificationSettingsCard flat \/>/);
    expect(settings).toMatch(/onValueChange=\{handleTwoFactorToggle\}/);
    expect(settings).toMatch(/testID="settings-logout"/);
    for (const page of ['privacy', 'terms', 'support']) {
      expect(settings).toMatch(new RegExp(`Linking\\.openURL\\(\`\\$\\{WEB_BASE\\}/${page}\`\\)`));
    }
    expect(settings).toMatch(/profile\.screen\.version/);
    expect(settings).toMatch(/profile\.screen\.credit/);
  });

  it('guards account deletion with the destructive confirm modal, in-flight lock and error display', () => {
    expect(settings).toMatch(/if \(deleting\) return;/);
    expect(settings).toMatch(/onConfirm=\{handleDeleteAccount\}/);
    expect(settings).toMatch(/loading=\{deleting\}/);
    expect(settings).toMatch(/errorText=\{deleteError\}/);
    expect(settings).toMatch(/destructive/);
    expect(settings).toMatch(/profile\.delete\.doneTitle/);
    expect(settings).toMatch(/setTimeout\(leave, 4000\)/);
    expect(settings).toMatch(/result\.outcome === 'session_gone'/);
    expect(settings).toMatch(/getAuthErrorMessage\(result\.error, t\('profile\.delete\.error'\)\)/);
  });

  it('reverts optimistic language and 2FA changes when the server rejects them', () => {
    expect(settings).toMatch(/setLocale\(previousLocale\)/);
    expect(settings).toMatch(/two_factor_enabled: previous/);
  });

  it('logs out server-side first (push token, session) and resets to Login', () => {
    expect(settings).toMatch(/unregisterPush: \(\) => notifications\.unregisterPushToken\(\)/);
    expect(settings).toMatch(/apiLogout: \(\) => authApi\.logout\(\)/);
    expect(settings).toMatch(/routes: \[\{ name: 'Login' \}\]/);
  });
});
