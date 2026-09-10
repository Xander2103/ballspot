import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Pressable, Modal } from 'react-native';
import { badgeApi } from '../api/badgeApi';
import { packApi } from '../api/packApi';
import type { CompetitionFinish, EarnedBadge, TournamentFinish } from '../types/badge';
import type { PackCompletion } from '../types/pack';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { rarityColor } from '../theme/rarity';
import { useI18n } from '../i18n';

type Styles = ReturnType<typeof createStyles>;
type Translate = ReturnType<typeof useI18n>['t'];

function placementMedal(placement: number): string {
  if (placement === 1) return '🏆';
  if (placement === 2) return '🥈';
  if (placement === 3) return '🥉';
  return `#${placement}`;
}

/** "1st" / "2nd" / "3rd" / "4th" … — each suffix form is its own key so translators can rewrite it. */
function ordinal(t: Translate, n: number): string {
  const v = n % 100;
  const form =
    v >= 11 && v <= 13 ? 'other'
      : n % 10 === 1 ? 'one'
        : n % 10 === 2 ? 'two'
          : n % 10 === 3 ? 'few'
            : 'other';
  return t(`profile.trophyRoom.ordinal.${form}`, { n });
}

function placementLabel(t: Translate, placement: number): string {
  return t('profile.trophyRoom.place', { ordinal: ordinal(t, placement) });
}

