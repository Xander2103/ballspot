import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { Avatar } from '../components/Avatar';
import { ConfirmModal } from '../components/ConfirmModal';
import { friendsApi } from '../api/friendsApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { PublicProfile } from '../types/friend';

type Props = NativeStackScreenProps<RootStackParamList, 'FriendProfile'>;

export function FriendProfileScreen({ route, navigation }: Props) {
  const { userId } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [profile, setProfile] = useState<PublicProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [removeError, setRemoveError] = useState('');

  const load = useCallback(() => {
    setLoadFailed(false);
    return friendsApi.publicProfile(userId)
      .then(setProfile)
      .catch(() => setLoadFailed(true))
      .finally(() => setLoading(false));
  }, [userId]);

  useEffect(() => { load(); }, [load]);

  async function handleRemove() {
    if (removing) return;
    setRemoving(true);
    setRemoveError('');
    try {
      await friendsApi.remove(userId);
      setConfirmRemove(false);
      navigation.goBack();
    } catch {
      setRemoveError('Could not remove this friend. Please try again.');
      setRemoving(false);
    }
  }

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  if (!profile) {
    return (
      <Screen padding>
        <Text style={styles.body}>
          {loadFailed ? 'Could not load this profile. Check your connection.' : 'Profile not found.'}
        </Text>
        {loadFailed ? (
          <AppButton title="Try again" onPress={() => { setLoading(true); load(); }} style={{ marginTop: spacing.lg }} />
        ) : null}
      </Screen>
    );
  }

  const s = profile.stats;

  return (
    <Screen scroll padding>
      <View style={styles.header}>
        <Avatar uri={profile.avatar_url} name={profile.name} size={88} />
        <Text style={styles.name}>{profile.name}</Text>
        <Text style={styles.username}>@{profile.username}</Text>
      </View>

      <View style={styles.rankCard}>
        <Text style={styles.rankName}>{profile.rank.name}</Text>
        <Text style={styles.rankMeta}>Level {profile.rank.level} · {profile.total_xp} XP</Text>
      </View>

      <Text style={styles.sectionTitle}>Stats</Text>
      <View style={styles.grid}>
        <Stat styles={styles} label="Tournaments" value={s.tournaments_played} />
        <Stat styles={styles} label="Completed" value={s.tournaments_completed} />
        <Stat styles={styles} label="Guesses" value={s.guesses_count} />
        <Stat styles={styles} label="Total score" value={s.total_score} />
        <Stat styles={styles} label="Avg score" value={s.average_score} />
        <Stat styles={styles} label="Dailies played" value={s.daily_challenges_played} />
        <Stat styles={styles} label="Best daily" value={s.best_daily_score} />
        <Stat styles={styles} label="Badges" value={`${profile.badges.earned_count}/${profile.badges.total_count}`} />
      </View>

      {profile.is_friend ? (
        <AppButton title="Remove friend" onPress={() => setConfirmRemove(true)} variant="danger" />
      ) : null}

      <ConfirmModal
        visible={confirmRemove}
        title="Remove friend?"
        message={`${profile.name} will be removed from your friends list. You can add each other again later.`}
        confirmLabel="Remove"
        cancelLabel="Cancel"
        onConfirm={handleRemove}
        onCancel={() => { setConfirmRemove(false); setRemoveError(''); }}
        loading={removing}
        errorText={removeError}
        destructive
      />
    </Screen>
  );
}

function Stat({ styles, label, value }: { styles: Styles; label: string; value: string | number }) {
  return (
    <View style={styles.statBox}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    body: { fontSize: 14, color: theme.textSecondary },
    header: { alignItems: 'center', marginBottom: spacing.lg },
    name: { fontSize: 22, fontWeight: '800', color: theme.text, marginTop: spacing.sm },
    username: { fontSize: 14, color: theme.textSecondary },
    rankCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center', marginBottom: spacing.lg },
    rankName: { fontSize: 18, fontWeight: '800', color: theme.primary },
    rankMeta: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm },
    grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.xl },
    statBox: { backgroundColor: theme.surface, borderRadius: 12, padding: spacing.md, alignItems: 'center', borderWidth: 1, borderColor: theme.border, minWidth: '45%', flex: 1 },
    statValue: { fontSize: 22, fontWeight: '800', color: theme.primary, marginBottom: 4 },
    statLabel: { fontSize: 12, color: theme.textSecondary, textAlign: 'center' },
  });
}
