import React, { useCallback, useState } from 'react';
import { View, StyleSheet, FlatList, RefreshControl } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { SegmentedControl } from '../components/clubs/SegmentedControl';
import { CompetitionRow } from '../components/clubs/CompetitionRow';
import { ClubCardSkeleton } from '../components/clubs/ClubCard';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { goClubs } from '../app/navigationActions';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { CompetitionCard, CompetitionListPhase } from '../types/club';
import { getClubErrorMessage, isClubGoneError } from '../utils/clubErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubCompetitions'>;

const EMPTY_KEYS: Record<CompetitionListPhase, string> = {
  active: 'clubs.competitions.emptyActive',
  upcoming: 'clubs.competitions.emptyUpcoming',
  completed: 'clubs.competitions.emptyCompleted',
};

/** All competitions of a club per phase (cursor pages). Completed = ended + finalized. */
export function ClubCompetitionsScreen({ navigation, route }: Props) {
  const { clubId, name: clubName } = route.params;
  const { t } = useI18n();
  const [phase, setPhase] = useState<CompetitionListPhase>(route.params.phase ?? 'active');

  return (
    <Screen padding={false}>
      <View style={styles.segments}>
        <SegmentedControl<CompetitionListPhase>
          segments={[
            { key: 'active', label: t('clubs.competitions.segmentActive') },
            { key: 'upcoming', label: t('clubs.competitions.segmentUpcoming') },
            { key: 'completed', label: t('clubs.competitions.segmentCompleted') },
          ]}
          value={phase}
          onChange={setPhase}
        />
      </View>
      {/* Keyed by phase: each phase gets its own fresh list state. */}
      <CompetitionList
        key={phase}
        clubId={clubId}
        phase={phase}
        onOpen={(c) => navigation.navigate('ClubCompetition', { clubId, competitionId: c.id, name: c.name, clubName })}
        onGone={() => goClubs(navigation)}
      />
    </Screen>
  );
}

function CompetitionList({ clubId, phase, onOpen, onGone }: {
  clubId: string; phase: CompetitionListPhase; onOpen: (c: CompetitionCard) => void; onGone: () => void;
}) {
  const { theme } = useTheme();
  const { t } = useI18n();

  const fetchPage = useCallback(async ({ cursor, signal }: PageRequest) => {
    const res = await clubApi.competitions(clubId, { phase, cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null };
  }, [clubId, phase]);

  const list = usePaginatedSearch<CompetitionCard>({ fetchPage, getKey: (c) => c.id });
  const { state, view } = list;

  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View>{[0, 1, 2].map((i) => <ClubCardSkeleton key={i} />)}</View>;
  } else if (view === 'error') {
    const gone = isClubGoneError(state.error);
    body = (
      <EmptyState
        icon="📡"
        title={t('clubs.competitions.loadError')}
        message={gone ? t('clubs.detail.goneMessage') : getClubErrorMessage(state.error, t('clubs.competitions.loadError'))}
        actions={gone
          ? [{ label: t('clubs.detail.backToClubs'), onPress: onGone }]
          : [{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty' || view === 'noResults') {
    body = <EmptyState icon="🏆" message={t(EMPTY_KEYS[phase])} />;
  }

  return (
    <FlatList<CompetitionCard>
      data={view === 'list' ? state.items : []}
      keyExtractor={(c) => c.id}
      renderItem={({ item }) => <CompetitionRow competition={item} onPress={() => onOpen(item)} />}
      contentContainerStyle={styles.listContent}
      ListHeaderComponent={view === 'list' && state.error ? <InlineBanner text={getClubErrorMessage(state.error, t('clubs.competitions.loadError'))} tone="warning" /> : null}
      ListEmptyComponent={body}
      ListFooterComponent={view === 'list' ? (
        <PageFooter
          loading={list.loadingMore}
          errorText={state.pageError ? t('clubs.competitions.pageError') : null}
          retryLabel={t('common.buttons.retry')}
          onRetry={list.retryMore}
        />
      ) : null}
      onEndReached={list.loadMore}
      onEndReachedThreshold={0.5}
      refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />}
    />
  );
}

const styles = StyleSheet.create({
  segments: { paddingHorizontal: spacing.md, paddingTop: spacing.md },
  listContent: { padding: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
});
