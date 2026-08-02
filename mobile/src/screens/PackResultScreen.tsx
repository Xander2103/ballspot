import React, { useCallback, useState } from 'react';
import { View, Text, StyleSheet, ScrollView, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ImageGuessPicker, Marker } from '../components/ImageGuessPicker';
import { FullscreenImageViewer } from '../components/FullscreenImageViewer';
import { FullscreenButton } from '../components/FullscreenButton';
import { useTheme } from '../theme/useTheme';
import { goPacks, useHardwareBack } from '../app/navigationActions';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'PackResult'>;

const pct = (n: number) => `${Math.round((Number.isFinite(n) ? n : 0) * 100)}%`;

export function PackResultScreen({ route, navigation }: Props) {
  useHardwareBack(useCallback(() => goPacks(navigation), [navigation]));
  const { slug, packName, result, imageUrl } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const [fullscreen, setFullscreen] = useState(false);

  const r = result.result;
  const isReveal = !!r.reveal_image_url;
  const completed = result.pack_completed;
  const progress = result.progress;
  const xpGained = result.rank_progress?.xp_gained ?? 0;
  const badges = result.new_badges ?? [];

  const markers: Marker[] = [
    { x_ratio: r.guessed_x, y_ratio: r.guessed_y, type: 'ghost-ball' },
    { x_ratio: r.ball_x_ratio, y_ratio: r.ball_y_ratio, type: isReveal ? 'glow' : 'default' },
  ];

  return (
    <Screen scroll={false} padding={false}>
      <ScrollView contentContainerStyle={styles.content}>
        {/* Score */}
        <View style={styles.scoreCard}>
          <Text style={styles.scoreValue}>{r.score}</Text>
          <Text style={styles.scoreLabel}>points</Text>
          {xpGained > 0 ? <Text style={styles.xp}>+{xpGained} XP</Text> : null}
        </View>

        {/* Reveal image with markers */}
        {imageUrl ? (
          <View style={styles.imageCard}>
            <Pressable
              onPress={() => setFullscreen(true)}
              accessibilityRole="button"
              accessibilityLabel="Open image fullscreen"
            >
              <View pointerEvents="none">
                <ImageGuessPicker imageUri={imageUrl} markers={markers} interactive={false} />
              </View>
            </Pressable>
            <FullscreenButton onPress={() => setFullscreen(true)} />
            <View style={styles.legendRow}>
              <Text style={styles.legend}>🔵 Your guess {pct(r.guessed_x)}, {pct(r.guessed_y)}</Text>
              <Text style={styles.legend}>🎯 Actual {pct(r.ball_x_ratio)}, {pct(r.ball_y_ratio)}</Text>
            </View>
          </View>
        ) : (
          <View style={styles.noImage}><Text style={styles.noImageText}>Image unavailable</Text></View>
        )}

        {/* New badges */}
        {badges.length > 0 ? (
          <View style={styles.badgeCard}>
            <Text style={styles.badgeHeader}>New badge{badges.length > 1 ? 's' : ''} unlocked!</Text>
            {badges.map((b) => (
              <Text key={b.code} style={styles.badgeRow}>{b.icon} {b.name}</Text>
            ))}
          </View>
        ) : null}

        {/* Completion card */}
        {completed ? (
          <View style={styles.completeCard}>
            <Text style={styles.completeEmoji}>🎉</Text>
            <Text style={styles.completeTitle}>Pack completed!</Text>
            <Text style={styles.completeSub}>{packName}</Text>
            <View style={styles.completeStats}>
              <Text style={styles.completeStat}>Total score: {result.final_score ?? progress.total_score}</Text>
              {result.completion_xp ? <Text style={styles.completeStat}>Completion bonus: +{result.completion_xp} XP</Text> : null}
              <Text style={styles.completeStat}>{progress.total_challenges} challenges</Text>
            </View>
          </View>
        ) : (
          <View style={styles.progressCard}>
            <Text style={styles.progressText}>
              {progress.completed_count} / {progress.total_challenges} completed
            </Text>
          </View>
        )}
      </ScrollView>

      {/* Actions */}
      <View style={styles.footer}>
        {completed ? (
          <>
            <AppButton title="View Trophy Room" onPress={() => navigation.navigate('TrophyRoom')} />
            <AppButton title="Back to Packs" onPress={() => navigation.navigate('Packs')} variant="secondary" />
          </>
        ) : (
          <>
            <AppButton title="Next challenge" onPress={() => navigation.replace('PackGuess', { slug, packName })} />
            <AppButton title="Leave pack" onPress={() => navigation.navigate('PackDetail', { slug, name: packName })} variant="secondary" />
          </>
        )}
      </View>

      <FullscreenImageViewer visible={fullscreen} imageUri={imageUrl} onClose={() => setFullscreen(false)} />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    content: { padding: spacing.md, gap: spacing.md, paddingBottom: spacing.xl },
    scoreCard: { alignItems: 'center', backgroundColor: theme.surface, borderRadius: 16, paddingVertical: spacing.lg, borderWidth: 1, borderColor: theme.border },
    scoreValue: { fontSize: 48, fontWeight: '800', color: theme.primary },
    scoreLabel: { fontSize: 13, color: theme.textMuted, textTransform: 'uppercase', letterSpacing: 1 },
    xp: { fontSize: 15, fontWeight: '700', color: theme.success, marginTop: spacing.xs },
    imageCard: { backgroundColor: theme.surface, borderRadius: 14, padding: spacing.sm, borderWidth: 1, borderColor: theme.border },
    legendRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: spacing.sm, flexWrap: 'wrap', gap: spacing.xs },
    legend: { fontSize: 12, color: theme.textSecondary },
    badgeCard: { backgroundColor: theme.surfaceElevated, borderRadius: 14, padding: spacing.md, borderWidth: 1, borderColor: theme.primary },
    badgeHeader: { fontSize: 14, fontWeight: '700', color: theme.primary, marginBottom: spacing.xs },
    badgeRow: { fontSize: 15, color: theme.text, marginTop: 2 },
    completeCard: { alignItems: 'center', backgroundColor: theme.surface, borderRadius: 16, padding: spacing.lg, borderWidth: 1, borderColor: theme.border },
    completeEmoji: { fontSize: 44 },
    completeTitle: { fontSize: 22, fontWeight: '800', color: theme.text, marginTop: spacing.xs },
    completeSub: { fontSize: 15, color: theme.textSecondary, marginTop: 2 },
    completeStats: { marginTop: spacing.md, alignItems: 'center', gap: 2 },
    completeStat: { fontSize: 14, color: theme.textSecondary },
    progressCard: { alignItems: 'center', backgroundColor: theme.surface, borderRadius: 12, paddingVertical: spacing.md, borderWidth: 1, borderColor: theme.border },
    progressText: { fontSize: 15, fontWeight: '600', color: theme.text },
    footer: { padding: spacing.md, gap: spacing.sm, backgroundColor: theme.background, borderTopWidth: 1, borderTopColor: theme.border },
    noImage: { alignItems: 'center', justifyContent: 'center', paddingVertical: spacing.xl },
    noImageText: { color: theme.textMuted, fontSize: 14, fontStyle: 'italic' },
  });
}
