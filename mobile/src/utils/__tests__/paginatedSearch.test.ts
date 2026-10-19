/** AT-MOB-02: paginated search state — stale guard, cursor append, error keeps items, views. */
import {
  appendUnique, canLoadMore, initialPaginatedState, normaliseSearchQuery, paginatedReducer, paginatedView,
  shouldSilentReload, uniqueByKey, SEARCH_QUERY_MAX,
  type PaginatedAction, type PaginatedState,
} from '../paginatedSearch';

type Item = { id: string; name: string };
const key = (i: Item) => i.id;
const items = (...ids: string[]): Item[] => ids.map((id) => ({ id, name: `club ${id}` }));

function run(actions: PaginatedAction<Item, { total: number }>[], from = initialPaginatedState<Item, { total: number }>()) {
  return actions.reduce<PaginatedState<Item, { total: number }>>((s, a) => paginatedReducer(s, a, key), from);
}

describe('normaliseSearchQuery', () => {
  it('trims, collapses whitespace and caps at 60 characters', () => {
    expect(normaliseSearchQuery('  kfc   gent ')).toBe('kfc gent');
    expect(normaliseSearchQuery(null)).toBe('');
    expect(normaliseSearchQuery('x'.repeat(80))).toHaveLength(SEARCH_QUERY_MAX);
  });
});

describe('dedupe helpers', () => {
  it('uniqueByKey keeps the first occurrence', () => {
    expect(uniqueByKey(items('a', 'b', 'a', 'c'), key).map(key)).toEqual(['a', 'b', 'c']);
  });

  it('appendUnique skips rows already loaded', () => {
    expect(appendUnique(items('a', 'b'), items('b', 'c'), key).map(key)).toEqual(['a', 'b', 'c']);
  });
});

describe('paginatedReducer', () => {
  it('loads a first page', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a', 'b'), nextCursor: 'c2', meta: { total: 30 } },
    ]);
    expect(s.items.map(key)).toEqual(['a', 'b']);
    expect(s.nextCursor).toBe('c2');
    expect(s.meta).toEqual({ total: 30 });
    expect(s.hasLoaded).toBe(true);
    expect(s.pages).toBe(1);
    expect(s.inFlight).toBeNull();
  });

  it('appends the next page without duplicates', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a', 'b'), nextCursor: 'c2' },
      { type: 'request', seq: 2, kind: 'more', query: '' },
      { type: 'success', seq: 2, kind: 'more', query: '', items: items('b', 'c', 'd'), nextCursor: null },
    ]);
    expect(s.items.map(key)).toEqual(['a', 'b', 'c', 'd']);
    expect(s.nextCursor).toBeNull();
    expect(s.pages).toBe(2);
  });

  it('ignores a stale (slower, older) search response', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a', 'b', 'c'), nextCursor: null },
      { type: 'request', seq: 2, kind: 'search', query: 'ge' },
      { type: 'request', seq: 3, kind: 'search', query: 'gent' },
      // "gent" answers first, then the stale "ge" response arrives.
      { type: 'success', seq: 3, kind: 'search', query: 'gent', items: items('g1'), nextCursor: null },
      { type: 'success', seq: 2, kind: 'search', query: 'ge', items: items('g1', 'g2', 'g3'), nextCursor: 'x' },
    ]);
    expect(s.query).toBe('gent');
    expect(s.items.map(key)).toEqual(['g1']);
    expect(s.nextCursor).toBeNull();
  });

  it('ignores a stale failure', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'search', query: 'a' },
      { type: 'request', seq: 2, kind: 'search', query: 'ab' },
      { type: 'success', seq: 2, kind: 'search', query: 'ab', items: items('x'), nextCursor: null },
      { type: 'failure', seq: 1, kind: 'search', error: { status: 500 } },
    ]);
    expect(s.error).toBeNull();
    expect(s.items.map(key)).toEqual(['x']);
  });

  it('a "load more" response is dropped once a new search started', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: 'c2' },
      { type: 'request', seq: 2, kind: 'more', query: '' },
      { type: 'request', seq: 3, kind: 'search', query: 'z' },
      { type: 'success', seq: 2, kind: 'more', query: '', items: items('b'), nextCursor: 'c3' },
      { type: 'success', seq: 3, kind: 'search', query: 'z', items: items('z1'), nextCursor: null },
    ]);
    expect(s.items.map(key)).toEqual(['z1']);
  });

  it('keeps the current results visible while a search is in flight', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a', 'b'), nextCursor: null },
      { type: 'request', seq: 2, kind: 'search', query: 'gent' },
    ]);
    expect(s.items.map(key)).toEqual(['a', 'b']);
    expect(s.query).toBe('');
    expect(s.pendingQuery).toBe('gent');
    expect(paginatedView(s)).toBe('list');
  });

  it('a failed next page keeps the loaded items and shows an inline error', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a', 'b'), nextCursor: 'c2' },
      { type: 'request', seq: 2, kind: 'more', query: '' },
      { type: 'failure', seq: 2, kind: 'more', error: new TypeError('Network request failed') },
    ]);
    expect(s.items.map(key)).toEqual(['a', 'b']);
    expect(s.pageError).toBeInstanceOf(TypeError);
    expect(s.error).toBeNull();
    expect(s.nextCursor).toBe('c2');
    expect(canLoadMore(s)).toBe(false); // no automatic retry loop on end-reached
    expect(paginatedView(s)).toBe('list');
  });

  it('a failed refresh keeps the loaded items (banner, not a blank error screen)', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: null },
      { type: 'request', seq: 2, kind: 'refresh', query: '' },
      { type: 'failure', seq: 2, kind: 'refresh', error: { status: 503 } },
    ]);
    expect(s.items.map(key)).toEqual(['a']);
    expect(s.error).toEqual({ status: 503 });
    expect(paginatedView(s)).toBe('list');
  });

  it('a failed search keeps the previous query as the applied one', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: null },
      { type: 'request', seq: 2, kind: 'search', query: 'x' },
      { type: 'failure', seq: 2, kind: 'search', error: { status: 500 } },
    ]);
    expect(s.query).toBe('');
    expect(s.pendingQuery).toBe('');
    expect(s.items.map(key)).toEqual(['a']);
  });

  it('a retry request clears the previous page error', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: 'c2' },
      { type: 'request', seq: 2, kind: 'more', query: '' },
      { type: 'failure', seq: 2, kind: 'more', error: 'x' },
      { type: 'request', seq: 3, kind: 'more', query: '' },
    ]);
    expect(s.pageError).toBeNull();
    expect(s.inFlight).toBe('more');
  });

  it('reset returns to the initial state', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: null },
      { type: 'reset' },
    ]);
    expect(s).toEqual(initialPaginatedState());
  });
});

