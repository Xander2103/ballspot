import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, StyleSheet, Alert, ActivityIndicator, TouchableOpacity } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { LeaderboardList } from '../components/LeaderboardList';
import { ConfirmModal } from '../components/ConfirmModal';
import { leagueApi } from '../api/leagueApi';
import { roundApi } from '../api/roundApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { getApiErrorMessage } from '../utils/apiError';
import { isTournamentsUnavailable } from '../utils/tournamentErrors';
import { League, LobbyMember } from '../types/league';
import { LeaderboardEntry } from '../types/guess';
import { rivalryLine, daysLeftLabel } from '../utils/rivalry';
import { useI18n, t as translate } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'LeagueDetail'>;

export function LeagueDetailScreen({ route, navigation }: Props) {
  const { leagueId, leagueName } = route.params;
  const { t } = useI18n();
  const [league, setLeague] = useState<League | null>(null);
  const [leaderboard, setLeaderboard] = useState<LeaderboardEntry[]>([]);
  const [hasRound, setHasRound] = useState(false);
  const [roundId, setRoundId] = useState<number | null>(null);
  const [progress, setProgress] = useState<{ completed: number; total: number; pct: number } | null>(null);
  const [dailyLimitReached, setDailyLimitReached] = useState(false);
  const [dailyContext, setDailyContext] = useState<{ playedToday: number; roundsPerDay: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [showStartConfirm, setShowStartConfirm] = useState(false);
  const [removeTarget, setRemoveTarget] = useState<LobbyMember | null>(null);
  const [removing, setRemoving] = useState(false);
  const [removedFromLobby, setRemovedFromLobby] = useState(false);

  const load = useCallback(async () => {
    if (!leagueId) {
      // Module-level `translate` keeps `load` independent of the hook `t`, so a
      // language change does not re-create the callback and refetch.
      Alert.alert(translate('tournaments.alerts.error'), translate('tournaments.detail.errors.invalidLeague'));
      setLoading(false);
      return;
    }
    try {
      const [l, lb] = await Promise.all([
        leagueApi.get(leagueId),
        leagueApi.leaderboard(leagueId),
      ]);
      setLeague(l);
      setLeaderboard(lb.data ?? []);

      if (l.status === 'active') {
        const cr = await roundApi.currentRound(leagueId);
        setHasRound(!cr.completed && cr.current_round !== null && cr.reason !== 'daily_limit_reached');
        setDailyLimitReached(cr.reason === 'daily_limit_reached');
        setDailyContext({ playedToday: cr.played_today_count, roundsPerDay: cr.rounds_per_day });
        if (cr.current_round) setRoundId(cr.current_round.id);
        if (cr.progress) setProgress(cr.progress);
      } else {
        setHasRound(false);
        setRoundId(null);
        setProgress(null);
        setDailyLimitReached(false);
        setDailyContext(null);
      }
    } catch (e) {
      if (e && typeof e === 'object' && 'status' in e && (e as { status: number }).status === 403) {
        setRemovedFromLobby(true);
        setLoading(false);
        return;
      }
      Alert.alert(translate('tournaments.alerts.error'), translate('tournaments.detail.errors.loadFailed'));
    } finally {
      setLoading(false);
    }
  }, [leagueId]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { const u = navigation.addListener('focus', load); return u; }, [navigation, load]);

  useEffect(() => {
    if (league?.status !== 'lobby' || removedFromLobby) return;
    const interval = setInterval(load, 3000);
    return () => clearInterval(interval);
  }, [league?.status, removedFromLobby, load]);

  async function handleStart() {
    if (!league || starting) return;
    setStarting(true);
    try {
      await leagueApi.start(league.id);
      setShowStartConfirm(false);
      await load();
    } catch (e: unknown) {
      setShowStartConfirm(false);
      if (isTournamentsUnavailable(e)) {
        Alert.alert(t('tournaments.unavailable.title'), t('tournaments.unavailable.body'));
      } else {
        Alert.alert(t('tournaments.detail.errors.startTitle'), getApiErrorMessage(e, t('tournaments.detail.errors.startFailed')));
      }
    } finally {
      setStarting(false);
    }
  }

  async function handleRemoveMember() {
    if (!removeTarget || !leagueId || removing) return;
    setRemoving(true);
    try {
      await leagueApi.removeMember(leagueId, removeTarget.id);
      setRemoveTarget(null);
      await load();
    } catch {
      setRemoveTarget(null);
    } finally {
      setRemoving(false);
    }
  }

  // rivalryLine/daysLeftLabel translate via the i18n core; useI18n() above
  // re-renders this screen on a language change so they follow along.
  const rivalry = league?.status === 'active' ? rivalryLine(leaderboard) : null;
  const daysLeft = league?.status === 'active' ? daysLeftLabel(league?.ends_at) : null;

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  if (removedFromLobby) {
    return (
      <Screen padding>
        <View style={styles.removedBox}>
          <Text style={styles.removedIcon}>🚫</Text>
          <Text style={styles.removedTitle}>{t('tournaments.detail.removed.title')}</Text>
          <Text style={styles.removedText}>
            {t('tournaments.detail.removed.message')}
          </Text>
          <AppButton
            title={t('tournaments.detail.removed.backHome')}
            onPress={() => navigation.navigate('Home')}
            style={{ marginTop: spacing.lg }}
          />
        </View>
      </Screen>
    );
  }

  return (
    <Screen scroll padding={false}>
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.code}>{t('tournaments.code', { code: league?.join_code || '…' })}</Text>
        <Text style={styles.meta}>{t('tournaments.players', { count: league?.members_count ?? 0 })}</Text>
      </View>

      {/* Status-aware body */}
      {league?.status === 'lobby' && (
        <View style={styles.section}>
          <View style={styles.lobbyBox}>
            <Text style={styles.lobbyIcon}>⏳</Text>
            <Text style={styles.lobbyTitle}>{t('tournaments.detail.lobby.title')}</Text>
            <Text style={styles.lobbyDesc}>
              {t('tournaments.detail.lobby.summary', {
                players: t('tournaments.players', { count: league.members_count }),
                count: league.duration_days * league.rounds_per_day,
              })}
            </Text>
          </View>

          <View style={styles.membersSection}>
            <Text style={styles.membersSectionTitle}>
              {t('tournaments.detail.lobby.playersTitle', { count: league.members_count })}
            </Text>
            {(league.members ?? []).map(member => (
              <View key={member.id} style={styles.memberRow}>
                <View style={styles.memberInfo}>
                  <Text style={styles.memberName}>
                    {member.name}
                    {member.is_owner ? ' 👑' : ''}
                  </Text>
                  <Text style={styles.memberUsername}>@{member.username}</Text>
                </View>
                {league.is_owner && !member.is_owner && (
                  <TouchableOpacity
                    onPress={() => setRemoveTarget(member)}
                    hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                    style={styles.removeBtn}
                  >
                    <Text style={styles.removeBtnText}>✕</Text>
                  </TouchableOpacity>
                )}
              </View>
            ))}
          </View>

          {league.is_owner ? (
            <AppButton
              title={t('tournaments.detail.lobby.start')}
              onPress={() => setShowStartConfirm(true)}
              loading={starting}
              style={styles.startBtn}
            />
          ) : (
            <View style={styles.waitingBox}>
              <Text style={styles.waitingText}>{t('tournaments.detail.lobby.waitingForOwner')}</Text>
            </View>
          )}
        </View>
      )}

      {league?.status === 'active' && (
        <View style={styles.section}>
          {(rivalry || daysLeft) && (
            <View style={styles.rivalryBox}>
              {rivalry && <Text style={styles.rivalryText}>{rivalry}</Text>}
              {daysLeft && <Text style={styles.rivalryDays}>{daysLeft}</Text>}
            </View>
          )}
          {dailyLimitReached ? (
            <View style={styles.doneBox}>
              <Text style={styles.doneText}>{t('tournaments.detail.active.playedAllToday')}</Text>
              <Text style={styles.doneSubText}>{t('tournaments.detail.active.comeBackTomorrow')}</Text>
            </View>
          ) : hasRound && roundId ? (
            <AppButton
              title={t('tournaments.detail.active.playRound')}
              onPress={() => navigation.navigate('Guess', { leagueId, roundId, leagueName })}
              style={styles.playBtn}
            />
          ) : (
            <View style={styles.doneBox}>
              <Text style={styles.doneText}>{t('tournaments.detail.active.allDoneForNow')}</Text>
            </View>
          )}
          {dailyContext && dailyContext.roundsPerDay > 1 && (
            <Text style={styles.dailyProgress}>
              {t('tournaments.detail.active.todayProgress', { played: dailyContext.playedToday, total: dailyContext.roundsPerDay })}
            </Text>
          )}
          {progress && (
            <View style={styles.progressBox}>
              <View style={styles.progressBarBg}>
                <View style={[styles.progressBarFill, { width: `${progress.pct}%` }]} />
              </View>
              <Text style={styles.progressText}>
                {t('tournaments.detail.active.progress', { completed: progress.completed, total: progress.total, pct: progress.pct })}
              </Text>
            </View>
          )}
          <AppButton
            title={t('tournaments.detail.fullLeaderboard')}
            onPress={() => navigation.navigate('Leaderboard', { leagueId, leagueName })}
            variant="secondary"
          />
        </View>
      )}

      {league?.status === 'completed' && (
        <View style={styles.section}>
          <View style={styles.completedBox}>
            <Text style={styles.completedIcon}>🏆</Text>
            <Text style={styles.completedTitle}>{t('tournaments.detail.completed.title')}</Text>
            <Text style={styles.completedDesc}>{t('tournaments.detail.completed.message')}</Text>
          </View>
          <AppButton
            title={t('tournaments.detail.fullLeaderboard')}
            onPress={() => navigation.navigate('Leaderboard', { leagueId, leagueName })}
            variant="secondary"
          />
        </View>
      )}

      {league?.status === 'cancelled' && (
        <View style={styles.section}>
          <View style={styles.cancelledBox}>
            <Text style={styles.cancelledText}>{t('tournaments.detail.cancelled')}</Text>
          </View>
        </View>
      )}

      {/* Leaderboard preview (always shown if active/completed) */}
      {(league?.status === 'active' || league?.status === 'completed') && (
        <>
          <Text style={styles.sectionTitle}>{t('tournaments.detail.leaderboardPreview')}</Text>
          <LeaderboardList entries={leaderboard.slice(0, 3)} />
        </>
      )}

      <ConfirmModal
        visible={showStartConfirm}
        title={t('tournaments.detail.startModal.title')}
        message={t('tournaments.detail.startModal.message', { rounds: league ? league.duration_days * league.rounds_per_day : 0 })}
        confirmLabel={t('tournaments.detail.startModal.confirm')}
        cancelLabel={t('tournaments.detail.startModal.cancel')}
        onConfirm={handleStart}
        onCancel={() => setShowStartConfirm(false)}
        loading={starting}
      />

      <ConfirmModal
        visible={!!removeTarget}
        title={t('tournaments.detail.removeModal.title')}
        message={t('tournaments.detail.removeModal.message')}
        confirmLabel={t('tournaments.detail.removeModal.confirm')}
        cancelLabel={t('common.buttons.cancel')}
        onConfirm={handleRemoveMember}
        onCancel={() => !removing && setRemoveTarget(null)}
        loading={removing}
        destructive
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  header: {
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  code: { fontSize: 20, fontWeight: '700', color: colors.primary, letterSpacing: 4, marginBottom: 4 },
  meta: { fontSize: 13, color: colors.textSecondary },
  section: { padding: spacing.md, gap: spacing.sm },
  lobbyBox: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 14,
    padding: spacing.lg,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: colors.border,
  },
  lobbyIcon: { fontSize: 32, marginBottom: spacing.sm },
  lobbyTitle: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: 4 },
  lobbyDesc: { fontSize: 13, color: colors.textSecondary, textAlign: 'center' },
  membersSection: { marginTop: spacing.sm },
  membersSectionTitle: { fontSize: 11, fontWeight: '700', color: colors.textMuted, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm },
  memberRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing.xs, borderBottomWidth: 1, borderBottomColor: colors.border },
  memberInfo: { flex: 1 },
  memberName: { fontSize: 14, fontWeight: '600', color: colors.text },
  memberUsername: { fontSize: 12, color: colors.textSecondary },
  removeBtn: { paddingHorizontal: spacing.sm, paddingVertical: spacing.xs },
  removeBtnText: { fontSize: 14, color: colors.error, fontWeight: '700' },
  startBtn: { marginTop: 4 },
  waitingBox: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 12,
    padding: spacing.md,
    alignItems: 'center',
  },
  waitingText: { color: colors.textSecondary, fontSize: 14, fontStyle: 'italic' },
  playBtn: { marginBottom: 0 },
  rivalryBox: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 12,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
  },
  rivalryText: { fontSize: 14, fontWeight: '700', color: colors.text, textAlign: 'center' },
  rivalryDays: { fontSize: 12, color: colors.textSecondary, marginTop: 2 },
  doneBox: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    alignItems: 'center',
  },
  doneText: { color: colors.success, fontWeight: '600' },
  doneSubText: {
    fontSize: 12,
    color: colors.textMuted,
    marginTop: 4,
    textAlign: 'center',
  },
  dailyProgress: {
    fontSize: 12,
    color: colors.textSecondary,
    textAlign: 'center',
    marginTop: spacing.xs,
    marginBottom: spacing.xs,
  },
  progressBox: { marginTop: 4 },
  progressBarBg: { height: 6, backgroundColor: colors.border, borderRadius: 3, overflow: 'hidden' },
  progressBarFill: { height: 6, backgroundColor: colors.primary, borderRadius: 3 },
  progressText: { fontSize: 12, color: colors.textSecondary, marginTop: 4, textAlign: 'center' },
  completedBox: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 14,
    padding: spacing.lg,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: colors.border,
  },
  completedIcon: { fontSize: 32, marginBottom: spacing.sm },
  completedTitle: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: 4 },
  completedDesc: { fontSize: 13, color: colors.textSecondary, textAlign: 'center' },
  cancelledBox: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 12,
    padding: spacing.md,
    alignItems: 'center',
  },
  cancelledText: { color: colors.textMuted, fontSize: 14, fontStyle: 'italic' },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: colors.text,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.md,
    paddingBottom: spacing.sm,
  },
  removedBox: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingTop: spacing.xxl },
  removedIcon: { fontSize: 48, marginBottom: spacing.md },
  removedTitle: { fontSize: 20, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  removedText: { fontSize: 14, color: colors.textSecondary, textAlign: 'center' },
});
