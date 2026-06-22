import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { roundApi } from '../api/roundApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { GuessResult } from '../types/guess';

type Props = NativeStackScreenProps<RootStackParamList, 'Result'>;

function getScoreRating(score: number): string {
  if (score >= 95) return 'Perfect! 🎯';
  if (score >= 75) return 'Very close!';
  if (score >= 50) return 'Not bad';
  if (score >= 25) return 'Far away';
  return 'Missed';
}

export function ResultScreen({ route, navigation }: Props) {
  const { roundId, leagueId, imageUrl, leagueName } = route.params;
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
    return <View style={styles.center}><ActivityIndicator color={colors.primary} size="large" /></View>;
  }

  if (!result) {
    return (
      <Screen padding>
        <Text style={{ color: colors.text }}>No result found.</Text>
        <AppButton title="Back to League" onPress={() => navigation.goBack()} style={{ marginTop: spacing.lg }} />
      </Screen>
    );
  }

  const scoreColor = result.score >= 80 ? colors.success : result.score >= 50 ? colors.warning : colors.error;
  const rating = getScoreRating(result.score);

  return (
    <Screen scroll padding>
      <View style={styles.scoreBox}>
        <Text style={styles.scoreLabel}>Your Score</Text>
        <Text style={[styles.score, { color: scoreColor }]}>{result.score}</Text>
        <Text style={styles.rating}>{rating}</Text>
        <Text style={styles.distance}>Distance: {(result.distance * 100).toFixed(1)}%</Text>
      </View>

      {imageUrl ? (
        <ImageGuessPicker
          imageUri={imageUrl}
          interactive={false}
          markers={[
            { x_ratio: result.guess_x_ratio, y_ratio: result.guess_y_ratio, color: colors.accent, label: 'U' },
            { x_ratio: result.ball_x_ratio, y_ratio: result.ball_y_ratio, color: colors.success, label: 'B' },
          ]}
        />
      ) : null}

      <View style={styles.coordsBox}>
        <View style={styles.coordRow}>
          <View style={[styles.dot, { backgroundColor: colors.accent }]} />
          <Text style={styles.coordText}>Your guess: ({result.guess_x_ratio.toFixed(3)}, {result.guess_y_ratio.toFixed(3)})</Text>
        </View>
        <View style={styles.coordRow}>
          <View style={[styles.dot, { backgroundColor: colors.success }]} />
          <Text style={styles.coordText}>Ball position: ({result.ball_x_ratio.toFixed(3)}, {result.ball_y_ratio.toFixed(3)})</Text>
        </View>
      </View>

      <AppButton
        title="Play Next Round"
        onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
        style={styles.nextBtn}
      />
      <AppButton
        title="Back to League"
        onPress={() => navigation.navigate('LeagueDetail', { leagueId, leagueName })}
        variant="secondary"
        style={styles.backBtn}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' },
  scoreBox: { alignItems: 'center', paddingVertical: spacing.xxl, backgroundColor: colors.surface, borderRadius: 16, marginBottom: spacing.lg },
  scoreLabel: { fontSize: 14, color: colors.textSecondary, textTransform: 'uppercase', letterSpacing: 1 },
  score: { fontSize: 72, fontWeight: '800', marginVertical: spacing.sm },
  rating: { fontSize: 20, fontWeight: '600', color: colors.text, marginBottom: spacing.xs },
  distance: { fontSize: 15, color: colors.textSecondary },
  coordsBox: { backgroundColor: colors.surface, borderRadius: 12, padding: spacing.md, marginBottom: spacing.lg, gap: spacing.sm },
  coordRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  dot: { width: 12, height: 12, borderRadius: 6 },
  coordText: { color: colors.textSecondary, fontSize: 13 },
  nextBtn: { marginBottom: spacing.sm },
  backBtn: { marginTop: 0 },
});
