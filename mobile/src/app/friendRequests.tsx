import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { friendsApi } from '../api/friendsApi';

interface FriendRequestsValue {
  /** Number of open incoming friend requests (drives tab + section badges). */
  incomingCount: number;
  /** Screens that already fetched the requests push the fresh count here. */
  setIncomingCount: (count: number) => void;
  /** Re-fetch the count from the API (best effort). */
  refresh: () => Promise<void>;
}

const FriendRequestsContext = createContext<FriendRequestsValue>({
  incomingCount: 0,
  setIncomingCount: () => {},
  refresh: async () => {},
});

/**
 * Shared incoming-friend-request count. Fetched once when the tab host mounts
 * so the Friends tab badge is correct on app start; the Friends screen keeps
 * it in sync whenever it loads or the user accepts/rejects a request.
 */
export function FriendRequestsProvider({ children }: { children: React.ReactNode }) {
  const [incomingCount, setIncomingCount] = useState(0);

  const refresh = useCallback(async () => {
    try {
      const res = await friendsApi.requests();
      setIncomingCount(res.incoming.length);
    } catch {
      // Best effort — keep the last known count on network errors.
    }
  }, []);

  useEffect(() => { refresh(); }, [refresh]);

  const value = useMemo(
    () => ({ incomingCount, setIncomingCount, refresh }),
    [incomingCount, refresh]
  );

  return <FriendRequestsContext.Provider value={value}>{children}</FriendRequestsContext.Provider>;
}

export function useFriendRequests(): FriendRequestsValue {
  return useContext(FriendRequestsContext);
}
