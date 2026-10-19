import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, FlatList, RefreshControl, TouchableOpacity } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { Avatar } from '../components/Avatar';
import { EmptyState } from '../components/EmptyState';
import { SearchField } from '../components/clubs/SearchField';
import { ClubCardSkeleton, roleChipColor } from '../components/clubs/ClubCard';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { ActionSheet, type SheetAction } from '../components/clubs/ActionSheet';
import { InputConfirmModal } from '../components/clubs/InputConfirmModal';
import { authApi } from '../api/authApi';
import { clubApi } from '../api/clubApi';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubMember, ClubMembersMeta } from '../types/club';
import { formatClubDate, roleLabel } from '../utils/clubDisplay';
import { getClubErrorMessage, hasClubErrorCode } from '../utils/clubErrors';
import { MEMBER_REASON_MAX, memberActions, type MemberAction } from '../utils/clubAdmin';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubMembers'>;

/**
 * Member list (reachable with `view_members` / `manage_members`). Server-side
 * search over usernames; a club that hides its list answers 403
 * club_forbidden, shown as a calm explanation. Admin actions per row come
 * from the server's `actions` flags (change role / remove / ban) and are
 * applied optimistically; "Report" is open to every member.
 */
export function ClubMembersScreen({ navigation, route }: Props) {
  const { clubId } = route.params;
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);

  const fetchPage = useCallback(async ({ query, cursor, signal }: PageRequest) => {
    const res = await clubApi.members(clubId, { q: query || undefined, cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null, meta: res.meta ?? null };
  }, [clubId]);

  const list = usePaginatedSearch<ClubMember, ClubMembersMeta>({ fetchPage, getKey: (m) => String(m.user_id) });
  const { state, view, mutate } = list;
  const hidden = hasClubErrorCode(state.error, 'club_forbidden');

  // Who am I (hides "Report" and admin actions on your own row). Best effort.
  const [meId, setMeId] = useState<number | null>(null);
  useEffect(() => {
    let active = true;
    authApi.me().then((u) => { if (active) setMeId((u as { id?: number })?.id ?? null); }).catch(() => {});
    return () => { active = false; };
  }, []);

  const [sheetFor, setSheetFor] = useState<ClubMember | null>(null);
  const [confirm, setConfirm] = useState<{ member: ClubMember; kind: 'remove' | 'ban' } | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmError, setConfirmError] = useState('');
  const [notice, setNotice] = useState<{ text: string; tone: 'success' | 'error' } | null>(null);

  async function changeRole(member: ClubMember, role: 'admin' | 'member') {
    setNotice(null);
    const previous = member.role;
    // Optimistic: flip the chip now, revert if the server refuses.
    mutate((items) => items.map((m) => (m.user_id === member.user_id ? { ...m, role } : m)));
    try {
      const res = await clubApi.changeMemberRole(clubId, member.user_id, role);
      mutate((items) => items.map((m) => (m.user_id === member.user_id ? { ...m, ...res.data } : m)));
      setNotice({ text: t('clubs.memberAdmin.roleChanged', { name: member.username, role: roleLabel(role, locale) }), tone: 'success' });
    } catch (e: unknown) {
      mutate((items) => items.map((m) => (m.user_id === member.user_id ? { ...m, role: previous } : m)));
      setNotice({ text: getClubErrorMessage(e, t('clubs.memberAdmin.failed')), tone: 'error' });
    }
  }

  async function confirmRemoval() {
    if (!confirm || busy) return;
    const { member, kind } = confirm;
    setBusy(true);
    setConfirmError('');
    // Optimistic: drop the row; put it back where it was on failure.
    const index = state.items.findIndex((m) => m.user_id === member.user_id);
    mutate((items) => items.filter((m) => m.user_id !== member.user_id));
    try {
      const trimmed = reason.trim() || undefined;
      if (kind === 'remove') await clubApi.removeMember(clubId, member.user_id, trimmed);
      else await clubApi.banMember(clubId, member.user_id, trimmed);
      setConfirm(null);
      setReason('');
      setNotice({ text: t(kind === 'remove' ? 'clubs.memberAdmin.removed' : 'clubs.memberAdmin.banned', { name: member.username }), tone: 'success' });
    } catch (e: unknown) {
      mutate((items) => {
        if (items.some((m) => m.user_id === member.user_id)) return items;
        const copy = [...items];
        copy.splice(index < 0 ? copy.length : Math.min(index, copy.length), 0, member);
        return copy;
      });
      setConfirmError(getClubErrorMessage(e, t('clubs.memberAdmin.failed')));
    } finally {
      setBusy(false);
    }
  }

  function runAction(member: ClubMember, action: MemberAction) {
    if (action === 'makeAdmin') void changeRole(member, 'admin');
    else if (action === 'makeMember') void changeRole(member, 'member');
    else if (action === 'report') {
      navigation.navigate('ClubReport', { clubId, subjectType: 'member', subjectId: String(member.user_id), subjectLabel: member.username });
    } else {
      setReason('');
      setConfirmError('');
      setConfirm({ member, kind: action });
    }
  }

  const ACTION_LABEL_KEYS: Record<MemberAction, string> = {
    makeAdmin: 'clubs.memberAdmin.makeAdmin',
    makeMember: 'clubs.memberAdmin.makeMember',
    remove: 'clubs.memberAdmin.remove',
    ban: 'clubs.memberAdmin.ban',
    report: 'clubs.memberAdmin.report',
  };
  const sheetActions: SheetAction[] = sheetFor
    ? memberActions(sheetFor, meId).map((a) => ({
      key: a,
      label: t(ACTION_LABEL_KEYS[a]),
      destructive: a === 'remove' || a === 'ban',
      onPress: () => runAction(sheetFor, a),
    }))
    : [];

  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View>{[0, 1, 2, 3].map((i) => <ClubCardSkeleton key={i} />)}</View>;
  } else if (view === 'error') {
    body = hidden ? (
      <EmptyState icon="🔒" message={t('clubs.members.hidden')} />
    ) : (
      <EmptyState
        icon="📡"
        title={t('clubs.members.loadError')}
        message={getClubErrorMessage(state.error, t('clubs.members.loadError'))}
        actions={[{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty') {
    body = <EmptyState icon="👥" message={t('clubs.members.empty')} />;
  } else if (view === 'noResults') {
    body = (
      <EmptyState
        icon="🔍"
        message={t('clubs.members.noResults', { query: state.query })}
        actions={[{ label: t('clubs.list.clearSearch'), onPress: list.clear }]}
      />
    );
  }

  const header = (
    <View style={styles.header}>
      {hidden ? null : (
        <SearchField
          value={list.text}
          onChangeText={list.setText}
          onClear={list.clear}
          placeholder={t('clubs.members.searchPlaceholder')}
          accessibilityLabel={t('clubs.members.searchA11y')}
          clearLabel={t('clubs.list.clearSearch')}
          busy={list.searching && state.hasLoaded}
        />
      )}
      {notice ? <View style={styles.banner}><InlineBanner text={notice.text} tone={notice.tone} /></View> : null}
      {view === 'list' && state.error ? <View style={styles.banner}><InlineBanner text={getClubErrorMessage(state.error, t('clubs.members.loadError'))} tone="warning" /></View> : null}
      {view === 'list' && state.meta && !state.query ? (
        <Text style={styles.count}>{t('clubs.members.total', { count: state.meta.total })}</Text>
      ) : null}
    </View>
  );

  return (
    <Screen padding={false}>
      <FlatList<ClubMember>
        data={view === 'list' ? state.items : []}
        keyExtractor={(m) => String(m.user_id)}
        renderItem={({ item }) => {
          const color = roleChipColor(item.role, theme);
          const joined = formatClubDate(item.joined_at, locale);
          return (
            <View style={styles.row}>
              <Avatar uri={item.avatar_url} name={item.username} size={40} ring={theme.border} />
              <View style={styles.rowBody}>
                <Text style={styles.username} numberOfLines={1}>@{item.username}</Text>
                {joined ? <Text style={styles.joined}>{t('clubs.members.joined', { date: joined })}</Text> : null}
              </View>
              {item.role !== 'member' ? (
                <Text style={[styles.role, { color, borderColor: color + '66' }]}>{roleLabel(item.role, locale)}</Text>
              ) : null}
              {memberActions(item, meId).length > 0 ? (
                <TouchableOpacity
                  onPress={() => setSheetFor(item)}
                  hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                  accessibilityRole="button"
                  accessibilityLabel={t('clubs.memberAdmin.actionsA11y', { name: item.username })}
                  style={styles.more}
                >
                  <Text style={styles.moreText}>⋯</Text>
                </TouchableOpacity>
              ) : null}
            </View>
          );
        }}
        ListHeaderComponent={header}
        ListEmptyComponent={body}
        ListFooterComponent={view === 'list' ? (
          <PageFooter
            loading={list.loadingMore}
            errorText={state.pageError ? t('clubs.members.pageError') : null}
            retryLabel={t('common.buttons.retry')}
            onRetry={list.retryMore}
          />
        ) : null}
        contentContainerStyle={styles.listContent}
        onEndReached={list.loadMore}
        onEndReachedThreshold={0.5}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />}
      />

      <ActionSheet
        visible={sheetFor !== null}
        title={sheetFor ? `@${sheetFor.username}` : undefined}
        actions={sheetActions}
        cancelLabel={t('common.buttons.cancel')}
        onClose={() => setSheetFor(null)}
      />
      <InputConfirmModal
        visible={confirm !== null}
        title={confirm ? t(confirm.kind === 'remove' ? 'clubs.memberAdmin.removeTitle' : 'clubs.memberAdmin.banTitle', { name: confirm.member.username }) : ''}
        message={confirm?.kind === 'ban' ? t('clubs.memberAdmin.banMessage') : t('clubs.memberAdmin.removeMessage')}
        confirmLabel={confirm?.kind === 'ban' ? t('clubs.memberAdmin.banConfirm') : t('clubs.memberAdmin.removeConfirm')}
        cancelLabel={t('common.buttons.cancel')}
        destructive
        loading={busy}
        errorText={confirmError || undefined}
        onConfirm={() => { void confirmRemoval(); }}
        onCancel={() => setConfirm(null)}
        input={{
          label: t('clubs.memberAdmin.reasonLabel'),
          value: reason,
          onChangeText: setReason,
          placeholder: t('clubs.memberAdmin.reasonPlaceholder'),
          maxLength: MEMBER_REASON_MAX,
        }}
      />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    listContent: { padding: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
    header: { marginBottom: spacing.sm },
    banner: { marginTop: spacing.sm },
    count: { fontSize: 12, color: theme.textMuted, marginTop: spacing.sm, marginLeft: spacing.xs },
    row: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.md,
      paddingVertical: spacing.sm, borderBottomWidth: 1, borderBottomColor: theme.border,
    },
    rowBody: { flex: 1, minWidth: 0 },
    username: { fontSize: 15, fontWeight: '700', color: theme.text },
    joined: { fontSize: 12, color: theme.textMuted, marginTop: 1 },
    more: { paddingHorizontal: spacing.xs },
    moreText: { fontSize: 20, fontWeight: '800', color: theme.textSecondary },
    role: {
      fontSize: 11, fontWeight: '700', borderWidth: 1, borderRadius: 999,
      paddingHorizontal: spacing.sm, paddingVertical: 1, overflow: 'hidden',
    },
  });
}
