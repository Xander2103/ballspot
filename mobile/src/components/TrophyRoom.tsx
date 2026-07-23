import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { badgeApi } from '../api/badgeApi';
import type { EarnedBadge, TournamentFinish } from '../types/badge';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

function placementMedal(placement: number): string {
  if (placement === 1) return '🏆';
  if (placement === 2) return '🥈';
  if (placement === 3) return '🥉';
  return `#${placement}`;
}

function placementLabel(placement: number): string {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = placement % 100;
  return `${placement}${s[(v - 20) % 10] ?? s[v] ?? s[0]} place`;
}

function FinishRow({ finish }: { finish: TournamentFinish }) {
  const date = finish.completed_at ? new Date(finish.completed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : null;
  const parts = [
    typeof finish.total_players === 'number' ? `${finish.total_players} players` : null,
    typeof finish.total_score === 'number' ? `${finish.total_score.toLocaleString('en-US')} pts` : null,
    date,
  ].filter(Boolean);

  return (
    <View style={styles.finishRow}>
      <Text style={styles.finishMedal}>{placementMedal(finish.placement)}</Text>
      <View style={styles.finishInfo}>
        <Text style={styles.finishTitle} numberOfLines={1}>
          {placementLabel(finish.placement)}{finish.league ? ` — ${finish.league.name}` : ''}
        </Text>
        {parts.length > 0 ? <Text style={styles.finishMeta} numberOfLines={1}>{parts.join(' · ')}</Text> : null}
      </View>
    </View>
  );
}

const RARITY_COLOR: Record<string, string> = {
  common: colors.textSecondary,
  rare: colors.accent,
  epic: '#b76bff',
  legendary: colors.warning,
};

function BadgeCell({ badge }: { badge: EarnedBadge }) {
  const earned = badge.earned;
  const rarityColor = RARITY_COLOR[badge.rarity] ?? colors.textSecondary;

  return (
    <View style={[styles.cell, earned ? { borderColor: rarityColor + '80' } : styles.cellLocked]}>
      <Text style={[styles.icon, !earned && styles.iconLocked]}>{earned ? badge.icon : '🔒'}</Text>
      <Text style={[styles.badgeName, !earned && styles.textLocked]} numberOfLines={1}>
        {badge.name}
      </Text>
      <Text style={[styles.badgeDesc, !earned && styles.textLocked]} numberOfLines={2}>
        {badge.description}
      </Text>
      {earned ? (
        <Text style={[styles.rarity, { color: rarityColor }]}>{badge.rarity}</Text>
      ) : (
        <Text style={styles.rarityLocked}>Locked</Text>
      )}
    </View>
  );
}

/**
 * Trophy Room — earned badges first, locked badges faded.
 * Self-contained (fetches its own data) so it drops into ProfileScreen cleanly.
 */
export function TrophyRoom() {
  const [badges, setBadges] = useState<EarnedBadge[] | null>(null);
  const [counts, setCounts] = useState<{ earned: number; total: number }>({ earned: 0, total: 0 });
  const [finishes, setFinishes] = useState<TournamentFinish[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    let cancelled = false;
    badgeApi
      .mine()
      .then((res) => {
        if (cancelled) return;
        // Earned first (most recent first), then locked in catalogue order.
        const sorted = [...res.badges].sort((a, b) => {
          if (a.earned !== b.earned) return a.earned ? -1 : 1;
          return a.sort_order - b.sort_order;
        });
        setBadges(sorted);
        setCounts({ earned: res.earned_count, total: res.total_count });
      })
      .catch(() => !cancelled && setError(true))
      .finally(() => !cancelled && setLoading(false));

    // Tournament finishes are non-fatal — a failure just hides the section.
    badgeApi.finishes()
      .then((data) => !cancelled && setFinishes(data))
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <View style={styles.wrap}>
      <View style={styles.headerRow}>
        <Text style={styles.sectionTitle}>Trophy Room</Text>
        {badges ? (
          <Text style={styles.count}>
            {counts.earned} / {counts.total} earned
          </Text>
        ) : null}
      </View>

      {loading ? (
        <ActivityIndicator color={colors.primary} style={styles.loader} />
      ) : error || !badges ? (
        <Text style={styles.errorText}>Could not load badges.</Text>
      ) : (
        <View style={styles.grid}>
          {badges.map((b) => (
            <BadgeCell key={b.code} badge={b} />
          ))}
        </View>
      )}

      {/* Tournament trophies (final placements) */}
      {!loading && !error ? (
        <View style={styles.finishesWrap}>
          <Text style={styles.subHeader}>Tournament trophies</Text>
          {finishes.length === 0 ? (
            <Text style={styles.emptyFinishes}>No tournament trophies yet.</Text>
          ) : (
            finishes.map((f) => <FinishRow key={f.id} finish={f} />)
          )}
        </View>
      ) : null}

      {/* Competition trophies — reserved for monthly top-3 finishes (future). */}
      {!loading && !error ? (
        <View style={styles.finishesWrap}>
          <Text style={styles.subHeader}>Competition trophies</Text>
          <Text style={styles.emptyFinishes}>Top finishes will appear here when monthly competitions end.</Text>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing.xl },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.md,
  },
  sectionTitle: {
    fontSize: 12,
    fontWeight: '700',
    color: colors.textSecondary,
    letterSpacing: 1,
    textTransform: 'uppercase',
  },
  count: { fontSize: 12, fontWeight: '700', color: colors.primary },
  loader: { marginVertical: spacing.lg },
  errorText: { fontSize: 13, color: colors.textMuted, textAlign: 'center', paddingVertical: spacing.md },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  cell: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: colors.border,
    minWidth: '30%',
    flexGrow: 1,
    flexBasis: '30%',
  },
  cellLocked: { opacity: 0.45, borderColor: colors.border },
  icon: { fontSize: 30, marginBottom: 4 },
  iconLocked: { fontSize: 24 },
  badgeName: { fontSize: 13, fontWeight: '700', color: colors.text, textAlign: 'center' },
  badgeDesc: { fontSize: 10, color: colors.textSecondary, textAlign: 'center', marginTop: 2, minHeight: 26 },
  rarity: { fontSize: 9, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.5, marginTop: 4 },
  rarityLocked: { fontSize: 9, fontWeight: '700', color: colors.textMuted, textTransform: 'uppercase', marginTop: 4 },
  textLocked: { color: colors.textMuted },
  finishesWrap: { marginTop: spacing.lg },
  subHeader: {
    fontSize: 12,
    fontWeight: '700',
    color: colors.textSecondary,
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginBottom: spacing.sm,
  },
  emptyFinishes: {
    fontSize: 13,
    color: colors.textMuted,
    fontStyle: 'italic',
    paddingVertical: spacing.sm,
  },
  finishRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.sm,
  },
  finishMedal: { fontSize: 26, width: 40, textAlign: 'center' },
  finishInfo: { flex: 1, marginLeft: spacing.sm },
  finishTitle: { fontSize: 14, fontWeight: '700', color: colors.text },
  finishMeta: { fontSize: 12, color: colors.textSecondary, marginTop: 2 },
});
