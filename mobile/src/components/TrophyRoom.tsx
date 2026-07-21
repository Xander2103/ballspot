import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { badgeApi } from '../api/badgeApi';
import type { EarnedBadge } from '../types/badge';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

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
});
