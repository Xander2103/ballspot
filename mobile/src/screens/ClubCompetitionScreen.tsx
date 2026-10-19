import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, ScrollView, RefreshControl, ActivityIndicator, TouchableOpacity } from 'react-native';
import { Image } from 'expo-image';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { EmptyState } from '../components/EmptyState';
import { YourPositionCard } from '../components/YourPositionCard';
import { LeaderboardRow, LeaderboardSeparator } from '../components/LeaderboardList';
import { InlineBanner } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { goClubs } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { CompetitionDetail, CompetitionPack } from '../types/club';
import { getClubErrorMessage, hasClubErrorCode, isClubGoneError } from '../utils/clubErrors';
import {
  attemptsLabel, competitionGroup, competitionPackAction, competitionStatusLine, competitionWindow, countdownTo,
  describeRulesText, packScoreParts,
} from '../utils/competition';
import { sharedRankFlags } from '../utils/sharedRank';
import type { CompetitionPlaySession } from '../utils/playSession';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubCompetition'>;

/** Countdown refresh while upcoming / active. */
const TICK_MS = 30000;

/**
 * A club competition: window + countdown (in the competition's time zone),
 * rules, my position, the linked packs with my per-pack attempt state and
 * play buttons, and the standings preview. Button states come only from the
 * server's per-pack `state` (utils/competition.ts competitionPackAction).
 */
