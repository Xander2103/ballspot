import React, { useEffect, useState, useCallback, useRef } from 'react';
import {
  View, Text, StyleSheet, ScrollView, TouchableOpacity, Image, AppState,
} from 'react-native';
import { CommonActions } from '@react-navigation/native';
import { MainTabScreenProps } from '../app/MainTabs';
import { signOut } from '../app/signOut';
import { homeIdentity, logoutLocally, ProfileLoadState } from '../utils/session';
import { Screen } from '../components/Screen';
import { ConfirmModal } from '../components/ConfirmModal';
import { LevelRing } from '../components/home/LevelRing';
import { SportSelector } from '../components/home/SportSelector';
import { DailyChallengeCard } from '../components/home/DailyChallengeCard';
import { ModeCard } from '../components/home/ModeCard';
import { useCountdown } from '../components/home/useCountdown';
import { leagueApi } from '../api/leagueApi';
import { authApi } from '../api/authApi';
import { dailyApi } from '../api/dailyApi';
import { noticesApi } from '../api/noticesApi';
import { sportApi } from '../api/sportApi';
import { preferencesApi } from '../api/preferencesApi';
import {
  dailyCardModel, levelRingModel, sportTapAction, nextDailyTarget, nextDailyWhen,
} from '../utils/homeModel';
import { serverNow } from '../utils/serverClock';
import { getApiErrorMessage } from '../utils/apiError';
import { resolveNotice, noticePalette } from '../utils/notice';
import type { AppNotice } from '../types/notice';
import { notificationsApi } from '../api/notificationsApi';
import { notifications } from '../services/notifications';
import { applyReminderState } from '../services/reminderScheduler';
import { localFlags } from '../storage/localFlags';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { User, PlayerRank } from '../types/auth';
import type { Sport } from '../types/sport';
import { TodayResponse, DailyStats } from '../types/daily';
import { useI18n, getLocale } from '../i18n';

// BallPicker wordmark, centred at the top of Home. A transparent, trimmed cut
// of assets/BallPickerHeader.png (which has an opaque navy background that
// showed as a box on the themed screen background).
const brandHeader = require('../../assets/home/ballpicker_wordmark.png');
const packsImage = require('../../assets/home/ballpicker_packs_rustig.png');
const tournamentsImage = require('../../assets/home/ballpicker_tournaments_rustig.png');

/** Side gutter shared by the header, the sport row and the cards. */
const GUTTER = spacing.md;
/** Level ring diameter; the header mirrors it on the left so the logo stays centred. */
const RING_SIZE = 44;

type Props = MainTabScreenProps<'Play'>;

/** How long "Tennis is coming soon." stays under the sport row. */
const SPORT_NOTE_MS = 3500;
/**
 * After the countdown ends: ask /daily/today again, a little later each time,
 * until the daily is really served (activation can lag the confirmed time by
 * a moment). Play only appears once the backend says has_daily.
 */
const DAILY_RECHECK_DELAYS_MS = [5_000, 30_000, 120_000, 300_000];
/** Back from the background: refresh Home when its data is older than this. */
const FOREGROUND_REFRESH_MS = 60_000;

// Persisted flag so we ask for notification permission at most once (non-spammy).
const NOTIF_PROMPT_SEEN = 'notif_prompt_seen';

/**
 * Admin-managed notice, shown directly above the Daily card in the same card
 * language (surface, radius, border). Renders nothing when there is none, so
 * the layout below is byte-for-byte what it was before the feature.
 */
function NoticeCard({ notice, styles }: { notice: AppNotice | null; styles: Styles }) {
  const { theme } = useTheme();
  if (!notice) return null;
  const palette = noticePalette(notice.type, theme);
  return (
    <View
      style={[styles.noticeCard, { borderColor: palette.accent }]}
      accessibilityRole="text"
      accessibilityLabel={`${palette.a11yType}: ${notice.message}`}
    >
      <View style={[styles.noticeBar, { backgroundColor: palette.accent }]} />
      <Text style={styles.noticeGlyph}>{palette.glyph}</Text>
      <Text style={styles.noticeText}>{notice.message}</Text>
    </View>
  );
}

