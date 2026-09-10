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
import { dailyApi } from '../api/dailyApi';
import { goHome, useHardwareBack } from '../app/navigationActions';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { DailyGuessResult, DailyStats, TodayResponse } from '../types/daily';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'DailyResult'>;

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

export function DailyResultScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goHome(navigation), [navigation]));
  const { dailyChallengeId, newBadges, rankProgress, rankUp } = route.params;
  const { t } = useI18n();

  const [result, setResult] = useState<DailyGuessResult | null>(null);
  const [today, setToday] = useState<TodayResponse | null>(null);
  const [stats, setStats] = useState<DailyStats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    Promise.all([
      dailyApi.result(dailyChallengeId).then((r) => r.data),
      dailyApi.today(),
      dailyApi.stats(),
    ])
      .then(([resultData, todayData, statsData]) => {
        if (cancelled) return;
        setResult(resultData);
        setToday(todayData);
        setStats(statsData);
        setLoading(false);
      })
      .catch(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, [dailyChallengeId]);

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
        <Text style={{ color: colors.text }}>{t('daily.result.notFound')}</Text>
        <AppButton
          title={t('game.buttons.backHome')}
          onPress={() => goHome(navigation)}
          style={{ marginTop: spacing.lg }}
        />
      </Screen>
    );
  }

  const scoreColor = getScoreColor(result.score);
  const rating = t(getScoreRatingKey(result.score));
  const distanceFeedbackKey = getDistanceFeedbackKey(result.distance);
  const distanceFeedback = distanceFeedbackKey ? t(distanceFeedbackKey) : '';
  const hiddenImageUrl = today?.daily_challenge?.challenge?.hidden_image_url ?? null;
  const displayImageUrl = result.reveal_image_url ?? hiddenImageUrl;
  const isRevealImage = !!result.reveal_image_url;
  const categoryName = today?.daily_challenge?.challenge?.category?.name ?? null;
  const challengeTitle = today?.daily_challenge?.challenge?.title ?? null;
  const sportSlug = result.sport?.slug ?? today?.daily_challenge?.challenge?.sport?.slug ?? null;
  const streak = stats?.current_streak ?? 0;

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
        {streak > 0 ? (
          <View style={styles.streakRow}>
            <Text style={styles.streakText}>{t('daily.streak', { count: streak })}</Text>
          </View>
        ) : null}
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
          title={challengeTitle}
          sportSlug={sportSlug}
        />
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>{t('game.image.unavailable')}</Text>
        </View>
      )}

      {/* Rank up / XP progress / new badges */}
      {rankUp ? <RankUpCard rankUp={rankUp} /> : null}
      {rankProgress ? <RankProgressCard progress={rankProgress} /> : null}
      {newBadges && newBadges.length > 0 ? <NewBadgesCard badges={newBadges} /> : null}

      {/* Rank / percentile insight */}
      {typeof result.total_players === 'number' ? (
        <RankInsight
          rank={result.rank}
          totalPlayers={result.total_players}
          betterThanPercentage={result.better_than_percentage}
        />
      ) : null}

      {/* Actions */}
      <AppButton
        title={t('daily.result.viewWeeklyLeaderboard')}
        onPress={() => navigation.navigate('WeeklyLeaderboard')}
        style={styles.leaderboardBtn}
      />
      <AppButton
        title={t('game.buttons.backHome')}
        onPress={() => goHome(navigation)}
        variant="secondary"
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
  streakRow: {
    marginTop: spacing.sm,
  },
  streakText: {
    fontSize: 16,
    fontWeight: '700',
    color: colors.warning,
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
  leaderboardBtn: {
    marginBottom: spacing.sm,
  },
});
