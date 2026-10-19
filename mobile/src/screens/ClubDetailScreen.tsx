import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, RefreshControl, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { ConfirmModal } from '../components/ConfirmModal';
import { ClubLogo } from '../components/clubs/ClubLogo';
import { roleChipColor } from '../components/clubs/ClubCard';
import { ClubPackRow } from '../components/clubs/ClubPackRow';
import { ActiveCompetitionCard } from '../components/clubs/ActiveCompetitionCard';
import { ActionSheet, type SheetAction } from '../components/clubs/ActionSheet';
import { InlineBanner } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { goClubs } from '../app/navigationActions';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubDetail, ClubPackCard, CompetitionCard } from '../types/club';
import { clubDetailParts, roleLabel } from '../utils/clubDisplay';
import { getClubErrorMessage, hasClubErrorCode, isClubGoneError } from '../utils/clubErrors';
import { activeCompetitions, homePacks, nextUpcoming, otherCompetitionsSummary, reservingCompetition } from '../utils/clubDetailLayout';
import { competitionStatusLine } from '../utils/competition';
import { clubAdminEntries } from '../utils/clubAdmin';
import type { PlaySession } from '../utils/playSession';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubDetail'>;

/** Packs fetched for the homepage (ordered client-side: playable first, reserved last). */
const HOME_PACK_FETCH = 12;

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

  // First page of the club's packs: the detail preview holds only a few in server
  // order, the homepage wants the playable ones first. Falls back to the preview.
  const [packs, setPacks] = useState<ClubPackCard[] | null>(null);

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
      const [res, packPage] = await Promise.all([
        clubApi.get(clubId),
        clubApi.packs(clubId, { limit: HOME_PACK_FETCH }).catch(() => null),
      ]);
      setClub(res.data);
      setPacks(packPage?.data ?? null);
      setError('');
      setGone(false);
      loadedOnce.current = true;
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

  const clubName = club?.name ?? route.params.name ?? '';

  const sheetActions = useMemo<SheetAction[]>(() => {
    if (!club) return [];
    const actions: SheetAction[] = [
      // The club-wide leaderboard stays available, one level down: competition
      // standings live inside each competition.
      { key: 'leaderboard', label: t('clubs.detail.clubLeaderboard'), onPress: () => navigation.navigate('ClubLeaderboard', { clubId, name: club.name }) },
    ];
    // Club admin entries follow the server's abilities (fail closed); the backend enforces them anyway.
    const admin = clubAdminEntries(club.abilities);
    if (admin.members) {
      actions.push({ key: 'members', label: t('clubs.detail.viewMembers'), onPress: () => navigation.navigate('ClubMembers', { clubId, name: club.name }) });
    }
    if (admin.invites) {
      actions.push({ key: 'invites', label: t('clubs.detail.invites'), onPress: () => navigation.navigate('ClubInvites', { clubId, name: club.name, role: club.role }) });
    }
    if (admin.photos) {
      actions.push({ key: 'photos', label: t('clubs.detail.photos'), onPress: () => navigation.navigate('ClubPhotos', { clubId, name: club.name }) });
    }
    actions.push({
      key: 'report',
      label: t('clubs.detail.reportClub'),
      onPress: () => navigation.navigate('ClubReport', { clubId, subjectType: 'club', subjectLabel: club.name }),
    });
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
      // The compact header below carries the name: no duplicate title in the nav bar.
      title: '',
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
  }, [navigation, sheetActions.length, t, theme.primary]);

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
    // A competition-reserved pack is played through its competition.
    const reservedIn = reservingCompetition(pack);
    if (reservedIn) {
      navigation.navigate('ClubCompetition', { clubId, competitionId: reservedIn.id, name: reservedIn.name, clubName: clubName || undefined });
      return;
    }
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
  const active = activeCompetitions(club);
  const next = active.length === 0 ? nextUpcoming(club) : null;
  const others = otherCompetitionsSummary(club);
  const shownPacks = homePacks(packs ?? club.packs_preview);
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

        {/* 1. Compact identity */}
        <View style={styles.header}>
          <ClubLogo uri={club.logo_url} name={club.name} emoji={club.sport?.emoji} size={52} dimmed={suspended} />
          <View style={styles.headerText}>
            <Text style={styles.name} numberOfLines={2}>{club.name}</Text>
            <View style={styles.metaRow}>
              <Text style={styles.headerMeta} numberOfLines={1}>{clubDetailParts(club, locale).join(' · ')}</Text>
              <View style={[styles.roleChip, { borderColor: roleColor + '66', backgroundColor: roleColor + '14' }]}>
                <Text style={[styles.roleText, { color: roleColor }]}>{roleLabel(club.role, locale)}</Text>
              </View>
            </View>
          </View>
        </View>
        {club.description ? <Text style={styles.description} numberOfLines={2}>{club.description}</Text> : null}

        {suspended ? (
          <View style={styles.suspendedCard}>
            <Text style={styles.suspendedTitle}>{t('clubs.detail.suspendedTitle')}</Text>
            <Text style={styles.suspendedText}>{t('clubs.detail.suspendedMessage')}</Text>
          </View>
        ) : null}

        {/* 2. Active competitions — the primary section */}
        {active.length > 0 ? (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>{t('clubs.detail.activeTitle')}</Text>
            {active.map((c) => <ActiveCompetitionCard key={c.id} competition={c} onPress={() => openCompetition(c)} />)}
          </View>
        ) : (
          <View style={styles.section}>
            <View style={styles.quietCard}>
              <Text style={styles.quietTitle}>{t('clubs.detail.noActiveTitle')}</Text>
              {next ? (
                <TouchableOpacity onPress={() => openCompetition(next)} accessibilityRole="button" hitSlop={{ top: 6, bottom: 6, left: 6, right: 6 }}>
                  <Text style={styles.quietLink} numberOfLines={2}>
                    {t('clubs.detail.nextUp', { name: next.name, when: competitionStatusLine(next, Date.now(), locale) })} ›
                  </Text>
                </TouchableOpacity>
              ) : (
                <Text style={styles.quietText}>{t('clubs.detail.noActiveText')}</Text>
              )}
            </View>
          </View>
        )}

        {/* 3. Packs — always visible, playable first */}
        <View style={styles.section}>
          <SectionHeader
            title={t('clubs.sections.packs')}
            seeAll={club.packs_count > shownPacks.length ? t('clubs.sections.seeAll') : null}
            seeAllA11y={t('clubs.sections.seeAllA11y', { section: t('clubs.sections.packs') })}
            onSeeAll={() => navigation.navigate('ClubPacks', { clubId, name: club.name })}
            styles={styles}
          />
          {shownPacks.length === 0 ? (
            <Text style={styles.muted}>{t('clubs.packs.empty')}</Text>
          ) : (
            shownPacks.map((p) => <ClubPackRow key={p.id} pack={p} onPress={() => openPack(p)} />)
          )}
        </View>

        {/* 4. Other competitions — one compact entry to the full list */}
        <TouchableOpacity
          style={styles.otherRow}
          onPress={() => navigation.navigate('ClubCompetitions', { clubId, phase: others.openPhase, name: club.name })}
          activeOpacity={0.85}
          accessibilityRole="button"
        >
          <View style={styles.otherBody}>
            <Text style={styles.otherTitle}>{t('clubs.detail.otherCompetitions')}</Text>
            <Text style={styles.otherMeta} numberOfLines={1}>
              {others.upcoming === 0 && others.finished === 0
                ? t('clubs.detail.allCompetitions')
                : [t('clubs.detail.upcomingCount', { count: others.upcoming }), t('clubs.detail.finishedCount', { count: others.finished })].join(' · ')}
            </Text>
          </View>
          <Text style={styles.otherChevron}>›</Text>
        </TouchableOpacity>

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
    content: { paddingHorizontal: spacing.md, paddingTop: spacing.sm, paddingBottom: spacing.xxl },
    header: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
    headerText: { flex: 1, minWidth: 0 },
    name: { fontSize: 20, fontWeight: '800', color: theme.text, lineHeight: 25 },
    metaRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: 4 },
    headerMeta: { flexShrink: 1, fontSize: 13, color: theme.textSecondary },
    roleChip: { borderWidth: 1, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 1 },
    roleText: { fontSize: 11, fontWeight: '700' },
    description: { fontSize: 13, color: theme.textMuted, lineHeight: 18, marginTop: spacing.sm },
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
    muted: { fontSize: 13, color: theme.textMuted, paddingVertical: spacing.sm },
    quietCard: {
      borderRadius: 14, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surface,
      paddingVertical: spacing.sm + 4, paddingHorizontal: spacing.md,
    },
    quietTitle: { fontSize: 14, fontWeight: '700', color: theme.textSecondary },
    quietText: { fontSize: 13, color: theme.textMuted, marginTop: 2 },
    quietLink: { fontSize: 13, fontWeight: '600', color: theme.primary, marginTop: 2 },
    otherRow: {
      flexDirection: 'row', alignItems: 'center', marginTop: spacing.md,
      borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingVertical: spacing.sm + 4, paddingHorizontal: spacing.md,
    },
    otherBody: { flex: 1, minWidth: 0 },
    otherTitle: { fontSize: 14, fontWeight: '700', color: theme.text },
    otherMeta: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    otherChevron: { fontSize: 22, color: theme.textMuted },
  });
}
