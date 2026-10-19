import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, RefreshControl, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { ConfirmModal } from '../components/ConfirmModal';
import { LeaderboardRow, LeaderboardSeparator } from '../components/LeaderboardList';
import { ClubLogo } from '../components/clubs/ClubLogo';
import { roleChipColor } from '../components/clubs/ClubCard';
import { ClubPackRow } from '../components/clubs/ClubPackRow';
import { CompetitionRow } from '../components/clubs/CompetitionRow';
import { SegmentedControl } from '../components/clubs/SegmentedControl';
import { ActionSheet, type SheetAction } from '../components/clubs/ActionSheet';
import { InlineBanner } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { goClubs } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubDetail, ClubLeaderboardEntry, ClubPackCard, CompetitionCard } from '../types/club';
import { clubHeaderParts, roleLabel, type CompetitionSegment } from '../utils/clubDisplay';
import { getClubErrorMessage, hasClubErrorCode, isClubGoneError } from '../utils/clubErrors';
import { mergePreviewRows, sharedRankFlags } from '../utils/sharedRank';
import type { PlaySession } from '../utils/playSession';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubDetail'>;

const COMPLETED_PREVIEW = 5;

export function ClubDetailScreen({ navigation, route }: Props) {
  const { clubId, notice } = route.params;
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);

  const [club, setClub] = useState<ClubDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [gone, setGone] = useState(false);

  const [segment, setSegment] = useState<CompetitionSegment | null>(null);
  const [completed, setCompleted] = useState<CompetitionCard[] | null>(null);
  const [completedLoading, setCompletedLoading] = useState(false);
  const [completedError, setCompletedError] = useState('');

  const [sheetOpen, setSheetOpen] = useState(false);
  const [leaveOpen, setLeaveOpen] = useState(false);
  const [ownerInfoOpen, setOwnerInfoOpen] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const [leaveError, setLeaveError] = useState('');

  const loadedOnce = useRef(false);

  const load = useCallback(async (mode: 'initial' | 'refresh' | 'silent') => {
    if (mode === 'initial') setLoading(true);
    if (mode === 'refresh') setRefreshing(true);
    try {
      const res = await clubApi.get(clubId);
      setClub(res.data);
      setError('');
      setGone(false);
      loadedOnce.current = true;
      // A refresh may change the completed count: re-fetch that segment lazily.
      setCompleted(null);
    } catch (e: unknown) {
      if (isClubGoneError(e)) {
        setGone(true);
      } else {
        setError(getClubErrorMessage(e, t('clubs.detail.loadError')));
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [clubId, t]);

  useEffect(() => { void load('initial'); }, [load]);
  // Progress / standings change after playing: refresh silently on focus.
  useEffect(() => navigation.addListener('focus', () => { if (loadedOnce.current) void load('silent'); }), [navigation, load]);

  // Default segment: whatever has content (Active → Upcoming), once.
  useEffect(() => {
    if (!club || segment) return;
    const preview = club.competitions_preview;
    setSegment(preview.active.length > 0 ? 'active' : preview.upcoming.length > 0 ? 'upcoming' : 'active');
  }, [club, segment]);

  const loadCompleted = useCallback(async () => {
    setCompletedLoading(true);
    setCompletedError('');
    try {
      const res = await clubApi.competitions(clubId, { phase: 'completed', limit: COMPLETED_PREVIEW });
      setCompleted(res.data ?? []);
    } catch (e: unknown) {
      setCompletedError(getClubErrorMessage(e, t('clubs.competitions.loadError')));
    } finally {
      setCompletedLoading(false);
    }
  }, [clubId, t]);

  useEffect(() => {
    if (segment !== 'completed' || !club || completed !== null || completedLoading || completedError) return;
    if (club.competitions_preview.completed_count === 0) { setCompleted([]); return; }
    void loadCompleted();
  }, [segment, club, completed, completedLoading, completedError, loadCompleted]);

  const clubName = club?.name ?? route.params.name ?? '';

  const sheetActions = useMemo<SheetAction[]>(() => {
    if (!club) return [];
    const actions: SheetAction[] = [];
    if (club.abilities.view_members) {
      actions.push({ key: 'members', label: t('clubs.detail.viewMembers'), onPress: () => navigation.navigate('ClubMembers', { clubId, name: club.name }) });
    }
    if (club.abilities.leave || club.role === 'owner') {
      actions.push({
        key: 'leave',
        label: t('clubs.leave.action'),
        destructive: true,
        onPress: () => {
          setLeaveError('');
          // A sole owner cannot leave: explain instead of offering a doomed confirm.
          if (!club.abilities.leave && club.role === 'owner') setOwnerInfoOpen(true);
          else setLeaveOpen(true);
        },
      });
    }
    return actions;
  }, [club, clubId, navigation, t]);

  useLayoutEffect(() => {
    navigation.setOptions({
      title: clubName || t('nav.titles.club'),
      headerRight: sheetActions.length > 0
        ? () => (
          <TouchableOpacity
            onPress={() => setSheetOpen(true)}
            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
            accessibilityRole="button"
            accessibilityLabel={t('clubs.detail.actionsA11y')}
          >
            <Text style={{ color: theme.primary, fontSize: 22, fontWeight: '800' }}>⋯</Text>
          </TouchableOpacity>
        )
        : undefined,
    });
  }, [navigation, clubName, sheetActions.length, t, theme.primary]);

  async function handleLeave() {
    if (leaving) return;
    setLeaving(true);
    setLeaveError('');
    try {
      await clubApi.leave(clubId);
      setLeaveOpen(false);
      goClubs(navigation);
    } catch (e: unknown) {
      if (hasClubErrorCode(e, 'club_owner_must_transfer') || hasClubErrorCode(e, 'club_last_owner')) {
        setLeaveOpen(false);
        setOwnerInfoOpen(true);
      } else {
        setLeaveError(getClubErrorMessage(e, t('clubs.leave.failed')));
      }
    } finally {
      setLeaving(false);
    }
  }

  function openPack(pack: ClubPackCard) {
    const session: PlaySession = { kind: 'pack', packSlug: pack.slug, clubId, clubName: clubName || null };
    navigation.navigate('PackDetail', { slug: pack.slug, name: pack.name, session });
  }

  function openCompetition(c: CompetitionCard) {
    navigation.navigate('ClubCompetition', { clubId, competitionId: c.id, name: c.name, clubName: clubName || undefined });
  }

  if (loading) {
    return <Screen><View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View></Screen>;
  }

  if (gone) {
    return (
      <Screen padding>
        <EmptyState
          icon="🛡️"
          title={t('clubs.detail.goneTitle')}
          message={t('clubs.detail.goneMessage')}
          actions={[{ label: t('clubs.detail.backToClubs'), onPress: () => goClubs(navigation) }]}
        />
      </Screen>
    );
  }

  if (!club) {
    return (
      <Screen padding>
        <EmptyState
          icon="📡"
          title={t('clubs.detail.loadError')}
          message={error}
          actions={[
            { label: t('common.buttons.retry'), onPress: () => { void load('initial'); } },
            { label: t('clubs.detail.backToClubs'), onPress: () => goClubs(navigation) },
          ]}
        />
      </Screen>
    );
  }

  const suspended = club.status === 'suspended';
  const preview = club.competitions_preview;
  const competitionItems: CompetitionCard[] =
    segment === 'upcoming' ? preview.upcoming : segment === 'completed' ? (completed ?? []) : preview.active;
  const emptyCompetitionKey =
    segment === 'upcoming' ? 'clubs.competitions.emptyUpcoming' : segment === 'completed' ? 'clubs.competitions.emptyCompleted' : 'clubs.competitions.emptyActive';
  const boardRows: ClubLeaderboardEntry[] = mergePreviewRows(club.leaderboard_preview.top, club.leaderboard_preview.me);
  const boardShared = sharedRankFlags(boardRows);
  const roleColor = roleChipColor(club.role, theme);

  return (
    <Screen padding={false}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { void load('refresh'); }} tintColor={theme.primary} colors={[theme.primary]} />}
      >
        {notice === 'joined' ? <InlineBanner text={t('clubs.detail.joinedNotice', { name: club.name })} tone="success" /> : null}
        {notice === 'alreadyMember' ? <InlineBanner text={t('clubs.detail.alreadyMemberNotice', { name: club.name })} /> : null}
        {error ? <InlineBanner text={error} tone="error" /> : null}

        {/* Identity header */}
        <View style={styles.header}>
          <ClubLogo uri={club.logo_url} name={club.name} emoji={club.sport?.emoji} size={64} dimmed={suspended} />
          <View style={styles.headerText}>
            <Text style={styles.name}>{club.name}</Text>
            <Text style={styles.headerMeta}>{clubHeaderParts(club, locale).join(' · ')}</Text>
            <Text style={[styles.role, { color: roleColor }]}>{t('clubs.detail.yourRole', { role: roleLabel(club.role, locale) })}</Text>
          </View>
        </View>
        {club.description ? <Text style={styles.description}>{club.description}</Text> : null}

        {suspended ? (
          <View style={styles.suspendedCard}>
            <Text style={styles.suspendedTitle}>{t('clubs.detail.suspendedTitle')}</Text>
            <Text style={styles.suspendedText}>{t('clubs.detail.suspendedMessage')}</Text>
          </View>
        ) : null}

        {/* Competitions */}
        <View style={styles.section}>
          <SectionHeader
            title={t('clubs.sections.competitions')}
            seeAll={t('clubs.sections.seeAll')}
            seeAllA11y={t('clubs.sections.seeAllA11y', { section: t('clubs.sections.competitions') })}
            onSeeAll={() => navigation.navigate('ClubCompetitions', { clubId, phase: segment ?? 'active', name: club.name })}
            styles={styles}
          />
          <SegmentedControl<CompetitionSegment>
            segments={[
              { key: 'active', label: t('clubs.competitions.segmentActive') },
              { key: 'upcoming', label: t('clubs.competitions.segmentUpcoming') },
              { key: 'completed', label: t('clubs.competitions.segmentCompleted') },
            ]}
            value={segment ?? 'active'}
            onChange={setSegment}
          />
          <View style={styles.sectionBody}>
            {segment === 'completed' && !completedError && (completedLoading || completed === null) ? (
              <ActivityIndicator color={theme.primary} style={{ marginVertical: spacing.md }} />
            ) : segment === 'completed' && completedError ? (
              <EmptyState compact message={completedError} actions={[{ label: t('common.buttons.retry'), onPress: () => { void loadCompleted(); } }]} />
            ) : competitionItems.length === 0 ? (
              <Text style={styles.muted}>{t(emptyCompetitionKey)}</Text>
            ) : (
              competitionItems.map((c) => <CompetitionRow key={c.id} competition={c} onPress={() => openCompetition(c)} />)
            )}
          </View>
        </View>

        {/* Packs */}
        <View style={styles.section}>
          <SectionHeader
            title={t('clubs.sections.packs')}
            seeAll={club.packs_count > 0 ? t('clubs.sections.seeAll') : null}
            seeAllA11y={t('clubs.sections.seeAllA11y', { section: t('clubs.sections.packs') })}
            onSeeAll={() => navigation.navigate('ClubPacks', { clubId, name: club.name })}
            styles={styles}
          />
          {club.packs_preview.length === 0 ? (
            <Text style={styles.muted}>{t('clubs.packs.empty')}</Text>
          ) : (
            club.packs_preview.map((p) => <ClubPackRow key={p.id} pack={p} onPress={() => openPack(p)} />)
          )}
        </View>

        {/* Leaderboard */}
        <View style={styles.section}>
          <SectionHeader
            title={t('clubs.sections.leaderboard')}
            seeAll={boardRows.length > 0 ? t('clubs.sections.seeAll') : null}
            seeAllA11y={t('clubs.sections.seeAllA11y', { section: t('clubs.sections.leaderboard') })}
            onSeeAll={() => navigation.navigate('ClubLeaderboard', { clubId, name: club.name })}
            styles={styles}
          />
          {boardRows.length === 0 ? (
            <Text style={styles.muted}>{t('clubs.leaderboard.previewEmpty')}</Text>
          ) : (
            <View style={styles.board}>
              {boardRows.map((row, i) => (
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

      </ScrollView>

      <ActionSheet
        visible={sheetOpen}
        title={t('clubs.detail.actionsTitle')}
        actions={sheetActions}
        cancelLabel={t('common.buttons.cancel')}
        onClose={() => setSheetOpen(false)}
      />
      <ConfirmModal
        visible={leaveOpen}
        title={t('clubs.leave.confirmTitle', { name: club.name })}
        message={t('clubs.leave.confirmMessage')}
        confirmLabel={t('clubs.leave.confirm')}
        destructive
        loading={leaving}
        errorText={leaveError || undefined}
        onConfirm={() => { void handleLeave(); }}
        onCancel={() => setLeaveOpen(false)}
      />
      <ConfirmModal
        visible={ownerInfoOpen}
        title={t('clubs.leave.ownerTitle')}
        message={t('clubs.leave.ownerMessage')}
        confirmLabel={t('common.buttons.ok')}
        cancelLabel={t('common.buttons.close')}
        onConfirm={() => setOwnerInfoOpen(false)}
        onCancel={() => setOwnerInfoOpen(false)}
      />
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function SectionHeader({ title, seeAll, seeAllA11y, onSeeAll, styles }: {
  title: string; seeAll: string | null; seeAllA11y: string; onSeeAll: () => void; styles: Styles;
}) {
  return (
    <View style={styles.sectionHeader}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {seeAll ? (
        <TouchableOpacity onPress={onSeeAll} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }} accessibilityRole="button" accessibilityLabel={seeAllA11y}>
          <Text style={styles.seeAll}>{seeAll} ›</Text>
        </TouchableOpacity>
      ) : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 240 },
    content: { padding: spacing.md, paddingBottom: spacing.xxl },
    header: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginTop: spacing.xs },
    headerText: { flex: 1, minWidth: 0 },
    name: { fontSize: 22, fontWeight: '800', color: theme.text },
    headerMeta: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    role: { fontSize: 13, fontWeight: '700', marginTop: 4 },
    description: { fontSize: 14, color: theme.textSecondary, lineHeight: 20, marginTop: spacing.md },
    suspendedCard: {
      marginTop: spacing.md, padding: spacing.md, borderRadius: 12, borderWidth: 1,
      borderColor: theme.warning + '66', backgroundColor: theme.warning + '14',
    },
    suspendedTitle: { fontSize: 15, fontWeight: '700', color: theme.warning },
    suspendedText: { fontSize: 13, color: theme.textSecondary, marginTop: 2, lineHeight: 18 },
    section: { marginTop: spacing.lg },
    sectionHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.sm },
    sectionTitle: { fontSize: 17, fontWeight: '800', color: theme.text, marginBottom: spacing.sm },
    seeAll: { fontSize: 14, fontWeight: '700', color: theme.primary, marginBottom: spacing.sm },
    sectionBody: { marginTop: spacing.sm },
    muted: { fontSize: 13, color: theme.textMuted, paddingVertical: spacing.sm },
    board: { backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border, overflow: 'hidden' },
  });
}
