import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { CommonActions } from '@react-navigation/native';
import { MainTabScreenProps } from '../app/MainTabs';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { ListGroup, ListRow } from '../components/ListRow';
import { ProfileHeader } from '../components/profile/ProfileHeader';
import { XpProgressCard } from '../components/profile/XpProgressCard';
import { StatTiles } from '../components/profile/StatTiles';
import { ActionCard } from '../components/profile/ActionCard';
import { authApi } from '../api/authApi';
import { badgeApi } from '../api/badgeApi';
import { avatarApi } from '../api/avatarApi';
import { growth } from '../services/growth';
import { firstShareRewardView, type ShareRewardState } from '../utils/share';
import { getApiErrorMessage } from '../utils/apiError';
import { signOut } from '../app/signOut';
import { openSettings } from '../app/navigationActions';
import { classifySessionError, logoutLocally, profileLoadFailureView, SessionFailureKind, describeApiFailure, formatApiFailure } from '../utils/session';
import { devLog } from '../utils/devLog';
import { notifications } from '../services/notifications';
import { profileTiles, historySummary, type ProfileTileKey } from '../utils/profileModel';
import { useI18n } from '../i18n';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { User, ProfileStats } from '../types/auth';
import type { TournamentFinish } from '../types/badge';

type Props = MainTabScreenProps<'Profile'>;

/**
 * Profile 2.0: a compact player profile — identity, rank / XP, three
 * at-a-glance numbers, the achievement surfaces (Trophy Room, statistics,
 * Daily Challenge, history, rank ladder) and the Share / Rate actions.
 * Settings (sport, theme, language, notifications, account, logout, legal,
 * deletion) moved to SettingsScreen, opened from the gear in the header.
 */
