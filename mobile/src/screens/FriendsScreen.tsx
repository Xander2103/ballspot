import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity } from 'react-native';
import * as Clipboard from 'expo-clipboard';
import QRCode from 'react-native-qrcode-svg';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { AppInput } from '../components/AppInput';
import { Avatar } from '../components/Avatar';
import { ConfirmModal } from '../components/ConfirmModal';
import { friendsApi } from '../api/friendsApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { FriendRequestItem, FriendSummary } from '../types/friend';

type Props = NativeStackScreenProps<RootStackParamList, 'Friends'>;

export function FriendsScreen({ navigation, route }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [code, setCode] = useState<string | null>(null);
  const [friends, setFriends] = useState<FriendSummary[]>([]);
  const [incoming, setIncoming] = useState<FriendRequestItem[]>([]);
  const [outgoing, setOutgoing] = useState<FriendRequestItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);

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
    const [codeRes, listRes, reqRes] = await Promise.allSettled([
      friendsApi.myCode(),
      friendsApi.list(),
      friendsApi.requests(),
    ]);
    if (codeRes.status === 'fulfilled') setCode(codeRes.value);
    if (listRes.status === 'fulfilled') setFriends(listRes.value);
    if (reqRes.status === 'fulfilled') {
      setIncoming(reqRes.value.incoming);
      setOutgoing(reqRes.value.outgoing);
    }
    setLoadFailed(
      codeRes.status === 'rejected' &&
      listRes.status === 'rejected' &&
      reqRes.status === 'rejected'
    );
    setLoading(false);
  }, []);

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
        <Text style={styles.loadError}>Could not load your friends. Check your connection.</Text>
        <AppButton
          title="Try again"
          onPress={() => { setLoading(true); load(); }}
          style={{ marginTop: spacing.lg }}
        />
      </Screen>
    );
  }

  return (
    <Screen scroll padding>
      {/* My friend code + QR */}
      <Text style={styles.sectionTitle}>Your friend code</Text>
      <View style={styles.codeCard}>
        <Text style={styles.code}>{code ?? '········'}</Text>
        {code ? (
          <View style={styles.qrWrap}>
            <QRCode value={code} size={168} color="#000000" backgroundColor="#ffffff" />
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

      {/* Add a friend */}
      <Text style={styles.sectionTitle}>Add a friend</Text>
      <View style={styles.addCard}>
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
      </View>

      {/* Incoming */}
      <Text style={styles.sectionTitle}>Incoming requests</Text>
      {incoming.length === 0 ? (
        <View style={styles.emptyCard}><Text style={styles.emptyText}>No incoming requests.</Text></View>
      ) : (
        incoming.map((item) => (
          <View key={item.id} style={styles.row}>
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

      {/* Outgoing */}
      <Text style={styles.sectionTitle}>Sent requests</Text>
      {outgoing.length === 0 ? (
        <View style={styles.emptyCard}><Text style={styles.emptyText}>No pending sent requests.</Text></View>
      ) : (
        outgoing.map((item) => (
          <View key={item.id} style={styles.row}>
            <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
            <View style={styles.rowText}>
              <Text style={styles.rowName}>{item.user.name}</Text>
              <Text style={styles.rowSub}>@{item.user.username} · pending</Text>
            </View>
          </View>
        ))
      )}

      {/* Friends */}
      <Text style={styles.sectionTitle}>Your friends ({friends.length})</Text>
      {friends.length === 0 ? (
        <View style={styles.emptyCard}><Text style={styles.emptyText}>No friends yet. Share your code to get started.</Text></View>
      ) : (
        friends.map((f) => (
          <TouchableOpacity
            key={f.id}
            style={styles.row}
            activeOpacity={0.8}
            onPress={() => navigation.navigate('FriendProfile', { userId: f.id, username: f.username })}
          >
            <Avatar uri={f.avatar_url} name={f.name} size={40} />
            <View style={styles.rowText}>
              <Text style={styles.rowName}>{f.name}</Text>
              <Text style={styles.rowSub}>@{f.username} · {f.rank_name} · {f.total_xp} XP</Text>
            </View>
            <TouchableOpacity onPress={() => setRemoveTarget(f)} style={styles.actionBtn} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
              <Text style={styles.rejectText}>Remove</Text>
            </TouchableOpacity>
          </TouchableOpacity>
        ))
      )}

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
    loadError: { fontSize: 14, color: theme.textSecondary, lineHeight: 20 },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm, marginTop: spacing.md },
    codeCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center', gap: spacing.sm },
    code: { fontSize: 30, fontWeight: '800', letterSpacing: 4, color: theme.primary },
    qrWrap: { backgroundColor: '#ffffff', padding: spacing.sm, borderRadius: 12 },
    codeHint: { fontSize: 12, color: theme.textMuted, textAlign: 'center' },
    addCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md },
    error: { color: theme.danger, fontSize: 13, marginBottom: spacing.sm },
    notice: { color: theme.success, fontSize: 13, marginBottom: spacing.sm },
    emptyCard: { backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center' },
    emptyText: { fontSize: 13, color: theme.textMuted, fontStyle: 'italic' },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border, padding: spacing.md, marginBottom: spacing.sm },
    rowText: { flex: 1 },
    rowName: { fontSize: 15, fontWeight: '700', color: theme.text },
    rowSub: { fontSize: 12, color: theme.textSecondary, marginTop: 1 },
    actionBtn: { paddingHorizontal: spacing.sm, paddingVertical: 4 },
    acceptText: { fontSize: 13, fontWeight: '700', color: theme.primary },
    rejectText: { fontSize: 13, fontWeight: '700', color: theme.danger },
  });
}
