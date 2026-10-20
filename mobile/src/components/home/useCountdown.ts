import { useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { serverNow } from '../../utils/serverClock';
import { countdownParts, msUntilNextTick, type CountdownParts } from '../../utils/homeModel';

/**
 * Live countdown to `targetMs` on the shared server clock (utils/serverClock).
 *
 * One timeout per displayed minute instead of an interval, re-armed after each
 * tick; recomputed the moment the app returns to the foreground (JS timers do
 * not run in the background); `onDue` fires once when the time is reached,
 * also when that happened while the app was in the background. Everything is
 * torn down when the target changes or the screen unmounts.
 *
 * Returns null when there is nothing (left) to count down — never a negative time.
 */
export function useCountdown(targetMs: number | null, onDue: () => void): CountdownParts | null {
  // Only drives re-renders: the value shown is always read fresh below, so a
  // new target never flashes a time computed from a stale clock reading.
  const [, setNow] = useState(serverNow);
  const onDueRef = useRef(onDue);
  useEffect(() => { onDueRef.current = onDue; }, [onDue]);

  useEffect(() => {
    if (targetMs === null) return;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let fired = false;

    const tick = () => {
      if (timer) clearTimeout(timer);
      timer = null;
      const current = serverNow();
      setNow(current);
      const wait = msUntilNextTick(targetMs, current);
      if (wait <= 0) {
        if (!fired) {
          fired = true;
          onDueRef.current();
        }
        return;
      }
      // A little past the boundary, so the re-render lands on the new minute.
      timer = setTimeout(tick, wait + 50);
    };

    tick();
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') tick();
    });
    return () => {
      if (timer) clearTimeout(timer);
      sub.remove();
    };
  }, [targetMs]);

  return targetMs === null ? null : countdownParts(targetMs, serverNow());
}
