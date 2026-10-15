import { settleDailyResultLoad } from '../dailyResultLoad';

const ok = <T>(value: T): PromiseFulfilledResult<T> => ({ status: 'fulfilled', value });
const failed: PromiseRejectedResult = { status: 'rejected', reason: { status: 500 } };

describe('daily result loading', () => {
  it('keeps the result when today or stats fail', () => {
    expect(settleDailyResultLoad([ok({ score: 91 }), failed, failed]))
      .toEqual({ result: { score: 91 }, today: null, stats: null });
    expect(settleDailyResultLoad([ok({ score: 91 }), ok({ id: 1 }), failed]))
      .toEqual({ result: { score: 91 }, today: { id: 1 }, stats: null });
  });

  it('has no result only when the result request itself failed', () => {
    expect(settleDailyResultLoad([failed, ok({ id: 1 }), ok({ current_streak: 3 })]))
      .toEqual({ result: null, today: { id: 1 }, stats: { current_streak: 3 } });
  });
});
