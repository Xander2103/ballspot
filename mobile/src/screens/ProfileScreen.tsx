import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Linking, TouchableOpacity, Alert, Switch } from 'react-native';
import { getApiErrorMessage } from '../utils/apiError';
import { getAuthErrorMessage } from '../utils/authErrors';
import { useI18n } from '../i18n';
import * as ImagePicker from 'expo-image-picker';
import { CommonActions } from '@react-navigation/native';
import { MainTabScreenProps } from '../app/MainTabs';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { Avatar } from '../components/Avatar';
import { RankCard } from '../components/RankCard';
import { NotificationSettingsCard } from '../components/NotificationSettingsCard';
import { ProfileHistoryCard } from '../components/ProfileHistoryCard';
import { CollapsibleSection } from '../components/CollapsibleSection';
import { EmptyState } from '../components/EmptyState';
import { StatList } from '../components/StatList';
import { LanguagePicker } from '../components/LanguagePicker';
import { authApi } from '../api/authApi';
import { preferencesApi } from '../api/preferencesApi';
import { isLanguageCode, languageLabel, LanguageCode, DEFAULT_LANGUAGE } from '../utils/language';
import { badgeApi } from '../api/badgeApi';
import { avatarApi } from '../api/avatarApi';
import { signOut } from '../app/signOut';
import { classifySessionError, deleteAccountAndSignOut, logoutLocally, profileLoadFailureView, SessionFailureKind, describeApiFailure, formatApiFailure } from '../utils/session';
import { devLog } from '../utils/devLog';
import { WEB_BASE_URL } from '../utils/urls';
import { notifications } from '../services/notifications';
import { useTheme } from '../theme/useTheme';
import { THEME_META, ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { User, ProfileStats } from '../types/auth';
import type { TournamentFinish } from '../types/badge';

const APP_VERSION = '1.0.0';

const WEB_BASE = WEB_BASE_URL;

type Props = MainTabScreenProps<'Profile'>;

export function ProfileScreen({ navigation }: Props) {
  const { theme, themeName, setTheme } = useTheme();
  const { t, locale, setLocale } = useI18n();
  const styles = createStyles(theme);

  const [user, setUser] = useState<User | null>(null);
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [uploadingAvatar, setUploadingAvatar] = useState(false);
  const [avatarError, setAvatarError] = useState('');
  const [history, setHistory] = useState<TournamentFinish[]>([]);
  // Why /me failed: 'recoverable' (offline / 5xx → Retry + Logout) or
  // 'invalid' (dead session → Logout only; the navigator normally resets the
  // app to Login before this is even seen).
  const [profileFailure, setProfileFailure] = useState<SessionFailureKind | null>(null);
  const [statsFailed, setStatsFailed] = useState(false);
  const [historyFailed, setHistoryFailed] = useState(false);
  // Account settings (language / 2FA) — saved through /me/preferences and
  // mirrored into the local user so the summary lines update immediately.
  const [savingLanguage, setSavingLanguage] = useState<LanguageCode | null>(null);
  const [savingTwoFactor, setSavingTwoFactor] = useState(false);
  const [accountError, setAccountError] = useState('');

  const loadProfile = useCallback(async () => {
    // allSettled, not all: one failing section must not blank the whole
    // screen. Profile shows at most 10 history entries; Recent XP lives on
    // All Ranks.
    const [meRes, statsRes, finishRes] = await Promise.allSettled([
      authApi.me(),
      authApi.stats(),
      badgeApi.finishes(),
    ]);

    if (meRes.status === 'fulfilled') setUser(meRes.value);
    if (statsRes.status === 'fulfilled') setStats(statsRes.value);
    if (finishRes.status === 'fulfilled') setHistory(finishRes.value.slice(0, 10));

    // Endpoint + status + stable code only (never the body) — enough to tell
    // "offline" from "profile_unavailable" from "session dead" in Metro.
    if (meRes.status === 'rejected') devLog(formatApiFailure(describeApiFailure(meRes.reason, 'GET /me')));
    if (statsRes.status === 'rejected') devLog(formatApiFailure(describeApiFailure(statsRes.reason, 'GET /profile/stats')));
    setProfileFailure(meRes.status === 'rejected' ? classifySessionError(meRes.reason, '/me') : null);
    setStatsFailed(statsRes.status === 'rejected');
    setHistoryFailed(finishRes.status === 'rejected');
  }, []);

  useEffect(() => {
    loadProfile().finally(() => setLoading(false));
  }, [loadProfile]);

  // Refresh when returning (e.g. after changing sport on the selection screen).
  useEffect(() => navigation.addListener('focus', () => { loadProfile(); }), [navigation, loadProfile]);

  async function handleChangePhoto() {
    setAvatarError('');
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) {
        setAvatarError(t('profile.screen.photoPermission'));
        return;
      }
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        allowsEditing: true,
        aspect: [1, 1],
        quality: 0.8,
      });
      if (result.canceled || !result.assets?.length) return;

      setUploadingAvatar(true);
      const { avatar_url } = await avatarApi.upload(result.assets[0].uri);
      setUser((u) => (u ? { ...u, avatar_url } : u));
    } catch (e: unknown) {
      // Never raw server text: 5xx / offline / HTML bodies map to friendly copy.
      setAvatarError(getApiErrorMessage(e, t('profile.screen.photoError')));
    } finally {
      setUploadingAvatar(false);
    }
  }

  async function handleLanguageChange(code: LanguageCode) {
    if (savingLanguage || !user || user.preferred_language === code) return;
    setAccountError('');
    setSavingLanguage(code);
    // Optimistic: switch the whole app right away so the picker feels
    // instant; revert to the previous locale if the server rejects it.
    const previousLocale = locale;
    setLocale(code);
    try {
      const res = await preferencesApi.update({ preferred_language: code });
      setUser((u) => (u ? { ...u, preferred_language: res.preferred_language } : u));
      setLocale(res.preferred_language);
    } catch (e: unknown) {
      setLocale(previousLocale);
      setAccountError(getApiErrorMessage(e, t('profile.account.languageError')));
    } finally {
      setSavingLanguage(null);
    }
  }

  async function handleTwoFactorToggle(enabled: boolean) {
    if (savingTwoFactor || !user) return;
    setAccountError('');
    setSavingTwoFactor(true);
    const previous = user.two_factor_enabled ?? false;
    setUser((u) => (u ? { ...u, two_factor_enabled: enabled } : u)); // optimistic
    try {
      const res = await preferencesApi.update({ two_factor_enabled: enabled });
      setUser((u) => (u ? { ...u, two_factor_enabled: res.two_factor_enabled } : u));
    } catch (e: unknown) {
      setUser((u) => (u ? { ...u, two_factor_enabled: previous } : u)); // revert
      setAccountError(getApiErrorMessage(e, t('profile.security.twoFactorError')));
    } finally {
      setSavingTwoFactor(false);
    }
  }

  async function handleLogout() {
    // Server-side first, while the auth token still works: stop pushes to
    // this device, then revoke the session. Both best-effort — a dead network
    // or a dead token must never trap the user in a signed-in state; the
    // local wipe always runs.
    await logoutLocally({
      unregisterPush: () => notifications.unregisterPushToken(),
      apiLogout: () => authApi.logout(),
      clearLocal: signOut,
    });
    // CommonActions.reset bubbles past the tab navigator up to the root
    // stack, which is the navigator that owns the Login route.
    navigation.dispatch(CommonActions.reset({ index: 0, routes: [{ name: 'Login' }] }));
  }

  async function handleDeleteAccount() {
    if (deleting) return; // guard against double-submit
    setDeleting(true);
    setDeleteError('');
    const leave = () => navigation.dispatch(CommonActions.reset({ index: 0, routes: [{ name: 'Login' }] }));
    // The server removes ALL push registrations on deletion, so only the
    // local cleanup is needed here.
    const result = await deleteAccountAndSignOut({ deleteAccount: () => authApi.deleteAccount(), clearLocal: signOut });

    if (result.outcome === 'deleted') {
      setShowDeleteModal(false);
      // The account is gone server-side; make that unmistakable before the
      // app returns to the public login screen.
      Alert.alert(
        t('profile.delete.doneTitle'),
        t('profile.delete.doneMessage'),
        [{ text: t('common.buttons.ok'), onPress: leave }],
        { cancelable: false },
      );
      setTimeout(leave, 4000); // never strand the user if the alert is dismissed silently (web)
      return;
    }
    if (result.outcome === 'session_gone') {
      // The session is already dead (token revoked) — nothing to delete
      // with; local state is cleared so the user is not stuck on a dead screen.
      setShowDeleteModal(false);
      leave();
      return;
    }
    // Code-aware: e.g. `admin_account_protected` renders its translated copy.
    setDeleteError(getAuthErrorMessage(result.error, t('profile.delete.error')));
    setDeleting(false);
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  // Only a failed /me leaves nothing to render; every other section degrades
  // to its own small retry below. Logout is always on offer here — this
  // screen must never be a dead end.
  if (profileFailure && !user) {
    const view = profileLoadFailureView(profileFailure);
    const onAction = {
      retry: () => { setLoading(true); loadProfile().finally(() => setLoading(false)); },
      logout: () => { handleLogout(); },
    };
    return (
      <Screen padding>
        <EmptyState
          title={view.title}
          message={view.message}
          actions={view.actions.map((a) => ({ label: a.label, onPress: onAction[a.id] }))}
        />
      </Screen>
    );
  }

  const sport = user?.preferred_sport ?? null;
  // Theme slugs are stable; their display copy lives under profile.themes.<slug>.
  const activeThemeLabel = THEME_META.some((m) => m.name === themeName) ? t(`profile.themes.${themeName}.label`) : undefined;
  const retryProfile = () => { loadProfile(); };
  const currentLanguage: LanguageCode = isLanguageCode(user?.preferred_language) ? user.preferred_language : DEFAULT_LANGUAGE;
  const twoFactorOn = user?.two_factor_enabled === true;

  return (
    <Screen scroll padding>
      {/* Avatar + identity */}
      <View style={styles.avatarWrap}>
        <Avatar uri={user?.avatar_url} name={user?.name} size={96} />
        <TouchableOpacity onPress={handleChangePhoto} disabled={uploadingAvatar} style={styles.changePhoto} activeOpacity={0.7}>
          {uploadingAvatar
            ? <ActivityIndicator color={theme.primary} size="small" />
            : <Text style={styles.changePhotoText}>{t('profile.screen.changePhoto')}</Text>}
        </TouchableOpacity>
      </View>
      {avatarError ? <Text style={styles.inlineError}>{avatarError}</Text> : null}
      <Text style={styles.name}>{user?.name ?? '—'}</Text>
      <Text style={styles.username}>@{user?.username ?? '—'}</Text>

      {/* Progression cluster: rank card, all ranks, then the XP that feeds it. */}
      {stats?.rank ? <RankCard rank={stats.rank} /> : null}
      {statsFailed && !stats ? (
        <View style={styles.retryCard}>
          <EmptyState
            compact
            message={t('profile.screen.statsError')}
            actions={[{ label: t('common.buttons.retry'), onPress: retryProfile }]}
          />
        </View>
      ) : null}

      <TouchableOpacity
        style={styles.entryCard}
        activeOpacity={0.8}
        onPress={() => navigation.navigate('RankOverview')}
      >
        <View style={styles.entryLeft}>
          <Text style={styles.entryIcon}>🏅</Text>
          <View style={styles.entryTextWrap}>
            <Text style={styles.entryTitle}>{t('profile.screen.viewAllRanks')}</Text>
            <Text style={styles.entrySubtitle}>{t('profile.screen.viewAllRanksSubtitle')}</Text>
          </View>
        </View>
        <Text style={styles.entryAction}>›</Text>
      </TouchableOpacity>

      {/* Trophy Room entry point */}
      <TouchableOpacity
        style={[styles.entryCard, styles.trophyCard]}
        activeOpacity={0.8}
        onPress={() => navigation.navigate('TrophyRoom')}
      >
        <View style={styles.entryLeft}>
          <Text style={styles.entryIcon}>🏆</Text>
          <View style={styles.entryTextWrap}>
            <Text style={styles.entryTitle}>{t('profile.screen.trophyRoom')}</Text>
            <Text style={styles.entrySubtitle}>{t('profile.screen.trophyRoomSubtitle')}</Text>
          </View>
        </View>
        <Text style={styles.entryAction}>›</Text>
      </TouchableOpacity>

      {/* Collapsed-by-default detail sections keep the page short. */}
      <CollapsibleSection title={t('profile.screen.history')} summary={history.length > 0 ? t('profile.screen.historySummary', { count: history.length }) : undefined}>
        {history.length > 0 ? (
          <ProfileHistoryCard finishes={history} flat />
        ) : historyFailed ? (
          <EmptyState
            compact
            message={t('profile.screen.historyError')}
            actions={[{ label: t('common.buttons.retry'), onPress: retryProfile }]}
          />
        ) : (
          <EmptyState compact message={t('profile.screen.historyEmpty')} />
        )}
      </CollapsibleSection>

      <CollapsibleSection title={t('profile.screen.yourSport')} summary={sport ? `${sport.emoji} ${sport.name}` : undefined}>
        <TouchableOpacity
          style={styles.sportRow}
          activeOpacity={0.8}
          onPress={() => navigation.navigate('SportSelection', { mode: 'change', currentSportId: sport?.id ?? null })}
        >
          <View style={styles.entryLeft}>
            <Text style={styles.sportEmoji}>{sport?.emoji ?? '🎯'}</Text>
            <Text style={styles.sportLabel}>{sport?.name ?? t('profile.screen.sportNotChosen')}</Text>
          </View>
          <Text style={styles.sportAction}>{t('profile.screen.changeSport')}</Text>
        </TouchableOpacity>
      </CollapsibleSection>

      <CollapsibleSection title={t('profile.screen.appTheme')} summary={activeThemeLabel}>
        <View style={styles.themeGrid}>
          {THEME_META.map((meta) => {
            const active = meta.name === themeName;
            return (
              <TouchableOpacity
                key={meta.name}
                activeOpacity={0.8}
                onPress={() => setTheme(meta.name)}
                style={[styles.themeCard, active && { borderColor: theme.primary, borderWidth: 2 }]}
              >
                <View style={styles.swatchRow}>
                  {meta.swatches.map((c, i) => (
                    <View key={i} style={[styles.swatch, { backgroundColor: c }]} />
                  ))}
                </View>
                <Text style={styles.themeLabel}>{t(`profile.themes.${meta.name}.label`)}</Text>
                <Text style={styles.themeDesc}>{t(`profile.themes.${meta.name}.description`)}</Text>
                {active ? <Text style={styles.themeActive}>{t('profile.screen.themeActive')}</Text> : null}
              </TouchableOpacity>
            );
          })}
        </View>
      </CollapsibleSection>

      {stats ? (
        <>
          <CollapsibleSection title={t('profile.screen.stats')}>
            <StatList
              items={[
                { label: t('profile.stats.tournaments'), value: stats.tournaments_count },
                { label: t('profile.stats.completed'), value: stats.completed_tournaments_count },
                { label: t('profile.stats.guesses'), value: stats.guesses_count },
                { label: t('profile.stats.totalScore'), value: stats.total_score, highlight: true },
                { label: t('profile.stats.averageScore'), value: stats.average_score },
              ]}
            />
          </CollapsibleSection>

          <CollapsibleSection
            title={t('profile.screen.dailyStats')}
            summary={stats.current_daily_streak > 0 ? t('profile.screen.streakSummary', { days: stats.current_daily_streak }) : undefined}
          >
            <StatList
              items={[
                {
                  label: t('profile.stats.currentStreak'),
                  value: t('common.time.days', { count: stats.current_daily_streak }),
                  detail: t('profile.stats.bestDetail', { best: stats.best_daily_streak }),
                },
                { label: t('profile.stats.played'), value: stats.daily_challenges_played },
                { label: t('profile.stats.averageScore'), value: stats.average_daily_score },
                { label: t('profile.stats.bestScore'), value: stats.best_daily_score, highlight: true },
              ]}
            />
          </CollapsibleSection>
        </>
      ) : null}

      <CollapsibleSection title={t('profile.screen.notifications')}>
        <NotificationSettingsCard flat />
      </CollapsibleSection>

      <CollapsibleSection
        title={t('profile.account.title')}
        summary={t('profile.account.summary', {
          language: languageLabel(currentLanguage),
          state: t(twoFactorOn ? 'common.labels.on' : 'common.labels.off'),
        })}
      >
        <LanguagePicker
          label={t('common.language.preferred')}
          value={currentLanguage}
          onChange={handleLanguageChange}
          disabled={!!savingLanguage}
          savingCode={savingLanguage}
        />
        <Text style={styles.settingHint}>{t('profile.account.languageHint')}</Text>

        <View style={styles.toggleRow}>
          <View style={styles.toggleTextWrap}>
            <Text style={styles.toggleLabel}>{t('profile.security.twoFactorLabel')}</Text>
            <Text style={styles.toggleHint}>
              {twoFactorOn ? t('profile.security.twoFactorOnHint') : t('profile.security.twoFactorOffHint')}
            </Text>
          </View>
          <Switch
            value={twoFactorOn}
            onValueChange={handleTwoFactorToggle}
            disabled={savingTwoFactor}
            trackColor={{ true: theme.primary, false: theme.border }}
            thumbColor="#ffffff"
            accessibilityLabel={t('profile.security.twoFactorLabel')}
          />
        </View>
        {accountError ? <Text style={styles.inlineError}>{accountError}</Text> : null}
      </CollapsibleSection>

      <AppButton title={t('common.buttons.logout')} onPress={handleLogout} variant="secondary" style={styles.logoutBtn} />

      {/* Info / legal footer — plain links, not fake settings. */}
      <View style={styles.footerLinks}>
        <FooterLink styles={styles} label={t('common.legal.privacy')} url={`${WEB_BASE}/privacy`} />
        <Text style={styles.footerDot}>·</Text>
        <FooterLink styles={styles} label={t('common.legal.terms')} url={`${WEB_BASE}/terms`} />
        <Text style={styles.footerDot}>·</Text>
        <FooterLink styles={styles} label={t('common.legal.support')} url={`${WEB_BASE}/support`} />
      </View>

      {/* Destructive action — deliberately small and out of the way; the
          ConfirmModal still guards it. */}
      <TouchableOpacity
        onPress={() => setShowDeleteModal(true)}
        style={styles.deleteLink}
        activeOpacity={0.7}
        hitSlop={{ top: 8, bottom: 8, left: 16, right: 16 }}
        accessibilityRole="button"
        accessibilityLabel={t('profile.screen.deleteAccount')}
      >
        <Text style={styles.deleteLinkText}>{t('profile.screen.deleteAccount')}</Text>
      </TouchableOpacity>

      <Text style={styles.versionText}>{t('profile.screen.version', { version: APP_VERSION })}</Text>
      <Text style={styles.creditText}>{t('profile.screen.credit')}</Text>

      <ConfirmModal
        visible={showDeleteModal}
        title={t('profile.delete.title')}
        message={t('profile.delete.message')}
        confirmLabel={t('profile.delete.confirm')}
        cancelLabel={t('common.buttons.cancel')}
        onConfirm={handleDeleteAccount}
        onCancel={() => { setShowDeleteModal(false); setDeleteError(''); }}
        loading={deleting}
        errorText={deleteError}
        destructive
      />
    </Screen>
  );
}

