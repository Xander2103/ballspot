import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, SectionList, TouchableOpacity, ActivityIndicator } from 'react-native';
import { MainTabScreenProps } from '../app/MainTabs';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { leagueApi } from '../api/leagueApi';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';

type Props = MainTabScreenProps<'Tournaments'>;

const STATUS_LABEL: Record<string, string> = { lobby: 'LOBBY', active: 'ACTIVE', completed: 'DONE' };

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
  const STATUS_COLOR: Record<string, string> = {
    lobby: theme.warning, active: theme.primary, completed: theme.textMuted,
  };
  const statusColor = STATUS_COLOR[item.status] ?? theme.textMuted;
  const statusLabel = STATUS_LABEL[item.status] ?? item.status.toUpperCase();

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
            accessibilityLabel={`Remove ${item.name} from your list`}
          >
            <Text style={styles.hideBtnText}>✕</Text>
          </TouchableOpacity>
        ) : null}
      </View>
      <Text style={styles.cardMeta}>
        {item.sport?.name ? `${item.sport.name} · ` : ''}Code: {item.join_code} · {item.members_count} players
        {item.rounds_count > 0 ? ` · ${item.completed_rounds_count}/${item.rounds_count} rounds` : ''}
      </Text>
      {item.is_owner && (item.status === 'lobby' || item.status === 'active') && onDelete ? (
        <TouchableOpacity
          style={styles.deleteBtn}
          onPress={(e) => { e.stopPropagation(); onDelete(); }}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        >
          <Text style={styles.deleteBtnText}>Delete</Text>
        </TouchableOpacity>
      ) : null}
    </TouchableOpacity>
  );
}

export function TournamentsScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [leagues, setLeagues] = useState<League[]>([]);
  const [loading, setLoading] = useState(true);
  const [cancelTarget, setCancelTarget] = useState<League | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');
  const [hideTarget, setHideTarget] = useState<League | null>(null);
  const [hiding, setHiding] = useState(false);
  const [hideError, setHideError] = useState('');

  const load = useCallback(async () => {
    try {
      setLeagues(await leagueApi.list());
    } catch {
      // silent — keep whatever we had
    } finally {
      setLoading(false);
    }
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
      setCancelError('Could not delete the tournament. Please try again.');
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
      setHideError('Could not remove the tournament. Please try again.');
    } finally {
      setHiding(false);
    }
  }

  const active = leagues.filter(l => l.status === 'lobby' || l.status === 'active');
  const completed = leagues.filter(l => l.status === 'completed');
  const sections = [
    ...(active.length > 0 ? [{ title: 'Your Tournaments', data: active }] : []),
    ...(completed.length > 0 ? [{ title: 'Completed', data: completed }] : []),
  ];

  if (loading) {
    return (
      <View style={styles.loadingWrap}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

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
          <View style={styles.actions}>
            <AppButton title="+ Create" onPress={() => navigation.navigate('CreateLeague')} style={styles.actionBtn} />
            <AppButton title="Join" onPress={() => navigation.navigate('JoinLeague')} variant="secondary" style={styles.actionBtn} />
          </View>
        }
        ListEmptyComponent={
          <View style={styles.emptyWrap}>
            <Text style={styles.emptyIcon}>⚽</Text>
            <Text style={styles.emptyTitle}>No tournaments yet</Text>
            <Text style={styles.emptyText}>Create a tournament and invite friends to play!</Text>
          </View>
        }
        stickySectionHeadersEnabled={false}
      />

      <ConfirmModal
        visible={!!cancelTarget}
        title={cancelTarget?.status === 'lobby' ? 'Delete lobby?' : 'Delete tournament?'}
        message={cancelTarget?.status === 'lobby'
          ? 'This lobby has not started yet. Are you sure you want to delete it?'
          : 'This will remove the tournament from your active list. Players will no longer be able to continue it.'}
        confirmLabel={cancelTarget?.status === 'lobby' ? 'Delete lobby' : 'Delete tournament'}
        cancelLabel={cancelTarget?.status === 'lobby' ? 'Keep lobby' : 'Cancel'}
        onConfirm={handleCancel}
        onCancel={() => { setCancelTarget(null); setCancelError(''); }}
        loading={cancelling}
        errorText={cancelError}
        destructive
      />

      <ConfirmModal
        visible={!!hideTarget}
        title="Remove tournament?"
        message="This will remove it from your list. Your result/history will stay saved."
        confirmLabel="Remove"
        cancelLabel="Cancel"
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
    emptyWrap: { paddingVertical: spacing.xxl, alignItems: 'center' },
    emptyIcon: { fontSize: 48, marginBottom: spacing.md },
    emptyTitle: { fontSize: 18, fontWeight: '700', color: theme.text, marginBottom: spacing.sm },
    emptyText: { fontSize: 14, color: theme.textSecondary, textAlign: 'center' },
  });
}
