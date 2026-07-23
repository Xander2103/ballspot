import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Linking, TouchableOpacity } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import * as ImagePicker from 'expo-image-picker';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { ConfirmModal } from '../components/ConfirmModal';
import { Avatar } from '../components/Avatar';
import { RankCard } from '../components/RankCard';
import { RecentXpCard } from '../components/RecentXpCard';
import { authApi } from '../api/authApi';
import { avatarApi } from '../api/avatarApi';
import { tokenStorage } from '../storage/tokenStorage';
import { useTheme } from '../theme/useTheme';
import { THEME_META, ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { User, ProfileStats, XpEvent } from '../types/auth';

const APP_VERSION = '1.0.0';

const API_BASE = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api';
const WEB_BASE = process.env.EXPO_PUBLIC_WEB_URL ?? API_BASE.replace(/\/api$/, '');

type Props = NativeStackScreenProps<RootStackParamList, 'Profile'>;

export function ProfileScreen({ navigation }: Props) {
  const { theme, themeName, setTheme } = useTheme();
  const styles = createStyles(theme);

  const [user, setUser] = useState<User | null>(null);
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [uploadingAvatar, setUploadingAvatar] = useState(false);
  const [avatarError, setAvatarError] = useState('');
  const [xpEvents, setXpEvents] = useState<XpEvent[]>([]);

  const loadProfile = useCallback(() => {
    // Profile shows at most 5 recent XP events.
    return Promise.all([authApi.me(), authApi.stats(), authApi.xpEvents(5).catch(() => null)])
      .then(([me, s, xp]) => { setUser(me); setStats(s); if (xp) setXpEvents(xp.data.slice(0, 5)); })
      .catch(() => {});
  }, []);

  useEffect(() => {
    loadProfile().finally(() => setLoading(false));
  }, [loadProfile]);

  // Refresh when returning (e.g. after changing sport on the selection screen).
  useEffect(() => navigation.addListener('focus', () => { loadProfile(); }), [navigation, loadProfile]);

  async function handleChangePhoto() {
    setAvatarError('');
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) {
        setAvatarError('Photo permission is needed to choose an avatar.');
        return;
      }
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        allowsEditing: true,
        aspect: [1, 1],
        quality: 0.8,
      });
      if (result.canceled || !result.assets?.length) return;

      setUploadingAvatar(true);
      const { avatar_url } = await avatarApi.upload(result.assets[0].uri);
      setUser((u) => (u ? { ...u, avatar_url } : u));
    } catch (e: any) {
      setAvatarError(e?.message || 'Could not update your photo. Please try again.');
    } finally {
      setUploadingAvatar(false);
    }
  }

  async function handleLogout() {
    try { await authApi.logout(); } catch {}
    await tokenStorage.remove();
    navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
  }

  async function handleDeleteAccount() {
    if (deleting) return; // guard against double-submit
    setDeleting(true);
    setDeleteError('');
    try {
      await authApi.deleteAccount();
      await tokenStorage.remove();
      setShowDeleteModal(false);
      navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
    } catch {
      setDeleteError('Failed to delete account. Please try again or contact support.');
      setDeleting(false);
    }
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  const sport = user?.preferred_sport ?? null;

  return (
    <Screen scroll padding>
      {/* Avatar + identity */}
      <View style={styles.avatarWrap}>
        <Avatar uri={user?.avatar_url} name={user?.name} size={96} />
        <TouchableOpacity onPress={handleChangePhoto} disabled={uploadingAvatar} style={styles.changePhoto} activeOpacity={0.7}>
          {uploadingAvatar
            ? <ActivityIndicator color={theme.primary} size="small" />
            : <Text style={styles.changePhotoText}>Change photo</Text>}
        </TouchableOpacity>
      </View>
      {avatarError ? <Text style={styles.inlineError}>{avatarError}</Text> : null}
      <Text style={styles.name}>{user?.name ?? '—'}</Text>
      <Text style={styles.username}>@{user?.username ?? '—'}</Text>

      {/* Personal rank / level / XP progression */}
      {stats?.rank ? <RankCard rank={stats.rank} /> : null}

      {/* View all ranks */}
      <TouchableOpacity
        style={styles.ranksCard}
        activeOpacity={0.8}
        onPress={() => navigation.navigate('RankOverview')}
      >
        <View style={styles.ranksLeft}>
          <Text style={styles.ranksIcon}>🏅</Text>
          <View style={styles.ranksTextWrap}>
            <Text style={styles.ranksTitle}>View all ranks</Text>
            <Text style={styles.ranksSubtitle}>See every rank and how much XP you need to reach the next level.</Text>
          </View>
        </View>
        <Text style={styles.ranksAction}>View ranks ›</Text>
      </TouchableOpacity>

      {/* Trophy Room entry point */}
      <TouchableOpacity
        style={styles.ranksCard}
        activeOpacity={0.8}
        onPress={() => navigation.navigate('TrophyRoom')}
      >
        <View style={styles.ranksLeft}>
          <Text style={styles.ranksIcon}>🏆</Text>
          <View style={styles.ranksTextWrap}>
            <Text style={styles.ranksTitle}>Trophy Room</Text>
            <Text style={styles.ranksSubtitle}>See your badges, achievements and top finishes.</Text>
          </View>
        </View>
        <Text style={styles.ranksAction}>Open ›</Text>
      </TouchableOpacity>

      {/* Recent XP activity — capped at 5 items */}
      <Text style={styles.sectionTitle}>Recent XP</Text>
      {xpEvents.length > 0 ? (
        <RecentXpCard events={xpEvents} />
      ) : (
        <View style={styles.emptyCard}>
          <Text style={styles.emptyText}>No XP activity yet.</Text>
        </View>
      )}

      {/* Sport preference */}
      <Text style={styles.sectionTitle}>Your sport</Text>
      <TouchableOpacity
        style={styles.rowCard}
        activeOpacity={0.8}
        onPress={() => navigation.navigate('SportSelection', { mode: 'change', currentSportId: sport?.id ?? null })}
      >
        <View style={styles.rowLeft}>
          <Text style={styles.sportEmoji}>{sport?.emoji ?? '🎯'}</Text>
          <Text style={styles.rowLabel}>{sport?.name ?? 'Not chosen yet'}</Text>
        </View>
        <Text style={styles.rowAction}>Change sport ›</Text>
      </TouchableOpacity>

      {/* App theme */}
      <Text style={styles.sectionTitle}>App theme</Text>
      <View style={styles.themeGrid}>
        {THEME_META.map((meta) => {
          const active = meta.name === themeName;
          return (
            <TouchableOpacity
              key={meta.name}
              activeOpacity={0.8}
              onPress={() => setTheme(meta.name)}
              style={[styles.themeCard, active && { borderColor: theme.primary, borderWidth: 2 }]}
            >
              <View style={styles.swatchRow}>
                {meta.swatches.map((c, i) => (
                  <View key={i} style={[styles.swatch, { backgroundColor: c }]} />
                ))}
              </View>
              <Text style={styles.themeLabel}>{meta.label}</Text>
              <Text style={styles.themeDesc}>{meta.description}</Text>
              {active ? <Text style={styles.themeActive}>Active</Text> : null}
            </TouchableOpacity>
          );
        })}
      </View>

      {/* Stats */}
      {stats ? (
        <>
          <Text style={styles.sectionTitle}>Stats</Text>
          <View style={styles.statsGrid}>
            <StatBox styles={styles} label="Tournaments" value={stats.tournaments_count} />
            <StatBox styles={styles} label="Completed" value={stats.completed_tournaments_count} />
            <StatBox styles={styles} label="Guesses" value={stats.guesses_count} />
            <StatBox styles={styles} label="Total Score" value={stats.total_score} />
            <StatBox styles={styles} label="Avg Score" value={stats.average_score} />
          </View>

          <View style={styles.dailySection}>
            <Text style={styles.sectionTitle}>Daily Challenge Stats</Text>
            <View style={styles.statsGrid}>
              <StatBox styles={styles} label="Streak" value={`${stats.current_daily_streak}d (best: ${stats.best_daily_streak})`} />
              <StatBox styles={styles} label="Played" value={stats.daily_challenges_played} />
              <StatBox styles={styles} label="Avg Score" value={stats.average_daily_score} />
              <StatBox styles={styles} label="Best Score" value={stats.best_daily_score} />
            </View>
          </View>
        </>
      ) : null}

      <AppButton title="Logout" onPress={handleLogout} variant="secondary" style={styles.logoutBtn} />

      {/* Settings section */}
      <Text style={styles.sectionTitle}>Settings</Text>
      <View style={styles.settingsCard}>
        <LegalRow styles={styles} label="Privacy Policy" url={`${WEB_BASE}/privacy`} />
        <View style={styles.divider} />
        <LegalRow styles={styles} label="Terms of Service" url={`${WEB_BASE}/terms`} />
        <View style={styles.divider} />
        <LegalRow styles={styles} label="Support" url={`${WEB_BASE}/support`} />
      </View>

      <AppButton title="Delete account" onPress={() => setShowDeleteModal(true)} variant="danger" style={styles.deleteBtn} />

      <Text style={styles.versionText}>v{APP_VERSION}</Text>

      <ConfirmModal
        visible={showDeleteModal}
        title="Delete account?"
        message="This will remove your account access and anonymize your profile. This action cannot be undone."
        confirmLabel="Delete account"
        cancelLabel="Cancel"
        onConfirm={handleDeleteAccount}
        onCancel={() => { setShowDeleteModal(false); setDeleteError(''); }}
        loading={deleting}
        errorText={deleteError}
        destructive
      />
    </Screen>
  );
}

