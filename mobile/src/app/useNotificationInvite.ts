import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Linking } from 'react-native';
import { notifications } from '../services/notifications';
import { notificationInvites } from '../services/notificationInvites';
import {
  canDeliver,
  decideInvite,
  markInviteAccepted,
  markInviteShown,
  type DevicePermission,
  type InviteDecision,
  type InviteState,
} from '../utils/notificationPermission';

/** Home is on screen and settled before an invitation appears over it. */
const SETTLE_MS = 1500;

interface Options {
  navigation: { isFocused: () => boolean; addListener: (type: 'focus', cb: () => void) => () => void };
  userId: number | null;
  /** Home's own data has finished loading. */
  ready: boolean;
}

/**
 * Home's notification invitation. It only ever appears on a focused, loaded
 * Home, so never during a challenge (those screens sit on top of Home). The
 * permission is re-read on every focus and return from the background, so a
 * change in the OS settings reaches the push registration and the reminders.
 */
export function useNotificationInvite({ navigation, userId, ready }: Options) {
  const [permission, setPermission] = useState<DevicePermission | null>(null);
  const [invite, setInvite] = useState<Exclude<InviteDecision, 'none'> | null>(null);
  const [busy, setBusy] = useState(false);

  const shown = useRef<InviteState | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  // The OS dialog sends the app inactive and back: not a return to re-evaluate.
  const requesting = useRef(false);

  const clearTimer = () => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
  };

  const evaluate = useCallback(async () => {
    if (!notifications.isSupported() || requesting.current) return;
    const status = await notifications.reconcile();
    setPermission(status);
    if (canDeliver(status)) {
      clearTimer();
      setInvite(null);
      return;
    }
    if (!ready || userId === null || shown.current) return;

    const state = await notificationInvites.load(userId, status);
    const decision = decideInvite(state, status, Date.now());
    if (decision === 'none') return;

    clearTimer();
    timer.current = setTimeout(async () => {
      if (!navigation.isFocused() || AppState.currentState !== 'active' || shown.current) return;
      const next = markInviteShown(state, Date.now());
      shown.current = next;
      await notificationInvites.save(userId, next);
      setInvite(decision);
    }, SETTLE_MS);
  }, [navigation, ready, userId]);

  useEffect(() => { notifications.configureHandler(); }, []);
  useEffect(() => { evaluate(); }, [evaluate]);
  useEffect(() => navigation.addListener('focus', evaluate), [navigation, evaluate]);
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        evaluate();
      } else {
        clearTimer();
      }
    });
    return () => { sub.remove(); clearTimer(); };
  }, [evaluate]);

  const close = useCallback(() => {
    shown.current = null;
    setInvite(null);
  }, []);

  /** "Maybe later": the record already says deferred, and the OS is not asked. */
  const onLater = close;

  const onAccept = useCallback(async () => {
    if (shown.current && userId !== null) {
      await notificationInvites.save(userId, markInviteAccepted(shown.current));
    }
    if (invite === 'openSettings') {
      close();
      Linking.openSettings().catch(() => {});
      return;
    }
    setBusy(true);
    requesting.current = true;
    try {
      await notifications.requestPermission();
      setPermission(await notifications.reconcile());
    } finally {
      requesting.current = false;
      setBusy(false);
      close();
    }
  }, [close, invite, userId]);

  return { permission, invite, busy, onAccept, onLater };
}