function FooterLink({ styles, label, url }: { styles: Styles; label: string; url: string }) {
  return (
    <TouchableOpacity onPress={() => Linking.openURL(url)} activeOpacity={0.7} hitSlop={{ top: 8, bottom: 8 }}>
      <Text style={styles.footerLink}>{label}</Text>
    </TouchableOpacity>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    avatarWrap: { alignItems: 'center', marginBottom: spacing.sm },
    changePhoto: { marginTop: spacing.sm, paddingVertical: 6, paddingHorizontal: spacing.md },
    changePhotoText: { color: theme.accent, fontSize: 14, fontWeight: '700' },
    inlineError: { color: theme.danger, fontSize: 13, textAlign: 'center', marginBottom: spacing.sm },
    name: { fontSize: 24, fontWeight: '800', color: theme.text, textAlign: 'center', marginBottom: 4 },
    username: { fontSize: 14, color: theme.textSecondary, textAlign: 'center', marginBottom: spacing.xl },
    entryCard: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, marginBottom: spacing.sm,
    },
    entryLeft: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, flex: 1, marginRight: spacing.sm },
    entryIcon: { fontSize: 22 },
    entryTextWrap: { flex: 1 },
    entryTitle: { fontSize: 15, fontWeight: '700', color: theme.text },
    entrySubtitle: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    entryAction: { fontSize: 20, fontWeight: '700', color: theme.textMuted },
    retryCard: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, marginBottom: spacing.sm,
    },
    trophyCard: { marginBottom: spacing.lg },
    sportRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    sportEmoji: { fontSize: 22 },
    sportLabel: { fontSize: 15, fontWeight: '700', color: theme.text },
    sportAction: { fontSize: 13, fontWeight: '700', color: theme.accent },
    themeGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    themeCard: {
      backgroundColor: theme.surfaceElevated, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, minWidth: '46%', flex: 1,
    },
    swatchRow: { flexDirection: 'row', gap: 6, marginBottom: spacing.sm },
    swatch: { width: 22, height: 22, borderRadius: 6, borderWidth: 1, borderColor: theme.border },
    themeLabel: { fontSize: 15, fontWeight: '700', color: theme.text },
    themeDesc: { fontSize: 12, color: theme.textMuted, marginTop: 2 },
    themeActive: { fontSize: 12, fontWeight: '700', color: theme.primary, marginTop: spacing.sm },
    settingHint: { fontSize: 12, color: theme.textMuted, marginTop: -spacing.xs, marginBottom: spacing.md },
    toggleRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.md },
    toggleTextWrap: { flex: 1 },
    toggleLabel: { fontSize: 15, fontWeight: '700', color: theme.text },
    toggleHint: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    logoutBtn: { marginTop: spacing.lg, marginBottom: spacing.xl },
    footerLinks: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'center',
      flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.xl,
    },
    footerLink: { fontSize: 13, color: theme.textSecondary, fontWeight: '600' },
    footerDot: { fontSize: 13, color: theme.textMuted },
    deleteLink: { alignSelf: 'center', paddingVertical: spacing.xs, marginBottom: spacing.md },
    deleteLinkText: { fontSize: 13, fontWeight: '600', color: theme.danger },
    versionText: { fontSize: 12, color: theme.textMuted, textAlign: 'center', marginBottom: spacing.xs },
    creditText: { fontSize: 11, color: theme.textMuted, textAlign: 'center', marginBottom: spacing.md },
  });
}
