import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, SectionList, TouchableOpacity, ActivityIndicator } from 'react-native';
import { MainTabScreenProps } from '../app/MainTabs';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { EmptyState } from '../components/EmptyState';
import { leagueApi } from '../api/leagueApi';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { useI18n } from '../i18n';

type Props = MainTabScreenProps<'Tournaments'>;

const STATUS_LABEL_KEY: Record<string, string> = {
  lobby: 'tournaments.status.lobby',
  active: 'tournaments.status.active',
  completed: 'tournaments.status.completed',
};

function TournamentCard({
  item, onPress, onDelete, onHide, styles, theme,
}: {
  item: League;
  onPress: () => void;
  onDelete?: () => void;
  /** Remove a finished tournament from this user's list — deletes nothing. */
  onHide?: () => void;
  styles: Styles;
  theme: ThemeTokens;
}) {
  const { t } = useI18n();
  const STATUS_COLOR: Record<string, string> = {
    lobby: theme.warning, active: theme.primary, completed: theme.textMuted,
  };
  const statusColor = STATUS_COLOR[item.status] ?? theme.textMuted;
  const statusLabelKey = STATUS_LABEL_KEY[item.status];
  const statusLabel = statusLabelKey ? t(statusLabelKey) : item.status.toUpperCase();

  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.8}>
      <View style={styles.cardHeader}>
        <Text style={styles.cardName} numberOfLines={1}>
          {item.sport?.emoji ? `${item.sport.emoji} ` : ''}{item.name}
        </Text>
        <View style={[styles.statusBadge, { borderColor: statusColor }]}>
          <Text style={[styles.statusText, { color: statusColor }]}>{statusLabel}</Text>
        </View>
        {item.status === 'completed' && onHide ? (
          <TouchableOpacity
            onPress={(e) => { e.stopPropagation(); onHide(); }}
            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
            style={styles.hideBtn}
            accessibilityRole="button"
            accessibilityLabel={t('tournaments.list.card.removeFromList', { name: item.name })}
          >
            <Text style={styles.hideBtnText}>✕</Text>
          </TouchableOpacity>
        ) : null}
      </View>
      <Text style={styles.cardMeta}>
        {item.sport?.name ? `${item.sport.name} · ` : ''}
        {t('tournaments.code', { code: item.join_code })} · {t('tournaments.players', { count: item.members_count })}
        {item.rounds_count > 0
          ? ` · ${t('tournaments.list.card.rounds', { completed: item.completed_rounds_count, total: item.rounds_count })}`
          : ''}
      </Text>
      {item.is_owner && (item.status === 'lobby' || item.status === 'active') && onDelete ? (
        <TouchableOpacity
          style={styles.deleteBtn}
          onPress={(e) => { e.stopPropagation(); onDelete(); }}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        >
          <Text style={styles.deleteBtnText}>{t('tournaments.list.card.delete')}</Text>
        </TouchableOpacity>
      ) : null}
    </TouchableOpacity>
  );
}

