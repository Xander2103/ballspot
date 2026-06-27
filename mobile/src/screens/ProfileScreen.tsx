import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Linking, TouchableOpacity } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { User, ProfileStats } from '../types/auth';

const APP_VERSION = '1.0.0';

// Derive web base from EXPO_PUBLIC_WEB_URL, or strip /api from the API URL
const API_BASE = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api';
const WEB_BASE = process.env.EXPO_PUBLIC_WEB_URL ?? API_BASE.replace(/\/api$/, '');

type Props = NativeStackScreenProps<RootStackParamList, 'Profile'>;

function StatBox({ label, value }: { label: string; value: string | number }) {
  return (
    <View style={styles.statBox}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

function SectionHeader({ title }: { title: string }) {
  return <Text style={styles.sectionTitle}>{title}</Text>;
}

function LegalRow({ label, url }: { label: string; url: string }) {
  return (
    <TouchableOpacity
      style={styles.legalRow}
      onPress={() => Linking.openURL(url)}
      activeOpacity={0.7}
    >
      <Text style={styles.legalLabel}>{label}</Text>
      <Text style={styles.legalChevron}>›</Text>
    </TouchableOpacity>
  );
}

export function ProfileScreen({ navigation }: Props) {
  const [user, setUser] = useState<User | null>(null);
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

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

  async function handleDeleteAccount() {
    setDeleting(true);
    setDeleteError('');
    try {
      await authApi.deleteAccount();
      await tokenStorage.remove();
      setShowDeleteModal(false);
      navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
    } catch {
      setDeleteError('Failed to delete account. Please try again or contact support.');
      setDeleting(false);
    }
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
      {/* Avatar + identity */}
      <View style={styles.avatar}>
        <Text style={styles.avatarText}>{user?.name?.[0]?.toUpperCase() ?? '?'}</Text>
      </View>
      <Text style={styles.name}>{user?.name ?? '—'}</Text>
      <Text style={styles.username}>@{user?.username ?? '—'}</Text>

      {/* Stats */}
      {stats ? (
        <>
          <SectionHeader title="Stats" />
          <View style={styles.statsGrid}>
            <StatBox label="Tournaments" value={stats.tournaments_count} />
            <StatBox label="Completed" value={stats.completed_tournaments_count} />
            <StatBox label="Guesses" value={stats.guesses_count} />
            <StatBox label="Total Score" value={stats.total_score} />
            <StatBox label="Avg Score" value={stats.average_score} />
          </View>

          <View style={styles.dailySection}>
            <SectionHeader title="Daily Challenge Stats" />
            <View style={styles.statsGrid}>
              <StatBox label="Streak" value={`${stats.current_daily_streak}d (best: ${stats.best_daily_streak})`} />
              <StatBox label="Played" value={stats.daily_challenges_played} />
              <StatBox label="Avg Score" value={stats.average_daily_score} />
              <StatBox label="Best Score" value={stats.best_daily_score} />
            </View>
          </View>
        </>
      ) : null}

      {/* Account actions */}
      <AppButton
        title="Logout"
        onPress={handleLogout}
        variant="secondary"
        style={styles.logoutBtn}
      />

      {/* Settings section */}
      <SectionHeader title="Settings" />
      <View style={styles.settingsCard}>
        <LegalRow label="Privacy Policy" url={`${WEB_BASE}/privacy`} />
        <View style={styles.divider} />
        <LegalRow label="Terms of Service" url={`${WEB_BASE}/terms`} />
        <View style={styles.divider} />
        <LegalRow label="Support" url={`${WEB_BASE}/support`} />
      </View>

      {deleteError ? (
        <Text style={styles.deleteError}>{deleteError}</Text>
      ) : null}

      <AppButton
        title="Delete account"
        onPress={() => setShowDeleteModal(true)}
        variant="danger"
        style={styles.deleteBtn}
      />

      <Text style={styles.versionText}>v{APP_VERSION}</Text>

      {/* Delete confirmation modal */}
      <ConfirmModal
        visible={showDeleteModal}
        title="Delete account?"
        message="This will remove your account access and anonymize your profile. This action cannot be undone."
        confirmLabel={deleting ? 'Deleting…' : 'Delete account'}
        cancelLabel="Cancel"
        onConfirm={handleDeleteAccount}
        onCancel={() => { setShowDeleteModal(false); setDeleteError(''); }}
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
  logoutBtn: {
    marginBottom: spacing.xl,
  },
  settingsCard: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.xl,
    overflow: 'hidden',
  },
  legalRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
  },
  legalLabel: {
    fontSize: 15,
    color: colors.text,
  },
  legalChevron: {
    fontSize: 18,
    color: colors.textMuted,
    lineHeight: 22,
  },
  divider: {
    height: 1,
    backgroundColor: colors.border,
    marginHorizontal: spacing.md,
  },
  deleteBtn: {
    marginBottom: spacing.md,
  },
  deleteError: {
    color: colors.error,
    fontSize: 13,
    textAlign: 'center',
    marginBottom: spacing.sm,
  },
  versionText: {
    fontSize: 12,
    color: colors.textMuted,
    textAlign: 'center',
    marginBottom: spacing.md,
  },
});
