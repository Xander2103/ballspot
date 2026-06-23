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
import { LeagueRound } from '../types/challenge';

type Props = NativeStackScreenProps<RootStackParamList, 'Guess'>;

const DIFFICULTY_COLOR: Record<string, string> = {
  easy: '#00c853',
  medium: '#ffab40',
  hard: '#ff5252',
};

export function GuessScreen({ route, navigation }: Props) {
  const { leagueId, roundId, leagueName } = route.params;
  const [round, setRound] = useState<LeagueRound | null>(null);
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
    roundApi.currentRound(leagueId)
      .then((res) => {
        if (res.current_round && res.current_round.id === roundId) {
          setRound(res.current_round);
        } else {
          Alert.alert('No Round', 'This round is no longer available.', [
            { text: 'OK', onPress: () => navigation.goBack() },
          ]);
        }
      })
      .catch(() => Alert.alert('Error', 'Failed to load round'))
      .finally(() => setLoading(false));
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
      await roundApi.submitGuess(roundId, { guess_x_ratio: guessX, guess_y_ratio: guessY });
      navigation.replace('Result', {
        roundId,
        leagueId,
        imageUrl: round!.challenge.hidden_image_url,
        leagueName,
        categoryName: round!.challenge.category?.name ?? null,
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
        <Text style={styles.instruction}>Tap where you think the ball was hidden.</Text>
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
