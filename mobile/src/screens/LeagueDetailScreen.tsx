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
import { League, LobbyMember } from '../types/league';
import { LeaderboardEntry } from '../types/guess';

type Props = NativeStackScreenProps<RootStackParamList, 'LeagueDetail'>;

export function LeagueDetailScreen({ route, navigation }: Props) {
  const { leagueId, leagueName } = route.params;
  const [league, setLeague] = useState<League | null>(null);
  const [leaderboard, setLeaderboard] = useState<LeaderboardEntry[]>([]);
  const [hasRound, setHasRound] = useState(false);
  const [roundId, setRoundId] = useState<number | null>(null);
  const [progress, setProgress] = useState<{ completed: number; total: number; pct: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [showStartConfirm, setShowStartConfirm] = useState(false);
  const [removeTarget, setRemoveTarget] = useState<LobbyMember | null>(null);
  const [removing, setRemoving] = useState(false);
  const [removedFromLobby, setRemovedFromLobby] = useState(false);

  const load = useCallback(async () => {
    if (!leagueId) {
      Alert.alert('Error', 'Invalid league — no ID was passed to this screen.');
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
        setHasRound(!cr.completed && cr.current_round !== null);
        if (cr.current_round) setRoundId(cr.current_round.id);
        if (cr.progress) setProgress(cr.progress);
      } else {
        setHasRound(false);
        setRoundId(null);
        setProgress(null);
      }
    } catch (e) {
      if (e && typeof e === 'object' && 'status' in e && (e as { status: number }).status === 403) {
        setRemovedFromLobby(true);
        setLoading(false);
        return;
      }
      Alert.alert('Error', 'Failed to load tournament');
    } finally {
      setLoading(false);
    }
  }, [leagueId]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { const u = navigation.addListener('focus', load); return u; }, [navigation, load]);

  useEffect(() => {
    if (league?.status !== 'lobby') return;
    const interval = setInterval(load, 3000);
    return () => clearInterval(interval);
  }, [league?.status, load]);

  async function handleStart() {
    if (!league) return;
    setStarting(true);
    try {
      await leagueApi.start(league.id);
      setShowStartConfirm(false);
      await load();
    } catch (e: any) {
      Alert.alert('Error', e?.message || 'Failed to start tournament');
    } finally {
      setStarting(false);
    }
  }

  async function handleRemoveMember() {
    if (!removeTarget || !leagueId) return;
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
          <Text style={styles.removedTitle}>You were removed</Text>
          <Text style={styles.removedText}>
            You have been removed from this tournament.
          </Text>
          <AppButton
            title="Back to Home"
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
        <Text style={styles.code}>Code: {league?.join_code || '…'}</Text>
        <Text style={styles.meta}>{league?.members_count ?? 0} players</Text>
      </View>

      {/* Status-aware body */}
      {league?.status === 'lobby' && (
        <View style={styles.section}>
          <View style={styles.lobbyBox}>
            <Text style={styles.lobbyIcon}>⏳</Text>
            <Text style={styles.lobbyTitle}>Waiting in Lobby</Text>
            <Text style={styles.lobbyDesc}>
              {league.members_count} player{league.members_count !== 1 ? 's' : ''} joined · {league.duration_days * league.rounds_per_day} rounds total
            </Text>
          </View>

          <View style={styles.membersSection}>
            <Text style={styles.membersSectionTitle}>
              Players in Lobby ({league.members_count})
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
              title="Start Tournament"
              onPress={() => setShowStartConfirm(true)}
              loading={starting}
              style={styles.startBtn}
            />
          ) : (
            <View style={styles.waitingBox}>
              <Text style={styles.waitingText}>Waiting for the owner to start…</Text>
            </View>
          )}
        </View>
      )}

      {league?.status === 'active' && (
        <View style={styles.section}>
          {hasRound && roundId ? (
            <AppButton
              title="▶ Play Current Round"
              onPress={() => navigation.navigate('Guess', { leagueId, roundId, leagueName })}
              style={styles.playBtn}
            />
          ) : (
            <View style={styles.doneBox}>
              <Text style={styles.doneText}>✓ All rounds completed for now</Text>
            </View>
          )}
          {progress && (
            <View style={styles.progressBox}>
              <View style={styles.progressBarBg}>
                <View style={[styles.progressBarFill, { width: `${progress.pct}%` }]} />
              </View>
              <Text style={styles.progressText}>
                {progress.completed}/{progress.total} rounds completed ({progress.pct}%)
              </Text>
            </View>
          )}
          <AppButton
            title="Full Leaderboard"
            onPress={() => navigation.navigate('Leaderboard', { leagueId, leagueName })}
            variant="secondary"
          />
        </View>
      )}

      {league?.status === 'completed' && (
        <View style={styles.section}>
          <View style={styles.completedBox}>
            <Text style={styles.completedIcon}>🏆</Text>
            <Text style={styles.completedTitle}>Tournament Finished</Text>
            <Text style={styles.completedDesc}>All rounds played. Check the final standings below.</Text>
          </View>
          <AppButton
            title="Full Leaderboard"
            onPress={() => navigation.navigate('Leaderboard', { leagueId, leagueName })}
            variant="secondary"
          />
        </View>
      )}

      {league?.status === 'cancelled' && (
        <View style={styles.section}>
          <View style={styles.cancelledBox}>
            <Text style={styles.cancelledText}>This tournament was cancelled.</Text>
          </View>
        </View>
      )}

      {/* Leaderboard preview (always shown if active/completed) */}
      {(league?.status === 'active' || league?.status === 'completed') && (
        <>
          <Text style={styles.sectionTitle}>Leaderboard Preview</Text>
          <LeaderboardList entries={leaderboard.slice(0, 3)} />
        </>
      )}

      <ConfirmModal
        visible={showStartConfirm}
        title="Start Tournament?"
        message={`This will generate ${league ? league.duration_days * league.rounds_per_day : 0} rounds and open play for all members. You cannot undo this.`}
        confirmLabel={starting ? 'Starting…' : 'Start Now'}
        cancelLabel="Not Yet"
        onConfirm={handleStart}
        onCancel={() => setShowStartConfirm(false)}
      />

      <ConfirmModal
        visible={!!removeTarget}
        title="Remove player?"
        message="This player will be removed from the lobby."
        confirmLabel={removing ? 'Removing…' : 'Remove'}
        cancelLabel="Cancel"
        onConfirm={handleRemoveMember}
        onCancel={() => !removing && setRemoveTarget(null)}
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
  doneBox: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    alignItems: 'center',
  },
  doneText: { color: colors.success, fontWeight: '600' },
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
