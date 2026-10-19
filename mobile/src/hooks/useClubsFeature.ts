import { useEffect, useState } from 'react';
import { AppState } from 'react-native';
import { configApi } from '../api/configApi';
import { isClubsFeatureEnabled } from '../utils/features';

/** Last answer of this app session, so a remounted tab bar does not flicker. Starts closed. */
let lastKnown = false;

/**
 * `features.clubs` from GET /api/config. Fails closed: hidden until the
 * backend says `true`, and a failed request keeps the last confirmed answer
 * of this session (false on a cold start). Re-checked whenever the app comes
 * back to the foreground, so switching the flag off hides the tab without a
 * restart.
 */
export function useClubsFeature(): boolean {
  const [enabled, setEnabled] = useState(lastKnown);

  useEffect(() => {
    let active = true;
    const check = () => {
      configApi
        .get()
        .then((cfg) => {
          lastKnown = isClubsFeatureEnabled(cfg);
          if (active) setEnabled(lastKnown);
        })
        .catch(() => { /* keep the last confirmed value (fail closed on a cold start) */ });
    };
    check();
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') check();
    });
    return () => {
      active = false;
      sub.remove();
    };
  }, []);

  return enabled;
}
