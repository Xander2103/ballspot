import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Linking, TouchableOpacity, Alert } from 'react-native';
import { getApiErrorMessage } from '../utils/apiError';
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
import { authApi } from '../api/authApi';
import { badgeApi } from '../api/badgeApi';
import { avatarApi } from '../api/avatarApi';
import { signOut } from '../app/signOut';
import { notifications } from '../services/notifications';
import { useTheme } from '../theme/useTheme';
import { THEME_META, ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { User, ProfileStats } from '../types/auth';
import type { TournamentFinish } from '../types/badge';

const APP_VERSION = '1.0.0';

const API_BASE = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api';
const WEB_BASE = process.env.EXPO_PUBLIC_WEB_URL ?? API_BASE.replace(/\/api$/, '');

type Props = MainTabScreenProps<'Profile'>;

export function ProfileScreen({ navigation }: Props) {
  const { theme, themeName, setTheme } = useTheme();
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
  const [profileFailed, setProfileFailed] = useState(false);
  const [statsFailed, setStatsFailed] = useState(false);
  const [historyFailed, setHistoryFailed] = useState(false);

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

    setProfileFailed(meRes.status === 'rejected');
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
        setAvatarError('Photo permission is needed to choose an avatar.');
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
    } catch (e: any) {
      setAvatarError(e?.message || 'Could not update your photo. Please try again.');
    } finally {
      setUploadingAvatar(false);
    }
  }

  async function handleLogout() {
    // Server-side first, while the auth token still works: stop pushes to
    // this device, then revoke the session. Both best-effort — a dead network
    // must never trap the user in a signed-in state.
    try { await notifications.unregisterPushToken(); } catch {}
    try { await authApi.logout(); } catch {}
    await signOut();
    // CommonActions.reset bubbles past the tab navigator up to the root
    // stack, which is the navigator that owns the Login route.
    navigation.dispatch(CommonActions.reset({ index: 0, routes: [{ name: 'Login' }] }));
  }

  async function handleDeleteAccount() {
    if (deleting) return; // guard against double-submit
    setDeleting(true);
    setDeleteError('');
    const leave = () => navigation.dispatch(CommonActions.reset({ index: 0, routes: [{ name: 'Login' }] }));
    try {
      // The server removes ALL push registrations on deletion, so only the
      // local cleanup is needed here.
      await authApi.deleteAccount();
      await signOut();
      setShowDeleteModal(false);
      // The account is gone server-side; make that unmistakable before the
      // app returns to the public login screen.
      Alert.alert(
        'Account deleted',
        'Your account has been deleted and your personal details removed. You can create a new account with the same email at any time.',
        [{ text: 'OK', onPress: leave }],
        { cancelable: false },
      );
      setTimeout(leave, 4000); // never strand the user if the alert is dismissed silently (web)
    } catch (e: unknown) {
      const status = (e as { status?: number })?.status;
      if (status === 401) {
        // The session is already dead (token revoked) — nothing to delete
        // with; sign out locally so the user is not stuck on a dead screen.
        await signOut();
        setShowDeleteModal(false);
        leave();
        return;
      }
      setDeleteError(getApiErrorMessage(e, 'We could not delete your account right now. Please try again in a moment or contact support.'));
      setDeleting(false);
    }
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  // Only a failed /me leaves nothing to render; every other section degrades
  // to its own small retry below.
  if (profileFailed && !user) {
    return (
      <Screen padding>
        <EmptyState
          title="Couldn't load your profile"
          message="Check your connection and try again."
          actions={[{ label: 'Retry', onPress: () => { setLoading(true); loadProfile().finally(() => setLoading(false)); } }]}
        />
      </Screen>
    );
  }

  const sport = user?.preferred_sport ?? null;
  const activeThemeLabel = THEME_META.find((m) => m.name === themeName)?.label;
  const retryProfile = () => { loadProfile(); };

  return (
    <Screen scroll padding>
      {/* Avatar + identity */}
      <View style={styles.avatarWrap}>
        <Avatar uri={user?.avatar_url} name={user?.name} size={96} />
        <TouchableOpacity onPress={handleChangePhoto} disabled={uploadingAvatar} style={styles.changePhoto} activeOpacity={0.7}>
          {uploadingAvatar
            ? <ActivityIndicator color={theme.primary} size="small" />
            : <Text style={styles.changePhotoText}>Change photo</Text>}
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
            message="Couldn't load your rank and stats."
            actions={[{ label: 'Retry', onPress: retryProfile }]}
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
            <Text style={styles.entryTitle}>View all ranks</Text>
            <Text style={styles.entrySubtitle}>Every rank, your progress and your recent XP.</Text>
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
            <Text style={styles.entryTitle}>Trophy Room</Text>
            <Text style={styles.entrySubtitle}>Badges, achievements and top finishes.</Text>
          </View>
        </View>
        <Text style={styles.entryAction}>›</Text>
      </TouchableOpacity>

      {/* Collapsed-by-default detail sections keep the page short. */}
      <CollapsibleSection title="History" summary={history.length > 0 ? `${history.length} finished` : undefined}>
        {history.length > 0 ? (
          <ProfileHistoryCard finishes={history} flat />
        ) : historyFailed ? (
          <EmptyState
            compact
            message="Couldn't load your history."
            actions={[{ label: 'Retry', onPress: retryProfile }]}
          />
        ) : (
          <EmptyState compact message="No finished tournaments yet." />
        )}
      </CollapsibleSection>

      <CollapsibleSection title="Your sport" summary={sport ? `${sport.emoji} ${sport.name}` : undefined}>
        <TouchableOpacity
          style={styles.sportRow}
          activeOpacity={0.8}
          onPress={() => navigation.navigate('SportSelection', { mode: 'change', currentSportId: sport?.id ?? null })}
        >
          <View style={styles.entryLeft}>
            <Text style={styles.sportEmoji}>{sport?.emoji ?? '🎯'}</Text>
            <Text style={styles.sportLabel}>{sport?.name ?? 'Not chosen yet'}</Text>
          </View>
          <Text style={styles.sportAction}>Change sport ›</Text>
        </TouchableOpacity>
      </CollapsibleSection>

      <CollapsibleSection title="App theme" summary={activeThemeLabel}>
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
                <Text style={styles.themeLabel}>{meta.label}</Text>
                <Text style={styles.themeDesc}>{meta.description}</Text>
                {active ? <Text style={styles.themeActive}>Active</Text> : null}
              </TouchableOpacity>
            );
          })}
        </View>
      </CollapsibleSection>

      {stats ? (
        <>
          <CollapsibleSection title="Stats">
            <StatList
              items={[
                { label: 'Tournaments', value: stats.tournaments_count },
                { label: 'Completed', value: stats.completed_tournaments_count },
                { label: 'Guesses', value: stats.guesses_count },
                { label: 'Total score', value: stats.total_score, highlight: true },
                { label: 'Average score', value: stats.average_score },
              ]}
            />
          </CollapsibleSection>

          <CollapsibleSection
            title="Daily challenge stats"
            summary={stats.current_daily_streak > 0 ? `🔥 ${stats.current_daily_streak} day streak` : undefined}
          >
            <StatList
              items={[
                {
                  label: 'Current streak',
                  value: `${stats.current_daily_streak} ${stats.current_daily_streak === 1 ? 'day' : 'days'}`,
                  detail: `best ${stats.best_daily_streak}`,
                },
                { label: 'Played', value: stats.daily_challenges_played },
                { label: 'Average score', value: stats.average_daily_score },
                { label: 'Best score', value: stats.best_daily_score, highlight: true },
              ]}
            />
          </CollapsibleSection>
        </>
      ) : null}

      <CollapsibleSection title="Notifications">
        <NotificationSettingsCard flat />
      </CollapsibleSection>

      <AppButton title="Logout" onPress={handleLogout} variant="secondary" style={styles.logoutBtn} />

      {/* Info / legal footer — plain links, not fake settings. */}
      <View style={styles.footerLinks}>
        <FooterLink styles={styles} label="Privacy Policy" url={`${WEB_BASE}/privacy`} />
        <Text style={styles.footerDot}>·</Text>
        <FooterLink styles={styles} label="Terms of Service" url={`${WEB_BASE}/terms`} />
        <Text style={styles.footerDot}>·</Text>
        <FooterLink styles={styles} label="Support" url={`${WEB_BASE}/support`} />
      </View>

      {/* Destructive action — deliberately small and out of the way; the
          ConfirmModal still guards it. */}
      <TouchableOpacity
        onPress={() => setShowDeleteModal(true)}
        style={styles.deleteLink}
        activeOpacity={0.7}
        hitSlop={{ top: 8, bottom: 8, left: 16, right: 16 }}
        accessibilityRole="button"
        accessibilityLabel="Delete account"
      >
        <Text style={styles.deleteLinkText}>Delete account</Text>
      </TouchableOpacity>

      <Text style={styles.versionText}>v{APP_VERSION}</Text>
      <Text style={styles.creditText}>BallPicker is created by Van Malder Studio.</Text>

      <ConfirmModal
        visible={showDeleteModal}
        title="Delete account?"
        message="This will remove your account access and anonymize your profile. This action cannot be undone."
        confirmLabel="Delete account"
        cancelLabel="Cancel"
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
