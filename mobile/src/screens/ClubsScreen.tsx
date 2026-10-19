import React, { useCallback, useLayoutEffect } from 'react';
import { View, Text, StyleSheet, FlatList, TouchableOpacity, RefreshControl } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { MainTabScreenProps } from '../app/MainTabs';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { ClubCard, ClubCardSkeleton } from '../components/clubs/ClubCard';
import { SearchField } from '../components/clubs/SearchField';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubCard as ClubCardData, MyClubsMeta } from '../types/club';
import { getClubErrorMessage } from '../utils/clubErrors';

type Props = MainTabScreenProps<'Clubs'>;

const SKELETON_ROWS = [0, 1, 2, 3];

/**
 * My Clubs (tab). Lists ONLY the clubs the player joined — the search filters
 * those memberships server-side and never suggests other clubs. Joining is a
 * separate flow (header "+ Join", or the empty state).
 */
export function ClubsScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const fetchPage = useCallback(async ({ query, cursor, signal }: PageRequest) => {
    const res = await clubApi.list({ q: query || undefined, cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null, meta: res.meta ?? null };
  }, []);

  const list = usePaginatedSearch<ClubCardData, MyClubsMeta>({
    fetchPage,
    getKey: (club) => club.id,
    autoLoad: false,
  });
  const { reload } = list;

  // First load + refresh whenever the tab comes back into focus.
  useFocusEffect(useCallback(() => { reload(); }, [reload]));

  useLayoutEffect(() => {
    navigation.setOptions({
      headerRight: () => (
        <TouchableOpacity
          onPress={() => navigation.navigate('JoinClub')}
          hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
          accessibilityRole="button"
          accessibilityLabel={t('clubs.list.joinA11y')}
          style={{ marginRight: spacing.md }}
        >
          <Text style={{ color: theme.primary, fontSize: 16, fontWeight: '700' }}>{t('clubs.list.joinAction')}</Text>
        </TouchableOpacity>
      ),
    });
  }, [navigation, t, theme.primary]);

  const openClub = useCallback((club: ClubCardData) => {
    navigation.navigate('ClubDetail', { clubId: club.id, name: club.name });
  }, [navigation]);

  const { state, view } = list;
  const errorMessage = state.error ? getClubErrorMessage(state.error, t('clubs.list.loadErrorTitle')) : '';
  const total = state.meta?.total_joined ?? null;
  // The search field only makes sense once the player has clubs at all.
  const showSearch = view !== 'empty' && !(view === 'error' && !state.hasLoaded) && !(view === 'skeleton');

  const header = (
    <View style={styles.header}>
      {showSearch || list.text.length > 0 ? (
        <SearchField
          value={list.text}
          onChangeText={list.setText}
          onClear={list.clear}
          placeholder={t('clubs.list.searchPlaceholder')}
          accessibilityLabel={t('clubs.list.searchA11y')}
          clearLabel={t('clubs.list.clearSearch')}
          busy={list.searching && state.hasLoaded}
        />
      ) : null}
      {view === 'list' && state.error ? <View style={styles.bannerWrap}><InlineBanner text={t('clubs.list.refreshError')} tone="warning" /></View> : null}
      {view === 'list' && total !== null && !state.query ? (
        <Text style={styles.count}>{t('clubs.list.total', { count: total })}</Text>
      ) : null}
    </View>
  );

  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View>{SKELETON_ROWS.map((i) => <ClubCardSkeleton key={i} />)}</View>;
  } else if (view === 'error') {
    body = (
      <EmptyState
        icon="📡"
        title={t('clubs.list.loadErrorTitle')}
        message={errorMessage}
        actions={[{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty') {
    body = (
      <EmptyState
        icon="🛡️"
        title={t('clubs.list.emptyTitle')}
        message={t('clubs.list.emptyMessage')}
        actions={[
          { label: t('clubs.list.joinWithCode'), onPress: () => navigation.navigate('JoinClub') },
          { label: t('clubs.list.scanQr'), onPress: () => navigation.navigate('ScanClubCode') },
        ]}
      />
    );
  } else if (view === 'noResults') {
    body = (
      <EmptyState
        icon="🔍"
        title={t('clubs.list.noResultsTitle', { query: state.query })}
        message={t('clubs.list.noResultsMessage')}
        actions={[{ label: t('clubs.list.clearSearch'), onPress: list.clear }]}
      />
    );
  }

  return (
    <Screen padding={false}>
      <FlatList<ClubCardData>
        data={view === 'list' ? state.items : []}
        keyExtractor={(club) => club.id}
        renderItem={({ item }) => <ClubCard club={item} onPress={() => openClub(item)} />}
        contentContainerStyle={styles.listContent}
        ListHeaderComponent={header}
        ListEmptyComponent={body}
        ListFooterComponent={
          view === 'list' ? (
            <PageFooter
              loading={list.loadingMore}
              errorText={state.pageError ? t('clubs.list.pageError') : null}
              retryLabel={t('common.buttons.retry')}
              onRetry={list.retryMore}
              loadingLabel={t('clubs.list.loadingMore')}
            />
          ) : null
        }
        onEndReached={list.loadMore}
        onEndReachedThreshold={0.5}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        refreshControl={
          <RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />
        }
        initialNumToRender={10}
        windowSize={7}
        removeClippedSubviews
      />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    listContent: { padding: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
    header: { marginBottom: spacing.sm },
    bannerWrap: { marginTop: spacing.sm },
    count: { fontSize: 12, color: theme.textMuted, marginTop: spacing.sm, marginLeft: spacing.xs },
  });
}
