import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, Alert, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { FullscreenImageViewer } from '../components/FullscreenImageViewer';
import { FullscreenButton } from '../components/FullscreenButton';
import { roundApi } from '../api/roundApi';
import { goHome, useHardwareBack } from '../app/navigationActions';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { LeagueRound, CurrentRoundProgress } from '../types/challenge';
import { getApiErrorMessage } from '../utils/apiError';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'Guess'>;

const DIFFICULTY_COLOR: Record<string, string> = {
  easy: '#00c853',
  medium: '#ffab40',
  hard: '#ff5252',
};

export function GuessScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goHome(navigation), [navigation]));
  const { leagueId, roundId, leagueName } = route.params;
  const { t } = useI18n();
  const [round, setRound] = useState<LeagueRound | null>(null);
  const [progress, setProgress] = useState<CurrentRoundProgress | null>(null);
  const [dailyContext, setDailyContext] = useState<{ roundsPerDay: number; playedToday: number; remainingToday: number } | null>(null);
  const [guessX, setGuessX] = useState<number | null>(null);
  const [guessY, setGuessY] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [loading, setLoading] = useState(true);
  const [fullscreen, setFullscreen] = useState(false);

  useEffect(() => {
    if (!leagueId || !roundId) {
      Alert.alert(t('game.alerts.errorTitle'), t('game.guess.alerts.invalidRound'), [
        { text: t('common.buttons.ok'), onPress: () => goHome(navigation) },
      ]);
      setLoading(false);
      return;
    }

    let cancelled = false;

    roundApi.currentRound(leagueId)
      .then((res) => {
        if (cancelled) return;

        // current-round already excludes rounds this user has guessed, so a
        // matching id proves the round is still unplayed. No result probe is
        // needed (it could only ever 404) — a mismatch means the round moved on.
        if (!res.current_round || res.current_round.id !== roundId) {
          Alert.alert(t('game.guess.alerts.unavailableTitle'), t('game.guess.alerts.unavailable'), [
            { text: t('common.buttons.ok'), onPress: () => goHome(navigation) },
          ]);
          setLoading(false);
          return;
        }

        setRound(res.current_round);
        setProgress(res.progress ?? null);
        setDailyContext({
          roundsPerDay: res.rounds_per_day,
          playedToday: res.played_today_count,
          remainingToday: res.remaining_today_count,
        });
        setLoading(false);
      })
      .catch(() => {
        if (cancelled) return;
        Alert.alert(t('game.guess.alerts.connectionTitle'), t('game.guess.alerts.connection'), [
          { text: t('common.buttons.ok'), onPress: () => goHome(navigation) },
        ]);
        setLoading(false);
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
      setSubmitError(t('game.guess.lockBeforeSubmit'));
      return;
    }
    setSubmitting(true);
    setSubmitError('');
    try {
      const { newBadges, rankProgress, rankUp, tournamentCompletion } = await roundApi.submitGuess(roundId, { guess_x_ratio: guessX, guess_y_ratio: guessY });
      navigation.replace('Result', {
        roundId,
        leagueId,
        imageUrl: round!.challenge.hidden_image_url,
        leagueName,
        categoryName: round!.challenge.category?.name ?? null,
        challengeTitle: round!.challenge.title,
        sportSlug: round!.challenge.sport?.slug ?? null,
        newBadges,
        rankProgress,
        rankUp,
        tournamentCompletion,
      });
    } catch (e: unknown) {
      setSubmitError(getApiErrorMessage(e, t('game.guess.submitError')));
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
    ? t('game.guess.locked', { x: Math.round(guessX! * 100), y: Math.round(guessY! * 100) })
    : t('game.image.tapToPlace');

  const roundContextSuffix = progress
    ? progress.remaining === 1
      ? t('game.guess.lastRound')
      : progress.remaining === 0 && progress.total > 0
        ? t('game.guess.bonusRound')
        : t('game.guess.moreRounds', { count: progress.remaining - 1 })
    : '';

  return (
    // scroll: a portrait image (height = width / aspect) can overflow small
    // phones; without scrolling the Submit footer becomes unreachable.
    <Screen scroll padding={false}>
      {/* Challenge info card */}
      <View style={styles.infoCard}>
        <View style={styles.infoRow}>
          <Text style={styles.roundNum}>{t('game.guess.round', { number: round.round_number })}</Text>
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
        {progress && (
          <Text style={styles.roundContext}>
            {t('game.guess.roundOf', { number: round.round_number, total: progress.total })}
            {`  ·  ${roundContextSuffix}`}
          </Text>
        )}
        {dailyContext && dailyContext.roundsPerDay > 1 && (
          <Text style={styles.dailyContext}>
            {t('game.guess.todayPlayed', { played: dailyContext.playedToday, total: dailyContext.roundsPerDay })}
            {' · '}{dailyContext.remainingToday === 1 ? t('game.guess.lastRoundToday') : t('game.guess.roundsLeftToday', { count: dailyContext.remainingToday })}
          </Text>
        )}
        <Text style={styles.instruction}>{t('game.guess.instruction')}</Text>
      </View>

      {/* Image card */}
      {round.challenge.hidden_image_url ? (
        <View style={styles.imageCard}>
          <ImageGuessPicker
            imageUri={round.challenge.hidden_image_url}
            onGuess={handleGuess}
            interactive
            selectedPoint={hasGuess ? { x: guessX!, y: guessY! } : null}
            sportSlug={round.challenge.sport?.slug}
          />
          <FullscreenButton onPress={() => setFullscreen(true)} variant="static" compact />
        </View>
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>{t('game.image.unavailable')}</Text>
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
          title={t('game.buttons.submitGuess')}
          onPress={handleSubmit}
          loading={submitting}
          disabled={!hasGuess}
        />
      </View>

      <FullscreenImageViewer
        visible={fullscreen}
        imageUri={round.challenge.hidden_image_url}
        onClose={() => setFullscreen(false)}
        selectable
        selectedPoint={hasGuess ? { x: guessX!, y: guessY! } : null}
        onSelectPoint={handleGuess}
        sportSlug={round.challenge.sport?.slug}
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
  noImage: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: spacing.xl },
  noImageText: { color: colors.textMuted, fontSize: 14, fontStyle: 'italic' },
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