function FinishRow({ finish, styles }: { finish: TournamentFinish; styles: Styles }) {
  const { t } = useI18n();
  const date = finish.completed_at ? new Date(finish.completed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : null;
  const parts = [
    typeof finish.total_players === 'number' ? t('profile.units.players', { count: finish.total_players }) : null,
    typeof finish.total_score === 'number' ? t('profile.units.pts', { score: finish.total_score.toLocaleString('en-US') }) : null,
    date,
  ].filter(Boolean);
  const place = placementLabel(t, finish.placement);

  return (
    <View style={styles.finishRow}>
      <Text style={styles.finishMedal}>{placementMedal(finish.placement)}</Text>
      <View style={styles.finishInfo}>
        <Text style={styles.finishTitle} numberOfLines={1}>
          {finish.league ? t('profile.trophyRoom.placeWithName', { place, name: finish.league.name }) : place}
        </Text>
        {parts.length > 0 ? <Text style={styles.finishMeta} numberOfLines={1}>{parts.join(' · ')}</Text> : null}
      </View>
    </View>
  );
}

function BadgeCell({
  badge,
  theme,
  styles,
  onPress,
}: {
  badge: EarnedBadge;
  theme: ThemeTokens;
  styles: Styles;
  onPress: () => void;
}) {
  const { t } = useI18n();
  const earned = badge.earned;
  const color = rarityColor(theme, badge.rarity);

  return (
    <Pressable
      style={[styles.cell, earned ? { borderColor: color + '80' } : styles.cellLocked]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={t('profile.trophyRoom.badgeA11y', {
        name: badge.name,
        state: t(earned ? 'profile.trophyRoom.stateEarned' : 'profile.trophyRoom.stateLocked'),
      })}
    >
      <Text style={[styles.icon, !earned && styles.iconLocked]}>{earned ? badge.icon : '🔒'}</Text>
      <Text style={[styles.badgeName, !earned && styles.textLocked]} numberOfLines={2}>
        {badge.name}
      </Text>
      <Text style={[styles.badgeDesc, !earned && styles.textLocked]} numberOfLines={2}>
        {badge.description}
      </Text>
      {earned ? (
        <Text style={[styles.rarity, { color }]}>{badge.rarity}</Text>
      ) : (
        <Text style={styles.rarityLocked}>{t('profile.trophyRoom.locked')}</Text>
      )}
    </Pressable>
  );
}

/**
 * Trophy Room — earned badges first, locked badges dimmed (but readable).
 * Self-contained (fetches its own data) so it drops into ProfileScreen cleanly.
 * Tapping a badge opens a detail modal with the untruncated name/description.
 */
export function TrophyRoom() {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [badges, setBadges] = useState<EarnedBadge[] | null>(null);
  const [counts, setCounts] = useState<{ earned: number; total: number }>({ earned: 0, total: 0 });
  const [finishes, setFinishes] = useState<TournamentFinish[]>([]);
  const [packCompletions, setPackCompletions] = useState<PackCompletion[]>([]);
  const [competitionFinishes, setCompetitionFinishes] = useState<CompetitionFinish[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [selected, setSelected] = useState<EarnedBadge | null>(null);

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

    // Pack completions — non-fatal too.
    packApi.completions()
      .then((data) => !cancelled && setPackCompletions(data))
      .catch(() => {});

    // Competition finishes (closed monthly/weekly periods) — non-fatal too.
    badgeApi.competitionFinishes()
      .then((data) => !cancelled && setCompetitionFinishes(data))
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <View style={styles.wrap}>
      <View style={styles.headerRow}>
        <Text style={styles.sectionTitle}>{t('profile.trophyRoom.title')}</Text>
        {badges ? (
          <Text style={styles.count}>
            {t('profile.trophyRoom.earnedCount', { earned: counts.earned, total: counts.total })}
          </Text>
        ) : null}
      </View>

      {loading ? (
        <ActivityIndicator color={theme.primary} style={styles.loader} />
      ) : error || !badges ? (
        <Text style={styles.errorText}>{t('profile.trophyRoom.loadError')}</Text>
      ) : (
        <View style={styles.grid}>
          {badges.map((b) => (
            <BadgeCell key={b.code} badge={b} theme={theme} styles={styles} onPress={() => setSelected(b)} />
          ))}
        </View>
      )}

      {/* Tournament trophies (final placements) */}
      {!loading && !error ? (
        <View style={styles.finishesWrap}>
          <Text style={styles.subHeader}>{t('profile.trophyRoom.tournamentTrophies')}</Text>
          {finishes.length === 0 ? (
            <Text style={styles.emptyFinishes}>{t('profile.trophyRoom.noTournamentTrophies')}</Text>
          ) : (
            finishes.map((f) => <FinishRow key={f.id} finish={f} styles={styles} />)
          )}
        </View>
      ) : null}

      {/* Pack trophies (completed challenge packs) */}
      {!loading && !error ? (
        <View style={styles.finishesWrap}>
          <Text style={styles.subHeader}>{t('profile.trophyRoom.packTrophies')}</Text>
          {packCompletions.length === 0 ? (
            <Text style={styles.emptyFinishes}>{t('profile.trophyRoom.noPackTrophies')}</Text>
          ) : (
            packCompletions.map((p) => <PackCompletionRow key={p.id} completion={p} styles={styles} />)
          )}
        </View>
      ) : null}

      {/* Competition trophies — real top-3 finishes from CLOSED periods only.
          The live leaderboard position is never shown here as a trophy. */}
      {!loading && !error ? (
        <View style={styles.finishesWrap}>
          <Text style={styles.subHeader}>{t('profile.trophyRoom.competitionTrophies')}</Text>
          {competitionFinishes.length === 0 ? (
            <Text style={styles.emptyFinishes}>{t('profile.trophyRoom.noCompetitionTrophies')}</Text>
          ) : (
            competitionFinishes.map((f) => <CompetitionFinishRow key={f.id} finish={f} styles={styles} />)
          )}
        </View>
      ) : null}

      {/* Badge detail — untruncated name/description, rarity, earned state. */}
      <Modal
        visible={selected != null}
        transparent
        animationType="fade"
        onRequestClose={() => setSelected(null)}
      >
        <Pressable style={styles.modalOverlay} onPress={() => setSelected(null)}>
          <Pressable style={styles.modalCard} onPress={() => {}}>
            {selected ? (
              <>
                <Text style={styles.modalIcon}>{selected.earned ? selected.icon : '🔒'}</Text>
                <Text style={styles.modalName}>{selected.name}</Text>
                <Text style={styles.modalDesc}>{selected.description}</Text>
                <Text style={[styles.modalRarity, { color: rarityColor(theme, selected.rarity) }]}>
                  {selected.rarity.toUpperCase()}
                </Text>
                <Text style={styles.modalStatus}>
                  {selected.earned && selected.earned_at
                    ? t('profile.trophyRoom.earnedOn', { date: new Date(selected.earned_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) })
                    : selected.earned
                      ? t('profile.trophyRoom.earned')
                      : t('profile.trophyRoom.notEarned')}
                </Text>
              </>
            ) : null}
          </Pressable>
        </Pressable>
      </Modal>
    </View>
  );
}

function CompetitionFinishRow({ finish, styles }: { finish: CompetitionFinish; styles: Styles }) {
  const { t } = useI18n();
  const competitionName = finish.period_type === 'weekly'
    ? t('profile.trophyRoom.weeklyCompetition')
    : t('profile.trophyRoom.monthlyCompetition');
  const parts = [
    finish.period_label || null,
    typeof finish.total_players === 'number' ? t('profile.units.players', { count: finish.total_players }) : null,
    typeof finish.total_score === 'number' ? t('profile.units.pts', { score: finish.total_score.toLocaleString('en-US') }) : null,
    finish.xp_awarded > 0 ? t('profile.units.xpAwarded', { xp: finish.xp_awarded.toLocaleString('en-US') }) : null,
  ].filter(Boolean);

  return (
    <View style={styles.finishRow}>
      <Text style={styles.finishMedal}>{placementMedal(finish.placement)}</Text>
      <View style={styles.finishInfo}>
        <Text style={styles.finishTitle} numberOfLines={1}>
          {t('profile.trophyRoom.placeWithName', { place: placementLabel(t, finish.placement), name: competitionName })}
        </Text>
        {parts.length > 0 ? <Text style={styles.finishMeta} numberOfLines={1}>{parts.join(' · ')}</Text> : null}
      </View>
    </View>
  );
}

function PackCompletionRow({ completion, styles }: { completion: PackCompletion; styles: Styles }) {
  const { t } = useI18n();
  const date = completion.completed_at
    ? new Date(completion.completed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
    : null;
  const parts = [
    t('profile.units.challenges', { count: completion.challenge_count }),
    t('profile.units.pts', { score: completion.total_score.toLocaleString('en-US') }),
    date,
  ].filter(Boolean);

  return (
    <View style={styles.finishRow}>
      <Text style={styles.finishMedal}>{completion.is_perfect ? '💎' : '📦'}</Text>
      <View style={styles.finishInfo}>
        <Text style={styles.finishTitle} numberOfLines={1}>
          {completion.pack?.name ?? t('profile.trophyRoom.packFallback')}{completion.is_perfect ? t('profile.trophyRoom.perfect') : ''}
        </Text>
        <Text style={styles.finishMeta} numberOfLines={1}>{parts.join(' · ')}</Text>
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
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
      color: theme.textSecondary,
      letterSpacing: 1,
      textTransform: 'uppercase',
    },
    count: { fontSize: 12, fontWeight: '700', color: theme.primary },
    loader: { marginVertical: spacing.lg },
    errorText: { fontSize: 13, color: theme.textMuted, textAlign: 'center', paddingVertical: spacing.md },
    grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
    cell: {
      backgroundColor: theme.surface,
      borderRadius: 12,
      padding: spacing.md,
      alignItems: 'center',
      borderWidth: 1,
      borderColor: theme.border,
      minWidth: '30%',
      flexGrow: 1,
      flexBasis: '30%',
      // Stops the last row's leftover cells stretching to full width.
      maxWidth: '31.5%',
    },
    // 0.45 made locked cards unreadable on dark themes; 0.7 still reads as
    // locked (lock icon + Locked label carry the state) but stays legible.
    cellLocked: { opacity: 0.7, borderColor: theme.border },
    icon: { fontSize: 30, marginBottom: 4 },
    iconLocked: { fontSize: 24 },
    badgeName: {
      fontSize: 13,
      fontWeight: '700',
      color: theme.text,
      textAlign: 'center',
      minHeight: 34, // two lines — keeps grid rows even now names can wrap
    },
    badgeDesc: { fontSize: 10, color: theme.textSecondary, textAlign: 'center', marginTop: 2, minHeight: 26 },
    rarity: { fontSize: 9, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.5, marginTop: 4 },
    rarityLocked: { fontSize: 9, fontWeight: '700', color: theme.textSecondary, textTransform: 'uppercase', marginTop: 4 },
    textLocked: { color: theme.textSecondary },
    finishesWrap: { marginTop: spacing.lg },
    subHeader: {
      fontSize: 12,
      fontWeight: '700',
      color: theme.textSecondary,
      letterSpacing: 1,
      textTransform: 'uppercase',
      marginBottom: spacing.sm,
    },
    emptyFinishes: {
      fontSize: 13,
      color: theme.textMuted,
      fontStyle: 'italic',
      paddingVertical: spacing.sm,
    },
    finishRow: {
      flexDirection: 'row',
      alignItems: 'center',
      backgroundColor: theme.surface,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.border,
      paddingVertical: spacing.sm,
      paddingHorizontal: spacing.md,
      marginBottom: spacing.sm,
    },
    finishMedal: { fontSize: 26, width: 40, textAlign: 'center' },
    finishInfo: { flex: 1, marginLeft: spacing.sm },
    finishTitle: { fontSize: 14, fontWeight: '700', color: theme.text },
    finishMeta: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    modalOverlay: {
      flex: 1,
      backgroundColor: theme.overlay,
      alignItems: 'center',
      justifyContent: 'center',
      padding: spacing.lg,
    },
    modalCard: {
      backgroundColor: theme.surfaceElevated,
      borderRadius: 16,
      borderWidth: 1,
      borderColor: theme.border,
      padding: spacing.lg,
      alignItems: 'center',
      width: '100%',
      maxWidth: 340,
      gap: spacing.xs,
    },
    modalIcon: { fontSize: 44 },
    modalName: { fontSize: 17, fontWeight: '800', color: theme.text, textAlign: 'center' },
    modalDesc: { fontSize: 13, color: theme.textSecondary, textAlign: 'center' },
    modalRarity: { fontSize: 11, fontWeight: '800', letterSpacing: 1, marginTop: 2 },
    modalStatus: { fontSize: 12, color: theme.textMuted, marginTop: 2 },
  });
}
