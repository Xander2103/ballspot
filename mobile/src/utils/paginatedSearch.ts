/**
 * Pure state machine behind `usePaginatedSearch` (src/hooks): server-side
 * search + cursor pagination for long lists (My Clubs, members, packs,
 * leaderboards). Unit tested; the hook only adds timers, abort signals and
 * React state.
 *
 * Rules:
 *  - Every request carries a sequence number. Only the response of the LATEST
 *    request is applied — a slow response for "ab" can never overwrite the
 *    results for "abc" (stale-response guard).
 *  - A new search / refresh keeps the current items on screen until its
 *    response arrives (no flicker), then replaces them.
 *  - "Load more" appends the next page, skipping items already loaded (a row
 *    that moved between pages never appears twice).
 *  - A failed request never throws away loaded items: a failed first page or
 *    search shows the error state (with the old items still available), a
 *    failed next page shows an inline retry row under the list.
 */

/** Longest `q` the API accepts (contract: trimmed, ≤ 60 chars). */
export const SEARCH_QUERY_MAX = 60;
export const SEARCH_DEBOUNCE_MS = 300;

export type RequestKind = 'initial' | 'search' | 'refresh' | 'reload' | 'more';

export interface PaginatedState<T, M = unknown> {
  /** The query the current `items` belong to (normalised). */
  query: string;
  /** The query of the request in flight (normalised); equals `query` when idle. */
  pendingQuery: string;
  items: T[];
  nextCursor: string | null;
  meta: M | null;
  /** The request currently in flight, or null when idle. */
  inFlight: RequestKind | null;
  /** Sequence number of the latest request; responses with another seq are stale. */
  seq: number;
  /** True once any first page (for any query) has loaded successfully. */
  hasLoaded: boolean;
  /** Pages loaded for the current query (1 after a first page, +1 per "load more"). */
  pages: number;
  /** Error of the latest first-page / search / refresh request. */
  error: unknown | null;
  /** Error of the latest "load more" request (the loaded items stay). */
  pageError: unknown | null;
}

export type PaginatedAction<T, M = unknown> =
  | { type: 'request'; seq: number; kind: RequestKind; query: string }
  | { type: 'success'; seq: number; kind: RequestKind; query: string; items: T[]; nextCursor: string | null; meta?: M | null }
  | { type: 'failure'; seq: number; kind: RequestKind; error: unknown }
  /** Local edit of the loaded rows (optimistic update / revert, prepend a created item). */
  | { type: 'mutate'; update: (items: T[]) => T[] }
  | { type: 'reset' };

export function initialPaginatedState<T, M = unknown>(query = ''): PaginatedState<T, M> {
  const q = normaliseSearchQuery(query);
  return {
    query: q, pendingQuery: q, items: [], nextCursor: null, meta: null,
    inFlight: null, seq: 0, hasLoaded: false, pages: 0, error: null, pageError: null,
  };
}

/** Trim, collapse inner whitespace and cap at the API's 60 characters. */
export function normaliseSearchQuery(raw: string | null | undefined): string {
  return String(raw ?? '').trim().replace(/\s+/g, ' ').slice(0, SEARCH_QUERY_MAX).trim();
}

/** Remove duplicate keys, keeping the first occurrence. */
export function uniqueByKey<T>(items: T[], getKey: (item: T) => string): T[] {
  const seen = new Set<string>();
  const out: T[] = [];
  for (const item of items) {
    const key = getKey(item);
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(item);
  }
  return out;
}

/** Append `next` to `current`, skipping items whose key is already loaded. */
export function appendUnique<T>(current: T[], next: T[], getKey: (item: T) => string): T[] {
  return uniqueByKey([...current, ...next], getKey);
}

export function paginatedReducer<T, M = unknown>(
  state: PaginatedState<T, M>,
  action: PaginatedAction<T, M>,
  getKey: (item: T) => string,
): PaginatedState<T, M> {
  switch (action.type) {
    case 'reset':
      return initialPaginatedState<T, M>();

    case 'mutate': {
      // In-flight responses still apply afterwards (their seq is unchanged):
      // a refresh always reflects the server, edits are only a preview.
      const items = uniqueByKey(action.update(state.items), getKey);
      // Prepending into an empty, loaded list must leave the empty state.
      return { ...state, items };
    }

    case 'request':
      return {
        ...state,
        seq: action.seq,
        inFlight: action.kind,
        pendingQuery: action.kind === 'more' ? state.query : action.query,
        // A new attempt clears the matching error; items stay on screen.
        error: action.kind === 'more' ? state.error : null,
        pageError: null,
      };

    case 'success': {
      if (action.seq !== state.seq) return state; // stale response
      const items = action.kind === 'more'
        ? appendUnique(state.items, action.items, getKey)
        : uniqueByKey(action.items, getKey);
      return {
        ...state,
        query: action.kind === 'more' ? state.query : action.query,
        pendingQuery: action.kind === 'more' ? state.query : action.query,
        items,
        nextCursor: action.nextCursor ?? null,
        // Page meta (totals, "your position") comes from the first page;
        // later pages may repeat it — keep the newest one we got.
        meta: action.meta !== undefined ? action.meta : state.meta,
        inFlight: null,
        hasLoaded: true,
        pages: action.kind === 'more' ? state.pages + 1 : 1,
        error: null,
        pageError: null,
      };
    }

    case 'failure': {
      if (action.seq !== state.seq) return state; // stale failure
      if (action.kind === 'more') {
        return { ...state, inFlight: null, pageError: action.error };
      }
      return { ...state, inFlight: null, pendingQuery: state.query, error: action.error };
    }
  }
}

/**
 * Should a silent refresh (screen focus) run? Not while a search/refresh is in
 * flight, and not once the player scrolled past the first page — replacing a
 * long list with page 1 would yank them back to the top. Pull-to-refresh
 * still reloads from the start.
 */
export function shouldSilentReload<T, M>(state: PaginatedState<T, M>): boolean {
  if (state.inFlight !== null) return false;
  return state.pages <= 1;
}

/** True when the list may ask for the next page now. */
export function canLoadMore<T, M>(state: PaginatedState<T, M>): boolean {
  return !!state.nextCursor && state.inFlight === null && state.pageError === null && state.error === null;
}

export type PaginatedView = 'skeleton' | 'error' | 'empty' | 'noResults' | 'list';

/**
 * Which body the screen renders. `skeleton` only before anything ever loaded;
 * afterwards a search in flight keeps showing the previous results. Loaded
 * items always win over an error: the screen then shows the error as a
 * banner/inline row above or below the list instead.
 */
export function paginatedView<T, M>(state: PaginatedState<T, M>): PaginatedView {
  if (state.items.length > 0) return 'list';
  if (state.error) return 'error';
  if (!state.hasLoaded) return 'skeleton';
  return state.query ? 'noResults' : 'empty';
}
