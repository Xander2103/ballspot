import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity } from 'react-native';
import * as Clipboard from 'expo-clipboard';
import QRCode from 'react-native-qrcode-svg';
import { MainTabScreenProps } from '../app/MainTabs';
import { useFriendRequests } from '../app/friendRequests';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { AppInput } from '../components/AppInput';
import { Avatar } from '../components/Avatar';
import { CollapsibleSection } from '../components/CollapsibleSection';
import { ConfirmModal } from '../components/ConfirmModal';
import { EmptyState } from '../components/EmptyState';
import { friendsApi } from '../api/friendsApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { FriendRequestItem, FriendSuggestion, FriendSummary } from '../types/friend';

type Props = MainTabScreenProps<'Friends'>;

export function FriendsScreen({ navigation, route }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const { setIncomingCount } = useFriendRequests();

  const [code, setCode] = useState<string | null>(null);
  const [friends, setFriends] = useState<FriendSummary[]>([]);
  const [incoming, setIncoming] = useState<FriendRequestItem[]>([]);
  const [outgoing, setOutgoing] = useState<FriendRequestItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);
  const [suggestions, setSuggestions] = useState<FriendSuggestion[]>([]);
  const [sentIds, setSentIds] = useState<Set<number>>(new Set());
  const [suggestBusyId, setSuggestBusyId] = useState<number | null>(null);

  const [query, setQuery] = useState('');
  const [input, setInput] = useState('');
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState('');
  const [addNotice, setAddNotice] = useState('');
  const [copied, setCopied] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [removeTarget, setRemoveTarget] = useState<FriendSummary | null>(null);
  const [removing, setRemoving] = useState(false);
  const [removeError, setRemoveError] = useState('');

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
    // Suggestions are non-essential: on failure just show an empty section.
    if (suggestRes.status === 'fulfilled') setSuggestions(suggestRes.value);
    if (reqRes.status === 'fulfilled') {
      setIncoming(reqRes.value.incoming);
      setOutgoing(reqRes.value.outgoing);
      // Keep the tab badge in sync with what this screen shows.
      setIncomingCount(reqRes.value.incoming.length);
    }
    setLoadFailed(
      codeRes.status === 'rejected' &&
      listRes.status === 'rejected' &&
      reqRes.status === 'rejected'
    );
    setLoading(false);
  }, [setIncomingCount]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => navigation.addListener('focus', () => { load(); }), [navigation, load]);

  // The scanner hands the code back through route params.
  const scanned = route.params?.scannedCode;
  useEffect(() => {
    if (scanned) {
      setInput(scanned);
      navigation.setParams({ scannedCode: undefined });
    }
  }, [scanned, navigation]);

  async function handleCopy() {
    if (!code) return;
    await Clipboard.setStringAsync(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  async function handleAdd() {
    const value = input.trim().toUpperCase();
    if (!value) return;
    setAdding(true);
    setAddError('');
    setAddNotice('');
    try {
      await friendsApi.sendRequest(value);
      setInput('');
      setAddNotice(t('friends.list.requestSentNotice'));
      await load();
    } catch (e: unknown) {
      const err = e as { message?: string };
      setAddError(err?.message ?? t('friends.list.sendError'));
    } finally {
      setAdding(false);
    }
  }

  async function handleAccept(item: FriendRequestItem) {
    setBusyId(item.id);
    setAddError('');
    try { await friendsApi.accept(item.id); await load(); }
    catch { setAddError(t('friends.list.acceptError')); }
    finally { setBusyId(null); }
  }

  async function handleReject(item: FriendRequestItem) {
    setBusyId(item.id);
    setAddError('');
    try { await friendsApi.reject(item.id); await load(); }
    catch { setAddError(t('friends.list.rejectError')); }
    finally { setBusyId(null); }
  }

  async function handleSuggestAdd(userId: number) {
    setSuggestBusyId(userId);
    try {
      await friendsApi.sendRequestById(userId);
      setSentIds((prev) => new Set(prev).add(userId));
      // Keep the "Sent requests" section truthful without a full reload.
      friendsApi.requests().then((r) => setOutgoing(r.outgoing)).catch(() => {});
    } catch {
      // Keep the Add button; the user can retry.
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

  const trimmedQuery = query.trim().toLowerCase();
  const visibleFriends = trimmedQuery
    ? friends.filter(
        (f) =>
          f.name.toLowerCase().includes(trimmedQuery) ||
          f.username.toLowerCase().includes(trimmedQuery)
      )
    : friends;

  return (
    <Screen scroll padding>
      {/* My friend code + QR — compact block, always visible. */}
      <Text style={styles.sectionTitle}>{t('friends.list.codeTitle')}</Text>
      <View style={styles.codeCard}>
        <Text style={styles.code}>{code ?? '········'}</Text>
        {code ? (
          <View style={styles.qrWrap}>
            <QRCode value={code} size={120} color="#000000" backgroundColor="#ffffff" />
          </View>
        ) : null}
        <Text style={styles.codeHint}>{t('friends.list.codeHint')}</Text>
        <AppButton
          title={copied ? t('common.buttons.copied') : t('friends.list.copyCode')}
          onPress={handleCopy}
          variant="secondary"
          disabled={!code}
        />
      </View>

      {/* Friend list — expanded by default, searchable. */}
      <CollapsibleSection
        title={t('friends.list.yourFriends')}
        summary={`${friends.length}`}
        initiallyExpanded
      >
        {friends.length === 0 ? (
          <EmptyState compact message={t('friends.list.empty')} />
        ) : (
          <>
            <AppInput
              value={query}
              onChangeText={setQuery}
              placeholder={t('friends.list.searchPlaceholder')}
              autoCapitalize="none"
              autoCorrect={false}
              accessibilityLabel={t('friends.list.searchA11y')}
            />
            {visibleFriends.length === 0 ? (
              <EmptyState compact message={t('friends.list.noMatch')} />
            ) : (
              visibleFriends.map((f, i) => (
                <TouchableOpacity
                  key={f.id}
                  style={[styles.row, i > 0 && styles.rowDivider]}
                  activeOpacity={0.8}
                  onPress={() => navigation.navigate('FriendProfile', { userId: f.id, username: f.username })}
                >
                  <Avatar uri={f.avatar_url} name={f.name} size={40} />
                  <View style={styles.rowText}>
                    <Text style={styles.rowName}>{f.name}</Text>
                    <Text style={styles.rowSub}>
                      {t('friends.list.friendMeta', { username: f.username, rank: f.rank_name, xp: f.total_xp })}
                    </Text>
                  </View>
                  {/* stopPropagation: this sits inside the row's own TouchableOpacity,
                      so without it a Remove tap can also open the friend's profile. */}
                  <TouchableOpacity
                    onPress={(e) => { e.stopPropagation(); setRemoveTarget(f); }}
                    style={styles.actionBtn}
                    hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                    accessibilityRole="button"
                    accessibilityLabel={t('friends.list.removeA11y', { name: f.name })}
                  >
                    <Text style={styles.rejectText}>{t('friends.list.remove')}</Text>
                  </TouchableOpacity>
                </TouchableOpacity>
              ))
            )}
          </>
        )}
      </CollapsibleSection>

      {/* Incoming — auto-expanded and badged whenever something is pending. */}
      <CollapsibleSection
        title={t('friends.list.incomingTitle')}
        badgeCount={incoming.length}
        initiallyExpanded={incoming.length > 0}
      >
        {incoming.length === 0 ? (
          <EmptyState compact message={t('friends.list.incomingEmpty')} />
        ) : (
          incoming.map((item, i) => (
            <View key={item.id} style={[styles.row, i > 0 && styles.rowDivider]}>
              <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
              <View style={styles.rowText}>
                <Text style={styles.rowName}>{item.user.name}</Text>
                <Text style={styles.rowSub}>
                  {t('friends.list.requestMeta', { username: item.user.username, rank: item.user.rank_name })}
                </Text>
              </View>
              <TouchableOpacity onPress={() => handleAccept(item)} disabled={busyId === item.id} style={styles.actionBtn}>
                <Text style={styles.acceptText}>{t('friends.list.accept')}</Text>
              </TouchableOpacity>
              <TouchableOpacity onPress={() => handleReject(item)} disabled={busyId === item.id} style={styles.actionBtn}>
                <Text style={styles.rejectText}>{t('friends.list.reject')}</Text>
              </TouchableOpacity>
            </View>
          ))
        )}
      </CollapsibleSection>

      {/* Outgoing — collapsed by default. */}
      <CollapsibleSection title={t('friends.list.sentTitle')} summary={outgoing.length > 0 ? `${outgoing.length}` : undefined}>
        {outgoing.length === 0 ? (
          <EmptyState compact message={t('friends.list.sentEmpty')} />
        ) : (
          outgoing.map((item, i) => (
            <View key={item.id} style={[styles.row, i > 0 && styles.rowDivider]}>
              <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
              <View style={styles.rowText}>
                <Text style={styles.rowName}>{item.user.name}</Text>
                <Text style={styles.rowSub}>{t('friends.list.pendingMeta', { username: item.user.username })}</Text>
              </View>
            </View>
          ))
        )}
      </CollapsibleSection>

      {/* Suggested friends — safe public data only, small and dismissable. */}
      <CollapsibleSection
        title={t('friends.list.suggestedTitle')}
        summary={suggestions.length > 0 ? `${suggestions.length}` : undefined}
      >
        {suggestions.length === 0 ? (
          <EmptyState compact message={t('friends.list.suggestedEmpty')} />
        ) : (
          suggestions.map((s, i) => (
            <View key={s.id} style={[styles.row, i > 0 && styles.rowDivider]}>
              <Avatar uri={s.avatar_url} name={s.name} size={40} />
              <View style={styles.rowText}>
                <Text style={styles.rowName} numberOfLines={1}>{s.name}</Text>
                <Text style={styles.rowSub} numberOfLines={1}>
                  {s.reason === 'same_tournament' ? t('friends.list.reasonSameTournament') : t('friends.list.reasonActivePlayer')}
                </Text>
              </View>
              {sentIds.has(s.id) ? (
                <Text style={styles.notice}>{t('friends.list.requestSent')}</Text>
              ) : (
                <TouchableOpacity
                  onPress={() => handleSuggestAdd(s.id)}
                  disabled={suggestBusyId === s.id}
                  style={styles.actionBtn}
                  accessibilityRole="button"
                  accessibilityLabel={t('friends.list.addA11y', { name: s.name })}
                >
                  <Text style={styles.acceptText}>{t('friends.list.add')}</Text>
                </TouchableOpacity>
              )}
            </View>
          ))
        )}
      </CollapsibleSection>

      {/* Add a friend — collapsed by default. */}
      <CollapsibleSection title={t('friends.list.addTitle')}>
        <AppInput
          label={t('friends.list.codeLabel')}
          value={input}
          onChangeText={(t) => setInput(t.toUpperCase())}
          autoCapitalize="characters"
          autoCorrect={false}
          placeholder="ABCD2345"
          maxLength={12}
        />
        {addError ? <Text style={styles.error}>{addError}</Text> : null}
        {addNotice ? <Text style={styles.notice}>{addNotice}</Text> : null}
        <AppButton title={t('friends.list.sendRequest')} onPress={handleAdd} loading={adding} disabled={!input.trim() || adding} />
        <AppButton
          title={t('friends.list.scanQr')}
          onPress={() => navigation.navigate('ScanFriendCode')}
          variant="secondary"
          style={{ marginTop: spacing.sm }}
        />
      </CollapsibleSection>

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

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm },
    codeCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center', gap: spacing.sm, marginBottom: spacing.md },
    code: { fontSize: 24, fontWeight: '800', letterSpacing: 3, color: theme.primary },
    qrWrap: { backgroundColor: '#ffffff', padding: spacing.sm, borderRadius: 12 },
    codeHint: { fontSize: 12, color: theme.textMuted, textAlign: 'center' },
    error: { color: theme.danger, fontSize: 13, marginBottom: spacing.sm },
    notice: { color: theme.success, fontSize: 13, marginBottom: spacing.sm },
    // Flat rows: the CollapsibleSection provides the card, rows just divide.
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.sm },
    rowDivider: { borderTopWidth: 1, borderTopColor: theme.border },
    rowText: { flex: 1 },
    rowName: { fontSize: 15, fontWeight: '700', color: theme.text },
    rowSub: { fontSize: 12, color: theme.textSecondary, marginTop: 1 },
    actionBtn: { paddingHorizontal: spacing.sm, paddingVertical: 4 },
    acceptText: { fontSize: 13, fontWeight: '700', color: theme.primary },
    rejectText: { fontSize: 13, fontWeight: '700', color: theme.danger },
  });
}
