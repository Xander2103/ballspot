import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import type { CompetitionCard } from '../../types/club';
import { competitionStatusLine } from '../../utils/competition';
import { rankDisplay } from '../../utils/clubDetailLayout';

/**
 * The primary element of the club homepage: one running competition with a
 * live badge, the time left, packs/players and the player's current rank (or
 * an invitation to play when not ranked yet). Standings live in the competition.
 */
export const ActiveCompetitionCard = React.memo(function ActiveCompetitionCard({ competition, onPress }: {
  competition: CompetitionCard;
  onPress: () => void;
}) {
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);
  const rank = rankDisplay(competition);
  const status = competitionStatusLine(competition, Date.now(), locale);
  const meta = [
    t('clubs.detail.packsCount', { count: competition.packs_count }),
    t('clubs.competitions.players', { count: competition.participants_count ?? 0 }),
  ].join(' · ');

  return (
    <TouchableOpacity
      style={styles.card}
      onPress={onPress}
      activeOpacity={0.85}
      accessibilityRole="button"
      accessibilityLabel={t('clubs.detail.openCompetitionA11y', { name: competition.name })}
    >
      <View style={styles.main}>
        <View style={styles.topRow}>
          <View style={styles.livePill}>
            <View style={styles.liveDot} />
            <Text style={styles.liveText}>{t('clubs.detail.live')}</Text>
          </View>
          <Text style={styles.status} numberOfLines={1}>{status}</Text>
        </View>
        <Text style={styles.name} numberOfLines={2}>{competition.name}</Text>
        <Text style={styles.meta} numberOfLines={1}>{meta}</Text>
      </View>

      <View
        style={styles.side}
        accessible
        accessibilityLabel={rank.kind === 'ranked'
          ? `${t('clubs.detail.rankLabel')} #${rank.rank} ${t('clubs.detail.rankOf', { total: rank.total })}`
          : t('clubs.detail.notRanked')}
      >
        {rank.kind === 'ranked' ? (
          <>
            <Text style={[styles.rankValue, rank.rank === 1 && { color: theme.gold }]}>#{rank.rank}</Text>
            <Text style={styles.rankOf} numberOfLines={1}>{t('clubs.detail.rankOf', { total: rank.total })}</Text>
          </>
        ) : (
          <Text style={styles.play} numberOfLines={3}>{t('clubs.detail.notRanked')}</Text>
        )}
      </View>
      <Text style={styles.chevron}>›</Text>
    </TouchableOpacity>
  );
});

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      flexDirection: 'row',
      alignItems: 'stretch',
      backgroundColor: theme.surfaceElevated,
      borderRadius: 16,
      borderWidth: 1,
      borderColor: theme.primary + '55',
      padding: spacing.md,
      marginBottom: spacing.sm,
      gap: spacing.md,
    },
    main: { flex: 1, minWidth: 0 },
    topRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
    livePill: {
      flexDirection: 'row', alignItems: 'center', gap: 5,
      backgroundColor: theme.primary + '22', borderRadius: 999,
      paddingHorizontal: 8, paddingVertical: 3,
    },
    liveDot: { width: 6, height: 6, borderRadius: 3, backgroundColor: theme.primary },
    liveText: { fontSize: 11, fontWeight: '800', color: theme.primary, letterSpacing: 0.4, textTransform: 'uppercase' },
    status: { flexShrink: 1, fontSize: 12, fontWeight: '600', color: theme.textSecondary },
    name: { fontSize: 18, fontWeight: '800', color: theme.text, marginTop: spacing.sm, lineHeight: 23 },
    meta: { fontSize: 13, color: theme.textSecondary, marginTop: 4 },
    side: {
      minWidth: 64, maxWidth: 96, alignItems: 'flex-end', justifyContent: 'center',
      borderLeftWidth: StyleSheet.hairlineWidth, borderLeftColor: theme.border, paddingLeft: spacing.md,
    },
    rankValue: { fontSize: 30, fontWeight: '900', color: theme.text, lineHeight: 34 },
    rankOf: { fontSize: 12, color: theme.textSecondary, textAlign: 'right', marginTop: 2 },
    play: { fontSize: 13, fontWeight: '700', color: theme.primary, textAlign: 'right' },
    chevron: { alignSelf: 'center', fontSize: 22, color: theme.textMuted, marginLeft: -spacing.xs },
  });
}
