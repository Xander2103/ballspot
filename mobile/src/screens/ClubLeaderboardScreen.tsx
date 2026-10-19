import React, { useCallback, useMemo } from 'react';
import { View, StyleSheet, FlatList, RefreshControl, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { YourPositionCard } from '../components/YourPositionCard';
import { LeaderboardEmpty, LeaderboardRow, LeaderboardSeparator } from '../components/LeaderboardList';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { goClubs } from '../app/navigationActions';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubLeaderboardEntry, ClubLeaderboardMeta } from '../types/club';
import { getClubErrorMessage, isClubGoneError } from '../utils/clubErrors';
import { sharedRanks } from '../utils/sharedRank';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubLeaderboard'>;

/**
 * Club all-time leaderboard (cursor pages). Ranks are the server's shared
 * ranks (1, 2, 2, 4); tied rows read "=4". Usernames + avatars only (D12).
 */
export function ClubLeaderboardScreen({ navigation, route }: Props) {
  const { clubId } = route.params;
  const { theme } = useTheme();
  const { t } = useI18n();

  const fetchPage = useCallback(async ({ cursor, signal }: PageRequest) => {
    const res = await clubApi.leaderboard(clubId, { cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null, meta: res.meta ?? null };
  }, [clubId]);

  const list = usePaginatedSearch<ClubLeaderboardEntry, ClubLeaderboardMeta>({
    fetchPage,
    getKey: (e) => String(e.user_id),
  });
  const { state, view } = list;
  const meta = state.meta;
  // Shared flags over every loaded row, so a tie across a page boundary still shows "=".
  const tied = useMemo(() => sharedRanks(state.items), [state.items]);
  const myRank = meta?.current_user_rank ?? null;
  const myRankShared = myRank !== null && (tied.has(myRank) || (meta?.nearby_users ?? []).filter((e) => e.rank === myRank).length > 1);

  const subtitle = useCallback(
    (e: ClubLeaderboardEntry) => t('clubs.leaderboard.packsCompleted', { count: e.packs_completed }),
    [t],
  );

  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  } else if (view === 'error') {
    const gone = isClubGoneError(state.error);
    body = (
      <EmptyState
        icon="📡"
        title={t('clubs.leaderboard.loadError')}
        message={gone ? t('clubs.detail.goneMessage') : getClubErrorMessage(state.error, t('clubs.leaderboard.loadError'))}
        actions={gone
          ? [{ label: t('clubs.detail.backToClubs'), onPress: () => goClubs(navigation) }]
          : [{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty' || view === 'noResults') {
    body = <LeaderboardEmpty title={t('clubs.leaderboard.emptyTitle')} text={t('clubs.leaderboard.emptyText')} />;
  }

  const header = (
    <View>
      {view === 'list' && state.error ? <View style={styles.banner}><InlineBanner text={getClubErrorMessage(state.error, t('clubs.leaderboard.loadError'))} tone="warning" /></View> : null}
      {meta && view === 'list' ? (
        <YourPositionCard
          rank={myRank}
          totalPlayers={meta.total_players}
          betterThanPercentage={meta.better_than_percentage}
          scoreText={typeof meta.current_user_score === 'number'
            ? t('clubs.leaderboard.clubPoints', { score: meta.current_user_score.toLocaleString() })
            : null}
          shared={myRankShared}
        />
      ) : null}
    </View>
  );

  return (
    <Screen padding={false}>
      <FlatList<ClubLeaderboardEntry>
        data={view === 'list' ? state.items : []}
        keyExtractor={(e) => String(e.user_id)}
        renderItem={({ item }) => <LeaderboardRow item={item} shared={tied.has(item.rank)} subtitle={subtitle} showAvatar />}
        ItemSeparatorComponent={LeaderboardSeparator}
        ListHeaderComponent={header}
        ListEmptyComponent={body}
        ListFooterComponent={view === 'list' ? (
          <View style={styles.footer}>
            <PageFooter
              loading={list.loadingMore}
              errorText={state.pageError ? t('clubs.leaderboard.pageError') : null}
              retryLabel={t('common.buttons.retry')}
              onRetry={list.retryMore}
            />
          </View>
        ) : null}
        contentContainerStyle={styles.listContent}
        onEndReached={list.loadMore}
        onEndReachedThreshold={0.5}
        refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 240 },
  listContent: { paddingBottom: spacing.xxl, flexGrow: 1 },
  banner: { paddingHorizontal: spacing.md, paddingTop: spacing.md },
  footer: { paddingHorizontal: spacing.md },
});
