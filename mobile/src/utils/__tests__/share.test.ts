import {
  applyShare, availableShareRewards, buildAppShareMessage, buildDailyResultShareMessage, dailyShareCta,
  HIGH_SCORE_SHARE_THRESHOLD, INITIAL_SHARE_REWARD_STATE, isHighScore, localDateString, SHARE_URL, SHARE_XP,
  stateFromServerStatus, type ShareRewardState, type ShareRewardStatus,
} from '../share';

const TODAY = '2026-10-14';
const TOMORROW = '2026-10-15';

describe('share text generation', () => {
  it('builds the spec share text with the score and the landing page URL', () => {
    expect(buildDailyResultShareMessage(85)).toBe(
      'I scored 85 points in today’s BallPicker Daily Challenge ⚽🎯 Can you beat me?\nhttps://ballpicker.vanmalderstudio.be',
    );
    expect(SHARE_URL).toBe('https://ballpicker.vanmalderstudio.be');
  });

  it('builds the app invitation with the URL', () => {
    const msg = buildAppShareMessage();
    expect(msg).toContain('BallPicker');
    expect(msg.endsWith(`\n${SHARE_URL}`)).toBe(true);
  });

  it('never contains anything but the score and copy (no user data)', () => {
    expect(buildDailyResultShareMessage(0)).not.toMatch(/@|token|user/i);
  });
});

describe('daily share CTA', () => {
  it('uses the stronger copy and the primary slot for 80+', () => {
    expect(HIGH_SCORE_SHARE_THRESHOLD).toBe(80);
    expect(isHighScore(80)).toBe(true);
    expect(dailyShareCta(80)).toEqual({ key: 'daily.share.ctaHigh', prominent: true });
    expect(dailyShareCta(100)).toEqual({ key: 'daily.share.ctaHigh', prominent: true });
  });

  it('keeps sharing available, less prominently, for lower scores', () => {
    expect(isHighScore(79)).toBe(false);
    expect(dailyShareCta(79)).toEqual({ key: 'daily.share.cta', prominent: false });
    expect(dailyShareCta(0)).toEqual({ key: 'daily.share.cta', prominent: false });
    // A low score can still earn the first-share and daily-share XP.
    expect(availableShareRewards(INITIAL_SHARE_REWARD_STATE, 'daily_result', 12, TODAY).rewards)
      .toEqual(['first_share', 'daily_share']);
  });
});

describe('share reward rules (local mirror of the server)', () => {
  it('first ever share is eligible for +25 XP', () => {
    const preview = availableShareRewards(INITIAL_SHARE_REWARD_STATE, 'app', null, TODAY);
    expect(preview).toEqual({ xp: SHARE_XP.firstShare, rewards: ['first_share'] });

    const applied = applyShare(INITIAL_SHARE_REWARD_STATE, 'app', null, TODAY);
    expect(applied.xp).toBe(25);
    expect(applied.state.firstShareClaimed).toBe(true);
    expect(applied.state.totalShareCount).toBe(1);
  });

  it('daily share reward is available only once per day', () => {
    const first = applyShare(INITIAL_SHARE_REWARD_STATE, 'daily_result', 55, TODAY);
    expect(first.rewards).toEqual(['first_share', 'daily_share']);
    expect(first.xp).toBe(35);

    const again = applyShare(first.state, 'daily_result', 55, TODAY);
    expect(again.rewards).toEqual([]);
    expect(again.xp).toBe(0);

    const nextDay = applyShare(again.state, 'daily_result', 55, TOMORROW);
    expect(nextDay.rewards).toEqual(['daily_share']);
    expect(nextDay.xp).toBe(10);
  });

  it('80+ share bonus is available only once per day', () => {
    const first = applyShare(INITIAL_SHARE_REWARD_STATE, 'daily_result', 80, TODAY);
    expect(first.rewards).toEqual(['first_share', 'daily_share', 'daily_high_score_share']);
    expect(first.xp).toBe(50);

    const again = applyShare(first.state, 'daily_result', 99, TODAY);
    expect(again.rewards).toEqual([]);

    const nextDay = applyShare(again.state, 'daily_result', 90, TOMORROW);
    expect(nextDay.rewards).toEqual(['daily_share', 'daily_high_score_share']);
    expect(nextDay.xp).toBe(25);
  });

  it('repeated shares do not farm XP', () => {
    let state: ShareRewardState = INITIAL_SHARE_REWARD_STATE;
    let xp = 0;
    for (let i = 0; i < 20; i++) {
      const r = applyShare(state, i % 2 ? 'app' : 'daily_result', 95, TODAY);
      state = r.state;
      xp += r.xp;
    }
    expect(xp).toBe(25 + 10 + 15);
    expect(state.totalShareCount).toBe(20);
    expect(availableShareRewards(state, 'daily_result', 100, TODAY).xp).toBe(0);
    expect(availableShareRewards(state, 'app', null, TODAY).xp).toBe(0);
  });

  it('the app share never carries the daily rewards', () => {
    const state = { ...INITIAL_SHARE_REWARD_STATE, firstShareClaimed: true };
    expect(availableShareRewards(state, 'app', null, TODAY)).toEqual({ xp: 0, rewards: [] });
  });

  it('rebuilds the local state from the server status (server wins)', () => {
    const status: ShareRewardStatus = {
      day: TODAY, total_shares: 4, first_share_available: false, daily_share_available: false,
      daily_high_score_available: true, high_score_threshold: 80, social_striker_shares: 5,
      xp: { first_share: 25, daily_share: 10, daily_high_score_share: 15 },
    };
    const state = stateFromServerStatus(status);
    expect(state).toEqual({
      totalShareCount: 4, firstShareClaimed: true, lastDailyShareRewardDate: TODAY, lastHighScoreShareRewardDate: null,
    });
    expect(availableShareRewards(state, 'daily_result', 85, TODAY, status.xp)).toEqual({ xp: 15, rewards: ['daily_high_score_share'] });
  });

  it('formats the local calendar day', () => {
    expect(localDateString(new Date(2026, 9, 4, 23, 59))).toBe('2026-10-04');
  });
});