describe('views and guards', () => {
  it('skeleton before the first load, error when it fails', () => {
    const loading = run([{ type: 'request', seq: 1, kind: 'initial', query: '' }]);
    expect(paginatedView(loading)).toBe('skeleton');
    const failed = run([{ type: 'failure', seq: 1, kind: 'initial', error: 'x' }], loading);
    expect(paginatedView(failed)).toBe('error');
  });

  it('empty (no clubs at all) vs no results (search matched nothing)', () => {
    const empty = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: [], nextCursor: null },
    ]);
    expect(paginatedView(empty)).toBe('empty');
    const none = run([
      { type: 'request', seq: 2, kind: 'search', query: 'zzz' },
      { type: 'success', seq: 2, kind: 'search', query: 'zzz', items: [], nextCursor: null },
    ], empty);
    expect(paginatedView(none)).toBe('noResults');
    expect(none.query).toBe('zzz');
  });

  it('canLoadMore only with a cursor and nothing in flight', () => {
    const s = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: 'c2' },
    ]);
    expect(canLoadMore(s)).toBe(true);
    expect(canLoadMore(run([{ type: 'request', seq: 2, kind: 'more', query: '' }], s))).toBe(false);
    expect(canLoadMore({ ...s, nextCursor: null })).toBe(false);
  });

  it('silent focus reloads only on the first page and when idle', () => {
    const one = run([
      { type: 'request', seq: 1, kind: 'initial', query: '' },
      { type: 'success', seq: 1, kind: 'initial', query: '', items: items('a'), nextCursor: 'c2' },
    ]);
    expect(shouldSilentReload(one)).toBe(true);
    const two = run([
      { type: 'request', seq: 2, kind: 'more', query: '' },
      { type: 'success', seq: 2, kind: 'more', query: '', items: items('b'), nextCursor: null },
    ], one);
    expect(shouldSilentReload(two)).toBe(false);
    expect(shouldSilentReload(run([{ type: 'request', seq: 2, kind: 'search', query: 'q' }], one))).toBe(false);
  });
});
