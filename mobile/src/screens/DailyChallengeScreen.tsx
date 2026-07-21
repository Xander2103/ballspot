import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, Alert, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { dailyApi } from '../api/dailyApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { DailyChallengeEntry } from '../types/daily';

type Props = NativeStackScreenProps<RootStackParamList, 'DailyChallenge'>;

const DIFFICULTY_COLOR: Record<string, string> = {
  easy: '#00c853',
  medium: '#ffab40',
  hard: '#ff5252',
};

export function DailyChallengeScreen({ route, navigation }: Props) {
  const { dailyChallengeId } = route.params;

  const [challenge, setChallenge] = useState<DailyChallengeEntry | null>(null);
  const [guessX, setGuessX] = useState<number | null>(null);
  const [guessY, setGuessY] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    dailyApi.today()
      .then((res) => {
        if (cancelled) return;

        if (res.already_played) {
          navigation.replace('DailyResult', { dailyChallengeId });
          return;
        }

        if (!res.has_daily || !res.daily_challenge) {
          Alert.alert('No Challenge', 'No daily challenge is available today.', [
            { text: 'OK', onPress: () => navigation.navigate('Home') },
          ]);
          setLoading(false);
          return;
        }

        setChallenge(res.daily_challenge);
        setLoading(false);
      })
      .catch(() => {
        if (!cancelled) {
          Alert.alert('Error', 'Failed to load daily challenge.', [
            { text: 'OK', onPress: () => navigation.navigate('Home') },
          ]);
          setLoading(false);
        }
      });

    return () => { cancelled = true; };
  }, [dailyChallengeId]);

  function handleGuess(x: number, y: number) {
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
      const res = await dailyApi.guess(dailyChallengeId, guessX, guessY);
      navigation.replace('DailyResult', { dailyChallengeId, newBadges: res.data.new_badges ?? [] });
    } catch (e: unknown) {
      const err = e as { status?: number; message?: string };
      if (err?.status === 422) {
        // Duplicate guess — already played
        navigation.replace('DailyResult', { dailyChallengeId });
        return;
      }
      setSubmitError(err?.message ?? 'Failed to submit guess. Please try again.');
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

  if (!challenge) return null;

  const hasGuess = guessX !== null && guessY !== null;
  const diffColor = DIFFICULTY_COLOR[challenge.challenge.difficulty] ?? colors.textSecondary;
  const categoryName = challenge.challenge.category?.name ?? null;
  const imageUrl = challenge.challenge.hidden_image_url;

  const todayLabel = new Date().toLocaleDateString('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
  });

  const guessLabel = hasGuess
    ? `Guess locked at ${Math.round(guessX! * 100)}%, ${Math.round(guessY! * 100)}%`
    : 'Tap the image to place your guess';

  return (
    <Screen scroll={false} padding={false}>
      {/* Challenge info card */}
      <View style={styles.infoCard}>
        <View style={styles.infoRow}>
          <Text style={styles.dateLabel}>{todayLabel}</Text>
          <View style={styles.badges}>
            {categoryName ? (
              <View style={styles.catBadge}>
                <Text style={styles.catText}>{categoryName}</Text>
              </View>
            ) : null}
            <View style={[styles.diffBadge, { backgroundColor: diffColor + '26' }]}>
              <Text style={[styles.diffText, { color: diffColor }]}>
                {challenge.challenge.difficulty.toUpperCase()}
              </Text>
            </View>
          </View>
        </View>
        <Text style={styles.challengeTitle}>{challenge.challenge.title}</Text>
        <Text style={styles.instruction}>Tap the image to place the missing ball.</Text>
      </View>

      {/* Image card */}
      {imageUrl ? (
        <View style={styles.imageCard}>
          <ImageGuessPicker
            imageUri={imageUrl}
            onGuess={handleGuess}
            interactive
          />
        </View>
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>Image unavailable</Text>
        </View>
      )}

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
          disabled={!hasGuess || submitting}
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
  dateLabel: {
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
  noImage: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.xl,
  },
  noImageText: {
    color: colors.textMuted,
    fontSize: 14,
    fontStyle: 'italic',
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
