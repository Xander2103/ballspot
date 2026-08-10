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
import type { FriendRequestItem, FriendSuggestion, FriendSummary } from '../types/friend';

type Props = MainTabScreenProps<'Friends'>;

export function FriendsScreen({ navigation, route }: Props) {
  const { theme } = useTheme();
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
      setAddNotice('Friend request sent.');
      await load();
    } catch (e: unknown) {
      const err = e as { message?: string };
      setAddError(err?.message ?? 'Could not send that friend request.');
    } finally {
      setAdding(false);
    }
  }

  async function handleAccept(item: FriendRequestItem) {
    setBusyId(item.id);
    setAddError('');
    try { await friendsApi.accept(item.id); await load(); }
    catch { setAddError('Could not accept that request.'); }
    finally { setBusyId(null); }
  }

  async function handleReject(item: FriendRequestItem) {
    setBusyId(item.id);
    setAddError('');
    try { await friendsApi.reject(item.id); await load(); }
    catch { setAddError('Could not reject that request.'); }
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
      setRemoveError('Could not remove this friend. Please try again.');
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
          title="Couldn't load your friends"
          message="Check your connection and try again."
          actions={[{ label: 'Retry', onPress: () => { setLoading(true); load(); } }]}
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
      <Text style={styles.sectionTitle}>Your friend code</Text>
      <View style={styles.codeCard}>
        <Text style={styles.code}>{code ?? '········'}</Text>
        {code ? (
          <View style={styles.qrWrap}>
            <QRCode value={code} size={120} color="#000000" backgroundColor="#ffffff" />
          </View>
        ) : null}
        <Text style={styles.codeHint}>Share this code (or the QR) so other players can add you.</Text>
        <AppButton
          title={copied ? 'Copied!' : 'Copy friend code'}
          onPress={handleCopy}
          variant="secondary"
          disabled={!code}
        />
      </View>

      {/* Friend list — expanded by default, searchable. */}
      <CollapsibleSection
        title="Your friends"
        summary={`${friends.length}`}
        initiallyExpanded
      >
        {friends.length === 0 ? (
          <EmptyState compact message="No friends yet. Share your code above to get started." />
        ) : (
          <>
            <AppInput
              value={query}
              onChangeText={setQuery}
              placeholder="Search by name or username"
              autoCapitalize="none"
              autoCorrect={false}
              accessibilityLabel="Search your friends"
            />
            {visibleFriends.length === 0 ? (
              <EmptyState compact message="No friends match your search." />
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
                    <Text style={styles.rowSub}>@{f.username} · {f.rank_name} · {f.total_xp} XP</Text>
                  </View>
                  {/* stopPropagation: this sits inside the row's own TouchableOpacity,
                      so without it a Remove tap can also open the friend's profile. */}
                  <TouchableOpacity
                    onPress={(e) => { e.stopPropagation(); setRemoveTarget(f); }}
                    style={styles.actionBtn}
                    hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                    accessibilityRole="button"
                    accessibilityLabel={`Remove ${f.name} from your friends`}
                  >
                    <Text style={styles.rejectText}>Remove</Text>
                  </TouchableOpacity>
                </TouchableOpacity>
              ))
            )}
          </>
        )}
      </CollapsibleSection>

      {/* Incoming — auto-expanded and badged whenever something is pending. */}
      <CollapsibleSection
        title="Incoming requests"
        badgeCount={incoming.length}
        initiallyExpanded={incoming.length > 0}
      >
        {incoming.length === 0 ? (
          <EmptyState compact message="No incoming requests right now." />
        ) : (
          incoming.map((item, i) => (
            <View key={item.id} style={[styles.row, i > 0 && styles.rowDivider]}>
              <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
              <View style={styles.rowText}>
                <Text style={styles.rowName}>{item.user.name}</Text>
                <Text style={styles.rowSub}>@{item.user.username} · {item.user.rank_name}</Text>
              </View>
              <TouchableOpacity onPress={() => handleAccept(item)} disabled={busyId === item.id} style={styles.actionBtn}>
                <Text style={styles.acceptText}>Accept</Text>
              </TouchableOpacity>
              <TouchableOpacity onPress={() => handleReject(item)} disabled={busyId === item.id} style={styles.actionBtn}>
                <Text style={styles.rejectText}>Reject</Text>
              </TouchableOpacity>
            </View>
          ))
        )}
      </CollapsibleSection>

      {/* Outgoing — collapsed by default. */}
      <CollapsibleSection title="Sent requests" summary={outgoing.length > 0 ? `${outgoing.length}` : undefined}>
        {outgoing.length === 0 ? (
          <EmptyState compact message="No pending sent requests." />
        ) : (
          outgoing.map((item, i) => (
            <View key={item.id} style={[styles.row, i > 0 && styles.rowDivider]}>
              <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
              <View style={styles.rowText}>
                <Text style={styles.rowName}>{item.user.name}</Text>
                <Text style={styles.rowSub}>@{item.user.username} · pending</Text>
              </View>
            </View>
          ))
        )}
      </CollapsibleSection>

      {/* Suggested friends — safe public data only, small and dismissable. */}
      <CollapsibleSection
        title="Suggested friends"
        summary={suggestions.length > 0 ? `${suggestions.length}` : undefined}
      >
        {suggestions.length === 0 ? (
          <EmptyState compact message="No suggestions right now — check back later." />
        ) : (
          suggestions.map((s, i) => (
            <View key={s.id} style={[styles.row, i > 0 && styles.rowDivider]}>
              <Avatar uri={s.avatar_url} name={s.name} size={40} />
              <View style={styles.rowText}>
                <Text style={styles.rowName} numberOfLines={1}>{s.name}</Text>
                <Text style={styles.rowSub} numberOfLines={1}>
                  {s.reason === 'same_tournament' ? 'Played in the same tournament' : 'Active player'}
                </Text>
              </View>
              {sentIds.has(s.id) ? (
                <Text style={styles.notice}>Request sent</Text>
              ) : (
                <TouchableOpacity
                  onPress={() => handleSuggestAdd(s.id)}
                  disabled={suggestBusyId === s.id}
                  style={styles.actionBtn}
                  accessibilityRole="button"
                  accessibilityLabel={`Send ${s.name} a friend request`}
                >
                  <Text style={styles.acceptText}>Add</Text>
                </TouchableOpacity>
              )}
            </View>
          ))
        )}
      </CollapsibleSection>

      {/* Add a friend — collapsed by default. */}
      <CollapsibleSection title="Add a friend">
        <AppInput
          label="Friend code"
          value={input}
          onChangeText={(t) => setInput(t.toUpperCase())}
          autoCapitalize="characters"
          autoCorrect={false}
          placeholder="ABCD2345"
          maxLength={12}
        />
        {addError ? <Text style={styles.error}>{addError}</Text> : null}
        {addNotice ? <Text style={styles.notice}>{addNotice}</Text> : null}
        <AppButton title="Send request" onPress={handleAdd} loading={adding} disabled={!input.trim() || adding} />
        <AppButton
          title="Scan QR code"
          onPress={() => navigation.navigate('ScanFriendCode')}
          variant="secondary"
          style={{ marginTop: spacing.sm }}
        />
      </CollapsibleSection>

      <ConfirmModal
        visible={!!removeTarget}
        title="Remove friend?"
        message={`${removeTarget?.name ?? 'This player'} will be removed from your friends list. You can add each other again later.`}
        confirmLabel="Remove"
        cancelLabel="Cancel"
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
