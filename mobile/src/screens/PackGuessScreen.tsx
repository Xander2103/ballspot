import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker } from '../components/ImageGuessPicker';
import { FullscreenImageViewer } from '../components/FullscreenImageViewer';
import { FullscreenButton } from '../components/FullscreenButton';
import { packApi } from '../api/packApi';
import { goPacks, useHardwareBack } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { isPackAlreadyCompleted } from '../types/pack';
import type { PackAttemptState, PackChallengeSummary, PackCompletionSummary } from '../types/pack';
import { getApiErrorMessage } from '../utils/apiError';

type Props = NativeStackScreenProps<RootStackParamList, 'PackGuess'>;

export function PackGuessScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goPacks(navigation), [navigation]));
  const { slug, packName } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [attempt, setAttempt] = useState<PackAttemptState | null>(null);
  const [challenge, setChallenge] = useState<PackChallengeSummary | null>(null);
  const [guessX, setGuessX] = useState<number | null>(null);
  const [guessY, setGuessY] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [fullscreen, setFullscreen] = useState(false);

  const showCompletion = useCallback((completion?: PackCompletionSummary | null) => {
    navigation.replace('PackComplete', { slug, packName, completion: completion ?? null });
  }, [navigation, slug, packName]);

  useEffect(() => {
    let cancelled = false;
    // start() resumes the active attempt and returns the current challenge.
    packApi.start(slug)
      .then((res) => {
        if (cancelled) return;
        if (res.attempt.status === 'completed') {
          showCompletion(res.completion);
          return;
        }
        if (!res.challenge) {
          // Nothing left to play — bounce back to the pack detail.
          navigation.replace('PackDetail', { slug, name: packName });
          return;
        }
        setAttempt(res.attempt);
        setChallenge(res.challenge);
        setLoading(false);
      })
      .catch((e: unknown) => {
        if (cancelled) return;
        if (isPackAlreadyCompleted(e)) {
          // The pack is already done: results, not an error.
          showCompletion(e.completion);
          return;
        }
        setError(getApiErrorMessage(e, 'Could not start this pack.'));
        setLoading(false);
      });
    return () => { cancelled = true; };
  }, [slug]);

  function handleGuess(x: number, y: number) {
    if (!Number.isFinite(x) || !Number.isFinite(y)) return;
    setGuessX(x);
    setGuessY(y);
    setError('');
  }

  async function handleSubmit() {
    // `submitting` is checked synchronously too: a double tap can fire before
    // the disabled prop re-renders, and the second request used to hit an
    // attempt that was already completed by the first.
    if (submitting) return;
    if (guessX === null || guessY === null || !attempt || !challenge) return;
    if (!Number.isFinite(guessX) || !Number.isFinite(guessY)) {
      setError('Tap the image to lock your guess before submitting.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      const result = await packApi.submitGuess(attempt.id, challenge.id, guessX, guessY);
      navigation.replace('PackResult', {
        slug, packName, result,
        imageUrl: result.result.reveal_image_url ?? challenge.hidden_image_url,
      });
    } catch (e: unknown) {
      if (isPackAlreadyCompleted(e)) {
        // Server says the pack is finished (e.g. the previous response was
        // lost): go to the completion overview instead of showing an error.
        showCompletion(e.completion);
        return;
      }
      setError(getApiErrorMessage(e, 'Failed to submit guess. Please try again.'));
      setSubmitting(false);
    }
  }

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  if (error && !challenge) {
    return (
      <Screen>
        <View style={styles.center}>
          <Text style={styles.errorText}>{error}</Text>
          <AppButton title="Back" onPress={() => navigation.replace('PackDetail', { slug, name: packName })} variant="secondary" style={{ marginTop: spacing.md }} />
        </View>
      </Screen>
    );
  }

  if (!challenge || !attempt) return null;

  const hasGuess = guessX !== null && guessY !== null;
  const step = attempt.completed_count + 1; // 1-indexed current step
  const total = attempt.total_challenges;
  const isFinal = step >= total;

  return (
    // scroll: a portrait image (height = width / aspect) can overflow small
    // phones; without scrolling the Submit footer becomes unreachable.
    <Screen scroll padding={false}>
      <View style={styles.infoCard}>
        <Text style={styles.progressLabel}>Challenge {step} / {total}{isFinal ? ' · final' : ''}</Text>
        <Text style={styles.instruction}>Tap the image to place the missing ball.</Text>
        <View style={styles.progressBar}>
          <View style={[styles.progressFill, { width: `${total > 0 ? (attempt.completed_count / total) * 100 : 0}%` }]} />
        </View>
      </View>

      {challenge.hidden_image_url ? (
        <View style={styles.imageCard}>
          <ImageGuessPicker
            imageUri={challenge.hidden_image_url}
            onGuess={handleGuess}
            interactive
            selectedPoint={hasGuess ? { x: guessX!, y: guessY! } : null}
          />
          <FullscreenButton onPress={() => setFullscreen(true)} compact />
        </View>
      ) : (
        <View style={styles.noImage}><Text style={styles.noImageText}>Image unavailable</Text></View>
      )}

      <View style={styles.footer}>
        <View style={styles.guessStatus}>
          <Text style={[styles.guessLabel, hasGuess && styles.guessLabelActive]}>
            {hasGuess ? `✓ Guess locked at ${Math.round(guessX! * 100)}%, ${Math.round(guessY! * 100)}%` : 'Tap the image to place your guess'}
          </Text>
        </View>
        {error ? <Text style={styles.errorText}>{error}</Text> : null}
        <AppButton
          title={isFinal ? 'Submit final guess' : 'Submit Guess'}
          onPress={handleSubmit}
          loading={submitting}
          disabled={!hasGuess || submitting}
        />
      </View>

      <FullscreenImageViewer
        visible={fullscreen}
        imageUri={challenge.hidden_image_url}
        onClose={() => setFullscreen(false)}
        selectable
        selectedPoint={hasGuess ? { x: guessX!, y: guessY! } : null}
        onSelectPoint={handleGuess}
      />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center', padding: spacing.lg },
    infoCard: {
      backgroundColor: theme.surface, paddingHorizontal: spacing.md, paddingTop: spacing.md,
      paddingBottom: spacing.sm, borderBottomWidth: 1, borderBottomColor: theme.border,
    },
    progressLabel: { fontSize: 12, color: theme.textSecondary, textTransform: 'uppercase', letterSpacing: 1, fontWeight: '700' },
    instruction: { fontSize: 13, color: theme.textSecondary, fontStyle: 'italic', marginTop: 2 },
    progressBar: { height: 6, borderRadius: 3, backgroundColor: theme.surfaceElevated, marginTop: spacing.sm, overflow: 'hidden' },
    progressFill: { height: '100%', backgroundColor: theme.primary },
    imageCard: { paddingHorizontal: spacing.md, paddingVertical: spacing.sm, backgroundColor: theme.background },
    noImage: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: spacing.xl },
    noImageText: { color: theme.textMuted, fontSize: 14, fontStyle: 'italic' },
    footer: { padding: spacing.md, gap: spacing.sm, backgroundColor: theme.background, borderTopWidth: 1, borderTopColor: theme.border },
    guessStatus: { backgroundColor: theme.surface, borderRadius: 10, paddingVertical: spacing.sm, paddingHorizontal: spacing.md, alignItems: 'center' },
    guessLabel: { fontSize: 14, color: theme.textMuted, textAlign: 'center' },
    guessLabelActive: { color: theme.primary, fontWeight: '600' },
    errorText: { color: theme.danger, fontSize: 13, textAlign: 'center' },
  });
}
