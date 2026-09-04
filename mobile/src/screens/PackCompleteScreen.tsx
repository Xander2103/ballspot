import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { EmptyState } from '../components/EmptyState';
import { packApi } from '../api/packApi';
import { goHome, goPacks, useHardwareBack } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { PackCompletionSummary } from '../types/pack';
import { getApiErrorMessage } from '../utils/apiError';
import { completionHeadline, challengeCountLabel, formatAverage, formatPct } from '../utils/packCompletion';

type Props = NativeStackScreenProps<RootStackParamList, 'PackComplete'>;

/**
 * "Pack completed" overview: totals, average, best guess, trophy. Reached from
 * the final PackResult, from a completed pack's detail page ("View results"),
 * and whenever the app finds an attempt that is already completed.
 */
export function PackCompleteScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goPacks(navigation), [navigation]));
  const { slug, packName, completion: initial } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [summary, setSummary] = useState<PackCompletionSummary | null>(initial ?? null);
  const [loading, setLoading] = useState(!initial);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await packApi.attempt(slug);
      if (res.completion) {
        setSummary(res.completion);
      } else {
        setError('This pack has not been completed yet.');
      }
    } catch (e: unknown) {
      setError(getApiErrorMessage(e, 'Could not load your pack results.'));
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => {
    if (!initial) load();
  }, [initial, load]);

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  if (!summary) {
    return (
      <Screen padding>
        <EmptyState
          icon="📦"
          title="No results yet"
          message={error || 'Play the pack to see your results here.'}
          actions={[
            { label: 'Retry', onPress: load },
            { label: 'Back to Packs', onPress: () => goPacks(navigation) },
          ]}
        />
      </Screen>
    );
  }

  const trophy = summary.trophy;

  return (
    <Screen scroll padding>
      <View style={styles.hero}>
        <Text style={styles.heroEmoji}>{summary.is_perfect ? '💎' : '🎉'}</Text>
        <Text style={styles.heroTitle}>{completionHeadline(summary)}</Text>
        <Text style={styles.heroSub}>{summary.pack?.name ?? packName}</Text>
      </View>

      <View style={styles.scoreCard}>
        <Text style={styles.scoreValue}>{summary.total_score}</Text>
        <Text style={styles.scoreLabel}>of {summary.max_score} points · {formatPct(summary.average_pct)}</Text>
      </View>

      <View style={styles.statsGrid}>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{formatAverage(summary.average_score)}</Text>
          <Text style={styles.statLabel}>Average score</Text>
        </View>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{summary.best_guess?.score ?? '–'}</Text>
          <Text style={styles.statLabel} numberOfLines={2}>
            Best guess{summary.best_guess?.title ? ` · ${summary.best_guess.title}` : ''}
          </Text>
        </View>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{summary.completed_count}/{summary.total_challenges}</Text>
          <Text style={styles.statLabel}>{challengeCountLabel(summary.total_challenges)} completed</Text>
        </View>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{summary.completion_xp > 0 ? `+${summary.completion_xp}` : '–'}</Text>
          <Text style={styles.statLabel}>Completion XP</Text>
        </View>
      </View>

      {trophy ? (
        <View style={[styles.trophyCard, trophy.earned && styles.trophyCardEarned]}>
          <Text style={styles.trophyIcon}>{trophy.icon}</Text>
          <View style={styles.trophyText}>
            <Text style={styles.trophyName}>{trophy.name}</Text>
            <Text style={styles.trophySub}>
              {trophy.earned ? 'Trophy earned — see it in your Trophy Room.' : 'Pack trophy'}
            </Text>
          </View>
        </View>
      ) : null}

      <Text style={styles.note}>
        Completed packs can't be replayed — you already know where every ball is. New packs appear in the Packs list.
      </Text>

      <View style={styles.footer}>
        {trophy?.earned ? (
          <AppButton title="View Trophy Room" onPress={() => navigation.navigate('TrophyRoom')} />
        ) : null}
        <AppButton title="Back to Packs" onPress={() => goPacks(navigation)} variant={trophy?.earned ? 'secondary' : 'primary'} />
        <AppButton title="Home" onPress={() => goHome(navigation)} variant="secondary" />
      </View>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    hero: { alignItems: 'center', paddingVertical: spacing.md },
    heroEmoji: { fontSize: 48 },
    heroTitle: { fontSize: 24, fontWeight: '800', color: theme.text, marginTop: spacing.xs },
    heroSub: { fontSize: 15, color: theme.textSecondary, marginTop: 2 },
    scoreCard: {
      alignItems: 'center', backgroundColor: theme.surface, borderRadius: 16, paddingVertical: spacing.lg,
      borderWidth: 1, borderColor: theme.border, marginTop: spacing.sm,
    },
    scoreValue: { fontSize: 44, fontWeight: '800', color: theme.primary },
    scoreLabel: { fontSize: 13, color: theme.textMuted, marginTop: 2 },
    statsGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.md },
    statCell: {
      width: '48%', flexGrow: 1, backgroundColor: theme.surface, borderRadius: 12, padding: spacing.md,
      borderWidth: 1, borderColor: theme.border,
    },
    statValue: { fontSize: 20, fontWeight: '800', color: theme.text },
    statLabel: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    trophyCard: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginTop: spacing.md,
      backgroundColor: theme.surface, borderRadius: 14, padding: spacing.md, borderWidth: 1, borderColor: theme.border,
    },
    trophyCardEarned: { borderColor: theme.primary, backgroundColor: theme.surfaceElevated },
    trophyIcon: { fontSize: 34 },
    trophyText: { flex: 1 },
    trophyName: { fontSize: 16, fontWeight: '700', color: theme.text },
    trophySub: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    note: { fontSize: 12, color: theme.textMuted, marginTop: spacing.md, textAlign: 'center', lineHeight: 18 },
    footer: { marginTop: spacing.lg, gap: spacing.sm },
  });
}
