import React, { useEffect, useState, useCallback } from 'react';
import {
  View, Text, StyleSheet, SectionList, TouchableOpacity, ActivityIndicator,
} from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { leagueApi } from '../api/leagueApi';
import { authApi } from '../api/authApi';
import { dailyApi } from '../api/dailyApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { User } from '../types/auth';
import { TodayResponse, DailyStats } from '../types/daily';

type Props = NativeStackScreenProps<RootStackParamList, 'Home'>;

const STATUS_LABEL: Record<string, string> = {
  lobby: 'LOBBY',
  active: 'ACTIVE',
  completed: 'DONE',
};

const STATUS_COLOR: Record<string, string> = {
  lobby: colors.warning,
  active: colors.primary,
  completed: colors.textMuted,
};

function todayDateFormatted(): string {
  return new Date().toLocaleDateString('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

function DailyCard({
  today,
  stats,
  navigation,
}: {
  today: TodayResponse | null;
  stats: DailyStats | null;
  navigation: Props['navigation'];
}) {
  if (!today?.has_daily) {
    return (
      <View style={styles.dailyCard}>
        <Text style={styles.dailyCardTitle}>⚽ Daily Ball Challenge</Text>
        <Text style={styles.dailyCardDate}>{todayDateFormatted()}</Text>
        <Text style={styles.dailyCardEmpty}>No challenge today. Check back tomorrow!</Text>
      </View>
    );
  }

  if (today.already_played) {
    return (
      <View style={styles.dailyCard}>
        <Text style={styles.dailyCardTitle}>⚽ Daily Ball Challenge</Text>
        <Text style={styles.dailyCardDate}>{todayDateFormatted()}</Text>
        {!!stats?.current_streak && (
          <Text style={styles.dailyStreak}>🔥 {stats.current_streak} day streak</Text>
        )}
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
      <Text style={styles.dailyCardTitle}>⚽ Daily Ball Challenge</Text>
      <Text style={styles.dailyCardDate}>{todayDateFormatted()}</Text>
      {today.daily_challenge?.challenge && (
        <Text style={styles.dailyChallengeInfo}>
          {today.daily_challenge.challenge.title}
          {today.daily_challenge.challenge.category
            ? ` · ${today.daily_challenge.challenge.category.name}`
            : ''}
          {' · '}{today.daily_challenge.challenge.difficulty}
        </Text>
      )}
      {!!stats?.current_streak && (
        <Text style={styles.dailyStreak}>🔥 {stats.current_streak} day streak</Text>
      )}
      <AppButton
        title="Play Daily Challenge"
        onPress={() => navigation.navigate('DailyChallenge', { dailyChallengeId: today.daily_challenge!.id })}
        style={styles.dailyBtn}
      />
    </View>
  );
}

function TournamentCard({
  item,
  onPress,
  onDelete,
}: {
  item: League;
  onPress: () => void;
  onDelete?: () => void;
}) {
  const statusColor = STATUS_COLOR[item.status] ?? colors.textMuted;
  const statusLabel = STATUS_LABEL[item.status] ?? item.status.toUpperCase();

  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.8}>
      <View style={styles.cardHeader}>
        <Text style={styles.cardName} numberOfLines={1}>{item.name}</Text>
        <View style={[styles.statusBadge, { borderColor: statusColor }]}>
          <Text style={[styles.statusText, { color: statusColor }]}>{statusLabel}</Text>
        </View>
      </View>
      <Text style={styles.cardMeta}>
        Code: {item.join_code} · {item.members_count} players
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
  const [leagues, setLeagues] = useState<League[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [cancelTarget, setCancelTarget] = useState<League | null>(null);
  const [cancelling, setCancelling] = useState(false);

  const [todayDaily, setTodayDaily] = useState<TodayResponse | null>(null);
  const [dailyStats, setDailyStats] = useState<DailyStats | null>(null);
  const [dailyLoading, setDailyLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const [me, list] = await Promise.all([authApi.me(), leagueApi.list()]);
      setUser(me);
      setLeagues(list);
    } catch {
      // silent
    } finally {
      setLoading(false);
    }

    const [todayRes, statsRes] = await Promise.allSettled([
      dailyApi.today(),
      dailyApi.stats(),
    ]);
    if (todayRes.status === 'fulfilled') setTodayDaily(todayRes.value);
    if (statsRes.status === 'fulfilled') setDailyStats(statsRes.value);
    setDailyLoading(false);
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    const unsub = navigation.addListener('focus', load);
    return unsub;
  }, [navigation, load]);

  async function handleCancel() {
    if (!cancelTarget) return;
    setCancelling(true);
    try {
      await leagueApi.cancel(cancelTarget.id);
      setCancelTarget(null);
      await load();
    } catch {
      // silent — will reload on next focus anyway
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

  return (
    <Screen padding={false}>
      <View style={styles.topBar}>
        <View style={styles.topBarLeft}>
          <Text style={styles.appTitle}>⚽ BallSpot</Text>
          <Text style={styles.greeting}>Hey, {user?.name || '…'}</Text>
          <Text style={styles.sub}>@{user?.username || '…'}</Text>
        </View>
        <TouchableOpacity onPress={() => navigation.navigate('Profile')} style={styles.profileIconBtn}>
          <Text style={styles.profileIconText}>👤</Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <View style={styles.loadingWrap}>
          <ActivityIndicator color={colors.primary} size="large" />
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
              onPress={() => navigation.navigate('LeagueDetail', { leagueId: item.id, leagueName: item.name })}
              onDelete={() => setCancelTarget(item)}
            />
          )}
          contentContainerStyle={styles.list}
          ListHeaderComponent={
            <View style={styles.dailySection}>
              {dailyLoading ? (
                <View style={[styles.dailyCard, styles.dailyCardLoading]}>
                  <Text style={styles.dailyCardLoadingText}>Loading daily challenge…</Text>
                </View>
              ) : (
                <DailyCard today={todayDaily} stats={dailyStats} navigation={navigation} />
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
        confirmLabel={cancelling ? 'Cancelling…' : 'Cancel Tournament'}
        cancelLabel="Keep Playing"
        onConfirm={handleCancel}
        onCancel={() => setCancelTarget(null)}
        destructive
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: spacing.md,
    backgroundColor: colors.surface,
  },
  topBarLeft: { flex: 1 },
  appTitle: {
    fontSize: 13,
    fontWeight: '700',
    color: colors.primary,
    letterSpacing: 0.5,
    marginBottom: 2,
  },
  greeting: { fontSize: 18, fontWeight: '700', color: colors.text },
  sub: { fontSize: 13, color: colors.textSecondary },
  profileIconBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: colors.border,
  },
  profileIconText: {
    fontSize: 18,
  },
  loadingWrap: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  list: { padding: spacing.md, gap: spacing.sm, paddingBottom: spacing.xl },
  sectionHeader: { paddingBottom: spacing.xs, paddingTop: spacing.sm },
  sectionTitle: { fontSize: 12, fontWeight: '700', color: colors.textSecondary, letterSpacing: 1, textTransform: 'uppercase' },
  card: {
    backgroundColor: colors.surface,
    borderRadius: 14,
    padding: spacing.md,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
  },
  cardHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 },
  cardName: { fontSize: 16, fontWeight: '700', color: colors.text, flex: 1, marginRight: spacing.sm },
  statusBadge: { borderRadius: 6, borderWidth: 1, paddingHorizontal: 6, paddingVertical: 2 },
  statusText: { fontSize: 10, fontWeight: '700', letterSpacing: 0.5 },
  cardMeta: { fontSize: 13, color: colors.textSecondary },
  deleteBtn: { marginTop: spacing.sm, alignSelf: 'flex-start' },
  deleteBtnText: { fontSize: 12, color: colors.error, fontWeight: '600' },
  emptyWrap: { paddingVertical: spacing.xxl, alignItems: 'center' },
  emptyIcon: { fontSize: 48, marginBottom: spacing.md },
  emptyTitle: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  emptyText: { fontSize: 14, color: colors.textSecondary, textAlign: 'center' },
  actions: { gap: spacing.sm, marginTop: spacing.md },
  actionBtn: { marginBottom: 0 },
  // Daily Challenge card
  dailySection: {},
  dailyCard: {
    backgroundColor: colors.surface,
    borderRadius: 16,
    padding: spacing.md,
    marginBottom: spacing.md,
    borderWidth: 1,
    borderColor: colors.border,
  },
  dailyCardLoading: {
    alignItems: 'center',
    paddingVertical: spacing.lg,
  },
  dailyCardLoadingText: {
    color: colors.textMuted,
    fontSize: 13,
    fontStyle: 'italic',
  },
  dailyCardTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: colors.primary,
    letterSpacing: 0.5,
    marginBottom: 2,
  },
  dailyCardDate: {
    fontSize: 12,
    color: colors.textSecondary,
    marginBottom: spacing.sm,
  },
  dailyChallengeInfo: {
    fontSize: 13,
    color: colors.text,
    fontWeight: '600',
    marginBottom: spacing.xs,
  },
  dailyStreak: {
    fontSize: 13,
    color: colors.warning,
    fontWeight: '700',
    marginBottom: spacing.sm,
  },
  dailyBtn: {
    marginTop: spacing.xs,
  },
  dailyCardEmpty: {
    fontSize: 13,
    color: colors.textMuted,
    fontStyle: 'italic',
  },
});