export function HomeScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  // Tournaments render in their own tab now; Home still fetches them silently
  // because the local reminder scheduling below needs the pending-action state.
  const [leagues, setLeagues] = useState<League[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  // /me on its own: 'failed' shows Retry + Logout in the header instead of a
  // "Hey, …" placeholder. A dead session never reaches 'failed' — the API
  // client raises it and the navigator resets the app to Login.
  const [profileState, setProfileState] = useState<ProfileLoadState>('loading');

  const [todayDaily, setTodayDaily] = useState<TodayResponse | null>(null);
  const [dailyStats, setDailyStats] = useState<DailyStats | null>(null);
  const [dailyLoading, setDailyLoading] = useState(true);
  // Admin notice for the daily card. Optional: a failed fetch simply shows none.
  const [notice, setNotice] = useState<AppNotice | null>(null);

  const [notifPromptVisible, setNotifPromptVisible] = useState(false);
  const [notifPromptLoading, setNotifPromptLoading] = useState(false);

  // Sport row (null until the first /sports answer) and the header level ring.
  // Both are optional extras: a failure falls back to the picker / hides the ring.
  const [sports, setSports] = useState<Sport[] | null>(null);
  const [sportsFailed, setSportsFailed] = useState(false);
  const [savingSportId, setSavingSportId] = useState<number | null>(null);
  const [sportMessage, setSportMessage] = useState<{ text: string; tone: 'info' | 'error' } | null>(null);
  const [rank, setRank] = useState<PlayerRank | null>(null);
  const lastLoadAt = useRef(0);
  const recheckTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async () => {
    lastLoadAt.current = Date.now();
    let me: User | null = null;
    // allSettled, not all: a failing tournament list must not blank the
    // greeting, and a failing profile must not hide the tournaments.
    const [meRes, listRes, sportsRes, profileStatsRes] = await Promise.allSettled([
      authApi.me(), leagueApi.list(), sportApi.list(), authApi.stats(),
    ]);
    if (meRes.status === 'fulfilled') {
      me = meRes.value;
      setUser(meRes.value);
      setProfileState('ready');
    } else {
      setProfileState((prev) => (prev === 'ready' ? 'ready' : 'failed')); // keep a name we already have
    }
    if (listRes.status === 'fulfilled') setLeagues(listRes.value);
    if (sportsRes.status === 'fulfilled') {
      setSports(sportsRes.value);
      setSportsFailed(false);
    } else {
      setSportsFailed(true); // only shown while no list was ever loaded (see render)
    }
    // Keep the last known level on a failed refresh rather than blinking the ring away.
    if (profileStatsRes.status === 'fulfilled') setRank(profileStatsRes.value.rank ?? null);
    setLoading(false);

    const [todayRes, statsRes, noticeRes] = await Promise.allSettled([
      dailyApi.today(me?.preferred_sport?.slug),
      dailyApi.stats(),
      noticesApi.active('home_daily_card'),
    ]);
    if (todayRes.status === 'fulfilled') setTodayDaily(todayRes.value);
    if (statsRes.status === 'fulfilled') setDailyStats(statsRes.value);
    // Null on failure too: the notice must never block Daily / Packs / Tournaments.
    setNotice(noticeRes.status === 'fulfilled' ? resolveNotice(noticeRes.value, getLocale()) : null);
    setDailyLoading(false);
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => navigation.addListener('focus', load), [navigation, load]);

  // Focus events do not fire when the app itself comes back to the foreground:
  // refresh then too, so a daily that went live (or a new day) shows up.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active' && navigation.isFocused() && Date.now() - lastLoadAt.current > FOREGROUND_REFRESH_MS) {
        load();
      }
    });
    return () => sub.remove();
  }, [navigation, load]);

  // "Tennis is coming soon." answers a tap; it is not a state of the row. It
  // clears itself (and when Home loses focus) so it never keeps taking room
  // under the Football selection. Save errors stay until the next tap.
  useEffect(() => {
    if (sportMessage?.tone !== 'info') return;
    const timer = setTimeout(() => setSportMessage(null), SPORT_NOTE_MS);
    return () => clearTimeout(timer);
  }, [sportMessage]);
  useEffect(() => navigation.addListener('blur', () => setSportMessage(null)), [navigation]);

  const preferredSlug = user?.preferred_sport?.slug;
  const recheckDaily = useCallback(() => {
    if (recheckTimer.current) clearTimeout(recheckTimer.current);
    let attempt = 0;
    const run = async () => {
      recheckTimer.current = null;
      let res: TodayResponse | null = null;
      try {
        res = await dailyApi.today(preferredSlug);
        setTodayDaily(res);
      } catch {
        // Offline: keep the card as it is and try again below.
      }
      const nextAt = res?.next_daily?.available_at ? Date.parse(res.next_daily.available_at) : NaN;
      const settled = !!res?.has_daily || nextAt > serverNow();
      if (!settled && attempt < DAILY_RECHECK_DELAYS_MS.length) {
        recheckTimer.current = setTimeout(run, DAILY_RECHECK_DELAYS_MS[attempt++]);
      }
    };
    recheckTimer.current = setTimeout(run, DAILY_RECHECK_DELAYS_MS[attempt++]);
  }, [preferredSlug]);
  useEffect(() => () => { if (recheckTimer.current) clearTimeout(recheckTimer.current); }, []);

  const dailyModel = dailyCardModel(dailyLoading, todayDaily, dailyStats);
  const nextTarget = nextDailyTarget(dailyModel, todayDaily);
  const countdown = useCountdown(nextTarget, recheckDaily);

  // One-time, non-spammy notification permission prompt after the app is in use.
  // If already granted, make sure the push token is registered. Web no-ops.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      notifications.configureHandler();
      if (!notifications.isSupported()) return;
      const status = await notifications.getPermissionStatus();
      if (cancelled) return;
      if (status === 'granted') {
        notifications.registerPushToken();
      } else if (status === 'undetermined') {
        const seen = await localFlags.get(NOTIF_PROMPT_SEEN);
        if (!cancelled && !seen) setNotifPromptVisible(true);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  // Once today's daily + tournament data is loaded, (re)schedule local reminders
  // to match: suppress the daily reminder if it's already done, enable the
  // tournament reminder only when there's a pending action. Runs on every focus
  // so completing a challenge cancels its reminder.
  useEffect(() => {
    if (loading || dailyLoading) return;
    let cancelled = false;
    (async () => {
      if (!notifications.isSupported()) return;
      if ((await notifications.getPermissionStatus()) !== 'granted') return;
      const settings = await notificationsApi.getSettings().catch(() => null);
      if (cancelled || !settings) return;
      await applyReminderState(settings, todayDaily, leagues);
    })();
    return () => { cancelled = true; };
  }, [loading, dailyLoading, todayDaily, leagues]);

  async function handleEnableNotifications() {
    setNotifPromptLoading(true);
    try {
      const status = await notifications.requestPermission();
      await localFlags.set(NOTIF_PROMPT_SEEN, '1');
      if (status === 'granted') {
        await notifications.registerPushToken();
        const settings = await notificationsApi.getSettings().catch(() => null);
        if (settings) await applyReminderState(settings, todayDaily, leagues);
      }
    } finally {
      setNotifPromptLoading(false);
      setNotifPromptVisible(false);
    }
  }

  async function handleDismissNotifications() {
    await localFlags.set(NOTIF_PROMPT_SEEN, '1');
    setNotifPromptVisible(false);
  }

  function retryProfile() {
    setProfileState('loading');
    load();
  }

  async function handleLogout() {
    // Best-effort server calls, then the local wipe — never blocked by a dead
    // network or a dead token (that is exactly when this button matters).
    await logoutLocally({
      unregisterPush: () => notifications.unregisterPushToken(),
      apiLogout: () => authApi.logout(),
      clearLocal: signOut,
    });
    navigation.dispatch(CommonActions.reset({ index: 0, routes: [{ name: 'Login' }] }));
  }

  const sport = user?.preferred_sport ?? null;
  const identity = homeIdentity(profileState, user);
  const level = levelRingModel(rank);

  // Same rules and API call as SportSelectionScreen: coming-soon sports only
  // explain themselves, the current sport is a no-op, anything else is saved
  // as the preferred sport and Home reloads its daily for it.
  async function handleSportTap(next: Sport) {
    if (savingSportId !== null) return;
    const action = sportTapAction(next, sport?.id);
    if (action === 'comingSoon') {
      setSportMessage({ text: t('home.sportSelection.comingSoonError', { sport: next.name }), tone: 'info' });
      return;
    }
    setSportMessage(null);
    if (action === 'none') return;
    setSavingSportId(next.id);
    try {
      const prefs = await preferencesApi.update({ preferred_sport_id: next.id });
      setUser((prev) => (prev ? { ...prev, preferred_sport: prefs.preferred_sport ?? next } : prev));
      setDailyLoading(true);
      await load();
    } catch (e: unknown) {
      setSportMessage({ text: getApiErrorMessage(e, t('home.sportSelection.saveError')), tone: 'error' });
    } finally {
      setSavingSportId(null);
    }
  }

  return (
    <Screen padding={false}>
      <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>
        {/* Header: centred wordmark with the level ring on the right (an
            equal spacer on the left keeps the logo optically centred). */}
        <View style={styles.brandRow}>
          <View style={styles.ringSlot} />
          <Image
            source={brandHeader}
            style={styles.brandImage}
            resizeMode="contain"
            accessibilityRole="image"
            accessibilityLabel={t('common.appName')}
          />
          <View style={styles.ringSlot}>
            {level ? <LevelRing model={level} size={RING_SIZE} onPress={() => navigation.navigate('Profile')} /> : null}
          </View>
        </View>

        <View style={styles.identity}>
          {identity.kind === 'ready' ? (
            <>
              <Text style={styles.greeting}>{identity.greeting}</Text>
              <Text style={styles.sub}>{identity.handle}</Text>
            </>
          ) : identity.kind === 'loading' ? (
            <>
              <Text style={styles.greeting}>{identity.label}</Text>
              <Text style={styles.sub}> </Text>
            </>
          ) : (
            <>
              <Text style={styles.sub}>{identity.message}</Text>
              <View style={styles.identityActions}>
                <TouchableOpacity onPress={retryProfile} hitSlop={8} accessibilityRole="button">
                  <Text style={styles.identityAction}>{identity.retryLabel}</Text>
                </TouchableOpacity>
                <Text style={styles.identityDot}>·</Text>
                <TouchableOpacity onPress={handleLogout} hitSlop={8} accessibilityRole="button">
                  <Text style={styles.identityAction}>{identity.logoutLabel}</Text>
                </TouchableOpacity>
              </View>
            </>
          )}
        </View>

        <SportSelector
          sports={sports}
          loadFailed={sportsFailed && !sports}
          selected={sport}
          savingId={savingSportId}
          onTap={handleSportTap}
          onOpenPicker={() => navigation.navigate('SportSelection', { mode: 'change', currentSportId: sport?.id ?? null })}
          message={sportMessage}
          inset={GUTTER}
        />

        <View style={styles.cards}>
          {/* Admin notice ("Daily login starts tomorrow") — sits right above the
              Daily card it talks about; absent entirely when there is none. */}
          <NoticeCard notice={notice} styles={styles} />

          <DailyChallengeCard
            model={dailyModel}
            countdown={countdown}
            nextWhen={nextDailyWhen(countdown ? nextTarget : null, serverNow())}
            onPlay={(dailyChallengeId) => navigation.navigate('DailyChallenge', { dailyChallengeId })}
            onViewResult={(dailyChallengeId) => navigation.navigate('DailyResult', { dailyChallengeId })}
          />

          {/* Secondary modes, always available — so Home never dead-ends,
              even on a day without a daily. */}
          <View style={styles.modes}>
            <ModeCard
              id="homePacksFade"
              testID="home-mode-packs"
              title={t('home.packs.title')}
              subtitle={t('home.packs.subtitle')}
              image={packsImage}
              onPress={() => navigation.navigate('Packs')}
            />
            <ModeCard
              id="homeTournamentsFade"
              testID="home-mode-tournaments"
              title={t('home.tournaments.title')}
              subtitle={t('home.tournaments.subtitle')}
              image={tournamentsImage}
              onPress={() => navigation.navigate('Tournaments')}
            />
          </View>
        </View>
      </ScrollView>

      <ConfirmModal
        visible={notifPromptVisible}
        title={t('home.notifPrompt.title')}
        message={t('home.notifPrompt.message')}
        confirmLabel={t('home.notifPrompt.confirm')}
        cancelLabel={t('home.notifPrompt.cancel')}
        onConfirm={handleEnableNotifications}
        onCancel={handleDismissNotifications}
        loading={notifPromptLoading}
      />
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    scroll: { flex: 1 },
    content: { paddingTop: spacing.sm, paddingBottom: spacing.lg },
    brandRow: {
      flexDirection: 'row', alignItems: 'center',
      paddingHorizontal: GUTTER,
    },
    ringSlot: { width: RING_SIZE, height: RING_SIZE, alignItems: 'center', justifyContent: 'center' },
    // The wordmark asset is 1985x429 with no @2x/@3x suffix, so RN reads its
    // intrinsic size as 1985x429 *dp*. An explicit height is REQUIRED: without
    // one the Image reserves that intrinsic height and swallows the screen.
    // `contain` keeps the full wordmark visible inside this box, never cropped.
    brandImage: {
      flex: 1,
      height: 40,
      marginHorizontal: spacing.sm,
    },
    identity: { paddingHorizontal: GUTTER, paddingTop: spacing.sm, paddingBottom: spacing.sm + 4 },
    greeting: { fontSize: 26, fontWeight: '800', color: theme.text },
    sub: { fontSize: 14, color: theme.textSecondary, marginTop: 1 },
    // Failed-profile header row: same type scale as the handle line, links in
    // the accent colour so it reads as part of the header, not an error card.
    identityActions: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: 2 },
    identityAction: { fontSize: 14, fontWeight: '700', color: theme.accent },
    identityDot: { fontSize: 14, color: theme.textMuted },
    cards: { paddingHorizontal: GUTTER, paddingTop: spacing.sm + 4, gap: spacing.sm + 4 },
    // Row children stretch to the tallest card, so both tiles stay the same size.
    modes: { flexDirection: 'row', gap: spacing.md - 4 },
    // Quieter than the Daily card (smaller radius, tighter padding) so it reads
    // as a note ON the daily card rather than a second card competing with it.
    // The border colour and the thin leading bar carry the type (info / warning / success).
    noticeCard: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm, overflow: 'hidden',
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingVertical: spacing.sm, paddingRight: spacing.md, paddingLeft: spacing.md + 4,
    },
    noticeBar: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 4 },
    noticeGlyph: { fontSize: 15 },
    noticeText: { flex: 1, fontSize: 13, lineHeight: 18, color: theme.text, fontWeight: '600' },
  });
}
