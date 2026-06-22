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

export function GuessScreen({ route, navigation }: Props) {
  const { leagueId, roundId, leagueName } = route.params;
  const [round, setRound] = useState<LeagueRound | null>(null);
  const [guessX, setGuessX] = useState<number | null>(null);
  const [guessY, setGuessY] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
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

  async function handleSubmit() {
    if (guessX === null || guessY === null) { Alert.alert('Tap the image first!', 'Tap on the image to place your guess.'); return; }
    setSubmitting(true);
    try {
      await roundApi.submitGuess(roundId, { guess_x_ratio: guessX, guess_y_ratio: guessY });
      navigation.replace('Result', {
        roundId,
        leagueId,
        imageUrl: round!.challenge.hidden_image_url,
        leagueName,
      });
    } catch (e: any) {
      Alert.alert('Error', e?.message || 'Failed to submit guess');
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={colors.primary} size="large" /></View>;
  }

  if (!round) return null;

  return (
    <Screen scroll={false} padding={false}>
      <View style={styles.info}>
        <Text style={styles.roundNum}>Round {round.round_number}</Text>
        <Text style={styles.challenge}>{round.challenge.title}</Text>
        <Text style={styles.difficulty}>{round.challenge.difficulty.toUpperCase()}</Text>
        <Text style={styles.instruction}>Tap the image below to mark where you think the ball is hidden.</Text>
      </View>
      <ImageGuessPicker
        imageUri={round.challenge.hidden_image_url}
        onGuess={(x, y) => { setGuessX(x); setGuessY(y); }}
        interactive
      />
      <View style={styles.footer}>
        {guessX !== null && (
          <Text style={styles.coords}>Guess: ({guessX.toFixed(3)}, {guessY!.toFixed(3)})</Text>
        )}
        <AppButton title="Submit Guess" onPress={handleSubmit} loading={submitting} disabled={guessX === null} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' },
  info: { padding: spacing.md, backgroundColor: colors.surface },
  roundNum: { fontSize: 12, color: colors.textSecondary, textTransform: 'uppercase', letterSpacing: 1 },
  challenge: { fontSize: 18, fontWeight: '700', color: colors.text, marginTop: 2 },
  difficulty: { fontSize: 11, color: colors.primary, fontWeight: '600', marginTop: 2 },
  instruction: { fontSize: 12, color: colors.textSecondary, marginTop: 6, fontStyle: 'italic' },
  footer: { padding: spacing.md, gap: spacing.sm },
  coords: { textAlign: 'center', color: colors.textMuted, fontSize: 12, fontFamily: 'monospace' },
});
