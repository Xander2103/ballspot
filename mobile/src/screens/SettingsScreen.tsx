import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Switch, Linking, Alert } from 'react-native';
import { CommonActions } from '@react-navigation/native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { ConfirmModal } from '../components/ConfirmModal';
import { EmptyState } from '../components/EmptyState';
import { ListGroup, ListRow } from '../components/ListRow';
import { LanguagePicker } from '../components/LanguagePicker';
import { NotificationSettingsCard } from '../components/NotificationSettingsCard';
import { authApi } from '../api/authApi';
import { configApi, DEFAULT_APP_CONFIG } from '../api/configApi';
import { preferencesApi } from '../api/preferencesApi';
import { getApiErrorMessage } from '../utils/apiError';
import { getAuthErrorMessage } from '../utils/authErrors';
import { isLanguageCode, LanguageCode, DEFAULT_LANGUAGE } from '../utils/language';
import { signOut } from '../app/signOut';
import { deleteAccountAndSignOut, logoutLocally } from '../utils/session';
import { WEB_BASE_URL } from '../utils/urls';
import { notifications } from '../services/notifications';
import { useI18n } from '../i18n';
import { useTheme } from '../theme/useTheme';
import { THEME_META, ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { User } from '../types/auth';

const APP_VERSION = '1.0.0';

const WEB_BASE = WEB_BASE_URL;

type Props = NativeStackScreenProps<RootStackParamList, 'Settings'>;

/**
 * Settings (opened from the gear on Profile). Everything that used to be a
 * collapsible section at the bottom of Profile lives here, with the same
 * calls, confirmations and safeguards: preferred sport, theme, language,
 * notifications, account & security (2FA), logout, legal links, account
 * deletion, version and credits.
 */
export function SettingsScreen({ navigation }: Props) {
  const { theme, themeName, setTheme } = useTheme();
  const { t, locale, setLocale } = useI18n();
  const styles = createStyles(theme);

  const [user, setUser] = useState<User | null>(null);
  const [profileFailed, setProfileFailed] = useState(false);
  // Account settings (language / 2FA) — saved through /me/preferences and
  // mirrored into the local user so the rows update immediately.
  const [savingLanguage, setSavingLanguage] = useState<LanguageCode | null>(null);
  const [savingTwoFactor, setSavingTwoFactor] = useState(false);
  const [accountError, setAccountError] = useState('');
  // Launch control: the toggle that turns login 2FA ON is hidden unless the
  // backend allows new opt-ins (GET /api/config login_two_factor_opt_in).
  // Fail closed (hidden) when offline or on an older backend. Accounts that
  // already have it on always see the toggle so they can switch it off.
  const [twoFactorOptIn, setTwoFactorOptIn] = useState(DEFAULT_APP_CONFIG.login_two_factor_opt_in === true);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  useEffect(() => {
    let active = true;
    configApi
      .get()
      .then((cfg) => { if (active) setTwoFactorOptIn(cfg.login_two_factor_opt_in === true); })
      .catch(() => { /* keep the fail-closed default */ });
    return () => { active = false; };
  }, []);

  const loadUser = useCallback(() => {
    return authApi.me()
      .then((me) => { setUser(me); setProfileFailed(false); })
      .catch(() => setProfileFailed(true));
  }, []);

  useEffect(() => { loadUser(); }, [loadUser]);
  // Refresh when returning (e.g. after changing sport on the selection screen).
  useEffect(() => navigation.addListener('focus', () => { loadUser(); }), [navigation, loadUser]);

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

  const sport = user?.preferred_sport ?? null;
  const currentLanguage: LanguageCode = isLanguageCode(user?.preferred_language) ? user.preferred_language : DEFAULT_LANGUAGE;
  const twoFactorOn = user?.two_factor_enabled === true;
  const showTwoFactor = twoFactorOptIn || twoFactorOn;

  return (
    <Screen scroll padding={false}>
      <View style={styles.content}>
        {/* Only the rows that need /me degrade; theme, notifications, legal
            links and logout never depend on it. */}
        {profileFailed && !user ? (
          <View style={styles.failedCard}>
            <EmptyState
              compact
              message={t('profile.screen.loadErrorTitle')}
              actions={[{ label: t('common.buttons.retry'), onPress: () => { loadUser(); } }]}
            />
          </View>
        ) : null}

        <ListGroup title={t('profile.settings.preferences')}>
          <ListRow
            emoji={sport?.emoji ?? '🎯'}
            title={t('profile.settings.sport')}
            value={sport?.name ?? t('profile.screen.sportNotChosen')}
            onPress={() => navigation.navigate('SportSelection', { mode: 'change', currentSportId: sport?.id ?? null })}
            testID="settings-sport"
          />
        </ListGroup>

        <ListGroup title={t('profile.screen.appTheme')}>
          <View style={styles.inner}>
            {/* All five themes stay visible (wrapping grid) — nothing hides
                behind a horizontal scroll. */}
            <View style={styles.themeRow} accessibilityRole="radiogroup">
              {THEME_META.map((meta) => {
                const active = meta.name === themeName;
                return (
                  <TouchableOpacity
                    key={meta.name}
                    activeOpacity={0.8}
                    onPress={() => setTheme(meta.name)}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: active }}
                    accessibilityLabel={t(`profile.themes.${meta.name}.label`)}
                    style={[styles.themeChip, active && styles.themeChipActive]}
                    testID={`settings-theme-${meta.name}`}
                  >
                    <View style={styles.swatchRow}>
                      {meta.swatches.map((c, i) => (
                        <View key={i} style={[styles.swatch, { backgroundColor: c }]} />
                      ))}
                    </View>
                    <Text style={[styles.themeLabel, active && { color: theme.primary }]} numberOfLines={2} maxFontSizeMultiplier={1.3}>
                      {t(`profile.themes.${meta.name}.label`)}
                    </Text>
                  </TouchableOpacity>
                );
              })}
            </View>
            <Text style={styles.hint} maxFontSizeMultiplier={1.4}>{t(`profile.themes.${themeName}.description`)}</Text>
          </View>
        </ListGroup>

        <ListGroup title={t('common.language.title')}>
          <View style={styles.inner}>
            <LanguagePicker
              value={currentLanguage}
              onChange={handleLanguageChange}
              disabled={!!savingLanguage || !user}
              savingCode={savingLanguage}
            />
            <Text style={styles.hint} maxFontSizeMultiplier={1.4}>{t('profile.account.languageHint')}</Text>
          </View>
        </ListGroup>

        <ListGroup title={t('profile.screen.notifications')}>
          <View style={styles.inner}>
            <NotificationSettingsCard flat />
          </View>
        </ListGroup>

        <ListGroup title={t('profile.account.title')}>
          {showTwoFactor ? (
            <ListRow
              icon="shield"
              title={t('profile.security.twoFactorLabel')}
              subtitle={twoFactorOn ? t('profile.security.twoFactorOnHint') : t('profile.security.twoFactorOffHint')}
              right={(
                <Switch
                  value={twoFactorOn}
                  onValueChange={handleTwoFactorToggle}
                  disabled={savingTwoFactor || !user}
                  trackColor={{ true: theme.primary, false: theme.border }}
                  thumbColor="#ffffff"
                  accessibilityLabel={t('profile.security.twoFactorLabel')}
                />
              )}
              testID="settings-two-factor"
            />
          ) : null}
          <ListRow icon="logout" title={t('common.buttons.logout')} onPress={handleLogout} testID="settings-logout" />
        </ListGroup>
        {accountError ? <Text style={styles.inlineError}>{accountError}</Text> : null}

        <ListGroup title={t('profile.settings.about')}>
          <ListRow icon="shield" title={t('common.legal.privacy')} external onPress={() => Linking.openURL(`${WEB_BASE}/privacy`)} testID="settings-privacy" />
          <ListRow icon="info" title={t('common.legal.terms')} external onPress={() => Linking.openURL(`${WEB_BASE}/terms`)} testID="settings-terms" />
          <ListRow icon="help" title={t('common.legal.support')} external onPress={() => Linking.openURL(`${WEB_BASE}/support`)} testID="settings-support" />
        </ListGroup>

        {/* Destructive action — deliberately small and out of the way; the
            ConfirmModal still guards it. */}
        <TouchableOpacity
          onPress={() => setShowDeleteModal(true)}
          style={styles.deleteLink}
          activeOpacity={0.7}
          hitSlop={{ top: 8, bottom: 8, left: 16, right: 16 }}
          accessibilityRole="button"
          accessibilityLabel={t('profile.screen.deleteAccount')}
          testID="settings-delete-account"
        >
          <Text style={styles.deleteLinkText}>{t('profile.screen.deleteAccount')}</Text>
        </TouchableOpacity>

        <Text style={styles.versionText}>{t('profile.screen.version', { version: APP_VERSION })}</Text>
        <Text style={styles.creditText}>{t('profile.screen.credit')}</Text>
      </View>

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

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    content: { paddingHorizontal: spacing.md, paddingTop: spacing.md, paddingBottom: spacing.lg },
    failedCard: {
      backgroundColor: theme.surface, borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, marginBottom: spacing.md,
    },
    inner: { paddingHorizontal: spacing.md - 2, paddingVertical: spacing.sm + 4 },
    themeRow: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    themeChip: {
      flexGrow: 1, flexBasis: '30%', maxWidth: '48%', minHeight: 72,
      borderRadius: 12, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
      paddingVertical: spacing.sm + 2, paddingHorizontal: spacing.sm + 2,
    },
    themeChipActive: { borderColor: theme.primary, backgroundColor: theme.primary + '1a' },
    swatchRow: { flexDirection: 'row', gap: 5, marginBottom: spacing.sm },
    swatch: { width: 16, height: 16, borderRadius: 5, borderWidth: 1, borderColor: theme.border },
    themeLabel: { fontSize: 12, lineHeight: 15, fontWeight: '700', color: theme.text },
    hint: { fontSize: 12, color: theme.textMuted, marginTop: spacing.xs },
    inlineError: { color: theme.danger, fontSize: 13, textAlign: 'center', marginTop: -spacing.sm, marginBottom: spacing.md },
    deleteLink: { alignSelf: 'center', paddingVertical: spacing.xs, marginTop: spacing.xs, marginBottom: spacing.md },
    deleteLinkText: { fontSize: 13, fontWeight: '600', color: theme.danger },
    versionText: { fontSize: 12, color: theme.textMuted, textAlign: 'center', marginBottom: spacing.xs },
    creditText: { fontSize: 11, color: theme.textMuted, textAlign: 'center', marginBottom: spacing.md },
  });
}
