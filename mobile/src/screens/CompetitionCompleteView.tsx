import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { clubApi } from '../api/clubApi';
import { exitPlaySession, goHome, useHardwareBack } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { CompetitionAttempt, CompetitionDetail } from '../types/club';
import { isCompetitionSession } from '../utils/playSession';

type Props = NativeStackScreenProps<RootStackParamList, 'PackComplete'>;

/**
 * End of a competition attempt (rendered by PackCompleteScreen for a
 * competition session): this attempt's score, the score that COUNTS for the
 * pack, the player's rank and attempts left, and — for extra attempts — that
 * they earn no XP (D3). Fresh numbers are loaded from the competition; the
 * last guess's outcome shows meanwhile.
 */
export function CompetitionCompleteView({ route, navigation }: Props) {
  const { packName, session, competitionOutcome: outcome } = route.params;
  const exit = useCallback(() => exitPlaySession(navigation, session), [navigation, session]);
  useHardwareBack(exit);
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const competitionSession = isCompetitionSession(session) ? session : null;
  const [detail, setDetail] = useState<CompetitionDetail | null>(null);
  const [attempt, setAttempt] = useState<CompetitionAttempt | null>(null);
  const [loading, setLoading] = useState(!outcome);

  useEffect(() => {
    if (!competitionSession) return;
    let cancelled = false;
    const { clubId, competitionId, attemptId } = competitionSession;
    Promise.allSettled([
      clubApi.competition(clubId, competitionId),
      attemptId ? clubApi.competitionAttempt(attemptId) : Promise.resolve(null),
    ]).then(([d, a]) => {
      if (cancelled) return;
      if (d.status === 'fulfilled') setDetail(d.value.data);
      if (a.status === 'fulfilled' && a.value) setAttempt(a.value.attempt);
      setLoading(false);
    });
    return () => { cancelled = true; };
    // Loaded once: the session is fixed for this screen.
  }, []);

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  const packState = detail?.packs.find((p) => p.slug === competitionSession?.packSlug)?.state ?? null;
  const attemptScore = attempt?.total_score ?? outcome?.finalScore ?? null;
  const counted = packState?.counted_score ?? outcome?.competition?.counted_score ?? null;
  const rank = detail?.my_entry?.rank ?? outcome?.competition?.rank ?? null;
  const total = detail?.my_entry?.total_score ?? outcome?.competition?.total_score ?? null;
  const remaining = packState?.attempts_remaining ?? outcome?.competition?.attempts_remaining ?? null;
  const rewarded = attempt ? attempt.is_rewarded_run : outcome ? outcome.isRewardedRun : true;
  const completionXp = outcome?.completionXp ?? 0;
  const players = detail?.participants_count ?? null;
  const competitionName = detail?.name ?? competitionSession?.competitionName ?? '';

  return (
    <Screen scroll padding>
      <View style={styles.hero}>
        <Text style={styles.heroEmoji}>🏁</Text>
        <Text style={styles.heroTitle}>
          {attempt && attempt.attempt_number > 1
            ? t('clubs.competition.complete.titleNumbered', { n: attempt.attempt_number })
            : t('clubs.competition.complete.title')}
        </Text>
        <Text style={styles.heroSub}>{packName}{competitionName ? ` · ${competitionName}` : ''}</Text>
      </View>

      {typeof attemptScore === 'number' ? (
        <View style={styles.scoreCard}>
          <Text style={styles.scoreValue}>{attemptScore.toLocaleString()}</Text>
          <Text style={styles.scoreLabel}>{t('clubs.competition.complete.attemptScore')}</Text>
        </View>
      ) : null}

      <View style={styles.statsGrid}>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{typeof counted === 'number' ? counted.toLocaleString() : '–'}</Text>
          <Text style={styles.statLabel}>{t('clubs.competition.complete.countedScore')}</Text>
        </View>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>
            {typeof rank === 'number' ? (players ? t('clubs.competition.complete.rankOf', { rank, total: players }) : `#${rank}`) : '–'}
          </Text>
          <Text style={styles.statLabel}>{t('clubs.competition.complete.rank')}</Text>
        </View>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{typeof total === 'number' ? total.toLocaleString() : '–'}</Text>
          <Text style={styles.statLabel}>{t('clubs.competition.complete.totalScore')}</Text>
        </View>
        <View style={styles.statCell}>
          <Text style={styles.statValue}>{typeof remaining === 'number' ? remaining : '–'}</Text>
          <Text style={styles.statLabel}>{t('clubs.competition.complete.attemptsLeft')}</Text>
        </View>
      </View>

      {rewarded ? (
        completionXp > 0 ? <Text style={styles.xp}>{t('packs.result.completionBonus', { xp: completionXp })}</Text> : null
      ) : (
        <View style={styles.noteCard}>
          <Text style={styles.noteText}>{t('clubs.competition.complete.noXp')}</Text>
        </View>
      )}

      <View style={styles.footer}>
        <AppButton title={t('clubs.play.backToCompetition')} onPress={exit} />
        <AppButton title={t('common.buttons.home')} onPress={() => goHome(navigation)} variant="secondary" />
      </View>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    hero: { alignItems: 'center', paddingVertical: spacing.md },
    heroEmoji: { fontSize: 48 },
    heroTitle: { fontSize: 24, fontWeight: '800', color: theme.text, marginTop: spacing.xs, textAlign: 'center' },
    heroSub: { fontSize: 15, color: theme.textSecondary, marginTop: 2, textAlign: 'center' },
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
    xp: { fontSize: 15, fontWeight: '700', color: theme.success, marginTop: spacing.md, textAlign: 'center' },
    noteCard: {
      marginTop: spacing.md, padding: spacing.md, borderRadius: 12, borderWidth: 1,
      borderColor: theme.border, backgroundColor: theme.surfaceElevated,
    },
    noteText: { fontSize: 13, color: theme.textSecondary, lineHeight: 18, textAlign: 'center' },
    footer: { marginTop: spacing.lg, gap: spacing.sm },
  });
}
