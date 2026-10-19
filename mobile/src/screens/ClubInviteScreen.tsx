import React, { useCallback, useLayoutEffect, useMemo, useState } from 'react';
import { View, Text, StyleSheet, FlatList, RefreshControl, TouchableOpacity, Modal, Share, TextInput, Pressable } from 'react-native';
import * as Clipboard from 'expo-clipboard';
import QRCode from 'react-native-qrcode-svg';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { EmptyState } from '../components/EmptyState';
import { ConfirmModal } from '../components/ConfirmModal';
import { SegmentedControl } from '../components/clubs/SegmentedControl';
import { ClubCardSkeleton } from '../components/clubs/ClubCard';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi } from '../api/clubApi';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { InviteCode } from '../types/club';
import {
  buildInviteCodeBody, buildInviteShareMessage, canGrantAdmin, EXPIRY_PRESETS, INVITE_LABEL_MAX, INVITE_MAX_USES_LIMIT, inviteCodeMeta,
  inviteCodeStatus, inviteStatusKey, sortInviteCodes, type ExpiryPreset,
} from '../utils/clubAdmin';
import { getClubErrorMessage } from '../utils/clubErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubInvites'>;

const EXPIRY_LABEL_KEYS: Record<ExpiryPreset, string> = {
  never: 'clubs.invites.form.expiryNever',
  '1d': 'clubs.invites.form.expiry1d',
  '7d': 'clubs.invites.form.expiry7d',
  '30d': 'clubs.invites.form.expiry30d',
};

/**
 * Club invite codes (manage_codes): usable codes first, create (label, max
 * uses, expiry preset, admin-granting for owners only), revoke, and for each
 * code a big QR, copy and the Share sheet with a friendly invite text.
 */
