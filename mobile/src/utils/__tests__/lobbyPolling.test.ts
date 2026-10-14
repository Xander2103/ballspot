import { LOBBY_POLL_INTERVAL_MS, shouldAlertOnLoadFailure } from '../lobbyPolling';

describe('lobby polling policy', () => {
  it('keeps the lobby refresh at a few seconds', () => {
    expect(LOBBY_POLL_INTERVAL_MS).toBe(3000);
  });

  it('never alerts on a failed background poll (offline lobby must not spam modals)', () => {
    expect(shouldAlertOnLoadFailure('poll')).toBe(false);
  });

  it.each(['initial', 'focus', 'manual'] as const)('still alerts when the user asked for the load (%s)', (trigger) => {
    expect(shouldAlertOnLoadFailure(trigger)).toBe(true);
  });
});
