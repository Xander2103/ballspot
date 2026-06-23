import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker, Marker } from '../components/ImageGuessPicker';
import { roundApi } from '../api/roundApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { GuessResult } from '../types/guess';

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

function pct(ratio: number): string {
  if (!Number.isFinite(ratio)) return '?';
  return `${Math.round(ratio * 100)}%`;
}

export function ResultScreen({ route, navigation }: Props) {
  const { roundId, leagueId, imageUrl, leagueName, categoryName } = route.params;
  const [result, setResult] = useState<GuessResult | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!roundId) { setLoading(false); return; }
    roundApi.result(roundId)
      .then(setResult)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [roundId]);

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
        <AppButton title="Back to League" onPress={() => navigation.goBack()} style={{ marginTop: spacing.lg }} />
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

      {/* Image with markers */}
      {displayImageUrl ? (
        <>
          {isRevealImage && (
            <View style={styles.revealHint}>
              <Text style={styles.revealHintText}>Reveal photo — the real ball is visible in the image</Text>
            </View>
          )}
          <ImageGuessPicker
            imageUri={displayImageUrl}
            interactive={false}
            markers={markers}
          />

          {/* Legend */}
          <View style={styles.legend}>
            <View style={styles.legendRow}>
              <View style={styles.legendGhostIcon}>
                <Text style={styles.legendGhostEmoji}>⚽</Text>
              </View>
              <View>
                <Text style={styles.legendTitle}>Your guess</Text>
                <Text style={styles.legendCoord}>
                  {pct(result.guess_x_ratio)}, {pct(result.guess_y_ratio)}
                </Text>
              </View>
            </View>
            <View style={styles.legendDivider} />
            <View style={styles.legendRow}>
              {isRevealImage ? (
                <View style={styles.legendGlowIcon} />
              ) : (
                <View style={styles.legendDefaultIcon} />
              )}
              <View>
                <Text style={styles.legendTitle}>Ball position</Text>
                <Text style={styles.legendCoord}>
                  {pct(result.ball_x_ratio)}, {pct(result.ball_y_ratio)}
                </Text>
              </View>
            </View>
            {isRevealImage ? (
              <Text style={styles.legendHint}>Your ghost ball shows your guess. The real ball is visible in the photo.</Text>
            ) : (
              <Text style={styles.legendHint}>The marker shows the approximate ball position.</Text>
            )}
          </View>
        </>
      ) : null}

      <AppButton
        title="Play Next Round"
        onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
        style={styles.nextBtn}
      />
      <AppButton
        title="Back to League"
        onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
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
  revealHint: {
    marginBottom: 6,
    paddingHorizontal: spacing.xs,
  },
  revealHintText: {
    fontSize: 11,
    color: colors.textMuted,
    fontStyle: 'italic',
    textAlign: 'right',
  },
  legend: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    marginTop: spacing.sm,
    marginBottom: spacing.md,
  },
  legendRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.xs,
  },
  legendGhostIcon: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 2,
    borderColor: 'rgba(255,255,255,0.85)',
    opacity: 0.72,
    alignItems: 'center',
    justifyContent: 'center',
  },
  legendGhostEmoji: {
    fontSize: 16,
  },
  legendGlowIcon: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: 'transparent',
    borderWidth: 3,
    borderColor: '#00E676',
    shadowColor: '#00E676',
    shadowOffset: { width: 0, height: 0 },
    shadowOpacity: 0.8,
    shadowRadius: 6,
  },
  legendDefaultIcon: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: 'rgba(0,230,118,0.85)',
    borderWidth: 3,
    borderColor: '#ffffff',
  },
  legendTitle: {
    fontSize: 13,
    fontWeight: '600',
    color: colors.text,
  },
  legendCoord: {
    fontSize: 12,
    color: colors.textSecondary,
  },
  legendDivider: {
    height: 1,
    backgroundColor: colors.border,
    marginVertical: spacing.xs,
  },
  legendHint: {
    fontSize: 11,
    color: colors.textMuted,
    fontStyle: 'italic',
    marginTop: spacing.xs,
    textAlign: 'center',
  },
  nextBtn: {
    marginBottom: spacing.sm,
  },
});