export function ClubCompetitionScreen({ navigation, route }: Props) {
  const { clubId, competitionId, clubName } = route.params;
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);

  const [detail, setDetail] = useState<CompetitionDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [gone, setGone] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const [startingSlug, setStartingSlug] = useState<string | null>(null);
  const [packErrors, setPackErrors] = useState<Record<string, string>>({});
  const startInFlight = useRef(false);
  const loadedOnce = useRef(false);

  const load = useCallback(async (mode: 'initial' | 'refresh' | 'silent') => {
    if (mode === 'initial') setLoading(true);
    if (mode === 'refresh') setRefreshing(true);
    try {
      const res = await clubApi.competition(clubId, competitionId);
      setDetail(res.data);
      setError('');
      setGone(false);
      setNow(Date.now());
      loadedOnce.current = true;
    } catch (e: unknown) {
      if (isClubGoneError(e)) setGone(true);
      else setError(getClubErrorMessage(e, t('clubs.competitions.loadError')));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [clubId, competitionId, t]);

  useEffect(() => { void load('initial'); }, [load]);
  // Attempts / standings change after playing: refresh silently on focus.
  useEffect(() => navigation.addListener('focus', () => {
    if (loadedOnce.current) { setPackErrors({}); void load('silent'); }
  }), [navigation, load]);

  // Countdown tick; reload once the window opens or closes so buttons follow the server.
  const phase = detail?.phase;
  useEffect(() => {
    if (phase !== 'upcoming' && phase !== 'active') return;
    const timer = setInterval(() => setNow(Date.now()), TICK_MS);
    return () => clearInterval(timer);
  }, [phase]);
  const boundary = detail ? (detail.phase === 'upcoming' ? detail.starts_at : detail.phase === 'active' ? detail.ends_at : null) : null;
  const boundaryPassed = boundary ? countdownTo(boundary, now).done : false;
  useEffect(() => {
    if (boundaryPassed) void load('silent');
  }, [boundaryPassed, load]);

  useLayoutEffect(() => {
    const title = detail?.name ?? route.params.name;
    if (title) navigation.setOptions({ title });
  }, [navigation, detail?.name, route.params.name]);

  const sessionFor = useCallback((pack: CompetitionPack, attemptId: number): CompetitionPlaySession => ({
    kind: 'competition',
    packSlug: pack.slug,
    clubId,
    clubName: clubName ?? null,
    competitionId,
    competitionName: detail?.name ?? route.params.name ?? null,
    attemptId,
  }), [clubId, clubName, competitionId, detail?.name, route.params.name]);

  async function handleStart(pack: CompetitionPack, attemptNumber: number) {
    // Synchronous guard: a double tap starts once (the server is idempotent per attempt number too).
    if (startInFlight.current) return;
    startInFlight.current = true;
    setStartingSlug(pack.slug);
    setPackErrors((prev) => ({ ...prev, [pack.slug]: '' }));
    try {
      const res = await clubApi.startCompetitionAttempt(clubId, competitionId, pack.slug, attemptNumber);
      const session = sessionFor(pack, res.attempt.id);
      if (res.attempt.status === 'completed') {
        navigation.navigate('PackComplete', { slug: pack.slug, packName: pack.name, session });
      } else {
        navigation.navigate('PackGuess', { slug: pack.slug, packName: pack.name, session });
      }
    } catch (e: unknown) {
      setPackErrors((prev) => ({ ...prev, [pack.slug]: getClubErrorMessage(e, t('packs.detail.startError')) }));
      // The server state moved on (attempt running elsewhere, window closed…): show the fresh buttons.
      if (hasClubErrorCode(e, 'competition_attempt_in_progress') || hasClubErrorCode(e, 'competition_ended')
        || hasClubErrorCode(e, 'competition_not_started') || hasClubErrorCode(e, 'competition_attempt_limit')) {
        void load('silent');
      }
    } finally {
      startInFlight.current = false;
      setStartingSlug(null);
    }
  }

  function handleContinue(pack: CompetitionPack, attemptId: number) {
    navigation.navigate('PackGuess', { slug: pack.slug, packName: pack.name, session: sessionFor(pack, attemptId) });
  }

  if (loading) {
    return <Screen><View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View></Screen>;
  }

  if (gone) {
    return (
      <Screen padding>
        <EmptyState
          icon="🏆"
          title={t('clubs.competition.goneTitle')}
          message={t('clubs.competition.goneMessage')}
          actions={[{ label: t('clubs.detail.backToClubs'), onPress: () => goClubs(navigation) }]}
        />
      </Screen>
    );
  }

  if (!detail) {
    return (
      <Screen padding>
        <EmptyState
          icon="📡"
          title={t('clubs.competitions.loadError')}
          message={error}
          actions={[
            { label: t('common.buttons.retry'), onPress: () => { void load('initial'); } },
            { label: t('clubs.competitions.backToClub'), onPress: () => navigation.goBack() },
          ]}
        />
      </Screen>
    );
  }

  const group = competitionGroup(detail.phase);
  const rules = describeRulesText(detail.rules, locale);
  const board = detail.leaderboard_preview ?? [];
  const boardShared = sharedRankFlags(board);
  const entry = detail.my_entry;

  return (
    <Screen padding={false}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { void load('refresh'); }} tintColor={theme.primary} colors={[theme.primary]} />}
      >
        {error ? <InlineBanner text={error} tone="error" /> : null}

        {/* Window + countdown */}
        <View style={styles.headerCard}>
          <Text style={styles.name}>{detail.name}</Text>
          {detail.description ? <Text style={styles.description}>{detail.description}</Text> : null}
          <Text style={styles.window}>{competitionWindow(detail, locale)}</Text>
          <Text style={[styles.status, group === 'active' && { color: theme.primary }, group === 'closed' && { color: theme.warning }]}>
            {competitionStatusLine(detail, now, locale)}
          </Text>
        </View>

        {group === 'closed' ? (
          <InlineBanner text={t(detail.phase === 'voided' ? 'clubs.competition.voidedBanner' : 'clubs.competition.cancelledBanner')} tone="warning" />
        ) : null}

        {/* Rules */}
        <View style={styles.card}>
          <Text style={styles.cardTitle}>{t('clubs.competition.rulesTitle')}</Text>
          {rules.map((line, i) => (
            <Text key={i} style={styles.ruleLine}>• {line}</Text>
          ))}
        </View>

        {/* My position */}
        {group === 'active' || group === 'completed' ? (
          <View style={styles.positionWrap}>
            {group === 'completed' && entry ? <Text style={styles.sectionLabel}>{t('clubs.competition.finalPlacement')}</Text> : null}
            <YourPositionCard
              rank={entry?.rank ?? null}
              totalPlayers={detail.participants_count}
              betterThanPercentage={null}
              scoreText={entry
                ? t('clubs.competition.myEntry', { score: entry.total_score.toLocaleString(), count: entry.packs_completed })
                : null}
              shared={entry ? board.filter((e) => e.rank === entry.rank).length > 1 : false}
            />
          </View>
        ) : null}

        {/* Packs */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>{t('clubs.sections.packs')}</Text>
          {group === 'upcoming' ? <Text style={styles.muted}>{t('clubs.competition.opensLater')}</Text> : null}
          {detail.packs.length === 0 ? <Text style={styles.muted}>{t('clubs.packs.empty')}</Text> : null}
          {detail.packs.map((pack) => {
            const action = competitionPackAction(pack.state, detail.phase, detail.rules);
            const attempts = attemptsLabel(pack.state, detail.rules, locale);
            const scores = packScoreParts(pack.state, detail.rules, locale);
            const meta = [attempts, ...scores].filter(Boolean).join(' · ');
            const packError = packErrors[pack.slug];
            return (
              <View key={pack.id} style={styles.packCard}>
                <View style={styles.packRow}>
                  <View style={styles.cover}>
                    {pack.cover_image_url ? (
                      <Image source={{ uri: pack.cover_image_url }} style={styles.coverImg} contentFit="cover" recyclingKey={pack.cover_image_url} />
                    ) : (
                      <Text style={styles.coverEmoji}>📦</Text>
                    )}
                  </View>
                  <View style={styles.packBody}>
                    <Text style={styles.packName} numberOfLines={1}>{pack.name}</Text>
                    <Text style={styles.packMeta} numberOfLines={2}>
                      {meta || t('packs.meta.challenges', { count: pack.challenge_count })}
                    </Text>
                  </View>
                </View>
                {action.kind === 'start' ? (
                  <AppButton
                    title={action.numbered ? t('clubs.competition.startNumbered', { n: action.attemptNumber }) : t('clubs.competition.start')}
                    onPress={() => { void handleStart(pack, action.attemptNumber); }}
                    loading={startingSlug === pack.slug}
                    disabled={startingSlug !== null && startingSlug !== pack.slug}
                    style={styles.packButton}
                  />
                ) : action.kind === 'continue' ? (
                  <AppButton title={t('clubs.competition.continue')} onPress={() => handleContinue(pack, action.attemptId)} style={styles.packButton} />
                ) : action.kind === 'blocked' ? (
                  <Text style={styles.blocked}>{t(action.reasonKey)}</Text>
                ) : null}
                {packError ? <Text style={styles.packError}>{packError}</Text> : null}
              </View>
            );
          })}
        </View>

        {/* Standings */}
        {group !== 'upcoming' ? (
          <View style={styles.section}>
            <View style={styles.sectionHeader}>
              <Text style={styles.sectionTitle}>
                {group === 'completed' ? t('clubs.competition.finalStandings') : t('clubs.sections.leaderboard')}
              </Text>
              {board.length > 0 ? (
                <TouchableOpacity
                  onPress={() => navigation.navigate('ClubLeaderboard', { clubId, competitionId, name: detail.name })}
                  hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                  accessibilityRole="button"
                  accessibilityLabel={t('clubs.sections.seeAllA11y', { section: t('clubs.sections.leaderboard') })}
                >
                  <Text style={styles.seeAll}>{t('clubs.sections.seeAll')} ›</Text>
                </TouchableOpacity>
              ) : null}
            </View>
            {board.length === 0 ? (
              <Text style={styles.muted}>{t('clubs.competition.noScores')}</Text>
            ) : (
              <View style={styles.board}>
                {board.map((row, i) => (
                  <React.Fragment key={row.user_id}>
                    {i > 0 ? <LeaderboardSeparator /> : null}
                    <LeaderboardRow
                      item={row}
                      shared={boardShared[i]}
                      showAvatar
                      subtitle={(e) => t('clubs.leaderboard.packsCompleted', { count: e.packs_completed })}
                    />
                  </React.Fragment>
                ))}
              </View>
            )}
          </View>
        ) : null}
      </ScrollView>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 240 },
    content: { padding: spacing.md, paddingBottom: spacing.xxl, gap: spacing.sm },
    headerCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md },
    name: { fontSize: 22, fontWeight: '800', color: theme.text },
    description: { fontSize: 14, color: theme.textSecondary, lineHeight: 20, marginTop: spacing.xs },
    window: { fontSize: 13, color: theme.textSecondary, marginTop: spacing.sm },
    status: { fontSize: 14, fontWeight: '700', color: theme.textSecondary, marginTop: 2 },
    card: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md },
    cardTitle: { fontSize: 13, fontWeight: '700', color: theme.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: spacing.xs },
    ruleLine: { fontSize: 14, color: theme.text, lineHeight: 21 },
    positionWrap: { marginHorizontal: -spacing.md },
    sectionLabel: { fontSize: 13, fontWeight: '700', color: theme.textMuted, textAlign: 'center', textTransform: 'uppercase', letterSpacing: 0.5 },
    section: { marginTop: spacing.md },
    sectionHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    sectionTitle: { fontSize: 17, fontWeight: '800', color: theme.text, marginBottom: spacing.sm },
    seeAll: { fontSize: 14, fontWeight: '700', color: theme.primary, marginBottom: spacing.sm },
    muted: { fontSize: 13, color: theme.textMuted, paddingBottom: spacing.sm },
    packCard: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.sm, marginBottom: spacing.sm,
    },
    packRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    cover: {
      width: 52, height: 52, borderRadius: 10, overflow: 'hidden',
      backgroundColor: theme.surfaceElevated, alignItems: 'center', justifyContent: 'center',
    },
    coverImg: { width: '100%', height: '100%' },
    coverEmoji: { fontSize: 24 },
    packBody: { flex: 1, minWidth: 0 },
    packName: { fontSize: 15, fontWeight: '700', color: theme.text },
    packMeta: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    packButton: { marginTop: spacing.sm, height: 44 },
    blocked: { fontSize: 13, color: theme.textMuted, marginTop: spacing.sm, fontStyle: 'italic' },
    packError: { fontSize: 13, color: theme.danger, marginTop: spacing.xs },
    board: { backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border, overflow: 'hidden' },
  });
}
