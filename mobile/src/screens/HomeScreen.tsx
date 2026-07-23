import React, { useEffect, useState, useCallback } from 'react';
import {
  View, Text, StyleSheet, SectionList, TouchableOpacity, ActivityIndicator, Image,
} from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { Avatar } from '../components/Avatar';
import { leagueApi } from '../api/leagueApi';
import { authApi } from '../api/authApi';
import { dailyApi } from '../api/dailyApi';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { User } from '../types/auth';
import { TodayResponse, DailyStats } from '../types/daily';

// Horizontal BallPicker brand header (wordmark). Rendered as the Home hero.
const brandHeader = require('../../assets/BallPickerHeader.png');

type Props = NativeStackScreenProps<RootStackParamList, 'Home'>;

const STATUS_LABEL: Record<string, string> = { lobby: 'LOBBY', active: 'ACTIVE', completed: 'DONE' };

function todayDateFormatted(): string {
  return new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function DailyCard({
  today, stats, navigation, styles, theme,
}: {
  today: TodayResponse | null;
  stats: DailyStats | null;
  navigation: Props['navigation'];
  styles: Styles;
  theme: ThemeTokens;
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
            ? `No daily challenge for ${sportName} today. Try Football or come back tomorrow.`
            : 'No challenge today. Check back tomorrow!'}
        </Text>
      </View>
    );
  }

  const emoji = today.daily_challenge?.challenge?.sport?.emoji ?? '⚽';

  if (today.already_played) {
    return (
      <View style={styles.dailyCard}>
        <Text style={styles.dailyCardTitle}>{emoji} Daily Ball Challenge</Text>
        <Text style={styles.dailyCardDate}>{todayDateFormatted()}</Text>
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
      <Text style={styles.dailyCardDate}>{todayDateFormatted()}</Text>
      {today.daily_challenge?.challenge && (
        <Text style={styles.dailyChallengeInfo}>
          {today.daily_challenge.challenge.title}
          {today.daily_challenge.challenge.category ? ` · ${today.daily_challenge.challenge.category.name}` : ''}
          {' · '}{today.daily_challenge.challenge.difficulty}
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

function TournamentCard({
  item, onPress, onDelete, styles, theme,
}: {
  item: League;
  onPress: () => void;
  onDelete?: () => void;
  styles: Styles;
  theme: ThemeTokens;
}) {
  const STATUS_COLOR: Record<string, string> = {
    lobby: theme.warning, active: theme.primary, completed: theme.textMuted,
  };
  const statusColor = STATUS_COLOR[item.status] ?? theme.textMuted;
  const statusLabel = STATUS_LABEL[item.status] ?? item.status.toUpperCase();

  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.8}>
      <View style={styles.cardHeader}>
        <Text style={styles.cardName} numberOfLines={1}>
          {item.sport?.emoji ? `${item.sport.emoji} ` : ''}{item.name}
        </Text>
        <View style={[styles.statusBadge, { borderColor: statusColor }]}>
          <Text style={[styles.statusText, { color: statusColor }]}>{statusLabel}</Text>
        </View>
      </View>
      <Text style={styles.cardMeta}>
        {item.sport?.name ? `${item.sport.name} · ` : ''}Code: {item.join_code} · {item.members_count} players
        {item.rounds_count > 0 ? ` · ${item.completed_rounds_count}/${item.rounds_count} rounds` : ''}
      </Text>
      {item.is_owner && (item.status === 'lobby' || item.status === 'active') && onDelete ? (
        <TouchableOpacity
          style={styles.deleteBtn}
          onPress={(e) => { e.stopPropagation(); onDelete(); }}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        >
          <Text style={styles.deleteBtnText}>Cancel</Text>
        </TouchableOpacity>
      ) : null}
    </TouchableOpacity>
  );
}

export function HomeScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [leagues, setLeagues] = useState<League[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [cancelTarget, setCancelTarget] = useState<League | null>(null);
  const [cancelling, setCancelling] = useState(false);

  const [todayDaily, setTodayDaily] = useState<TodayResponse | null>(null);
  const [dailyStats, setDailyStats] = useState<DailyStats | null>(null);
  const [dailyLoading, setDailyLoading] = useState(true);

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

  async function handleCancel() {
    if (!cancelTarget || cancelling) return;
    setCancelling(true);
    try {
      await leagueApi.cancel(cancelTarget.id);
      setCancelTarget(null);
      await load();
    } catch {
      setCancelTarget(null);
    } finally {
      setCancelling(false);
    }
  }

  const active = leagues.filter(l => l.status === 'lobby' || l.status === 'active');
  const completed = leagues.filter(l => l.status === 'completed');
  const sections = [
    ...(active.length > 0 ? [{ title: 'Your Tournaments', data: active }] : []),
    ...(completed.length > 0 ? [{ title: 'Completed', data: completed }] : []),
  ];
  const isEmpty = leagues.length === 0;
  const sport = user?.preferred_sport ?? null;

  return (
    <Screen padding={false}>
      {/* Branded hero header — the horizontal BallPicker wordmark. */}
      <View style={styles.brandHeader}>
        <Image
          source={brandHeader}
          style={styles.brandImage}
          resizeMode="contain"
          accessibilityRole="image"
          accessibilityLabel="BallPicker"
        />
      </View>

      <View style={styles.topBar}>
        <View style={styles.topBarLeft}>
          <Text style={styles.greeting}>Hey, {user?.name || '…'}</Text>
          <Text style={styles.sub}>@{user?.username || '…'}</Text>
        </View>
        <TouchableOpacity onPress={() => navigation.navigate('Profile')} activeOpacity={0.8}>
          <Avatar uri={user?.avatar_url} name={user?.name} size={42} />
        </TouchableOpacity>
      </View>

      {loading ? (
        <View style={styles.loadingWrap}>
          <ActivityIndicator color={theme.primary} size="large" />
        </View>
      ) : (
        <SectionList
          sections={sections}
          keyExtractor={(l) => String(l.id)}
          renderSectionHeader={({ section }) => (
            <View style={styles.sectionHeader}>
              <Text style={styles.sectionTitle}>{section.title}</Text>
            </View>
          )}
          renderItem={({ item }) => (
            <TournamentCard
              item={item}
              styles={styles}
              theme={theme}
              onPress={() => navigation.navigate('LeagueDetail', { leagueId: item.id, leagueName: item.name })}
              onDelete={() => setCancelTarget(item)}
            />
          )}
          contentContainerStyle={styles.list}
          ListHeaderComponent={
            <View>
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
                <DailyCard today={todayDaily} stats={dailyStats} navigation={navigation} styles={styles} theme={theme} />
              )}
            </View>
          }
          ListEmptyComponent={
            isEmpty ? (
              <View style={styles.emptyWrap}>
                <Text style={styles.emptyIcon}>⚽</Text>
                <Text style={styles.emptyTitle}>No tournaments yet</Text>
                <Text style={styles.emptyText}>Create a tournament and invite friends to play!</Text>
              </View>
            ) : null
          }
          ListFooterComponent={
            <View style={styles.actions}>
              <AppButton title="+ Create Tournament" onPress={() => navigation.navigate('CreateLeague')} style={styles.actionBtn} />
              <AppButton title="Join Tournament" onPress={() => navigation.navigate('JoinLeague')} variant="secondary" />
            </View>
          }
          stickySectionHeadersEnabled={false}
        />
      )}

      <ConfirmModal
        visible={!!cancelTarget}
        title="Cancel Tournament"
        message={`Cancel "${cancelTarget?.name}"? This cannot be undone.`}
        confirmLabel="Cancel Tournament"
        cancelLabel="Keep Playing"
        onConfirm={handleCancel}
        onCancel={() => setCancelTarget(null)}
        loading={cancelling}
        destructive
      />
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    brandHeader: {
      backgroundColor: theme.surface,
      alignItems: 'center',
      justifyContent: 'center',
      paddingVertical: spacing.sm,
      paddingHorizontal: spacing.md,
      borderBottomLeftRadius: 20,
      borderBottomRightRadius: 20,
      borderBottomWidth: 1,
      borderColor: theme.border,
    },
    brandImage: { width: '100%', height: 120 },
    topBar: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      padding: spacing.md, backgroundColor: theme.surface,
    },
    topBarLeft: { flex: 1 },
    greeting: { fontSize: 18, fontWeight: '700', color: theme.text },
    sub: { fontSize: 13, color: theme.textSecondary },
    loadingWrap: { flex: 1, alignItems: 'center', justifyContent: 'center' },
    list: { padding: spacing.md, gap: spacing.sm, paddingBottom: spacing.xl },
    sportChip: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surfaceElevated, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm, marginBottom: spacing.md,
    },
    sportChipText: { fontSize: 15, fontWeight: '700', color: theme.text },
    sportChipAction: { fontSize: 13, fontWeight: '700', color: theme.accent },
    sectionHeader: { paddingBottom: spacing.xs, paddingTop: spacing.sm },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase' },
    card: {
      backgroundColor: theme.surface, borderRadius: 14, padding: spacing.md,
      borderWidth: 1, borderColor: theme.border, marginBottom: spacing.sm,
    },
    cardHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 },
    cardName: { fontSize: 16, fontWeight: '700', color: theme.text, flex: 1, marginRight: spacing.sm },
    statusBadge: { borderRadius: 6, borderWidth: 1, paddingHorizontal: 6, paddingVertical: 2 },
    statusText: { fontSize: 10, fontWeight: '700', letterSpacing: 0.5 },
    cardMeta: { fontSize: 13, color: theme.textSecondary },
    deleteBtn: { marginTop: spacing.sm, alignSelf: 'flex-start' },
    deleteBtnText: { fontSize: 12, color: theme.danger, fontWeight: '600' },
    emptyWrap: { paddingVertical: spacing.xxl, alignItems: 'center' },
    emptyIcon: { fontSize: 48, marginBottom: spacing.md },
    emptyTitle: { fontSize: 18, fontWeight: '700', color: theme.text, marginBottom: spacing.sm },
    emptyText: { fontSize: 14, color: theme.textSecondary, textAlign: 'center' },
    actions: { gap: spacing.sm, marginTop: spacing.md },
    actionBtn: { marginBottom: 0 },
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
