import React, { useCallback } from 'react';
import { View, Text, StyleSheet, FlatList, RefreshControl } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { Avatar } from '../components/Avatar';
import { EmptyState } from '../components/EmptyState';
import { SearchField } from '../components/clubs/SearchField';
import { ClubCardSkeleton, roleChipColor } from '../components/clubs/ClubCard';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubMember, ClubMembersMeta } from '../types/club';
import { formatClubDate, roleLabel } from '../utils/clubDisplay';
import { getClubErrorMessage, hasClubErrorCode } from '../utils/clubErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubMembers'>;

/**
 * Member list (only reachable when `abilities.view_members`). Server-side
 * search over usernames; a club that hides its list answers 403
 * club_forbidden, shown as a calm explanation.
 */
export function ClubMembersScreen({ route }: Props) {
  const { clubId } = route.params;
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);

  const fetchPage = useCallback(async ({ query, cursor, signal }: PageRequest) => {
    const res = await clubApi.members(clubId, { q: query || undefined, cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null, meta: res.meta ?? null };
  }, [clubId]);

  const list = usePaginatedSearch<ClubMember, ClubMembersMeta>({ fetchPage, getKey: (m) => String(m.user_id) });
  const { state, view } = list;
  const hidden = hasClubErrorCode(state.error, 'club_forbidden');

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
    role: {
      fontSize: 11, fontWeight: '700', borderWidth: 1, borderRadius: 999,
      paddingHorizontal: spacing.sm, paddingVertical: 1, overflow: 'hidden',
    },
  });
}
