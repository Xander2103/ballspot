import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { Marker } from '../components/ImageGuessPicker';
import { ResultImageSection } from '../components/ResultImageSection';
import { RankInsight } from '../components/RankInsight';
import { NewBadgesCard } from '../components/NewBadgesCard';
import { RankProgressCard } from '../components/RankProgressCard';
import { RankUpCard } from '../components/RankUpCard';
import { TournamentCompletionCard } from '../components/TournamentCompletionCard';
import { roundApi } from '../api/roundApi';
import { goHome, useHardwareBack } from '../app/navigationActions';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { GuessResult } from '../types/guess';
import { CurrentRoundResponse } from '../types/challenge';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'Result'>;

/** Translation key for the score headline (resolved with t() in the component). */
function getScoreRatingKey(score: number): string {
  if (score >= 90) return 'game.result.rating.perfect';
  if (score >= 70) return 'game.result.rating.veryClose';
  if (score >= 40) return 'game.result.rating.notBad';
  if (score >= 1) return 'game.result.rating.farAway';
  return 'game.result.rating.missed';
}

function getScoreColor(score: number): string {
  if (score >= 90) return colors.success;
  if (score >= 70) return colors.primary;
  if (score >= 40) return colors.warning;
  return colors.error;
}

/** Translation key for the distance feedback, or '' when the distance is unusable. */
function getDistanceFeedbackKey(distance: number): string {
  if (!Number.isFinite(distance)) return '';
  if (distance <= 0.03) return 'game.result.distance.rightOnIt';
  if (distance <= 0.10) return 'game.result.distance.veryClose';
  if (distance <= 0.25) return 'game.result.distance.bitOff';
  return 'game.result.distance.wayOff';
}

