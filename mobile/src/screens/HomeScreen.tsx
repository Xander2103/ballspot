import React, { useEffect, useState, useCallback } from 'react';
import {
  View, Text, StyleSheet, ScrollView, TouchableOpacity, Image,
} from 'react-native';
import { MainTabScreenProps } from '../app/MainTabs';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { EmptyState } from '../components/EmptyState';
import { ConfirmModal } from '../components/ConfirmModal';
import { Avatar } from '../components/Avatar';
import { leagueApi } from '../api/leagueApi';
import { authApi } from '../api/authApi';
import { dailyApi } from '../api/dailyApi';
import { notificationsApi } from '../api/notificationsApi';
import { notifications } from '../services/notifications';
import { applyReminderState } from '../services/reminderScheduler';
import { localFlags } from '../storage/localFlags';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { User } from '../types/auth';
import { TodayResponse, DailyStats } from '../types/daily';

// Horizontal BallPicker brand header (wordmark). Rendered as the Home hero.
const brandHeader = require('../../assets/BallPickerHeader.png');

type Props = MainTabScreenProps<'Play'>;

// Persisted flag so we ask for notification permission at most once (non-spammy).
const NOTIF_PROMPT_SEEN = 'notif_prompt_seen';

function todayDateFormatted(): string {
  return new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function DailyCard({
  today, stats, navigation, styles,
}: {
  today: TodayResponse | null;
  stats: DailyStats | null;
  navigation: Props['navigation'];
  styles: Styles;
}) {
  if (!today?.has_daily) {
    const sportName = today?.sport?.name;
    const isOtherSport = sportName && today?.sport?.slug !== 'football';
    return (
      <View style={styles.dailyCard}>
        <Text style={styles.dailyCardTitle}>
          {today?.sport?.emoji ?? '⚽'} Daily {sportName ?? 'Ball'} Challenge
        </Text>
        <Text style={styles.dailyCardDate}>{todayDateFormatted()}</Text>
        <Text style={styles.dailyCardEmpty}>
          {isOtherSport
            ? `No ${sportName} daily today — try Football, or the next one lands tomorrow.`
            : 'No challenge today. The next daily lands tomorrow.'}
        </Text>
      </View>
    );
  }

  const emoji = today.daily_challenge?.challenge?.sport?.emoji ?? '⚽';
  // Monthly progress ("Day 4 of 31") from the backend; absent on older servers.
  const monthIndex = today.daily_challenge?.daily_month_index;
  const monthTotal = today.daily_challenge?.daily_month_total;
  const monthProgress = monthIndex && monthTotal ? ` · Day ${monthIndex} of ${monthTotal}` : '';

  if (today.already_played) {
    return (
      <View style={styles.dailyCard}>
        <Text style={styles.dailyCardTitle}>{emoji} Daily Ball Challenge</Text>
        <Text style={styles.dailyCardDate}>{todayDateFormatted()}{monthProgress}</Text>
        {!!stats?.current_streak && <Text style={styles.dailyStreak}>🔥 {stats.current_streak} day streak</Text>}
        <AppButton
          title="View Today's Result"
          onPress={() => navigation.navigate('DailyResult', { dailyChallengeId: today.daily_challenge!.id })}
          variant="secondary"
          style={styles.dailyBtn}
        />
      </View>
    );
  }

  return (
    <View style={styles.dailyCard}>
      <Text style={styles.dailyCardTitle}>{emoji} Daily Ball Challenge</Text>
      <Text style={styles.dailyCardDate}>{todayDateFormatted()}{monthProgress}</Text>
      {today.daily_challenge?.challenge && (
        <Text style={styles.dailyChallengeInfo}>
          {today.daily_challenge.challenge.category ? `${today.daily_challenge.challenge.category.name} · ` : ''}
          {today.daily_challenge.challenge.difficulty}
        </Text>
      )}
      {!!stats?.current_streak && <Text style={styles.dailyStreak}>🔥 {stats.current_streak} day streak</Text>}
      <AppButton
        title="Play Daily Challenge"
        onPress={() => navigation.navigate('DailyChallenge', { dailyChallengeId: today.daily_challenge!.id })}
        style={styles.dailyBtn}
      />
    </View>
  );
}

export function HomeScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  // Tournaments render in their own tab now; Home still fetches them silently
  // because the local reminder scheduling below needs the pending-action state.
  const [leagues, setLeagues] = useState<League[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const [todayDaily, setTodayDaily] = useState<TodayResponse | null>(null);
  const [dailyStats, setDailyStats] = useState<DailyStats | null>(null);
  const [dailyLoading, setDailyLoading] = useState(true);

  const [notifPromptVisible, setNotifPromptVisible] = useState(false);
  const [notifPromptLoading, setNotifPromptLoading] = useState(false);

  const load = useCallback(async () => {
    let me: User | null = null;
    try {
      const [meRes, list] = await Promise.all([authApi.me(), leagueApi.list()]);
      me = meRes;
      setUser(meRes);
      setLeagues(list);
    } catch {
      // silent
    } finally {
      setLoading(false);
    }

    const [todayRes, statsRes] = await Promise.allSettled([
      dailyApi.today(me?.preferred_sport?.slug),
      dailyApi.stats(),
    ]);
    if (todayRes.status === 'fulfilled') setTodayDaily(todayRes.value);
    if (statsRes.status === 'fulfilled') setDailyStats(statsRes.value);
    setDailyLoading(false);
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => navigation.addListener('focus', load), [navigation, load]);

  // One-time, non-spammy notification permission prompt after the app is in use.
  // If already granted, make sure the push token is registered. Web no-ops.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      notifications.configureHandler();
      if (!notifications.isSupported()) return;
      const status = await notifications.getPermissionStatus();
      if (cancelled) return;
      if (status === 'granted') {
        notifications.registerPushToken();
      } else if (status === 'undetermined') {
        const seen = await localFlags.get(NOTIF_PROMPT_SEEN);
        if (!cancelled && !seen) setNotifPromptVisible(true);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  // Once today's daily + tournament data is loaded, (re)schedule local reminders
  // to match: suppress the daily reminder if it's already done, enable the
  // tournament reminder only when there's a pending action. Runs on every focus
  // so completing a challenge cancels its reminder.
  useEffect(() => {
    if (loading || dailyLoading) return;
    let cancelled = false;
    (async () => {
      if (!notifications.isSupported()) return;
      if ((await notifications.getPermissionStatus()) !== 'granted') return;
      const settings = await notificationsApi.getSettings().catch(() => null);
      if (cancelled || !settings) return;
      await applyReminderState(settings, todayDaily, leagues);
    })();
    return () => { cancelled = true; };
  }, [loading, dailyLoading, todayDaily, leagues]);

  async function handleEnableNotifications() {
    setNotifPromptLoading(true);
    try {
      const status = await notifications.requestPermission();
      await localFlags.set(NOTIF_PROMPT_SEEN, '1');
      if (status === 'granted') {
        await notifications.registerPushToken();
        const settings = await notificationsApi.getSettings().catch(() => null);
        if (settings) await applyReminderState(settings, todayDaily, leagues);
      }
    } finally {
      setNotifPromptLoading(false);
      setNotifPromptVisible(false);
    }
  }

  async function handleDismissNotifications() {
    await localFlags.set(NOTIF_PROMPT_SEEN, '1');
    setNotifPromptVisible(false);
  }

  const sport = user?.preferred_sport ?? null;

  return (
    <Screen padding={false}>
      {/* App header: wordmark + greeting live in ONE surface block with a
          single bottom border, so the logo reads as the screen's real header
          rather than an image card floating above it. */}
      <View style={styles.header}>
        <Image
          source={brandHeader}
          style={styles.brandImage}
          resizeMode="contain"
          accessibilityRole="image"
          accessibilityLabel="BallPicker"
        />
        <View style={styles.topBar}>
          <View style={styles.topBarLeft}>
            <Text style={styles.greeting}>Hey, {user?.name || '…'}</Text>
            <Text style={styles.sub}>@{user?.username || '…'}</Text>
          </View>
          <TouchableOpacity onPress={() => navigation.navigate('Profile')} activeOpacity={0.8}>
            <Avatar uri={user?.avatar_url} name={user?.name} size={42} />
          </TouchableOpacity>
        </View>
      </View>

      {/* flex:1 so the list fills exactly the space under the header and
          scrolls within it, instead of sizing itself to its content. */}
      <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>
        {/* Selected sport chip */}
        <TouchableOpacity
          style={styles.sportChip}
          activeOpacity={0.8}
          onPress={() => navigation.navigate('SportSelection', { mode: 'change', currentSportId: sport?.id ?? null })}
        >
          <Text style={styles.sportChipText}>
            {sport ? `${sport.emoji} ${sport.name}` : '🎯 Pick a sport'}
          </Text>
          <Text style={styles.sportChipAction}>Change sport ›</Text>
        </TouchableOpacity>

        {dailyLoading ? (
          <View style={[styles.dailyCard, styles.dailyCardLoading]}>
            <Text style={styles.dailyCardLoadingText}>Loading daily challenge…</Text>
          </View>
        ) : (
          <DailyCard today={todayDaily} stats={dailyStats} navigation={navigation} styles={styles} />
        )}

        {/* Nothing to play today — offer the two things that are always
            available, so Home never dead-ends. */}
        {!dailyLoading && !todayDaily?.has_daily ? (
          <View style={styles.dailyFallback}>
            <EmptyState
              compact
              message="No daily yet. Play a pack or join a tournament while you wait."
              actions={[
                { label: 'Play packs', onPress: () => navigation.navigate('Packs') },
                { label: 'View tournaments', onPress: () => navigation.navigate('Tournaments') },
              ]}
            />
          </View>
        ) : null}

        {/* Challenge Packs discovery entry point */}
        <TouchableOpacity
          style={styles.packsCard}
          activeOpacity={0.85}
          onPress={() => navigation.navigate('Packs')}
        >
          <Text style={styles.packsEmoji}>📦</Text>
          <View style={styles.packsText}>
            <Text style={styles.packsTitle}>Challenge Packs</Text>
            <Text style={styles.packsSubtitle}>Play themed sets of challenges.</Text>
          </View>
          <Text style={styles.packsChevron}>›</Text>
        </TouchableOpacity>
      </ScrollView>

      <ConfirmModal
        visible={notifPromptVisible}
        title="Stay in the game"
        message="Get a reminder when your Daily Challenge is ready or when a tournament needs your guess."
        confirmLabel="Enable notifications"
        cancelLabel="Not now"
        onConfirm={handleEnableNotifications}
        onCancel={handleDismissNotifications}
        loading={notifPromptLoading}
      />
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    header: {
      backgroundColor: theme.surface,
      borderBottomWidth: 1,
      borderBottomColor: theme.border,
      paddingTop: spacing.sm,
    },
    // The wordmark asset is 2172x724 with no @2x/@3x suffix, so RN reads its
    // intrinsic size as 2172x724 *dp*. An explicit height is REQUIRED: without
    // one the Image reserves that intrinsic height and swallows the screen.
    // `contain` keeps the full wordmark visible inside this box, never cropped.
    brandImage: {
      width: '100%',
      height: 48,
    },
    topBar: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      paddingHorizontal: spacing.md, paddingTop: spacing.sm, paddingBottom: spacing.md,
    },
    topBarLeft: { flex: 1 },
    greeting: { fontSize: 18, fontWeight: '700', color: theme.text },
    sub: { fontSize: 13, color: theme.textSecondary },
    scroll: { flex: 1 },
    content: { padding: spacing.md, paddingBottom: spacing.xl },
    dailyFallback: {
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm, marginBottom: spacing.md,
    },
    sportChip: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surfaceElevated, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm, marginBottom: spacing.md,
    },
    sportChipText: { fontSize: 15, fontWeight: '700', color: theme.text },
    sportChipAction: { fontSize: 13, fontWeight: '700', color: theme.accent },
    packsCard: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.md,
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.md, marginTop: spacing.md,
    },
    packsEmoji: { fontSize: 26 },
    packsText: { flex: 1 },
    packsTitle: { fontSize: 15, fontWeight: '700', color: theme.text },
    packsSubtitle: { fontSize: 12, color: theme.textMuted, marginTop: 1 },
    packsChevron: { fontSize: 22, color: theme.textMuted, fontWeight: '700' },
    dailyCard: {
      backgroundColor: theme.surface, borderRadius: 16, padding: spacing.md,
      marginBottom: spacing.md, borderWidth: 1, borderColor: theme.border,
    },
    dailyCardLoading: { alignItems: 'center', paddingVertical: spacing.lg },
    dailyCardLoadingText: { color: theme.textMuted, fontSize: 13, fontStyle: 'italic' },
    dailyCardTitle: { fontSize: 14, fontWeight: '800', color: theme.primary, letterSpacing: 0.5, marginBottom: 2 },
    dailyCardDate: { fontSize: 12, color: theme.textSecondary, marginBottom: spacing.sm },
    dailyChallengeInfo: { fontSize: 13, color: theme.text, fontWeight: '600', marginBottom: spacing.xs },
    dailyStreak: { fontSize: 13, color: theme.warning, fontWeight: '700', marginBottom: spacing.sm },
    dailyBtn: { marginTop: spacing.xs },
    dailyCardEmpty: { fontSize: 13, color: theme.textMuted, fontStyle: 'italic' },
  });
}
