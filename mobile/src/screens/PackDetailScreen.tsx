import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Image } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { packApi } from '../api/packApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { ChallengePackDetail, PackAttemptState } from '../types/pack';

type Props = NativeStackScreenProps<RootStackParamList, 'PackDetail'>;

export function PackDetailScreen({ route, navigation }: Props) {
  const { slug } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [pack, setPack] = useState<ChallengePackDetail | null>(null);
  const [attempt, setAttempt] = useState<PackAttemptState | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [packRes, attemptRes] = await Promise.all([
        packApi.get(slug),
        packApi.attempt(slug).catch(() => ({ attempt: null, challenge: null })),
      ]);
      setPack(packRes.data);
      setAttempt(attemptRes.attempt);
    } catch {
      setError('Could not load this pack.');
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => { load(); }, [load]);
  // Refresh progress when returning from a play session.
  useEffect(() => navigation.addListener('focus', load), [navigation, load]);

  async function handlePlay(packName: string) {
    setStarting(true);
    setError('');
    try {
      await packApi.start(slug);
      navigation.navigate('PackGuess', { slug, packName });
    } catch (e: unknown) {
      setError((e as { message?: string })?.message ?? 'Could not start this pack.');
    } finally {
      setStarting(false);
    }
  }

  if (loading) {
    return <Screen><View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View></Screen>;
  }

  if (!pack) {
    return <Screen><View style={styles.center}><Text style={styles.emptyText}>{error || 'Pack not found.'}</Text></View></Screen>;
  }

  const count = pack.challenges.length;
  const playable = count > 0;
  const isActive = attempt?.status === 'active';
  const isCompleted = attempt?.status === 'completed';
  const ctaLabel = !playable
    ? 'No challenges yet'
    : isActive
      ? `Continue (${attempt!.completed_count}/${attempt!.total_challenges})`
      : isCompleted
        ? 'Play again'
        : 'Start Pack';

  // No challenge previews before starting — that would spoil the pack.
  // The player only sees cover, title, description, sport, difficulty,
  // challenge count and the Start button.
  return (
    <Screen scroll>
      <View style={styles.header}>
        {pack.cover_image_url ? (
          <Image source={{ uri: pack.cover_image_url }} style={styles.cover} resizeMode="cover" />
        ) : (
          <View style={styles.coverFallback}>
            <Text style={styles.coverEmoji}>{pack.sport?.emoji ?? '⚽'}</Text>
          </View>
        )}
        <Text style={styles.title}>{pack.name}</Text>
        {pack.description ? <Text style={styles.desc}>{pack.description}</Text> : null}
        <View style={styles.metaRow}>
          <Text style={styles.metaChip}>{pack.sport?.name ?? 'All sports'}</Text>
          <Text style={styles.metaChip}>{count} {count === 1 ? 'challenge' : 'challenges'}</Text>
          {pack.difficulty ? <Text style={styles.metaChip}>{cap(pack.difficulty)}</Text> : null}
          {isCompleted ? <Text style={[styles.metaChip, styles.completedChip]}>✓ Completed</Text> : null}
          {isActive ? <Text style={[styles.metaChip, styles.activeChip]}>In progress</Text> : null}
        </View>

        {playable ? (
          <AppButton
            title={ctaLabel}
            onPress={() => handlePlay(pack.name)}
            loading={starting}
            style={styles.cta}
          />
        ) : (
          <Text style={styles.note}>This pack has no ready challenges yet. Check back soon.</Text>
        )}
        {error ? <Text style={styles.errorInline}>{error}</Text> : null}
      </View>
    </Screen>
  );
}

function cap(s: string): string {
  return s.length ? s[0].toUpperCase() + s.slice(1) : s;
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 240 },
    header: { marginBottom: spacing.md },
    cover: { width: '100%', height: 160, borderRadius: 12, marginBottom: spacing.md },
    coverFallback: {
      width: '100%', height: 120, borderRadius: 12, marginBottom: spacing.md,
      backgroundColor: theme.surfaceElevated, alignItems: 'center', justifyContent: 'center',
    },
    coverEmoji: { fontSize: 48 },
    title: { fontSize: 24, fontWeight: '800', color: theme.text },
    desc: { fontSize: 14, color: theme.textSecondary, marginTop: spacing.xs, lineHeight: 20 },
    metaRow: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.sm },
    metaChip: {
      fontSize: 12, color: theme.textSecondary, backgroundColor: theme.surfaceElevated,
      borderRadius: 999, paddingHorizontal: spacing.sm, paddingVertical: 3, overflow: 'hidden',
    },
    note: { fontSize: 12, color: theme.textMuted, marginTop: spacing.md, fontStyle: 'italic' },
    cta: { marginTop: spacing.md },
    completedChip: { color: theme.success, fontWeight: '700' },
    activeChip: { color: theme.primary, fontWeight: '700' },
    errorInline: { color: theme.danger, fontSize: 13, marginTop: spacing.sm },
    emptyText: { fontSize: 15, color: theme.textMuted, textAlign: 'center' },
  });
}