export function ResultScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goHome(navigation), [navigation]));
  const { roundId, leagueId, imageUrl, leagueName, categoryName, challengeTitle, newBadges, rankProgress, rankUp, tournamentCompletion } = route.params;
  const routeSportSlug = route.params.sportSlug ?? null;
  const { t } = useI18n();
  const [result, setResult] = useState<GuessResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [nextRound, setNextRound] = useState<CurrentRoundResponse | null>(null);
  const [loadError, setLoadError] = useState<'missing' | 'network' | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    if (!roundId) { setLoading(false); setLoadError('missing'); return; }
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    roundApi.result(roundId)
      .then((r) => { if (!cancelled) setResult(r); })
      .catch((e: unknown) => {
        if (cancelled) return;
        // 404 = this round has no guess from this user (nothing to retry).
        setLoadError((e as { status?: number })?.status === 404 ? 'missing' : 'network');
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [roundId, reloadKey]);

  useEffect(() => {
    if (!leagueId) return;
    roundApi.currentRound(leagueId)
      .then(setNextRound)
      .catch(() => {}); // silent — worst case: show no next round info
  }, [leagueId]);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  if (!result) {
    return (
      <Screen padding>
        <Text style={{ color: colors.text }}>
          {loadError === 'network'
            ? t('game.result.loadNetworkError')
            : t('game.result.notFound')}
        </Text>
        {loadError === 'network' ? (
          <AppButton
            title={t('common.buttons.tryAgain')}
            onPress={() => setReloadKey((k) => k + 1)}
            style={{ marginTop: spacing.lg }}
          />
        ) : null}
        <AppButton
          title={t('game.buttons.backHome')}
          onPress={() => goHome(navigation)}
          variant={loadError === 'network' ? 'secondary' : 'primary'}
          style={{ marginTop: spacing.sm }}
        />
      </Screen>
    );
  }

  const scoreColor = getScoreColor(result.score);
  const rating = t(getScoreRatingKey(result.score));
  const distanceFeedbackKey = getDistanceFeedbackKey(result.distance);
  const distanceFeedback = distanceFeedbackKey ? t(distanceFeedbackKey) : '';
  const displayImageUrl = result.reveal_image_url ?? imageUrl;
  const isRevealImage = !!result.reveal_image_url;

  const markers: Marker[] = [
    { x_ratio: result.guess_x_ratio, y_ratio: result.guess_y_ratio, type: 'ghost-ball' },
    { x_ratio: result.ball_x_ratio, y_ratio: result.ball_y_ratio, type: isRevealImage ? 'glow' : 'default' },
  ];

  return (
    <Screen scroll padding>
      {/* Score card */}
      <View style={styles.scoreBox}>
        {categoryName ? (
          <Text style={styles.categoryLabel}>{categoryName}</Text>
        ) : null}
        <Text style={styles.scoreLabel}>{t('game.result.yourScore')}</Text>
        <Text style={[styles.score, { color: scoreColor }]}>{result.score}</Text>
        <Text style={styles.rating}>{rating}</Text>
        <View style={styles.distanceRow}>
          <Text style={styles.distanceValue}>
            {Number.isFinite(result.distance) ? t('game.result.away', { percent: (result.distance * 100).toFixed(1) }) : '—'}
          </Text>
          {distanceFeedback ? (
            <Text style={styles.distanceFeedback}> · {distanceFeedback}</Text>
          ) : null}
        </View>
      </View>

      {/* Reveal image + legend — directly under the score card */}
      {displayImageUrl ? (
        <ResultImageSection
          imageUri={displayImageUrl}
          markers={markers}
          isRevealImage={isRevealImage}
          guessXRatio={result.guess_x_ratio}
          guessYRatio={result.guess_y_ratio}
          ballXRatio={result.ball_x_ratio}
          ballYRatio={result.ball_y_ratio}
          title={challengeTitle ?? null}
          sportSlug={result.sport?.slug ?? routeSportSlug}
        />
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>{t('game.image.unavailable')}</Text>
        </View>
      )}

      {/* Tournament completion (only on the finishing round) */}
      {tournamentCompletion?.is_completed ? <TournamentCompletionCard completion={tournamentCompletion} /> : null}

      {/* Rank up / XP progress / new badges */}
      {rankUp ? <RankUpCard rankUp={rankUp} /> : null}
      {rankProgress ? <RankProgressCard progress={rankProgress} /> : null}
      {newBadges && newBadges.length > 0 ? <NewBadgesCard badges={newBadges} /> : null}

      {/* Rank / percentile insight */}
      {typeof result.total_players === 'number' ? (
        <RankInsight
          rank={result.rank ?? 1}
          totalPlayers={result.total_players}
          betterThanPercentage={result.better_than_percentage ?? 0}
        />
      ) : null}

      {(() => {
        const hasNextRound =
          nextRound?.has_current_round === true &&
          nextRound?.reason !== 'daily_limit_reached';

        const doneMessage = nextRound?.reason === 'daily_limit_reached'
          ? t('game.result.doneForToday')
          : nextRound?.completed
            ? t('game.result.completedAll')
            : nextRound !== null
              ? t('game.result.noMoreRounds')
              : null;

        return (
          <>
            {!hasNextRound && doneMessage ? (
              <Text style={styles.doneForTodayText}>{doneMessage}</Text>
            ) : null}
            {hasNextRound ? (
              <AppButton
                title={t('game.buttons.playNextRound')}
                onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
                style={styles.nextBtn}
              />
            ) : null}
            <AppButton
              title={t('game.buttons.backToTournament')}
              onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
              variant={hasNextRound ? 'secondary' : 'primary'}
              style={hasNextRound ? undefined : styles.nextBtn}
            />
          </>
        );
      })()}
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
  scoreBox: {
    alignItems: 'center',
    paddingVertical: spacing.xl,
    backgroundColor: colors.surface,
    borderRadius: 16,
    marginBottom: spacing.sm,
  },
  categoryLabel: {
    fontSize: 11,
    color: colors.textMuted,
    fontWeight: '600',
    letterSpacing: 0.5,
    marginBottom: 4,
  },
  scoreLabel: {
    fontSize: 12,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    letterSpacing: 1,
    fontWeight: '600',
  },
  score: {
    fontSize: 72,
    fontWeight: '800',
    marginVertical: spacing.xs,
  },
  rating: {
    fontSize: 20,
    fontWeight: '600',
    color: colors.text,
    marginBottom: spacing.xs,
  },
  distanceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    justifyContent: 'center',
    paddingHorizontal: spacing.md,
  },
  distanceValue: {
    fontSize: 14,
    color: colors.textSecondary,
  },
  distanceFeedback: {
    fontSize: 14,
    color: colors.textMuted,
  },
  noImage: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.xl,
  },
  noImageText: {
    color: colors.textMuted,
    fontSize: 14,
    fontStyle: 'italic',
  },
  nextBtn: {
    marginBottom: spacing.sm,
  },
  doneForTodayText: {
    fontSize: 13,
    color: colors.textSecondary,
    textAlign: 'center',
    fontStyle: 'italic',
    marginBottom: spacing.sm,
  },
});
