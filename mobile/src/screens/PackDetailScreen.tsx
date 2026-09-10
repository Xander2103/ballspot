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
import { isPackAlreadyCompleted } from '../types/pack';
import type { ChallengePackDetail, PackAttemptState, PackCompletionSummary } from '../types/pack';
import { getApiErrorMessage } from '../utils/apiError';
import { formatPct } from '../utils/packCompletion';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'PackDetail'>;

export function PackDetailScreen({ route, navigation }: Props) {
  const { slug } = route.params;
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [pack, setPack] = useState<ChallengePackDetail | null>(null);
  const [attempt, setAttempt] = useState<PackAttemptState | null>(null);
  const [completion, setCompletion] = useState<PackCompletionSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [packRes, attemptRes] = await Promise.all([
        packApi.get(slug),
        packApi.attempt(slug).catch(() => ({ attempt: null, challenge: null, completion: null })),
      ]);
      setPack(packRes.data);
      setAttempt(attemptRes.attempt);
      setCompletion(attemptRes.completion ?? null);
    } catch (e: unknown) {
      setError(getApiErrorMessage(e, t('packs.detail.loadError')));
    } finally {
      setLoading(false);
    }
  }, [slug, t]);

  useEffect(() => { load(); }, [load]);
  // Refresh progress when returning from a play session.
  useEffect(() => navigation.addListener('focus', load), [navigation, load]);

  function showResults(packName: string) {
    navigation.navigate('PackComplete', { slug, packName, completion });
  }

  async function handlePlay(packName: string) {
    if (starting) return;
    setStarting(true);
    setError('');
    try {
      await packApi.start(slug);
      navigation.navigate('PackGuess', { slug, packName });
    } catch (e: unknown) {
      if (isPackAlreadyCompleted(e)) {
        // Completed in the meantime (other device / stale screen): show the
        // overview instead of an error.
        setAttempt(e.attempt ?? attempt);
        setCompletion(e.completion ?? completion);
        navigation.navigate('PackComplete', { slug, packName, completion: e.completion ?? completion });
        return;
      }
      setError(getApiErrorMessage(e, t('packs.detail.startError')));
    } finally {
      setStarting(false);
    }
  }

  if (loading) {
    return <Screen><View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View></Screen>;
  }

  if (!pack) {
    return (
      <Screen padding>
        <View style={styles.center}>
          <Text style={styles.emptyText}>{error || t('packs.detail.notFound')}</Text>
          <AppButton title={t('common.buttons.retry')} onPress={load} variant="secondary" style={styles.cta} />
        </View>
      </Screen>
    );
  }

  const count = pack.challenges.length;
  const playable = count > 0;
  const isActive = attempt?.status === 'active';
  const isCompleted = attempt?.status === 'completed';

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
          <Text style={styles.metaChip}>{pack.sport?.name ?? t('packs.meta.allSports')}</Text>
          <Text style={styles.metaChip}>{t('packs.meta.challenges', { count })}</Text>
          {pack.difficulty ? <Text style={styles.metaChip}>{cap(pack.difficulty)}</Text> : null}
          {isCompleted ? <Text style={[styles.metaChip, styles.completedChip]}>{t('packs.meta.completed')}</Text> : null}
          {isActive ? <Text style={[styles.metaChip, styles.activeChip]}>{t('packs.meta.inProgress')}</Text> : null}
        </View>

        {isCompleted ? (
          // Completed packs are not replayable (the photos are known). The
          // player gets their results instead of a "Play again".
          <View style={styles.completedCard}>
            <Text style={styles.completedTitle}>{t('packs.detail.completedTitle')}</Text>
            <Text style={styles.completedSub}>
              {completion
                ? t('packs.detail.completedSummary', { score: completion.total_score, max: completion.max_score, pct: formatPct(completion.average_pct) })
                  + (completion.trophy?.earned ? t('packs.detail.trophyEarnedSuffix', { icon: completion.trophy.icon }) : '')
                : t('packs.detail.pointsOnly', { score: attempt!.total_score })}
            </Text>
            <AppButton title={t('packs.detail.viewResults')} onPress={() => showResults(pack.name)} style={styles.cta} />
          </View>
        ) : playable ? (
          <AppButton
            title={isActive ? t('packs.detail.continue', { done: attempt!.completed_count, total: attempt!.total_challenges }) : t('packs.detail.start')}
            onPress={() => handlePlay(pack.name)}
            loading={starting}
            style={styles.cta}
          />
        ) : (
          <Text style={styles.note}>{t('packs.detail.noChallenges')}</Text>
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
    completedCard: {
      marginTop: spacing.md, backgroundColor: theme.surface, borderRadius: 14, padding: spacing.md,
      borderWidth: 1, borderColor: theme.border,
    },
    completedTitle: { fontSize: 16, fontWeight: '700', color: theme.text },
    completedSub: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    completedChip: { color: theme.success, fontWeight: '700' },
    activeChip: { color: theme.primary, fontWeight: '700' },
    errorInline: { color: theme.danger, fontSize: 13, marginTop: spacing.sm },
    emptyText: { fontSize: 15, color: theme.textMuted, textAlign: 'center' },
  });
}
