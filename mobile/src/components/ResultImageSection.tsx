import React, { useState } from 'react';
import { View, Text, StyleSheet, Pressable } from 'react-native';
import { ImageGuessPicker, Marker } from './ImageGuessPicker';
import { FullscreenImageViewer } from './FullscreenImageViewer';
import { FullscreenButton } from './FullscreenButton';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  imageUri: string;
  markers: Marker[];
  isRevealImage: boolean;
  guessXRatio: number;
  guessYRatio: number;
  ballXRatio: number;
  ballYRatio: number;
  /** Shown under the legend — safe here, the answer is already revealed. */
  title?: string | null;
}

function pct(ratio: number): string {
  if (!Number.isFinite(ratio)) return '?';
  return `${Math.round(ratio * 100)}%`;
}

/**
 * Reveal image + "View fullscreen" action + guess-vs-ball legend. Rendered
 * directly under the score card on the Daily and Tournament result screens.
 */
export function ResultImageSection({
  imageUri, markers, isRevealImage,
  guessXRatio, guessYRatio, ballXRatio, ballYRatio, title,
}: Props) {
  const [fullscreen, setFullscreen] = useState(false);

  return (
    <View>
      {isRevealImage ? (
        <View style={styles.revealHint}>
          <Text style={styles.revealHintText}>Reveal photo — the real ball is visible in the image</Text>
        </View>
      ) : null}

      <Pressable
        onPress={() => setFullscreen(true)}
        accessibilityRole="button"
        accessibilityLabel="Open image fullscreen"
      >
        <View pointerEvents="none">
          <ImageGuessPicker imageUri={imageUri} interactive={false} markers={markers} />
        </View>
      </Pressable>

      <FullscreenButton onPress={() => setFullscreen(true)} variant="static" />

      <View style={styles.legend}>
        <View style={styles.legendRow}>
          <View style={styles.legendGhostIcon}>
            <Text style={styles.legendGhostEmoji}>⚽</Text>
          </View>
          <View>
            <Text style={styles.legendTitle}>Your guess</Text>
            <Text style={styles.legendCoord}>{pct(guessXRatio)}, {pct(guessYRatio)}</Text>
          </View>
        </View>
        <View style={styles.legendDivider} />
        <View style={styles.legendRow}>
          {isRevealImage ? <View style={styles.legendGlowIcon} /> : <View style={styles.legendDefaultIcon} />}
          <View>
            <Text style={styles.legendTitle}>Ball position</Text>
            <Text style={styles.legendCoord}>{pct(ballXRatio)}, {pct(ballYRatio)}</Text>
          </View>
        </View>
        {isRevealImage ? (
          <Text style={styles.legendHint}>Your ghost ball shows your guess. The real ball is visible in the photo.</Text>
        ) : (
          <Text style={styles.legendHint}>The marker shows the approximate ball position.</Text>
        )}
        {title ? <Text style={styles.challengeTitle}>{title}</Text> : null}
      </View>

      <FullscreenImageViewer
        visible={fullscreen}
        imageUri={imageUri}
        onClose={() => setFullscreen(false)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  revealHint: { marginBottom: 6, paddingHorizontal: spacing.xs },
  revealHintText: { fontSize: 11, color: colors.textMuted, fontStyle: 'italic', textAlign: 'right' },
  legend: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    marginTop: spacing.sm,
    marginBottom: spacing.md,
  },
  legendRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.xs },
  legendGhostIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 2, borderColor: 'rgba(255,255,255,0.85)',
    opacity: 0.72, alignItems: 'center', justifyContent: 'center',
  },
  legendGhostEmoji: { fontSize: 16 },
  legendGlowIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: 'transparent', borderWidth: 3, borderColor: '#00E676',
    shadowColor: '#00E676', shadowOffset: { width: 0, height: 0 }, shadowOpacity: 0.8, shadowRadius: 6,
  },
  legendDefaultIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: 'rgba(0,230,118,0.85)', borderWidth: 3, borderColor: '#ffffff',
  },
  legendTitle: { fontSize: 13, fontWeight: '600', color: colors.text },
  legendCoord: { fontSize: 12, color: colors.textSecondary },
  legendDivider: { height: 1, backgroundColor: colors.border, marginVertical: spacing.xs },
  legendHint: { fontSize: 11, color: colors.textMuted, fontStyle: 'italic', marginTop: spacing.xs, textAlign: 'center' },
  challengeTitle: { fontSize: 13, fontWeight: '700', color: colors.textSecondary, textAlign: 'center', marginTop: spacing.sm },
});
