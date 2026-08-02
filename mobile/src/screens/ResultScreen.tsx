import React, { useEffect, useState } from 'react';
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
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { GuessResult } from '../types/guess';
import { CurrentRoundResponse } from '../types/challenge';

type Props = NativeStackScreenProps<RootStackParamList, 'Result'>;

function getScoreRating(score: number): string {
  if (score >= 90) return 'Perfect spot!';
  if (score >= 70) return 'Very close!';
  if (score >= 40) return 'Not bad';
  if (score >= 1) return 'Far away';
  return 'Missed!';
}

function getScoreColor(score: number): string {
  if (score >= 90) return colors.success;
  if (score >= 70) return colors.primary;
  if (score >= 40) return colors.warning;
  return colors.error;
}

function getDistanceFeedback(distance: number): string {
  if (!Number.isFinite(distance)) return '';
  if (distance <= 0.03) return 'Right on it!';
  if (distance <= 0.10) return 'Very close';
  if (distance <= 0.25) return 'A bit off';
  return 'Way off';
}

export function ResultScreen({ route, navigation }: Props) {
  const { roundId, leagueId, imageUrl, leagueName, categoryName, challengeTitle, newBadges, rankProgress, rankUp, tournamentCompletion } = route.params;
  const [result, setResult] = useState<GuessResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [nextRound, setNextRound] = useState<CurrentRoundResponse | null>(null);

  useEffect(() => {
    if (!roundId) { setLoading(false); return; }
    roundApi.result(roundId)
      .then(setResult)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [roundId]);

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
        <Text style={{ color: colors.text }}>No result found.</Text>
        <AppButton title="Back to Tournament" onPress={() => navigation.goBack()} style={{ marginTop: spacing.lg }} />
      </Screen>
    );
  }

  const scoreColor = getScoreColor(result.score);
  const rating = getScoreRating(result.score);
  const distanceFeedback = getDistanceFeedback(result.distance);
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
        <Text style={styles.scoreLabel}>Your Score</Text>
        <Text style={[styles.score, { color: scoreColor }]}>{result.score}</Text>
        <Text style={styles.rating}>{rating}</Text>
        <View style={styles.distanceRow}>
          <Text style={styles.distanceValue}>
            {Number.isFinite(result.distance) ? `${(result.distance * 100).toFixed(1)}% away` : '—'}
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
        />
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>Image unavailable</Text>
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
          ? "You're done for today — come back tomorrow."
          : nextRound?.completed
            ? "You've completed all rounds!"
            : nextRound !== null
              ? "No more rounds available right now."
              : null;

        return (
          <>
            {!hasNextRound && doneMessage ? (
              <Text style={styles.doneForTodayText}>{doneMessage}</Text>
            ) : null}
            {hasNextRound ? (
              <AppButton
                title="Play Next Round"
                onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
                style={styles.nextBtn}
              />
            ) : null}
            <AppButton
              title="Back to Tournament"
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
