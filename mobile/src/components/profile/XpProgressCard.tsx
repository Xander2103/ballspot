import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { getRankVisualStyle } from '../../theme/rankVisuals';
import { useI18n } from '../../i18n';
import { xpProgress, formatXp } from '../../utils/profileModel';
import type { PlayerRank } from '../../types/auth';

interface Props {
  rank: PlayerRank;
  /** Tap = open the full rank ladder. */
  onPress?: () => void;
}

/**
 * Compact rank / XP card: lifetime XP, the next rank and the progress bar
 * toward it. The numbers are the server's (see utils/profileModel.xpProgress);
 * the border carries the rank tier like the old RankCard did.
 */
export function XpProgressCard({ rank, onPress }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const model = xpProgress(rank);
  if (!model) return null;

  const xp = formatXp(model.totalXp);
  const nextRank = model.nextRankName ?? '';
  const a11y = model.isMax
    ? t('profile.xp.a11yMax', { xp })
    : t('profile.xp.a11y', { xp, pct: model.pct, rank: nextRank });

  return (
    <TouchableOpacity
      style={[styles.card, getRankVisualStyle(rank.level, theme)]}
      onPress={onPress}
      disabled={!onPress}
      activeOpacity={0.85}
      accessibilityRole="button"
      accessibilityLabel={a11y}
      testID="profile-xp-card"
    >
      <View style={styles.topRow}>
        <View style={styles.col}>
          <Text style={styles.label} maxFontSizeMultiplier={1.3}>{t('profile.xp.total')}</Text>
          <Text style={styles.xp} maxFontSizeMultiplier={1.3}>{t('profile.rankCard.xp', { xp })}</Text>
        </View>
        <View style={[styles.col, styles.colRight]}>
          <Text style={styles.label} numberOfLines={1} maxFontSizeMultiplier={1.3}>
            {model.isMax ? t('profile.xp.max') : t('profile.xp.next', { rank: nextRank })}
          </Text>
          <Text style={styles.pct} maxFontSizeMultiplier={1.3}>{`${model.pct}%`}</Text>
        </View>
      </View>

      <View style={styles.track}>
        <View style={[styles.fill, { width: `${model.pct}%` }]} />
      </View>

      <Text style={styles.footer} maxFontSizeMultiplier={1.4}>
        {model.isMax
          ? t('profile.rankCard.maxReached')
          : t('profile.xp.toGo', { xp: formatXp(model.xpToNext), rank: nextRank })}
      </Text>
    </TouchableOpacity>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surfaceElevated, borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md - 2,
    },
    topRow: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', gap: spacing.md, marginBottom: spacing.sm + 2 },
    col: { flex: 1 },
    colRight: { alignItems: 'flex-end' },
    label: { fontSize: 11, fontWeight: '700', color: theme.textSecondary, letterSpacing: 0.8, textTransform: 'uppercase' },
    xp: { fontSize: 20, fontWeight: '800', color: theme.score, marginTop: 2, fontVariant: ['tabular-nums'] },
    pct: { fontSize: 16, fontWeight: '800', color: theme.text, marginTop: 2, fontVariant: ['tabular-nums'] },
    track: { height: 8, borderRadius: 5, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.border, overflow: 'hidden' },
    fill: { height: '100%', backgroundColor: theme.primary, borderRadius: 5 },
    footer: { fontSize: 12, fontWeight: '600', color: theme.textMuted, marginTop: spacing.sm },
  });
}
