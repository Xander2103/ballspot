/** AT-MOB-04: shared-rank rendering helper. */
import { mergePreviewRows, rankDisplay, sharedRankFlags, sharedRanks } from '../sharedRank';

const r = (...ranks: number[]) => ranks.map((rank, i) => ({ rank, user_id: i + 1 }));

describe('sharedRankFlags', () => {
  it('marks every row of a tie (1, 2, 2, 4)', () => {
    expect(sharedRankFlags(r(1, 2, 2, 4))).toEqual([false, true, true, false]);
  });

  it('handles a tie at the top and a three-way tie', () => {
    expect(sharedRankFlags(r(1, 1, 3, 3, 3, 6))).toEqual([true, true, true, true, true, false]);
  });

  it('no ties, empty list', () => {
    expect(sharedRankFlags(r(1, 2, 3))).toEqual([false, false, false]);
    expect(sharedRankFlags([])).toEqual([]);
  });

  it('works across page boundaries once both rows are loaded', () => {
    expect(sharedRanks(r(19, 20, 20, 22))).toEqual(new Set([20]));
  });
});

describe('rankDisplay', () => {
  it('medals for the podium, also when shared', () => {
    expect(rankDisplay(1)).toEqual({ text: '🥇', isMedal: true, shared: false });
    expect(rankDisplay(2, true)).toEqual({ text: '🥈', isMedal: true, shared: true });
    expect(rankDisplay(3).text).toBe('🥉');
  });

  it('"#n" for a unique rank, "=n" for a shared one', () => {
    expect(rankDisplay(4).text).toBe('#4');
    expect(rankDisplay(4, true).text).toBe('=4');
    expect(rankDisplay(120, true)).toEqual({ text: '=120', isMedal: false, shared: true });
  });

  it('never renders a nonsense rank', () => {
    expect(rankDisplay(0).text).toBe('–');
    expect(rankDisplay(Number.NaN).text).toBe('–');
  });
});

describe('mergePreviewRows', () => {
  const top = [{ rank: 1, user_id: 10 }, { rank: 2, user_id: 11 }, { rank: 2, user_id: 12 }];

  it('appends "me" when outside the top rows', () => {
    expect(mergePreviewRows(top, { rank: 12, user_id: 99 }).map((e) => e.user_id)).toEqual([10, 11, 12, 99]);
  });

  it('does not duplicate "me" when already in the top rows', () => {
    expect(mergePreviewRows(top, { rank: 2, user_id: 12 })).toHaveLength(3);
  });

  it('no "me" row', () => {
    expect(mergePreviewRows(top, null)).toBe(top);
  });
});