function StatBox({ styles, label, value }: { styles: Styles; label: string; value: string | number }) {
  return (
    <View style={styles.statBox}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

function LegalRow({ styles, label, url }: { styles: Styles; label: string; url: string }) {
  return (
    <TouchableOpacity style={styles.legalRow} onPress={() => Linking.openURL(url)} activeOpacity={0.7}>
      <Text style={styles.legalLabel}>{label}</Text>
      <Text style={styles.legalChevron}>›</Text>
    </TouchableOpacity>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    avatarWrap: { alignItems: 'center', marginBottom: spacing.sm },
    changePhoto: { marginTop: spacing.sm, paddingVertical: 6, paddingHorizontal: spacing.md },
    changePhotoText: { color: theme.accent, fontSize: 14, fontWeight: '700' },
    inlineError: { color: theme.danger, fontSize: 13, textAlign: 'center', marginBottom: spacing.sm },
    name: { fontSize: 24, fontWeight: '800', color: theme.text, textAlign: 'center', marginBottom: 4 },
    username: { fontSize: 14, color: theme.textSecondary, textAlign: 'center', marginBottom: spacing.xl },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.md },
    ranksCard: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, marginBottom: spacing.xl,
    },
    ranksLeft: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, flex: 1, marginRight: spacing.sm },
    ranksIcon: { fontSize: 24 },
    ranksTextWrap: { flex: 1 },
    ranksTitle: { fontSize: 16, fontWeight: '700', color: theme.text },
    ranksSubtitle: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    ranksAction: { fontSize: 13, fontWeight: '700', color: theme.accent },
    emptyCard: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, marginBottom: spacing.xl, alignItems: 'center',
    },
    emptyText: { fontSize: 13, color: theme.textMuted, fontStyle: 'italic' },
    rowCard: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, marginBottom: spacing.xl,
    },
    rowLeft: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
    sportEmoji: { fontSize: 24 },
    rowLabel: { fontSize: 16, fontWeight: '700', color: theme.text },
    rowAction: { fontSize: 14, fontWeight: '700', color: theme.accent },
    themeGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.xl },
    themeCard: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, minWidth: '46%', flex: 1,
    },
    swatchRow: { flexDirection: 'row', gap: 6, marginBottom: spacing.sm },
    swatch: { width: 22, height: 22, borderRadius: 6, borderWidth: 1, borderColor: theme.border },
    themeLabel: { fontSize: 15, fontWeight: '700', color: theme.text },
    themeDesc: { fontSize: 12, color: theme.textMuted, marginTop: 2 },
    themeActive: { fontSize: 12, fontWeight: '700', color: theme.primary, marginTop: spacing.sm },
    statsGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.xl },
    statBox: {
      backgroundColor: theme.surface, borderRadius: 12, padding: spacing.md, alignItems: 'center',
      borderWidth: 1, borderColor: theme.border, minWidth: '45%', flex: 1,
    },
    statValue: { fontSize: 28, fontWeight: '800', color: theme.primary, marginBottom: 4 },
    statLabel: { fontSize: 12, color: theme.textSecondary, textAlign: 'center' },
    dailySection: { marginBottom: spacing.xl },
    logoutBtn: { marginBottom: spacing.xl },
    settingsCard: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      marginBottom: spacing.xl, overflow: 'hidden',
    },
    legalRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: spacing.md, paddingVertical: spacing.md },
    legalLabel: { fontSize: 15, color: theme.text },
    legalChevron: { fontSize: 18, color: theme.textMuted, lineHeight: 22 },
    divider: { height: 1, backgroundColor: theme.border, marginHorizontal: spacing.md },
    deleteBtn: { marginBottom: spacing.md },
    deleteError: { color: theme.danger, fontSize: 13, textAlign: 'center', marginBottom: spacing.sm },
    versionText: { fontSize: 12, color: theme.textMuted, textAlign: 'center', marginBottom: spacing.md },
  });
}
