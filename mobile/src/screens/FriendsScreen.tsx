import React, { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import {
  View, Text, StyleSheet, ActivityIndicator, FlatList, RefreshControl, TextInput,
  AccessibilityInfo, Platform, useWindowDimensions,
} from 'react-native';
import * as Clipboard from 'expo-clipboard';
import { MainTabScreenProps } from '../app/MainTabs';
import { useFriendRequests } from '../app/friendRequests';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { EmptyState } from '../components/EmptyState';
import { HeaderIconButton } from '../components/HeaderIconButton';
import { LineIcon } from '../components/LineIcon';
import { FriendCodeCard, QrCodeSheet } from '../components/friends/FriendCodeCard';
import { AddFriendSheet } from '../components/friends/AddFriendSheet';
import { FriendsTabs } from '../components/friends/FriendsTabs';
import { SentRequestsBar } from '../components/friends/SentRequestsBar';
import { PersonRow, RowButton, StatusPill } from '../components/friends/PersonRow';
import { friendsApi } from '../api/friendsApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { FriendRequestItem, FriendSuggestion, FriendSummary } from '../types/friend';
import { getApiErrorMessage } from '../utils/apiError';
import {
  checkFriendCode, filterFriends, openRequests, suggestionState, type FriendsTab,
} from '../utils/friendsModel';

type Props = MainTabScreenProps<'Friends'>;

/** Shared FlatList tuning: a few hundred rows stay smooth, only what is near the screen renders. */
const LIST_TUNING = {
  initialNumToRender: 12,
  maxToRenderPerBatch: 12,
  windowSize: 9,
  keyboardShouldPersistTaps: 'handled' as const,
  keyboardDismissMode: 'on-drag' as const,
  // iOS: the keyboard (search) grows the bottom inset instead of hiding rows.
  automaticallyAdjustKeyboardInsets: Platform.OS === 'ios',
};

/** Below this window height (iPhone SE class) the fixed header tightens up. */
const SMALL_SCREEN_HEIGHT = 700;

/**
 * Friends tab. Fixed on top: friend code, Add a Friend, the three tabs and —
 * only while something is pending — the Sent Requests bar. Below that, one
 * virtualized list for the active tab is the only part that scrolls, so the
 * tabs never scroll away, however many friends there are.
 */
export function FriendsScreen({ navigation, route }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const { height } = useWindowDimensions();
  const small = height < SMALL_SCREEN_HEIGHT;
  const styles = createStyles(theme, small);
  const { setIncomingCount } = useFriendRequests();

  const [code, setCode] = useState<string | null>(null);
  const [friends, setFriends] = useState<FriendSummary[]>([]);
  const [incoming, setIncoming] = useState<FriendRequestItem[]>([]);
  const [outgoing, setOutgoing] = useState<FriendRequestItem[]>([]);
  const [suggestions, setSuggestions] = useState<FriendSuggestion[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadFailed, setLoadFailed] = useState(false);

  const [tab, setTab] = useState<FriendsTab>('friends');
  const [query, setQuery] = useState('');
  const [sentOpen, setSentOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const [qrOpen, setQrOpen] = useState(false);

  const [addOpen, setAddOpen] = useState(false);
  const [input, setInput] = useState('');
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState('');
  const [addNotice, setAddNotice] = useState('');
  // Codes sent from this screen since the last load: blocks an identical second request.
  const [sentCodes, setSentCodes] = useState<Set<string>>(new Set());

  const [busyRequestId, setBusyRequestId] = useState<number | null>(null);
  const [actionError, setActionError] = useState('');
  const [sentIds, setSentIds] = useState<Set<number>>(new Set());
  const [suggestBusyId, setSuggestBusyId] = useState<number | null>(null);
  const [suggestFailedId, setSuggestFailedId] = useState<number | null>(null);

  const [removeTarget, setRemoveTarget] = useState<FriendSummary | null>(null);
  const [removing, setRemoving] = useState(false);
  const [removeError, setRemoveError] = useState('');

  const applyRequests = useCallback((res: { incoming: FriendRequestItem[]; outgoing: FriendRequestItem[] }) => {
    const nextIncoming = openRequests(res.incoming);
    setIncoming(nextIncoming);
    setOutgoing(openRequests(res.outgoing));
    // Keep the tab badge in sync with what this screen shows.
    setIncomingCount(nextIncoming.length);
  }, [setIncomingCount]);

  const load = useCallback(async () => {
    // allSettled: one failing section must not blank the whole screen.
    const [codeRes, listRes, reqRes, suggestRes] = await Promise.allSettled([
      friendsApi.myCode(),
      friendsApi.list(),
      friendsApi.requests(),
      friendsApi.suggestions(),
    ]);
    if (codeRes.status === 'fulfilled') setCode(codeRes.value);
    if (listRes.status === 'fulfilled') setFriends(listRes.value);
    // Suggestions are non-essential: on failure just show an empty tab.
    if (suggestRes.status === 'fulfilled') setSuggestions(suggestRes.value);
    if (reqRes.status === 'fulfilled') {
      applyRequests(reqRes.value);
      // The server list is now the truth for what is pending.
      setSentIds(new Set());
      setSentCodes(new Set());
    }
    setLoadFailed(
      codeRes.status === 'rejected' &&
      listRes.status === 'rejected' &&
      reqRes.status === 'rejected'
    );
    setLoading(false);
  }, [applyRequests]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => navigation.addListener('focus', () => { load(); }), [navigation, load]);

  // Close the sent list once nothing is pending, so a new request starts collapsed.
  useEffect(() => { if (outgoing.length === 0) setSentOpen(false); }, [outgoing.length]);

  useLayoutEffect(() => {
    navigation.setOptions({
      headerRight: () => (
        <HeaderIconButton
          icon="scan"
          testID="friends-scan"
          accessibilityLabel={t('friends.list.scanQr')}
          onPress={() => navigation.navigate('ScanFriendCode')}
        />
      ),
    });
  }, [navigation, t]);

  // The scanner hands the code back through route params: open Add with it filled in.
  const scanned = route.params?.scannedCode;
  useEffect(() => {
    if (scanned) {
      setInput(scanned);
      setAddError('');
      setAddNotice('');
      setAddOpen(true);
      navigation.setParams({ scannedCode: undefined });
    }
  }, [scanned, navigation]);

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  async function handleCopy() {
    if (!code) return;
    await Clipboard.setStringAsync(code);
    setCopied(true);
    AccessibilityInfo.announceForAccessibility(t('common.buttons.copied'));
    setTimeout(() => setCopied(false), 2000);
  }

  function openAdd() {
    setAddError('');
    setAddNotice('');
    setAddOpen(true);
  }

  async function handleAdd() {
    if (adding) return; // a second tap / return key while in flight
    const check = checkFriendCode(input, code, sentCodes);
    if (!check.ok) {
      setAddNotice('');
      setAddError(t(`friends.list.codeError.${check.reason}`));
      return;
    }
    setAdding(true);
    setAddError('');
    setAddNotice('');
    try {
      await friendsApi.sendRequest(check.code);
      setSentCodes((prev) => new Set(prev).add(check.code));
      setInput('');
      setAddNotice(t('friends.list.requestSentNotice'));
      friendsApi.requests().then(applyRequests).catch(() => {});
    } catch (e: unknown) {
      // Known codes / validation text stay (not found, already friends,
      // pending, declined); 5xx, offline and technical bodies become the
      // friendly generic copy instead of raw server text.
      setAddError(getApiErrorMessage(e, t('friends.list.sendError')));
    } finally {
      setAdding(false);
    }
  }

  async function handleRequest(item: FriendRequestItem, action: 'accept' | 'reject') {
    if (busyRequestId !== null) return;
    setBusyRequestId(item.id);
    setActionError('');
    try {
      if (action === 'accept') await friendsApi.accept(item.id);
      else await friendsApi.reject(item.id);
      await load();
    } catch {
      setActionError(t(action === 'accept' ? 'friends.list.acceptError' : 'friends.list.rejectError'));
    } finally {
      setBusyRequestId(null);
    }
  }

  async function handleSuggestAdd(userId: number) {
    if (suggestBusyId !== null) return;
    setSuggestBusyId(userId);
    setSuggestFailedId(null);
    try {
      await friendsApi.sendRequestById(userId);
      setSentIds((prev) => new Set(prev).add(userId));
      // Keep the Sent Requests bar truthful without a full reload.
      friendsApi.requests().then(applyRequests).catch(() => {});
    } catch {
      setSuggestFailedId(userId); // keep Add; the row says it failed
    } finally {
      setSuggestBusyId(null);
    }
  }

  async function handleRemove() {
    if (!removeTarget || removing) return;
    setRemoving(true);
    setRemoveError('');
    try {
      await friendsApi.remove(removeTarget.id);
      setFriends((prev) => prev.filter((f) => f.id !== removeTarget.id));
      setRemoveTarget(null);
    } catch {
      setRemoveError(t('friends.remove.error'));
    } finally {
      setRemoving(false);
    }
  }

  const visibleFriends = useMemo(() => filterFriends(friends, query), [friends, query]);
  const friendIds = useMemo(() => new Set(friends.map((f) => f.id)), [friends]);

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  if (loadFailed) {
    return (
      <Screen padding>
        <EmptyState
          title={t('friends.list.loadErrorTitle')}
          message={t('common.states.checkConnection')}
          actions={[{ label: t('common.buttons.retry'), onPress: () => { setLoading(true); load(); } }]}
        />
      </Screen>
    );
  }

  const refreshControl = <RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={theme.primary} />;
  const errorBanner = actionError ? <Text style={styles.actionError} accessibilityLiveRegion="polite">{actionError}</Text> : null;

  let list: React.ReactNode;
  if (tab === 'friends') {
    list = (
      <FlatList
        key="friends"
        data={visibleFriends}
        keyExtractor={(f) => String(f.id)}
        renderItem={({ item }) => (
          <PersonRow
            name={item.name}
            subtitle={t('friends.list.friendMeta', { username: item.username, rank: item.rank_name, xp: item.total_xp })}
            avatarUrl={item.avatar_url}
            onPress={() => navigation.navigate('FriendProfile', { userId: item.id, username: item.username })}
            testID={`friends-row-${item.id}`}
          >
            <RowButton
              label={t('friends.list.remove')}
              tone="text"
              onPress={() => setRemoveTarget(item)}
              accessibilityLabel={t('friends.list.removeA11y', { name: item.name })}
            />
          </PersonRow>
        )}
        ItemSeparatorComponent={Separator}
        ListHeaderComponent={
          <>
            {errorBanner}
            {friends.length > 0 ? (
              <Text style={styles.listCaption} testID="friends-count">
                {t('friends.list.friendsCount', { count: friends.length })}
              </Text>
            ) : null}
          </>
        }
        ListEmptyComponent={
          friends.length === 0
            ? <EmptyState title={t('friends.list.emptyTitle')} message={t('friends.list.emptyMessage')} />
            : <EmptyState compact message={t('friends.list.noMatch')} />
        }
        contentContainerStyle={styles.listContent}
        refreshControl={refreshControl}
        {...LIST_TUNING}
      />
    );
  } else if (tab === 'requests') {
    list = (
      <FlatList
        key="requests"
        data={incoming}
        keyExtractor={(r) => String(r.id)}
        renderItem={({ item }) => {
          const busy = busyRequestId === item.id;
          return (
            <PersonRow
              name={item.user.name}
              subtitle={t('friends.list.requestMeta', { username: item.user.username, rank: item.user.rank_name })}
              avatarUrl={item.user.avatar_url}
              testID={`friends-request-${item.id}`}
            >
              {busy ? <ActivityIndicator color={theme.primary} style={styles.rowSpinner} /> : (
                <View style={styles.rowActions}>
                  <RowButton
                    label={t('friends.list.decline')}
                    tone="quiet"
                    disabled={busyRequestId !== null}
                    onPress={() => handleRequest(item, 'reject')}
                    accessibilityLabel={t('friends.list.declineA11y', { name: item.user.name })}
                  />
                  <RowButton
                    label={t('friends.list.accept')}
                    tone="primary"
                    disabled={busyRequestId !== null}
                    onPress={() => handleRequest(item, 'accept')}
                    accessibilityLabel={t('friends.list.acceptA11y', { name: item.user.name })}
                  />
                </View>
              )}
            </PersonRow>
          );
        }}
        ItemSeparatorComponent={Separator}
        ListHeaderComponent={errorBanner}
        ListEmptyComponent={<EmptyState title={t('friends.list.requestsEmptyTitle')} message={t('friends.list.requestsEmptyMessage')} />}
        contentContainerStyle={styles.listContent}
        refreshControl={refreshControl}
        {...LIST_TUNING}
      />
    );
  } else {
    list = (
      <FlatList
        key="discover"
        data={suggestions}
        keyExtractor={(s) => String(s.id)}
        renderItem={({ item }) => {
          const state = suggestionState(item.id, outgoing, sentIds, friendIds, suggestBusyId);
          const reason = item.reason === 'same_tournament'
            ? t('friends.list.reasonSameTournament')
            : t('friends.list.reasonActivePlayer');
          const failed = suggestFailedId === item.id;
          return (
            <PersonRow
              name={item.name}
              subtitle={failed ? t('friends.list.addFailed') : t('friends.list.suggestionMeta', { reason, rank: item.rank_name })}
              subtitleTone={failed ? 'danger' : 'default'}
              avatarUrl={item.avatar_url}
              onPress={() => navigation.navigate('FriendProfile', { userId: item.id, username: item.username })}
              testID={`friends-suggestion-${item.id}`}
            >
              {state === 'sending' ? <ActivityIndicator color={theme.primary} style={styles.rowSpinner} /> : null}
              {state === 'pending' ? <StatusPill label={t('friends.list.pending')} /> : null}
              {state === 'friend' ? <StatusPill label={t('friends.tabs.friends')} /> : null}
              {state === 'add' ? (
                <RowButton
                  label={t('friends.list.add')}
                  tone="primary"
                  disabled={suggestBusyId !== null}
                  onPress={() => handleSuggestAdd(item.id)}
                  accessibilityLabel={t('friends.list.addA11y', { name: item.name })}
                  testID={`friends-suggestion-add-${item.id}`}
                />
              ) : null}
            </PersonRow>
          );
        }}
        ItemSeparatorComponent={Separator}
        ListEmptyComponent={<EmptyState title={t('friends.list.discoverEmptyTitle')} message={t('friends.list.discoverEmptyMessage')} />}
        contentContainerStyle={styles.listContent}
        refreshControl={refreshControl}
        {...LIST_TUNING}
      />
    );
  }

  return (
    <Screen padding={false}>
      <View style={styles.fixed}>
        <FriendCodeCard code={code} copied={copied} onCopy={handleCopy} onShowQr={() => setQrOpen(true)} />
        <AppButton title={t('friends.list.addFriendButton')} onPress={openAdd} style={styles.addButton} />
        <FriendsTabs
          active={tab}
          onChange={(next) => { setTab(next); setActionError(''); }}
          requestsCount={incoming.length}
        />
        {outgoing.length > 0 ? (
          <SentRequestsBar
            items={outgoing}
            expanded={sentOpen}
            onToggle={() => setSentOpen((v) => !v)}
            // ~2 rows on a small phone, ~3 otherwise: the list below always keeps room.
            maxListHeight={small ? 116 : 172}
          />
        ) : null}
        {tab === 'friends' && friends.length > 0 ? (
          <View style={styles.search}>
            <LineIcon name="search" color={theme.textMuted} size={18} />
            <TextInput
              value={query}
              onChangeText={setQuery}
              placeholder={t('friends.list.searchPlaceholder')}
              placeholderTextColor={theme.textMuted}
              autoCapitalize="none"
              autoCorrect={false}
              clearButtonMode="while-editing"
              returnKeyType="search"
              style={styles.searchInput}
              maxFontSizeMultiplier={1.3}
              accessibilityLabel={t('friends.list.searchA11y')}
              testID="friends-search"
            />
          </View>
        ) : null}
      </View>

      <View style={styles.listArea}>{list}</View>

      <QrCodeSheet visible={qrOpen} code={code} copied={copied} onCopy={handleCopy} onClose={() => setQrOpen(false)} />
      <AddFriendSheet
        visible={addOpen}
        value={input}
        onChange={(v) => { setInput(v); setAddError(''); }}
        sending={adding}
        error={addError}
        notice={addNotice}
        onSend={handleAdd}
        onScan={() => { setAddOpen(false); navigation.navigate('ScanFriendCode'); }}
        onClose={() => setAddOpen(false)}
      />

      <ConfirmModal
        visible={!!removeTarget}
        title={t('friends.remove.title')}
        message={t('friends.remove.message', { name: removeTarget?.name ?? t('friends.remove.fallbackName') })}
        confirmLabel={t('friends.remove.confirm')}
        cancelLabel={t('common.buttons.cancel')}
        onConfirm={handleRemove}
        onCancel={() => { setRemoveTarget(null); setRemoveError(''); }}
        loading={removing}
        errorText={removeError}
        destructive
      />
    </Screen>
  );
}

function Separator() {
  const { theme } = useTheme();
  return <View style={{ height: StyleSheet.hairlineWidth, backgroundColor: theme.border, marginLeft: 52 }} />;
}

function createStyles(theme: ThemeTokens, small: boolean) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    // Kept tight on purpose: on an iPhone SE the list below still gets most of the screen.
    fixed: { paddingHorizontal: spacing.md, paddingTop: small ? spacing.sm : spacing.md - 4, paddingBottom: spacing.sm, gap: small ? spacing.sm : spacing.sm + 2 },
    addButton: { height: small ? 44 : 48 },
    search: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm,
      height: 40, paddingHorizontal: spacing.md - 4, borderRadius: 12,
      backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.border,
    },
    searchInput: { flex: 1, height: 40, fontSize: 15, color: theme.text },
    listArea: { flex: 1 },
    listContent: { paddingHorizontal: spacing.md, paddingBottom: spacing.lg, flexGrow: 1 },
    listCaption: {
      fontSize: 11, fontWeight: '700', letterSpacing: 1, textTransform: 'uppercase',
      color: theme.textMuted, paddingTop: spacing.xs, paddingBottom: 2,
    },
    actionError: { color: theme.danger, fontSize: 13, paddingVertical: spacing.sm },
    rowActions: { flexDirection: 'row', gap: spacing.sm },
    rowSpinner: { paddingHorizontal: spacing.md },
  });
}