export function TournamentsScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [leagues, setLeagues] = useState<League[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);
  const [cancelTarget, setCancelTarget] = useState<League | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');
  const [hideTarget, setHideTarget] = useState<League | null>(null);
  const [hiding, setHiding] = useState(false);
  const [hideError, setHideError] = useState('');
  // false = pool too small right now (create disabled + info card); null/true = normal.
  const [tournamentsAvailable, setTournamentsAvailable] = useState<boolean | null>(null);

  const load = useCallback(async () => {
    try {
      setLeagues(await leagueApi.list());
      setLoadFailed(false);
    } catch {
      // Only surface a failure on a cold list; if we already have tournaments
      // on screen, keep showing them rather than blanking on a flaky refresh.
      setLeagues((prev) => { setLoadFailed(prev.length === 0); return prev; });
    } finally {
      setLoading(false);
    }
    // Separate, best effort: a failed probe never hides the list or the button.
    leagueApi.availability().then((a) => setTournamentsAvailable(a.available)).catch(() => setTournamentsAvailable(null));
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => navigation.addListener('focus', load), [navigation, load]);

  async function handleCancel() {
    if (!cancelTarget || cancelling) return;
    setCancelling(true);
    setCancelError('');
    const id = cancelTarget.id;
    try {
      await leagueApi.cancel(id);
      // Optimistically drop it from the list — no full refresh needed.
      setLeagues((prev) => prev.filter((l) => l.id !== id));
      setCancelTarget(null);
    } catch {
      setCancelError(t('tournaments.list.deleteModal.error'));
    } finally {
      setCancelling(false);
    }
  }

  async function handleHide() {
    if (!hideTarget || hiding) return;
    setHiding(true);
    setHideError('');
    const id = hideTarget.id;
    try {
      await leagueApi.hide(id);
      // Optimistic — the server keeps every result, only this list changes.
      setLeagues((prev) => prev.filter((l) => l.id !== id));
      setHideTarget(null);
    } catch {
      setHideError(t('tournaments.list.hideModal.error'));
    } finally {
      setHiding(false);
    }
  }

  const active = leagues.filter(l => l.status === 'lobby' || l.status === 'active');
  const completed = leagues.filter(l => l.status === 'completed');

  // Once the user has ANY tournament, keep both sections visible so a section
  // that happens to be empty reads as "nothing here yet" rather than silently
  // vanishing. With nothing at all, ListEmptyComponent covers the whole screen.
  const sections = active.length + completed.length === 0 ? [] : [
    { title: t('tournaments.list.sections.yours'), data: active, emptyText: t('tournaments.list.empty.yours') },
    { title: t('tournaments.list.sections.completed'), data: completed, emptyText: t('tournaments.list.empty.completed') },
  ];

  if (loading) {
    return (
      <View style={styles.loadingWrap}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  const cancelIsLobby = cancelTarget?.status === 'lobby';

  return (
    <Screen padding={false}>
      <SectionList
        sections={sections}
        keyExtractor={(l) => String(l.id)}
        renderSectionHeader={({ section }) => (
          <View style={styles.sectionHeader}>
            <Text style={styles.sectionTitle}>{section.title}</Text>
          </View>
        )}
        renderItem={({ item }) => (
          <TournamentCard
            item={item}
            styles={styles}
            theme={theme}
            onPress={() => navigation.navigate('LeagueDetail', { leagueId: item.id, leagueName: item.name })}
            onDelete={() => setCancelTarget(item)}
            onHide={() => setHideTarget(item)}
          />
        )}
        contentContainerStyle={styles.list}
        ListHeaderComponent={
          <View>
          {tournamentsAvailable === false ? (
            <View style={styles.unavailableCard} accessibilityRole="alert">
              <Text style={styles.unavailableTitle}>{t('tournaments.unavailable.title')}</Text>
              <Text style={styles.unavailableBody}>{t('tournaments.unavailable.body')}</Text>
              <Text style={styles.unavailableHint}>{t('tournaments.unavailable.hint')}</Text>
            </View>
          ) : null}
          <View style={styles.actions}>
            <AppButton title={t('tournaments.list.create')} onPress={() => navigation.navigate('CreateLeague')} style={styles.actionBtn} disabled={tournamentsAvailable === false} />
            <AppButton title={t('tournaments.list.join')} onPress={() => navigation.navigate('JoinLeague')} variant="secondary" style={styles.actionBtn} />
          </View>
          </View>
        }
        renderSectionFooter={({ section }) =>
          section.data.length === 0
            ? <EmptyState compact message={section.emptyText} />
            : null
        }
        ListEmptyComponent={
          loadFailed ? (
            <EmptyState
              title={t('tournaments.list.loadFailed.title')}
              message={t('common.states.checkConnection')}
              actions={[{ label: t('common.buttons.retry'), onPress: () => { setLoading(true); load(); } }]}
            />
          ) : (
            <EmptyState
              icon="⚽"
              title={t('tournaments.list.empty.title')}
              message={t('tournaments.list.empty.message')}
            />
          )
        }
        stickySectionHeadersEnabled={false}
      />

      <ConfirmModal
        visible={!!cancelTarget}
        title={cancelIsLobby ? t('tournaments.list.deleteModal.lobbyTitle') : t('tournaments.list.deleteModal.tournamentTitle')}
        message={cancelIsLobby
          ? t('tournaments.list.deleteModal.lobbyMessage')
          : t('tournaments.list.deleteModal.tournamentMessage')}
        confirmLabel={cancelIsLobby ? t('tournaments.list.deleteModal.lobbyConfirm') : t('tournaments.list.deleteModal.tournamentConfirm')}
        cancelLabel={cancelIsLobby ? t('tournaments.list.deleteModal.keepLobby') : t('common.buttons.cancel')}
        onConfirm={handleCancel}
        onCancel={() => { setCancelTarget(null); setCancelError(''); }}
        loading={cancelling}
        errorText={cancelError}
        destructive
      />

      <ConfirmModal
        visible={!!hideTarget}
        title={t('tournaments.list.hideModal.title')}
        message={t('tournaments.list.hideModal.message')}
        confirmLabel={t('tournaments.list.hideModal.confirm')}
        cancelLabel={t('common.buttons.cancel')}
        onConfirm={handleHide}
        onCancel={() => { setHideTarget(null); setHideError(''); }}
        loading={hiding}
        errorText={hideError}
        destructive
      />
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    loadingWrap: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    list: { padding: spacing.md, paddingBottom: spacing.xl, flexGrow: 1 },
    actions: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md },
    unavailableCard: {
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, marginBottom: spacing.md,
    },
    unavailableTitle: { fontSize: 15, fontWeight: '700', color: theme.text, marginBottom: 2 },
    unavailableBody: { fontSize: 13, color: theme.textSecondary, lineHeight: 19 },
    unavailableHint: { fontSize: 12, color: theme.textMuted, marginTop: spacing.xs },
    actionBtn: { flex: 1, marginBottom: 0 },
    sectionHeader: { paddingBottom: spacing.xs, paddingTop: spacing.sm },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase' },
    card: {
      backgroundColor: theme.surface, borderRadius: 14, padding: spacing.md,
      borderWidth: 1, borderColor: theme.border, marginBottom: spacing.sm,
    },
    cardHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 },
    hideBtn: { marginLeft: spacing.sm, width: 24, height: 24, alignItems: 'center', justifyContent: 'center' },
    hideBtnText: { fontSize: 15, fontWeight: '700', color: theme.textMuted, lineHeight: 18 },
    cardName: { fontSize: 16, fontWeight: '700', color: theme.text, flex: 1, marginRight: spacing.sm },
    statusBadge: { borderRadius: 6, borderWidth: 1, paddingHorizontal: 6, paddingVertical: 2 },
    statusText: { fontSize: 10, fontWeight: '700', letterSpacing: 0.5 },
    cardMeta: { fontSize: 13, color: theme.textSecondary },
    deleteBtn: { marginTop: spacing.sm, alignSelf: 'flex-start' },
    deleteBtnText: { fontSize: 12, color: theme.danger, fontWeight: '600' },
  });
}
