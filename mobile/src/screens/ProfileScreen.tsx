import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { User, ProfileStats } from '../types/auth';

const APP_VERSION = '1.0.0';

type Props = NativeStackScreenProps<RootStackParamList, 'Profile'>;

function StatBox({ label, value }: { label: string; value: string | number }) {
  return (
    <View style={styles.statBox}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

export function ProfileScreen({ navigation }: Props) {
  const [user, setUser] = useState<User | null>(null);
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([authApi.me(), authApi.stats()])
      .then(([me, s]) => { setUser(me); setStats(s); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  async function handleLogout() {
    try { await authApi.logout(); } catch {}
    await tokenStorage.remove();
    navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  return (
    <Screen scroll padding>
      <View style={styles.avatar}>
        <Text style={styles.avatarText}>{user?.name?.[0]?.toUpperCase() ?? '?'}</Text>
      </View>
      <Text style={styles.name}>{user?.name ?? '—'}</Text>
      <Text style={styles.username}>@{user?.username ?? '—'}</Text>

      {stats ? (
        <>
          <Text style={styles.sectionTitle}>Stats</Text>
          <View style={styles.statsGrid}>
            <StatBox label="Tournaments" value={stats.tournaments_count} />
            <StatBox label="Completed" value={stats.completed_tournaments_count} />
            <StatBox label="Guesses" value={stats.guesses_count} />
            <StatBox label="Total Score" value={stats.total_score} />
            <StatBox label="Avg Score" value={stats.average_score} />
          </View>
        </>
      ) : null}

      <View style={styles.dailySection}>
        <Text style={styles.sectionTitle}>Daily Challenge Stats</Text>
        {stats ? (
          <View style={styles.statsGrid}>
            <StatBox label="Streak" value={`${stats.current_daily_streak}d (best: ${stats.best_daily_streak})`} />
            <StatBox label="Played" value={stats.daily_challenges_played} />
            <StatBox label="Avg Score" value={stats.average_daily_score} />
            <StatBox label="Best Score" value={stats.best_daily_score} />
          </View>
        ) : (
          <Text style={styles.comingSoon}>Coming soon…</Text>
        )}
      </View>

      <AppButton
        title="Logout"
        onPress={handleLogout}
        variant="secondary"
        style={styles.logoutBtn}
      />

      <Text style={styles.versionText}>v{APP_VERSION}</Text>
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
  avatar: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    alignSelf: 'center',
    marginBottom: spacing.md,
  },
  avatarText: {
    fontSize: 36,
    fontWeight: '800',
    color: colors.background,
  },
  name: {
    fontSize: 24,
    fontWeight: '800',
    color: colors.text,
    textAlign: 'center',
    marginBottom: 4,
  },
  username: {
    fontSize: 14,
    color: colors.textSecondary,
    textAlign: 'center',
    marginBottom: spacing.xl,
  },
  sectionTitle: {
    fontSize: 12,
    fontWeight: '700',
    color: colors.textSecondary,
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginBottom: spacing.md,
  },
  statsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginBottom: spacing.xl,
  },
  statBox: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: colors.border,
    minWidth: '45%',
    flex: 1,
  },
  statValue: {
    fontSize: 28,
    fontWeight: '800',
    color: colors.primary,
    marginBottom: 4,
  },
  statLabel: {
    fontSize: 12,
    color: colors.textSecondary,
    textAlign: 'center',
  },
  dailySection: {
    marginBottom: spacing.xl,
  },
  comingSoon: {
    fontSize: 14,
    color: colors.textMuted,
    textAlign: 'center',
    paddingVertical: spacing.md,
  },
  logoutBtn: {
    marginBottom: spacing.md,
  },
  versionText: {
    fontSize: 12,
    color: colors.textMuted,
    textAlign: 'center',
    marginBottom: spacing.md,
  },
});
