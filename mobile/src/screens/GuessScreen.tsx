import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, Alert, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { roundApi } from '../api/roundApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { LeagueRound, CurrentRoundProgress } from '../types/challenge';

type Props = NativeStackScreenProps<RootStackParamList, 'Guess'>;

const DIFFICULTY_COLOR: Record<string, string> = {
  easy: '#00c853',
  medium: '#ffab40',
  hard: '#ff5252',
};

export function GuessScreen({ route, navigation }: Props) {
  const { leagueId, roundId, leagueName } = route.params;
  const [round, setRound] = useState<LeagueRound | null>(null);
  const [progress, setProgress] = useState<CurrentRoundProgress | null>(null);
  const [dailyContext, setDailyContext] = useState<{ roundsPerDay: number; playedToday: number; remainingToday: number } | null>(null);
  const [guessX, setGuessX] = useState<number | null>(null);
  const [guessY, setGuessY] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!leagueId || !roundId) {
      Alert.alert('Error', 'Invalid round — missing league or round ID.', [
        { text: 'OK', onPress: () => navigation.goBack() },
      ]);
      setLoading(false);
      return;
    }

    let cancelled = false;

    roundApi.currentRound(leagueId)
      .then(async (res) => {
        if (cancelled) return;
        if (!res.current_round || res.current_round.id !== roundId) {
          Alert.alert('No Round', 'This round is no longer available.', [
            { text: 'OK', onPress: () => navigation.goBack() },
          ]);
          setLoading(false);
          return;
        }

        // Check if already guessed — redirect to Result if so
        try {
          const existing = await roundApi.result(roundId);
          if (!cancelled && existing) {
            navigation.replace('Result', {
              roundId,
              leagueId,
              imageUrl: res.current_round.challenge.hidden_image_url,
              leagueName,
              categoryName: res.current_round.challenge.category?.name ?? null,
            });
            return;
          }
        } catch {
          // 404 means not yet guessed — proceed normally
        }

        if (!cancelled) {
          setRound(res.current_round);
          setProgress(res.progress ?? null);
          setDailyContext({
            roundsPerDay: res.rounds_per_day,
            playedToday: res.played_today_count,
            remainingToday: res.remaining_today_count,
          });
          setLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) Alert.alert('Error', 'Failed to load round');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, [leagueId, roundId]);

  function handleGuess(x: number, y: number) {
    // Extra safety: never let NaN reach state
    if (!Number.isFinite(x) || !Number.isFinite(y)) return;
    setGuessX(x);
    setGuessY(y);
    setSubmitError('');
  }

  async function handleSubmit() {
    if (guessX === null || guessY === null) return;
    if (!Number.isFinite(guessX) || !Number.isFinite(guessY)) {
      setSubmitError('Tap the image to lock your guess before submitting.');
      return;
    }
    setSubmitting(true);
    setSubmitError('');
    try {
      const { newBadges, rankProgress, rankUp } = await roundApi.submitGuess(roundId, { guess_x_ratio: guessX, guess_y_ratio: guessY });
      navigation.replace('Result', {
        roundId,
        leagueId,
        imageUrl: round!.challenge.hidden_image_url,
        leagueName,
        categoryName: round!.challenge.category?.name ?? null,
        newBadges,
        rankProgress,
        rankUp,
      });
    } catch (e: any) {
      setSubmitError(e?.message || 'Failed to submit guess. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  if (!round) return null;

  const hasGuess = guessX !== null && guessY !== null;
  const diffColor = DIFFICULTY_COLOR[round.challenge.difficulty] ?? colors.textSecondary;
  const categoryName = round.challenge.category?.name ?? null;

  const guessLabel = hasGuess
    ? `Guess locked at ${Math.round(guessX! * 100)}%, ${Math.round(guessY! * 100)}%`
    : 'Tap the image to place your guess';

  return (
    <Screen scroll={false} padding={false}>
      {/* Challenge info card */}
      <View style={styles.infoCard}>
        <View style={styles.infoRow}>
          <Text style={styles.roundNum}>Round {round.round_number}</Text>
          <View style={styles.badges}>
            {categoryName ? (
              <View style={styles.catBadge}>
                <Text style={styles.catText}>{categoryName}</Text>
              </View>
            ) : null}
            <View style={[styles.diffBadge, { backgroundColor: diffColor + '26' }]}>
              <Text style={[styles.diffText, { color: diffColor }]}>
                {round.challenge.difficulty.toUpperCase()}
              </Text>
            </View>
          </View>
        </View>
        <Text style={styles.challengeTitle}>{round.challenge.title}</Text>
        {progress && (
          <Text style={styles.roundContext}>
            Round {round.round_number} of {progress.total}
            {progress.remaining === 1
              ? '  ·  Last round!'
              : progress.remaining === 0 && progress.total > 0
                ? '  ·  Bonus round'
                : `  ·  ${progress.remaining - 1} more round${progress.remaining - 1 !== 1 ? 's' : ''} after this`}
          </Text>
        )}
        {dailyContext && dailyContext.roundsPerDay > 1 && (
          <Text style={styles.dailyContext}>
            Today: {dailyContext.playedToday}/{dailyContext.roundsPerDay} played
            {' · '}{dailyContext.remainingToday === 1 ? 'Last round available today' : `${dailyContext.remainingToday} rounds left today`}
          </Text>
        )}
        <Text style={styles.instruction}>Tap the image to place the missing ball.</Text>
      </View>

      {/* Image card */}
      <View style={styles.imageCard}>
        <ImageGuessPicker
          imageUri={round.challenge.hidden_image_url}
          onGuess={handleGuess}
          interactive
        />
      </View>

      {/* Footer: guess status + submit */}
      <View style={styles.footer}>
        <View style={styles.guessStatus}>
          <Text style={[styles.guessLabel, hasGuess && styles.guessLabelActive]}>
            {hasGuess ? '✓ ' : ''}{guessLabel}
          </Text>
        </View>
        {submitError ? <Text style={styles.submitError}>{submitError}</Text> : null}
        <AppButton
          title="Submit Guess"
          onPress={handleSubmit}
          loading={submitting}
          disabled={!hasGuess}
        />
      </View>
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
  infoCard: {
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.md,
    paddingBottom: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  infoRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 4,
  },
  roundNum: {
    fontSize: 12,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    letterSpacing: 1,
    fontWeight: '600',
  },
  badges: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  catBadge: {
    borderRadius: 6,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    backgroundColor: colors.surfaceElevated,
  },
  catText: {
    fontSize: 10,
    fontWeight: '600',
    color: colors.textSecondary,
    letterSpacing: 0.5,
  },
  diffBadge: {
    borderRadius: 6,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
  },
  diffText: {
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 1,
  },
  challengeTitle: {
    fontSize: 20,
    fontWeight: '700',
    color: colors.text,
    marginBottom: 4,
  },
  roundContext: {
    fontSize: 12,
    color: colors.primary,
    fontWeight: '600',
    marginBottom: 2,
  },
  dailyContext: {
    fontSize: 12,
    color: colors.textSecondary,
    fontWeight: '500',
    marginBottom: 2,
  },
  instruction: {
    fontSize: 13,
    color: colors.textSecondary,
    fontStyle: 'italic',
  },
  imageCard: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    backgroundColor: colors.background,
  },
  footer: {
    padding: spacing.md,
    gap: spacing.sm,
    backgroundColor: colors.background,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  guessStatus: {
    backgroundColor: colors.surface,
    borderRadius: 10,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    alignItems: 'center',
  },
  guessLabel: {
    fontSize: 14,
    color: colors.textMuted,
    textAlign: 'center',
  },
  guessLabelActive: {
    color: colors.primary,
    fontWeight: '600',
  },
  submitError: {
    color: colors.error,
    fontSize: 13,
    textAlign: 'center',
  },
});
