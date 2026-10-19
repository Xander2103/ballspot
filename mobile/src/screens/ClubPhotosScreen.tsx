import React, { useCallback, useRef, useState } from 'react';
import { View, Text, StyleSheet, FlatList, RefreshControl, TouchableOpacity, TextInput, Pressable, ActivityIndicator } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { Image } from 'expo-image';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { EmptyState } from '../components/EmptyState';
import { ClubCardSkeleton } from '../components/clubs/ClubCard';
import { InlineBanner, PageFooter } from '../components/clubs/ListStates';
import { clubApi, type UploadablePhoto } from '../api/clubApi';
import { usePaginatedSearch, type PageRequest } from '../hooks/usePaginatedSearch';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { PhotoSubmission } from '../types/club';
import {
  canUploadPhotos, MAX_PHOTOS_PER_BATCH, PHOTO_NOTE_MAX, photoStatusKey, photoStatusTone, stopsPhotoQueue,
  type StatusTone, type UploadState,
} from '../utils/clubAdmin';
import { formatClubDate } from '../utils/clubDisplay';
import { getClubErrorMessage } from '../utils/clubErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubPhotos'>;

interface QueuedPhoto extends UploadablePhoto {
  key: string;
  state: UploadState;
  error?: string;
}

const STATE_KEYS: Record<UploadState, string> = {
  queued: 'clubs.photos.state.queued',
  uploading: 'clubs.photos.state.uploading',
  uploaded: 'clubs.photos.state.uploaded',
  failed: 'clubs.photos.state.failed',
};

/**
 * Club photo submissions (upload_photos): pick photos from the library, a
 * mandatory rights/consent confirmation, an optional note, one upload per
 * photo with its own state, then the club's submissions with their review
 * status. Photos stay private: BallPicker reviews every one before use.
 */