export function ProfileScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [user, setUser] = useState<User | null>(null);
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [uploadingAvatar, setUploadingAvatar] = useState(false);
  const [avatarError, setAvatarError] = useState('');
  const [history, setHistory] = useState<TournamentFinish[]>([]);
  // Why /me failed: 'recoverable' (offline / 5xx → Retry + Logout) or
  // 'invalid' (dead session → Logout only; the navigator normally resets the
  // app to Login before this is even seen).
  const [profileFailure, setProfileFailure] = useState<SessionFailureKind | null>(null);
  const [statsFailed, setStatsFailed] = useState(false);
  const [historyFailed, setHistoryFailed] = useState(false);
  // Growth (v1.9.12): Share BallPicker can earn XP (server-side, bounded);
  // Rate BallPicker never earns anything. Both are secondary actions.
  const [shareState, setShareState] = useState<ShareRewardState | null>(null);
  const [sharingApp, setSharingApp] = useState(false);
  const [rating, setRating] = useState(false);
  const [growthMessage, setGrowthMessage] = useState('');
  const [growthMessageIsError, setGrowthMessageIsError] = useState(false);

  const loadShareState = useCallback(() => {
    growth.loadShareState().then(setShareState).catch(() => {});
  }, []);
  useEffect(() => { loadShareState(); }, [loadShareState]);
  useEffect(() => navigation.addListener('focus', loadShareState), [navigation, loadShareState]);

  const loadProfile = useCallback(async () => {
    // allSettled, not all: one failing section must not blank the whole
    // screen. The finishes only feed the History row's count here; the full
    // list lives on the stats screen.
    const [meRes, statsRes, finishRes] = await Promise.allSettled([
      authApi.me(),
      authApi.stats(),
      badgeApi.finishes(),
    ]);

    if (meRes.status === 'fulfilled') setUser(meRes.value);
    if (statsRes.status === 'fulfilled') setStats(statsRes.value);
    if (finishRes.status === 'fulfilled') setHistory(finishRes.value);

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

  // Refresh when returning (e.g. after changing sport or photo, or after XP
  // earned on a game screen).
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

  async function handleShareApp() {
    if (sharingApp) return;
    setSharingApp(true);
    setGrowthMessage('');
    const outcome = await growth.shareApp();
    if (!outcome.opened) {
      setGrowthMessage(t('profile.growth.shareError'));
      setGrowthMessageIsError(true);
    } else {
      if (outcome.state) setShareState(outcome.state);
      setGrowthMessage(outcome.xpAwarded > 0 ? t('profile.growth.shareXpEarned', { xp: outcome.xpAwarded }) : t('profile.growth.shareThanks'));
      setGrowthMessageIsError(false);
      if (outcome.xpAwarded > 0) loadProfile(); // XP card reflects the new XP
    }
    setSharingApp(false);
  }

  async function handleRateApp() {
    if (rating) return;
    setRating(true);
    setGrowthMessage('');
    // Native in-app review where available, the store page only because the
    // user asked. No XP, no trophy, no unlock — ever.
    const { action } = await growth.rateApp();
    if (action === 'unavailable') {
      setGrowthMessage(t('profile.growth.rateUnavailable'));
      setGrowthMessageIsError(false);
    }
    setRating(false);
  }

  async function handleLogout() {
    // Only reachable from the failed-profile state (Settings owns the regular
    // Log out). Best-effort server calls, then the local wipe — never blocked
    // by a dead network or a dead token.
    await logoutLocally({
      unregisterPush: () => notifications.unregisterPushToken(),
      apiLogout: () => authApi.logout(),
      clearLocal: signOut,
    });
    // CommonActions.reset bubbles past the tab navigator up to the root
    // stack, which is the navigator that owns the Login route.
    navigation.dispatch(CommonActions.reset({ index: 0, routes: [{ name: 'Login' }] }));
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

  const retryProfile = () => { loadProfile(); };
  const rank = stats?.rank ?? null;
  const tiles = stats ? profileTiles(stats) : null;
  const tileLabel: Record<ProfileTileKey, string> = {
    streak: t('common.labels.streak'),
    totalScore: t('profile.stats.totalScore'),
    bestDaily: t('profile.tiles.bestDaily'),
  };
  const hist = historySummary(history.length, historyFailed);
  // Only promise XP the server can still grant (first share once per account,
  // amount as the server reports it); once used, say so instead of hiding it.
  const firstShare = firstShareRewardView(shareState);
  const shareSubtitle = firstShare.kind === 'available'
    ? t('profile.growth.shareAppXp', { xp: firstShare.xp })
    : firstShare.kind === 'claimed'
      ? t('profile.growth.shareAppClaimed')
      : t('profile.growth.shareAppSubtitle');

  return (
    <Screen scroll padding={false} keyboardSafe={false}>
      <View style={styles.content}>
        <ProfileHeader
          name={user?.name}
          username={user?.username}
          avatarUrl={user?.avatar_url}
          rank={rank}
          uploading={uploadingAvatar}
          onChangePhoto={handleChangePhoto}
        />
        {avatarError ? <Text style={styles.inlineError}>{avatarError}</Text> : null}

        {/* Progression: the XP card (tap = full rank ladder), then three numbers. */}
        {stats?.rank ? <XpProgressCard rank={stats.rank} onPress={() => navigation.navigate('RankOverview')} /> : null}
        {statsFailed && !stats ? (
          <View style={styles.retryCard}>
            <EmptyState
              compact
              message={t('profile.screen.statsError')}
              actions={[{ label: t('common.buttons.retry'), onPress: retryProfile }]}
            />
          </View>
        ) : null}
        {tiles ? (
          <StatTiles
            items={tiles.map((tile) => ({
              key: tile.key,
              label: tileLabel[tile.key],
              value: tile.key === 'streak' ? t('common.time.days', { count: tile.value }) : tile.value.toLocaleString('en-US'),
              icon: tile.key === 'streak' ? 'flame' : undefined,
              highlight: tile.key === 'totalScore',
              onPress: () => navigation.navigate('ProfileStats', { section: tile.section }),
              testID: `profile-tile-${tile.key}`,
            }))}
          />
        ) : null}

        {/* Achievement surfaces — one card of rows instead of stacked accordions. */}
        <ListGroup title={t('profile.nav.heading')} style={styles.group} testID="profile-achievements">
          <ListRow
            icon="trophy"
            title={t('profile.screen.trophyRoom')}
            subtitle={t('profile.screen.trophyRoomSubtitle')}
            onPress={() => navigation.navigate('TrophyRoom')}
            testID="profile-nav-trophies"
          />
          <ListRow
            icon="stats"
            title={t('profile.nav.stats')}
            subtitle={t('profile.nav.statsSubtitle')}
            onPress={() => navigation.navigate('ProfileStats', { section: 'overall' })}
            testID="profile-nav-stats"
          />
          <ListRow
            icon="flame"
            title={t('profile.nav.daily')}
            subtitle={stats && stats.current_daily_streak > 0
              ? t('profile.screen.streakSummary', { days: stats.current_daily_streak })
              : t('profile.nav.dailySubtitle')}
            onPress={() => navigation.navigate('ProfileStats', { section: 'daily' })}
            testID="profile-nav-daily"
          />
          <ListRow
            icon="clock"
            title={t('profile.screen.history')}
            subtitle={hist.kind === 'failed' ? t('profile.screen.historyError') : t('profile.nav.historySubtitle')}
            value={hist.kind === 'count' ? t('profile.screen.historySummary', { count: hist.count }) : undefined}
            onPress={() => navigation.navigate('ProfileStats', { section: 'history' })}
            testID="profile-nav-history"
          />
          <ListRow
            icon="medal"
            title={t('profile.screen.viewAllRanks')}
            subtitle={t('profile.screen.viewAllRanksSubtitle')}
            onPress={() => navigation.navigate('RankOverview')}
            testID="profile-nav-ranks"
          />
        </ListGroup>

        {/* Settings — its own card, so it reads as app setup rather than an
            achievement. Same route as the header gear (openSettings). */}
        <ListGroup style={styles.group} testID="profile-settings-group">
          <ListRow
            icon="settings"
            title={t('nav.titles.settings')}
            subtitle={t('profile.nav.settingsSubtitle')}
            onPress={() => openSettings(navigation)}
            testID="profile-nav-settings"
          />
        </ListGroup>

        {/* Growth — deliberately secondary. Sharing uses the native share sheet
            (no social APIs) and may earn bounded XP; rating opens the native
            review / store page and earns nothing (store policy). */}
        <View style={styles.growthRow}>
          <ActionCard
            icon="share"
            title={t('profile.growth.shareApp')}
            subtitle={shareSubtitle}
            onPress={handleShareApp}
            disabled={sharingApp}
            testID="profile-share"
          />
          <ActionCard
            icon="star"
            title={t('profile.growth.rateApp')}
            subtitle={t('profile.growth.rateAppSubtitle')}
            onPress={handleRateApp}
            disabled={rating}
            testID="profile-rate"
          />
        </View>
        {growthMessage ? (
          <Text style={growthMessageIsError ? styles.inlineError : styles.growthMessage}>{growthMessage}</Text>
        ) : null}
      </View>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    content: { paddingHorizontal: spacing.md, paddingTop: spacing.sm + 4, paddingBottom: spacing.lg, gap: spacing.sm + 4 },
    inlineError: { color: theme.danger, fontSize: 13, textAlign: 'center' },
    retryCard: {
      backgroundColor: theme.surface, borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md,
    },
    group: { marginBottom: 0 },
    growthRow: { flexDirection: 'row', gap: spacing.sm },
    growthMessage: { fontSize: 13, color: theme.textSecondary, textAlign: 'center' },
  });
}
