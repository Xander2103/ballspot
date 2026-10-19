import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { Image } from 'expo-image';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import type { ClubPackCard } from '../../types/club';
import { clubPackState } from '../../utils/clubDisplay';
import { packProgressFraction, reservingCompetition } from '../../utils/clubDetailLayout';

interface Props {
  pack: ClubPackCard;
  onPress: () => void;
}

/**
 * A club pack with its availability: normal-track progress ("8/12 · 640 pts"
 * plus a progress bar), "✓ Completed", or "🔒 Competition only" with where it
 * can be played ("Play it in Derby Days").
 */
export const ClubPackRow = React.memo(function ClubPackRow({ pack, onPress }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const state = clubPackState(pack);
  const progress = pack.progress;
  const fraction = packProgressFraction(pack);
  const reservedIn = state === 'reserved' ? reservingCompetition(pack) : null;

  let status: string;
  let statusColor = theme.textSecondary;
  let hint: string | null = null;
  if (state === 'reserved') {
    status = t('clubs.packs.competitionOnly');
    statusColor = theme.warning;
    hint = reservedIn ? t('clubs.packs.playIn', { name: reservedIn.name }) : t('clubs.packs.playInGeneric');
  } else if (state === 'completed' && progress) {
    status = t('clubs.packs.completed', { score: (pack.best_score ?? progress.total_score).toLocaleString() });
    statusColor = theme.success;
  } else if (state === 'active' && progress) {
    status = t('clubs.packs.progress', { done: progress.completed_count, total: progress.total_challenges, score: progress.total_score.toLocaleString() });
    statusColor = theme.primary;
  } else {
    status = t('packs.meta.challenges', { count: pack.challenge_count });
  }

  return (
    <TouchableOpacity style={styles.row} onPress={onPress} activeOpacity={0.85} accessibilityRole="button">
      <View style={styles.cover}>
        {pack.cover_image_url ? (
          <Image source={{ uri: pack.cover_image_url }} style={styles.coverImg} contentFit="cover" recyclingKey={pack.cover_image_url} transition={120} />
        ) : (
          <Text style={styles.coverEmoji}>{pack.sport?.emoji ?? '📦'}</Text>
        )}
      </View>
      <View style={styles.body}>
        <Text style={styles.name} numberOfLines={1}>{pack.name}</Text>
        <Text style={[styles.status, { color: statusColor }]} numberOfLines={1}>{status}</Text>
        {hint ? <Text style={styles.hint} numberOfLines={1}>{hint}</Text> : null}
        {fraction !== null ? (
          <View style={styles.track} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
            <View style={[styles.fill, { width: `${Math.round(fraction * 100)}%` }]} />
          </View>
        ) : null}
      </View>
      <Text style={styles.chevron}>›</Text>
    </TouchableOpacity>
  );
});

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.md,
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingVertical: spacing.sm + 2, paddingHorizontal: spacing.sm + 2, marginBottom: spacing.sm,
    },
    cover: {
      width: 48, height: 48, borderRadius: 10, overflow: 'hidden',
      backgroundColor: theme.surfaceElevated, alignItems: 'center', justifyContent: 'center',
    },
    coverImg: { width: '100%', height: '100%' },
    coverEmoji: { fontSize: 22 },
    body: { flex: 1, minWidth: 0 },
    name: { fontSize: 15, fontWeight: '700', color: theme.text },
    status: { fontSize: 13, marginTop: 2, fontWeight: '600' },
    hint: { fontSize: 12, marginTop: 1, color: theme.textSecondary },
    track: { height: 4, borderRadius: 2, backgroundColor: theme.border, marginTop: 6, overflow: 'hidden' },
    fill: { height: 4, borderRadius: 2, backgroundColor: theme.primary },
    chevron: { fontSize: 22, color: theme.textMuted, paddingRight: spacing.xs },
  });
}
