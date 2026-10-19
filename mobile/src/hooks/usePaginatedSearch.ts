import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import {
  canLoadMore, initialPaginatedState, normaliseSearchQuery, paginatedReducer, paginatedView, shouldSilentReload,
  SEARCH_DEBOUNCE_MS,
  type PaginatedAction, type PaginatedState, type PaginatedView, type RequestKind,
} from '../utils/paginatedSearch';

export interface PageRequest {
  /** Normalised search text ('' = no filter). */
  query: string;
  cursor: string | null;
  signal?: AbortSignal;
}

export interface PageResult<T, M> {
  items: T[];
  nextCursor: string | null;
  meta?: M | null;
}

interface Options<T, M> {
  fetchPage: (req: PageRequest) => Promise<PageResult<T, M>>;
  getKey: (item: T) => string;
  debounceMs?: number;
  /** Load the first page on mount (default true). Screens that refresh on focus pass false and call reload(). */
  autoLoad?: boolean;
}

export interface PaginatedSearch<T, M> {
  /** What the search field shows (raw text). */
  text: string;
  setText: (text: string) => void;
  /** Empty the field and immediately show the unfiltered list. */
  clear: () => void;
  state: PaginatedState<T, M>;
  view: PaginatedView;
  /** Pull-to-refresh spinner. */
  refreshing: boolean;
  /** A first page / search is in flight (small spinner in the search field). */
  searching: boolean;
  loadingMore: boolean;
  /** FlatList onEndReached: next page if there is one and nothing is in flight. */
  loadMore: () => void;
  /** Retry a failed next page (the inline retry row). */
  retryMore: () => void;
  /** Pull-to-refresh. */
  refresh: () => void;
  /** Silent refresh (screen focus); does the first load if nothing loaded yet. */
  reload: () => void;
}

/**
 * Server-side search + cursor pagination for long lists. The pure state rules
 * (stale-response guard, append without duplicates, error keeps items) live
 * in utils/paginatedSearch.ts; this hook adds the 300 ms debounce, aborts
 * superseded requests and wires FlatList callbacks.
 */
export function usePaginatedSearch<T, M = unknown>({
  fetchPage, getKey, debounceMs = SEARCH_DEBOUNCE_MS, autoLoad = true,
}: Options<T, M>): PaginatedSearch<T, M> {
  const getKeyRef = useRef(getKey);
  getKeyRef.current = getKey;
  const fetchRef = useRef(fetchPage);
  fetchRef.current = fetchPage;

  const reducer = useCallback(
    (s: PaginatedState<T, M>, a: PaginatedAction<T, M>) => paginatedReducer(s, a, (item: T) => getKeyRef.current(item)),
    [],
  );
  const [state, dispatch] = useReducer(reducer, undefined, () => initialPaginatedState<T, M>());
  const stateRef = useRef(state);
  stateRef.current = state;

  const [text, setTextState] = useState('');
  const seqRef = useRef(0);
  const abortRef = useRef<AbortController | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const mounted = useRef(true);
  /** The query the latest first-page request asked for (debounce skips no-op searches). */
  const requestedQuery = useRef('');

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      if (timerRef.current) clearTimeout(timerRef.current);
      abortRef.current?.abort();
    };
  }, []);

  const run = useCallback((kind: RequestKind, query: string) => {
    const current = stateRef.current;
    const cursor = kind === 'more' ? current.nextCursor : null;
    if (kind === 'more' && !cursor) return;

    // A first page / search supersedes everything in flight; "more" only runs when idle.
    abortRef.current?.abort();
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    abortRef.current = controller;

    const seq = ++seqRef.current;
    const q = kind === 'more' ? current.query : query;
    if (kind !== 'more') requestedQuery.current = q;
    dispatch({ type: 'request', seq, kind, query: q });

    fetchRef.current({ query: q, cursor, signal: controller?.signal })
      .then((page) => {
        if (!mounted.current) return;
        dispatch({ type: 'success', seq, kind, query: q, items: page.items, nextCursor: page.nextCursor, meta: page.meta });
      })
      .catch((error: unknown) => {
        if (!mounted.current) return;
        // Superseded/aborted requests are dropped by the reducer's seq check.
        dispatch({ type: 'failure', seq, kind, error });
      });
  }, []);

  // Debounced search: only when the normalised text really changes.
  const setText = useCallback((value: string) => {
    setTextState(value);
    if (timerRef.current) clearTimeout(timerRef.current);
    const q = normaliseSearchQuery(value);
    timerRef.current = setTimeout(() => {
      timerRef.current = null;
      if (q !== requestedQuery.current) run('search', q);
    }, debounceMs);
  }, [debounceMs, run]);

  const clear = useCallback(() => {
    setTextState('');
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = null;
    if (requestedQuery.current !== '') run('search', '');
  }, [run]);

  useEffect(() => {
    if (autoLoad) run('initial', '');
  }, [autoLoad, run]);

  const loadMore = useCallback(() => {
    if (canLoadMore(stateRef.current)) run('more', stateRef.current.query);
  }, [run]);

  const retryMore = useCallback(() => {
    const s = stateRef.current;
    if (s.nextCursor && s.inFlight === null) run('more', s.query);
  }, [run]);

  const refresh = useCallback(() => {
    run(stateRef.current.hasLoaded ? 'refresh' : 'initial', requestedQuery.current);
  }, [run]);

  const reload = useCallback(() => {
    const s = stateRef.current;
    if (!s.hasLoaded) {
      if (s.inFlight === null) run('initial', requestedQuery.current);
      return;
    }
    if (shouldSilentReload(s)) run('reload', requestedQuery.current);
  }, [run]);

  return {
    text,
    setText,
    clear,
    state,
    view: paginatedView(state),
    refreshing: state.inFlight === 'refresh',
    searching: state.inFlight === 'initial' || state.inFlight === 'search' || state.inFlight === 'reload',
    loadingMore: state.inFlight === 'more',
    loadMore,
    retryMore,
    refresh,
    reload,
  };
}
