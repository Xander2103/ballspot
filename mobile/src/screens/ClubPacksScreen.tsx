import React, { useCallback } from 'react';
import { View, StyleSheet, FlatList, RefreshControl } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { ClubPackRow } from '../components/clubs/ClubPackRow';
import { ClubCardSkeleton } from '../components/clubs/ClubCard';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { goClubs } from '../app/navigationActions';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ClubPackCard } from '../types/club';
import { getClubErrorMessage, isClubGoneError } from '../utils/clubErrors';
import type { PlaySession } from '../utils/playSession';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubPacks'>;

/** Every pack of a club (cursor pages), with the player's normal-track progress. */
export function ClubPacksScreen({ navigation, route }: Props) {
  const { clubId, name: clubName } = route.params;
  const { theme } = useTheme();
  const { t } = useI18n();

  const fetchPage = useCallback(async ({ cursor, signal }: PageRequest) => {
    const res = await clubApi.packs(clubId, { cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null };
  }, [clubId]);

  const list = usePaginatedSearch<ClubPackCard>({ fetchPage, getKey: (p) => String(p.id), autoLoad: false });
  const { reload } = list;
  // First load + refresh progress when coming back from a play session.
  React.useEffect(() => navigation.addListener('focus', reload), [navigation, reload]);

  const { state, view } = list;

  const openPack = (pack: ClubPackCard) => {
    const session: PlaySession = { kind: 'pack', packSlug: pack.slug, clubId, clubName: clubName ?? null };
    navigation.navigate('PackDetail', { slug: pack.slug, name: pack.name, session });
  };

  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View>{[0, 1, 2].map((i) => <ClubCardSkeleton key={i} />)}</View>;
  } else if (view === 'error') {
    const gone = isClubGoneError(state.error);
    body = (
      <EmptyState
        icon="📡"
        title={t('clubs.packs.loadError')}
        message={gone ? t('clubs.detail.goneMessage') : getClubErrorMessage(state.error, t('clubs.packs.loadError'))}
        actions={gone
          ? [{ label: t('clubs.detail.backToClubs'), onPress: () => goClubs(navigation) }]
          : [{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty' || view === 'noResults') {
    body = <EmptyState icon="📦" message={t('clubs.packs.empty')} />;
  }

  return (
    <Screen padding={false}>
      <FlatList<ClubPackCard>
        data={view === 'list' ? state.items : []}
        keyExtractor={(p) => String(p.id)}
        renderItem={({ item }) => <ClubPackRow pack={item} onPress={() => openPack(item)} />}
        contentContainerStyle={styles.listContent}
        ListHeaderComponent={view === 'list' && state.error ? <InlineBanner text={getClubErrorMessage(state.error, t('clubs.packs.loadError'))} tone="warning" /> : null}
        ListEmptyComponent={body}
        ListFooterComponent={view === 'list' ? (
          <PageFooter
            loading={list.loadingMore}
            errorText={state.pageError ? t('clubs.packs.pageError') : null}
            retryLabel={t('common.buttons.retry')}
            onRetry={list.retryMore}
          />
        ) : null}
        onEndReached={list.loadMore}
        onEndReachedThreshold={0.5}
        refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  listContent: { padding: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
});