export function ClubInviteScreen({ navigation, route }: Props) {
  const { clubId, name: clubName, role } = route.params;
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);

  const fetchPage = useCallback(async ({ cursor, signal }: PageRequest) => {
    const res = await clubApi.inviteCodes(clubId, { cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null };
  }, [clubId]);
  const list = usePaginatedSearch<InviteCode>({ fetchPage, getKey: (c) => String(c.id) });
  const { state, view, mutate } = list;
  const codes = useMemo(() => sortInviteCodes(state.items), [state.items]);

  // Create form
  const [formOpen, setFormOpen] = useState(false);
  const [label, setLabel] = useState('');
  const [maxUses, setMaxUses] = useState('');
  const [expiry, setExpiry] = useState<ExpiryPreset>('never');
  const [grantsAdmin, setGrantsAdmin] = useState(false);
  const [creating, setCreating] = useState(false);
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');

  // Per-code UI
  const [qrCode, setQrCode] = useState<InviteCode | null>(null);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [revokeTarget, setRevokeTarget] = useState<InviteCode | null>(null);
  const [revoking, setRevoking] = useState(false);
  const [revokeError, setRevokeError] = useState('');

  const ownerMayGrantAdmin = canGrantAdmin(role);

  useLayoutEffect(() => {
    navigation.setOptions({
      headerRight: () => (
        <TouchableOpacity onPress={() => { setFormOpen((v) => !v); setFormError(''); }} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }} accessibilityRole="button">
          <Text style={{ color: theme.primary, fontSize: 16, fontWeight: '700' }}>{t('clubs.invites.newCode')}</Text>
        </TouchableOpacity>
      ),
    });
  }, [navigation, t, theme.primary]);

  async function handleCreate() {
    if (creating) return;
    const built = buildInviteCodeBody({ label, maxUses, expiry, grantsAdmin }, role, Date.now());
    if (!built.ok) {
      setFormError(t(built.errorKey, { max: built.errorKey.endsWith('labelTooLong') ? INVITE_LABEL_MAX : INVITE_MAX_USES_LIMIT }));
      return;
    }
    setCreating(true);
    setFormError('');
    try {
      const res = await clubApi.createInviteCode(clubId, built.body);
      mutate((items) => [res.data, ...items]);
      setFormOpen(false);
      setLabel(''); setMaxUses(''); setExpiry('never'); setGrantsAdmin(false);
      setNotice(t('clubs.invites.created'));
      setQrCode(res.data);
    } catch (e: unknown) {
      setFormError(getClubErrorMessage(e, t('clubs.invites.form.createFailed')));
    } finally {
      setCreating(false);
    }
  }

  async function handleRevoke() {
    if (!revokeTarget || revoking) return;
    setRevoking(true);
    setRevokeError('');
    try {
      const res = await clubApi.revokeInviteCode(clubId, revokeTarget.id);
      mutate((items) => items.map((c) => (c.id === res.data.id ? res.data : c)));
      setRevokeTarget(null);
    } catch (e: unknown) {
      setRevokeError(getClubErrorMessage(e, t('clubs.invites.revokeFailed')));
    } finally {
      setRevoking(false);
    }
  }

  async function handleCopy(code: InviteCode) {
    await Clipboard.setStringAsync(code.code);
    setCopiedId(code.id);
    setTimeout(() => setCopiedId((id) => (id === code.id ? null : id)), 2000);
  }

  async function handleShare(code: InviteCode) {
    try {
      await Share.share({ message: buildInviteShareMessage({ clubName: clubName ?? '', code: code.code }, locale) });
    } catch {
      // The user closed the sheet or sharing is unavailable: nothing to report.
    }
  }

  const now = Date.now();
  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View>{[0, 1, 2].map((i) => <ClubCardSkeleton key={i} />)}</View>;
  } else if (view === 'error') {
    body = (
      <EmptyState
        icon="📡"
        title={t('clubs.invites.loadError')}
        message={getClubErrorMessage(state.error, t('clubs.invites.loadError'))}
        actions={[{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty' || view === 'noResults') {
    body = <EmptyState icon="🎟️" message={t('clubs.invites.empty')} actions={[{ label: t('clubs.invites.newCode'), onPress: () => setFormOpen(true) }]} />;
  }

  const header = (
    <View>
      <Text style={styles.intro}>{t('clubs.invites.intro', { club: clubName ?? '' })}</Text>
      {notice ? <InlineBanner text={notice} tone="success" /> : null}
      {view === 'list' && state.error ? <InlineBanner text={getClubErrorMessage(state.error, t('clubs.invites.loadError'))} tone="warning" /> : null}
      {formOpen ? (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>{t('clubs.invites.createTitle')}</Text>
          <Text style={styles.fieldLabel}>{t('clubs.invites.form.label')}</Text>
          <TextInput
            value={label}
            onChangeText={setLabel}
            placeholder={t('clubs.invites.form.labelPlaceholder')}
            placeholderTextColor={theme.textMuted}
            maxLength={INVITE_LABEL_MAX}
            style={styles.input}
          />
          <Text style={styles.fieldLabel}>{t('clubs.invites.form.maxUses')}</Text>
          <TextInput
            value={maxUses}
            onChangeText={(v) => setMaxUses(v.replace(/[^0-9]/g, ''))}
            placeholder={t('clubs.invites.form.maxUsesPlaceholder')}
            placeholderTextColor={theme.textMuted}
            keyboardType="number-pad"
            maxLength={5}
            style={styles.input}
          />
          <Text style={styles.fieldLabel}>{t('clubs.invites.form.expiry')}</Text>
          <SegmentedControl<ExpiryPreset>
            segments={EXPIRY_PRESETS.map((p) => ({ key: p.key, label: t(EXPIRY_LABEL_KEYS[p.key]) }))}
            value={expiry}
            onChange={setExpiry}
          />
          {ownerMayGrantAdmin ? (
            <>
              <Text style={styles.fieldLabel}>{t('clubs.invites.form.role')}</Text>
              <SegmentedControl<'member' | 'admin'>
                segments={[
                  { key: 'member', label: t('clubs.roles.member') },
                  { key: 'admin', label: t('clubs.roles.admin') },
                ]}
                value={grantsAdmin ? 'admin' : 'member'}
                onChange={(k) => setGrantsAdmin(k === 'admin')}
              />
              {grantsAdmin ? <Text style={styles.warning}>{t('clubs.invites.form.adminHint')}</Text> : null}
            </>
          ) : null}
          {formError ? <Text style={styles.error}>{formError}</Text> : null}
          <View style={styles.formButtons}>
            <AppButton title={t('common.buttons.cancel')} onPress={() => setFormOpen(false)} variant="secondary" disabled={creating} style={styles.flex1} />
            <AppButton title={t('clubs.invites.form.create')} onPress={() => { void handleCreate(); }} loading={creating} style={styles.flex1} />
          </View>
        </View>
      ) : null}
    </View>
  );

  return (
    <Screen padding={false}>
      <FlatList<InviteCode>
        data={view === 'list' ? codes : []}
        keyExtractor={(c) => String(c.id)}
        renderItem={({ item }) => {
          const status = inviteCodeStatus(item, now);
          const usable = status === 'active';
          const statusColor = usable ? theme.success : theme.textMuted;
          return (
            <View style={[styles.codeCard, !usable && styles.dimmed]}>
              <View style={styles.codeTop}>
                <View style={styles.flex1}>
                  <Text style={styles.code} selectable>{item.code}</Text>
                  <Text style={styles.codeLabel} numberOfLines={1}>{item.label || t('clubs.invites.untitled')}</Text>
                </View>
                <Text style={[styles.statusChip, { color: statusColor, borderColor: statusColor + '66' }]}>{t(inviteStatusKey(status))}</Text>
              </View>
              <Text style={styles.codeMeta}>{inviteCodeMeta(item, locale).join(' · ')}</Text>
              {usable ? (
                <View style={styles.actions}>
                  {item.qr_payload !== null ? (
                    // Masked codes (admin-granting, seen by a non-owner) can only be revoked.
                    <>
                      <ActionPill label={t('clubs.invites.showQr')} onPress={() => setQrCode(item)} styles={styles} />
                      <ActionPill label={copiedId === item.id ? t('common.buttons.copied') : t('common.buttons.copy')} onPress={() => { void handleCopy(item); }} styles={styles} />
                      <ActionPill label={t('common.buttons.share')} onPress={() => { void handleShare(item); }} styles={styles} />
                    </>
                  ) : null}
                  <ActionPill label={t('clubs.invites.revoke')} onPress={() => { setRevokeError(''); setRevokeTarget(item); }} styles={styles} danger />
                </View>
              ) : null}
            </View>
          );
        }}
        contentContainerStyle={styles.listContent}
        ListHeaderComponent={header}
        ListEmptyComponent={body}
        ListFooterComponent={view === 'list' ? (
          <PageFooter loading={list.loadingMore} errorText={state.pageError ? t('clubs.invites.pageError') : null} retryLabel={t('common.buttons.retry')} onRetry={list.retryMore} />
        ) : null}
        onEndReached={list.loadMore}
        onEndReachedThreshold={0.5}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />}
      />

      {/* Big QR for one code */}
      <Modal visible={qrCode !== null} transparent animationType="fade" onRequestClose={() => setQrCode(null)}>
        <Pressable style={styles.qrOverlay} onPress={() => setQrCode(null)}>
          <Pressable style={styles.qrCard} onPress={() => {}}>
            {qrCode && qrCode.qr_payload !== null ? (
              <>
                <Text style={styles.qrClub} numberOfLines={1}>{clubName}</Text>
                <View style={styles.qrBox}>
                  <QRCode value={qrCode.qr_payload} size={240} color="#000000" backgroundColor="#ffffff" />
                </View>
                <Text style={styles.qrCode} selectable>{qrCode.code}</Text>
                <Text style={styles.qrHint}>{t('clubs.invites.qrHint')}</Text>
                <View style={styles.formButtons}>
                  <AppButton title={copiedId === qrCode.id ? t('common.buttons.copied') : t('common.buttons.copy')} onPress={() => { void handleCopy(qrCode); }} variant="secondary" style={styles.flex1} />
                  <AppButton title={t('common.buttons.share')} onPress={() => { void handleShare(qrCode); }} style={styles.flex1} />
                </View>
                <AppButton title={t('common.buttons.close')} onPress={() => setQrCode(null)} variant="secondary" style={{ marginTop: spacing.sm, alignSelf: 'stretch' }} />
              </>
            ) : null}
          </Pressable>
        </Pressable>
      </Modal>

      <ConfirmModal
        visible={revokeTarget !== null}
        title={t('clubs.invites.revokeTitle', { code: revokeTarget?.code ?? '' })}
        message={t('clubs.invites.revokeMessage')}
        confirmLabel={t('clubs.invites.revokeConfirm')}
        destructive
        loading={revoking}
        errorText={revokeError || undefined}
        onConfirm={() => { void handleRevoke(); }}
        onCancel={() => setRevokeTarget(null)}
      />
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function ActionPill({ label, onPress, styles, danger = false }: { label: string; onPress: () => void; styles: Styles; danger?: boolean }) {
  return (
    <TouchableOpacity style={styles.pill} onPress={onPress} accessibilityRole="button" activeOpacity={0.8}>
      <Text style={[styles.pillText, danger && styles.pillDanger]}>{label}</Text>
    </TouchableOpacity>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    listContent: { padding: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
    flex1: { flex: 1 },
    intro: { fontSize: 13, color: theme.textSecondary, lineHeight: 19, marginBottom: spacing.md },
    card: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, marginBottom: spacing.md },
    cardTitle: { fontSize: 16, fontWeight: '800', color: theme.text, marginBottom: spacing.sm },
    fieldLabel: { fontSize: 12, fontWeight: '600', color: theme.textSecondary, marginTop: spacing.sm, marginBottom: spacing.xs },
    input: {
      height: 44, borderRadius: 10, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
      color: theme.text, paddingHorizontal: spacing.md, fontSize: 15,
    },
    warning: { fontSize: 12, color: theme.warning, marginTop: spacing.xs, lineHeight: 17 },
    error: { fontSize: 13, color: theme.danger, marginTop: spacing.sm },
    formButtons: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.md, alignSelf: 'stretch' },
    codeCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, marginBottom: spacing.sm },
    dimmed: { opacity: 0.65 },
    codeTop: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
    code: { fontSize: 22, fontWeight: '800', color: theme.text, letterSpacing: 2 },
    codeLabel: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    statusChip: { fontSize: 11, fontWeight: '700', borderWidth: 1, borderRadius: 999, paddingHorizontal: spacing.sm, paddingVertical: 1, overflow: 'hidden' },
    codeMeta: { fontSize: 12, color: theme.textMuted, marginTop: spacing.xs },
    actions: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.sm },
    pill: {
      borderRadius: 999, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
      paddingHorizontal: spacing.md, paddingVertical: spacing.xs + 2,
    },
    pillText: { fontSize: 13, fontWeight: '700', color: theme.primary },
    pillDanger: { color: theme.danger },
    qrOverlay: { flex: 1, backgroundColor: theme.overlay, alignItems: 'center', justifyContent: 'center', padding: spacing.lg },
    qrCard: {
      backgroundColor: theme.surface, borderRadius: 18, padding: spacing.lg, width: '100%', maxWidth: 380,
      alignItems: 'center', borderWidth: 1, borderColor: theme.border,
    },
    qrClub: { fontSize: 16, fontWeight: '800', color: theme.text, marginBottom: spacing.md },
    // White quiet zone so every scanner reads it, in any theme.
    qrBox: { backgroundColor: '#ffffff', padding: spacing.md, borderRadius: 12 },
    qrCode: { fontSize: 26, fontWeight: '800', color: theme.text, letterSpacing: 3, marginTop: spacing.md },
    qrHint: { fontSize: 12, color: theme.textMuted, textAlign: 'center', marginTop: spacing.xs, lineHeight: 17 },
  });
}