export function ClubPhotosScreen({ route }: Props) {
  const { clubId } = route.params;
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);

  const fetchPage = useCallback(async ({ cursor, signal }: PageRequest) => {
    const res = await clubApi.photos(clubId, { cursor, signal });
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null };
  }, [clubId]);
  const list = usePaginatedSearch<PhotoSubmission>({ fetchPage, getKey: (p) => String(p.id) });
  const { state, view, mutate } = list;

  const [queue, setQueue] = useState<QueuedPhoto[]>([]);
  const [rights, setRights] = useState(false);
  const [note, setNote] = useState('');
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);
  const [message, setMessage] = useState<{ text: string; tone: StatusTone } | null>(null);
  const keySeq = useRef(0);

  const setPhotoState = (key: string, next: UploadState, error?: string) =>
    setQueue((q) => q.map((p) => (p.key === key ? { ...p, state: next, error } : p)));

  async function pickPhotos() {
    setMessage(null);
    const room = MAX_PHOTOS_PER_BATCH - queue.filter((p) => p.state !== 'uploaded').length;
    if (room <= 0) { setMessage({ text: t('clubs.photos.limit', { max: MAX_PHOTOS_PER_BATCH }), tone: 'warning' }); return; }
    try {
      // The system photo picker needs no library permission (Expo v56 docs).
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        allowsMultipleSelection: true,
        selectionLimit: room,
        quality: 0.9,
        exif: false,
        // iOS: transcode HEIC/HEIF to a compatible format (JPEG); the backend
        // accepts JPEG, PNG and WebP only.
        preferredAssetRepresentationMode: ImagePicker.UIImagePickerPreferredAssetRepresentationMode.Compatible,
      });
      if (result.canceled || !result.assets?.length) return;
      const added = result.assets.slice(0, room).map((a) => ({
        key: `p${++keySeq.current}`,
        uri: a.uri,
        fileName: a.fileName ?? null,
        mimeType: a.mimeType ?? null,
        state: 'queued' as UploadState,
      }));
      // Uploaded photos leave the queue once a new pick starts.
      setQueue((q) => [...q.filter((p) => p.state !== 'uploaded'), ...added]);
    } catch {
      setMessage({ text: t('clubs.photos.pickError'), tone: 'error' });
    }
  }

  async function uploadAll() {
    if (uploading) return;
    if (!rights) { setMessage({ text: t('clubs.photos.rightsRequired'), tone: 'warning' }); return; }
    const pending = queue.filter((p) => p.state === 'queued' || p.state === 'failed');
    if (pending.length === 0) return;
    setUploading(true);
    setMessage(null);
    let done = 0;
    let failed = 0;
    setProgress({ done, total: pending.length });
    for (const photo of pending) {
      setPhotoState(photo.key, 'uploading');
      try {
        const res = await clubApi.uploadPhoto(clubId, photo, { rightsConfirmed: rights, note });
        setPhotoState(photo.key, 'uploaded');
        mutate((items) => [res.data, ...items]);
      } catch (e: unknown) {
        failed += 1;
        setPhotoState(photo.key, 'failed', getClubErrorMessage(e, t('clubs.photos.uploadFailed')));
        if (stopsPhotoQueue(e)) {
          // The rest would fail the same way (limit, throttle, access): stop here.
          setMessage({ text: getClubErrorMessage(e, t('clubs.photos.uploadFailed')), tone: 'error' });
          break;
        }
      }
      done += 1;
      setProgress({ done, total: pending.length });
    }
    setUploading(false);
    setProgress(null);
    if (failed === 0) {
      setMessage({ text: t('clubs.photos.allSent'), tone: 'success' });
      setNote('');
    }
  }

  const states = queue.map((p) => p.state);
  const toSend = queue.filter((p) => p.state === 'queued' || p.state === 'failed').length;

  let body: React.ReactElement | null = null;
  if (view === 'skeleton') {
    body = <View>{[0, 1].map((i) => <ClubCardSkeleton key={i} />)}</View>;
  } else if (view === 'error') {
    body = (
      <EmptyState
        icon="📡"
        title={t('clubs.photos.loadError')}
        message={getClubErrorMessage(state.error, t('clubs.photos.loadError'))}
        actions={[{ label: t('common.buttons.retry'), onPress: list.refresh }]}
      />
    );
  } else if (view === 'empty' || view === 'noResults') {
    body = <Text style={styles.muted}>{t('clubs.photos.empty')}</Text>;
  }

  const toneColor = (tone: StatusTone) => (tone === 'success' ? theme.success : tone === 'error' ? theme.danger : tone === 'warning' ? theme.warning : theme.textSecondary);

  const header = (
    <View>
      <View style={styles.card}>
        <Text style={styles.cardTitle}>{t('clubs.photos.submitTitle')}</Text>
        <Text style={styles.intro}>{t('clubs.photos.intro')}</Text>

        {queue.length > 0 ? (
          <View style={styles.grid}>
            {queue.map((p) => (
              <View key={p.key} style={styles.thumbWrap}>
                <Image source={{ uri: p.uri }} style={styles.thumb} contentFit="cover" />
                <Text style={[styles.thumbState, { color: p.state === 'failed' ? theme.danger : p.state === 'uploaded' ? theme.success : theme.textSecondary }]} numberOfLines={1}>
                  {t(STATE_KEYS[p.state])}
                </Text>
                {p.state === 'uploading' ? <ActivityIndicator style={styles.thumbSpinner} color="#ffffff" /> : null}
                {p.state === 'queued' || p.state === 'failed' ? (
                  <TouchableOpacity
                    style={styles.thumbRemove}
                    onPress={() => setQueue((q) => q.filter((x) => x.key !== p.key))}
                    disabled={uploading}
                    accessibilityRole="button"
                    accessibilityLabel={t('clubs.photos.removeA11y')}
                  >
                    <Text style={styles.thumbRemoveText}>✕</Text>
                  </TouchableOpacity>
                ) : null}
                {p.error ? <Text style={styles.thumbError} numberOfLines={3}>{p.error}</Text> : null}
              </View>
            ))}
          </View>
        ) : null}

        <AppButton
          title={queue.length > 0 ? t('clubs.photos.chooseMore') : t('clubs.photos.choose')}
          onPress={() => { void pickPhotos(); }}
          variant="secondary"
          disabled={uploading}
          style={styles.spaced}
        />
        <Text style={styles.hint}>{t('clubs.photos.limit', { max: MAX_PHOTOS_PER_BATCH })}</Text>

        <Pressable
          style={styles.consent}
          onPress={() => { setRights((v) => !v); if (message?.tone === 'warning') setMessage(null); }}
          accessibilityRole="checkbox"
          accessibilityState={{ checked: rights }}
          disabled={uploading}
        >
          <Text style={[styles.checkbox, rights && styles.checkboxOn]}>{rights ? '☑' : '☐'}</Text>
          <Text style={styles.consentText}>{t('clubs.photos.rightsLabel')}</Text>
        </Pressable>

        <Text style={styles.fieldLabel}>{t('clubs.photos.noteLabel')}</Text>
        <TextInput
          value={note}
          onChangeText={setNote}
          placeholder={t('clubs.photos.notePlaceholder')}
          placeholderTextColor={theme.textMuted}
          maxLength={PHOTO_NOTE_MAX}
          multiline
          editable={!uploading}
          style={styles.note}
        />

        {message ? <Text style={[styles.message, { color: toneColor(message.tone) }]}>{message.text}</Text> : null}
        <AppButton
          title={progress
            ? t('clubs.photos.uploading', { done: progress.done, total: progress.total })
            : t('clubs.photos.upload', { count: toSend })}
          onPress={() => { void uploadAll(); }}
          loading={uploading && !progress}
          disabled={uploading || !canUploadPhotos(rights, states)}
          style={styles.spaced}
        />
      </View>

      <Text style={styles.sectionTitle}>{t('clubs.photos.submissionsTitle')}</Text>
      {view === 'list' && state.error ? <InlineBanner text={getClubErrorMessage(state.error, t('clubs.photos.loadError'))} tone="warning" /> : null}
    </View>
  );

  return (
    <Screen padding={false}>
      <FlatList<PhotoSubmission>
        data={view === 'list' ? state.items : []}
        keyExtractor={(p) => String(p.id)}
        renderItem={({ item }) => {
          const color = toneColor(photoStatusTone(item.status));
          const date = formatClubDate(item.created_at, locale);
          return (
            <View style={styles.row}>
              <View style={styles.rowThumb}>
                {item.thumbnail_url ? (
                  <Image source={{ uri: item.thumbnail_url }} style={styles.rowThumbImg} contentFit="cover" recyclingKey={String(item.id)} />
                ) : (
                  <Text style={styles.rowThumbEmoji}>🖼️</Text>
                )}
              </View>
              <View style={styles.rowBody}>
                <Text style={[styles.status, { color }]}>{t(photoStatusKey(item.status))}</Text>
                {date ? <Text style={styles.rowMeta}>{t('clubs.photos.submittedOn', { date })}</Text> : null}
                {item.club_note ? <Text style={styles.rowNote} numberOfLines={2}>{item.club_note}</Text> : null}
                {item.status === 'rejected' && item.rejection_reason ? (
                  <Text style={styles.rowReason}>{t('clubs.photos.rejectionReason', { reason: item.rejection_reason })}</Text>
                ) : null}
              </View>
            </View>
          );
        }}
        contentContainerStyle={styles.listContent}
        ListHeaderComponent={header}
        ListEmptyComponent={body}
        ListFooterComponent={view === 'list' ? (
          <PageFooter loading={list.loadingMore} errorText={state.pageError ? t('clubs.photos.pageError') : null} retryLabel={t('common.buttons.retry')} onRetry={list.retryMore} />
        ) : null}
        onEndReached={list.loadMore}
        onEndReachedThreshold={0.5}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.refresh} tintColor={theme.primary} colors={[theme.primary]} />}
      />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    listContent: { padding: spacing.md, paddingBottom: spacing.xxl, flexGrow: 1 },
    card: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, marginBottom: spacing.lg },
    cardTitle: { fontSize: 17, fontWeight: '800', color: theme.text },
    intro: { fontSize: 13, color: theme.textSecondary, lineHeight: 19, marginTop: spacing.xs },
    grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.md },
    thumbWrap: { width: '31%', flexGrow: 0 },
    thumb: { width: '100%', aspectRatio: 1, borderRadius: 10, backgroundColor: theme.surfaceElevated },
    thumbState: { fontSize: 11, fontWeight: '700', marginTop: 2 },
    thumbSpinner: { position: 'absolute', top: '35%', alignSelf: 'center' },
    thumbRemove: {
      position: 'absolute', top: 4, right: 4, width: 24, height: 24, borderRadius: 12,
      backgroundColor: 'rgba(0,0,0,0.6)', alignItems: 'center', justifyContent: 'center',
    },
    thumbRemoveText: { color: '#ffffff', fontSize: 12, fontWeight: '800' },
    thumbError: { fontSize: 11, color: theme.danger },
    spaced: { marginTop: spacing.md },
    hint: { fontSize: 12, color: theme.textMuted, marginTop: spacing.xs, textAlign: 'center' },
    consent: {
      flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm, marginTop: spacing.md, padding: spacing.sm,
      borderRadius: 10, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
    },
    checkbox: { fontSize: 22, color: theme.textMuted, lineHeight: 24 },
    checkboxOn: { color: theme.primary },
    consentText: { flex: 1, fontSize: 13, color: theme.text, lineHeight: 19 },
    fieldLabel: { fontSize: 12, fontWeight: '600', color: theme.textSecondary, marginTop: spacing.md, marginBottom: spacing.xs },
    note: {
      minHeight: 64, borderRadius: 10, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
      color: theme.text, padding: spacing.sm, fontSize: 14, textAlignVertical: 'top',
    },
    message: { fontSize: 13, marginTop: spacing.md, lineHeight: 18 },
    sectionTitle: { fontSize: 17, fontWeight: '800', color: theme.text, marginBottom: spacing.sm },
    muted: { fontSize: 13, color: theme.textMuted, paddingVertical: spacing.sm },
    row: {
      flexDirection: 'row', gap: spacing.md, paddingVertical: spacing.sm,
      borderBottomWidth: 1, borderBottomColor: theme.border,
    },
    rowThumb: {
      width: 64, height: 64, borderRadius: 10, overflow: 'hidden', backgroundColor: theme.surfaceElevated,
      alignItems: 'center', justifyContent: 'center',
    },
    rowThumbImg: { width: '100%', height: '100%' },
    rowThumbEmoji: { fontSize: 24 },
    rowBody: { flex: 1, minWidth: 0 },
    status: { fontSize: 14, fontWeight: '700' },
    rowMeta: { fontSize: 12, color: theme.textMuted, marginTop: 1 },
    rowNote: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    rowReason: { fontSize: 13, color: theme.danger, marginTop: 2 },
  });
}
