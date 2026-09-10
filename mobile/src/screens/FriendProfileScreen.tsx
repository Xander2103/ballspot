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
import { rarityColor } from '../theme/rarity';
import { getRankVisualStyle } from '../theme/rankVisuals';
import { useI18n } from '../i18n';
import type { PublicProfile } from '../types/friend';

type Props = NativeStackScreenProps<RootStackParamList, 'FriendProfile'>;

export function FriendProfileScreen({ route, navigation }: Props) {
  const { userId } = route.params;
  const { theme } = useTheme();
  const { t } = useI18n();
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
      setRemoveError(t('friends.remove.error'));
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
          {loadFailed ? t('friends.profile.loadError') : t('friends.profile.notFound')}
        </Text>
        {loadFailed ? (
          <AppButton title={t('common.buttons.tryAgain')} onPress={() => { setLoading(true); load(); }} style={{ marginTop: spacing.lg }} />
        ) : null}
      </Screen>
    );
  }

  const s = profile.stats;
  // Defensive: an older backend without the v1.8.8 field must not crash the screen.
  const trophies = profile.badges?.earned ?? [];

  return (
    <Screen scroll padding>
      <View style={styles.header}>
        <Avatar uri={profile.avatar_url} name={profile.name} size={88} />
        <Text style={styles.name}>{profile.name}</Text>
        <Text style={styles.username}>@{profile.username}</Text>
      </View>

      <View style={[styles.rankCard, getRankVisualStyle(profile.rank?.level, theme)]}>
        <Text style={styles.rankName}>{profile.rank.name}</Text>
        <Text style={styles.rankMeta}>{t('friends.profile.rankMeta', { level: profile.rank.level, xp: profile.total_xp })}</Text>
      </View>

      <Text style={styles.sectionTitle}>{t('friends.profile.trophies')}</Text>
      {trophies.length === 0 ? (
        <Text style={styles.trophyEmpty}>{t('friends.profile.noTrophies')}</Text>
      ) : (
        <View style={styles.trophyGrid}>
          {trophies.map((b) => (
            <View
              key={b.code}
              style={[styles.trophyCell, { borderColor: rarityColor(theme, b.rarity) + '80' }]}
            >
              <Text style={styles.trophyIcon}>{b.icon}</Text>
              <Text style={styles.trophyName} numberOfLines={1}>{b.name}</Text>
              <Text style={[styles.trophyRarity, { color: rarityColor(theme, b.rarity) }]}>
                {b.rarity.toUpperCase()}
              </Text>
            </View>
          ))}
        </View>
      )}

      <Text style={styles.sectionTitle}>{t('friends.profile.stats')}</Text>
      <View style={styles.grid}>
        <Stat styles={styles} label={t('profile.stats.tournaments')} value={s.tournaments_played} />
        <Stat styles={styles} label={t('profile.stats.completed')} value={s.tournaments_completed} />
        <Stat styles={styles} label={t('profile.stats.guesses')} value={s.guesses_count} />
        <Stat styles={styles} label={t('profile.stats.totalScore')} value={s.total_score} />
        <Stat styles={styles} label={t('friends.profile.avgScore')} value={s.average_score} />
        <Stat styles={styles} label={t('friends.profile.dailiesPlayed')} value={s.daily_challenges_played} />
        <Stat styles={styles} label={t('friends.profile.bestDaily')} value={s.best_daily_score} />
        <Stat styles={styles} label={t('friends.profile.badges')} value={`${profile.badges.earned_count}/${profile.badges.total_count}`} />
      </View>

      {profile.is_friend ? (
        <AppButton title={t('friends.remove.button')} onPress={() => setConfirmRemove(true)} variant="danger" />
      ) : null}

      <ConfirmModal
        visible={confirmRemove}
        title={t('friends.remove.title')}
        message={t('friends.remove.message', { name: profile.name })}
        confirmLabel={t('friends.remove.confirm')}
        cancelLabel={t('common.buttons.cancel')}
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
    trophyGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.xl },
    trophyCell: {
      minWidth: '30%', flexGrow: 1, flexBasis: '30%', maxWidth: '31.5%',
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1,
      alignItems: 'center', paddingVertical: spacing.sm, paddingHorizontal: spacing.xs,
    },
    trophyIcon: { fontSize: 26 },
    trophyName: { fontSize: 11, fontWeight: '700', color: theme.text, marginTop: 4 },
    trophyRarity: { fontSize: 9, fontWeight: '800', letterSpacing: 0.5, marginTop: 2 },
    trophyEmpty: { color: theme.textSecondary, fontSize: 13, marginBottom: spacing.xl },
    statBox: { backgroundColor: theme.surface, borderRadius: 12, padding: spacing.md, alignItems: 'center', borderWidth: 1, borderColor: theme.border, minWidth: '45%', flex: 1 },
    statValue: { fontSize: 22, fontWeight: '800', color: theme.primary, marginBottom: 4 },
    statLabel: { fontSize: 12, color: theme.textSecondary, textAlign: 'center' },
  });
}
