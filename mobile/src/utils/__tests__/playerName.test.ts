import { publicPlayerName } from '../playerName';

describe('publicPlayerName', () => {
  it('shows the username, keeping its letter case', () => {
    expect(publicPlayerName({ username: 'XanderVM' })).toBe('XanderVM');
    expect(publicPlayerName({ username: '  sam ' })).toBe('sam');
  });

  it('never falls back to the real name, even when an older backend sends one', () => {
    const fromOldBackend = { username: '', name: 'Jan Peeters' };
    expect(publicPlayerName(fromOldBackend)).toBe('A player');
    expect(publicPlayerName({ username: 'jp7', name: 'Jan Peeters' } as { username: string })).toBe('jp7');
  });

  it('survives missing and legacy data', () => {
    expect(publicPlayerName(null)).toBe('A player');
    expect(publicPlayerName(undefined)).toBe('A player');
    expect(publicPlayerName({ username: null })).toBe('A player');
  });
});
