import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, Alert, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { FullscreenImageViewer } from '../components/FullscreenImageViewer';
import { FullscreenButton } from '../components/FullscreenButton';
import { dailyApi } from '../api/dailyApi';
import { goHome, useHardwareBack } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { DailyChallengeEntry } from '../types/daily';

type Props = NativeStackScreenProps<RootStackParamList, 'DailyChallenge'>;

const DIFFICULTY_COLOR: Record<string, string> = {
  easy: '#00c853',
  medium: '#ffab40',
  hard: '#ff5252',
};

export function DailyChallengeScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goHome(navigation), [navigation]));
  const { dailyChallengeId } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [challenge, setChallenge] = useState<DailyChallengeEntry | null>(null);
  const [guessX, setGuessX] = useState<number | null>(null);
  const [guessY, setGuessY] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [loading, setLoading] = useState(true);
  const [fullscreen, setFullscreen] = useState(false);

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
            { text: 'OK', onPress: () => goHome(navigation) },
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
            { text: 'OK', onPress: () => goHome(navigation) },
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
      navigation.replace('DailyResult', {
        dailyChallengeId,
        newBadges: res.data.new_badges ?? [],
        rankProgress: res.rank_progress,
        rankUp: res.rank_up ?? null,
      });
    } catch (e: unknown) {
      const err = e as { status?: number; message?: string };
      if (err?.status === 422) {
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
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  if (!challenge) return null;

  const hasGuess = guessX !== null && guessY !== null;
  const diffColor = DIFFICULTY_COLOR[challenge.challenge.difficulty] ?? theme.textSecondary;
  const categoryName = challenge.challenge.category?.name ?? null;
  const imageUrl = challenge.challenge.hidden_image_url;

  const todayLabel = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

  const guessLabel = hasGuess
    ? `Guess locked at ${Math.round(guessX! * 100)}%, ${Math.round(guessY! * 100)}%`
    : 'Tap the image to place your guess';

  return (
    <Screen scroll={false} padding={false}>
      <View style={styles.infoCard}>
        <View style={styles.infoRow}>
          <Text style={styles.dateLabel}>{todayLabel}</Text>
          <View style={styles.badges}>
            {challenge.challenge.sport ? (
              <View style={[styles.catBadge, { backgroundColor: (challenge.challenge.sport.primary_color ?? theme.primary) + '26' }]}>
                <Text style={styles.catText}>{challenge.challenge.sport.emoji} {challenge.challenge.sport.name}</Text>
              </View>
            ) : null}
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
        {challenge.challenge.tags && challenge.challenge.tags.length > 0 ? (
          <View style={styles.tagRow}>
            {challenge.challenge.tags.slice(0, 4).map((t) => (
              <View key={t.slug} style={styles.tagChip}>
                <Text style={styles.tagText}>#{t.name}</Text>
              </View>
            ))}
          </View>
        ) : null}
      </View>

      {imageUrl ? (
        <View style={styles.imageCard}>
          <ImageGuessPicker imageUri={imageUrl} onGuess={handleGuess} interactive />
          <FullscreenButton onPress={() => setFullscreen(true)} compact />
        </View>
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>Image unavailable</Text>
        </View>
      )}

      <View style={styles.footer}>
        <View style={styles.guessStatus}>
          <Text style={[styles.guessLabel, hasGuess && styles.guessLabelActive]}>
            {hasGuess ? '✓ ' : ''}{guessLabel}
          </Text>
        </View>
        {submitError ? <Text style={styles.submitError}>{submitError}</Text> : null}
        <AppButton title="Submit Guess" onPress={handleSubmit} loading={submitting} disabled={!hasGuess || submitting} />
      </View>

      <FullscreenImageViewer visible={fullscreen} imageUri={imageUrl} onClose={() => setFullscreen(false)} />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    infoCard: {
      backgroundColor: theme.surface, paddingHorizontal: spacing.md, paddingTop: spacing.md,
      paddingBottom: spacing.sm, borderBottomWidth: 1, borderBottomColor: theme.border,
    },
    infoRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 },
    dateLabel: { fontSize: 12, color: theme.textSecondary, textTransform: 'uppercase', letterSpacing: 1, fontWeight: '600' },
    badges: { flexDirection: 'row', alignItems: 'center', gap: 6 },
    catBadge: { borderRadius: 6, paddingHorizontal: spacing.sm, paddingVertical: 2, backgroundColor: theme.surfaceElevated },
    catText: { fontSize: 10, fontWeight: '600', color: theme.textSecondary, letterSpacing: 0.5 },
    diffBadge: { borderRadius: 6, paddingHorizontal: spacing.sm, paddingVertical: 2 },
    diffText: { fontSize: 10, fontWeight: '800', letterSpacing: 1 },
    challengeTitle: { fontSize: 20, fontWeight: '700', color: theme.text, marginBottom: 4 },
    instruction: { fontSize: 13, color: theme.textSecondary, fontStyle: 'italic' },
    tagRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: spacing.sm },
    tagChip: { backgroundColor: theme.surfaceElevated, borderRadius: 6, paddingHorizontal: spacing.sm, paddingVertical: 2 },
    tagText: { fontSize: 11, color: theme.textSecondary, fontWeight: '600' },
    imageCard: { paddingHorizontal: spacing.md, paddingVertical: spacing.sm, backgroundColor: theme.background },
    noImage: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: spacing.xl },
    noImageText: { color: theme.textMuted, fontSize: 14, fontStyle: 'italic' },
    footer: { padding: spacing.md, gap: spacing.sm, backgroundColor: theme.background, borderTopWidth: 1, borderTopColor: theme.border },
    guessStatus: { backgroundColor: theme.surface, borderRadius: 10, paddingVertical: spacing.sm, paddingHorizontal: spacing.md, alignItems: 'center' },
    guessLabel: { fontSize: 14, color: theme.textMuted, textAlign: 'center' },
    guessLabelActive: { color: theme.primary, fontWeight: '600' },
    submitError: { color: theme.danger, fontSize: 13, textAlign: 'center' },
  });
}
