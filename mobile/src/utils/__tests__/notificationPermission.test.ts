import {
  MAX_INVITES,
  NO_INVITES,
  REINVITE_DELAY_MS,
  canDeliver,
  decideInvite,
  mapDevicePermission,
  markInviteAccepted,
  markInviteShown,
  migrateLegacyPrompt,
  parseInviteState,
  reminderDelivery,
  serializeInviteState,
  type DevicePermission,
} from '../notificationPermission';

const NOW = 1_800_000_000_000;
const DAY = 24 * 60 * 60 * 1000;

describe('mapDevicePermission', () => {
  it.each([
    [0, 'notDetermined'],
    [1, 'denied'],
    [2, 'granted'],
    [3, 'provisional'],
    [4, 'provisional'],
  ])('reads iOS status %i as %s, whatever the root status says', (ios, expected) => {
    expect(mapDevicePermission({ status: 'undetermined', ios: { status: ios } })).toBe(expected);
  });

  it('does not lose provisional, which iOS reports as undetermined at root level', () => {
    expect(mapDevicePermission({ status: 'undetermined', ios: { status: 3 } })).toBe('provisional');
  });

  it.each([
    ['granted', 'granted'],
    ['denied', 'denied'],
    ['undetermined', 'notDetermined'],
  ])('reads Android status %s as %s', (status, expected) => {
    expect(mapDevicePermission({ status })).toBe(expected);
  });

  it('never reads an unknown answer as allowed', () => {
    expect(mapDevicePermission(null)).toBe('notDetermined');
    expect(mapDevicePermission({})).toBe('notDetermined');
    expect(mapDevicePermission({ status: 'nonsense' })).toBe('notDetermined');
  });
});

describe('what is really delivered', () => {
  it('only granted and provisional deliver', () => {
    expect(['notDetermined', 'granted', 'provisional', 'denied', 'unsupported'].filter((p) => canDeliver(p as DevicePermission)))
      .toEqual(['granted', 'provisional']);
  });

  it.each<[boolean, DevicePermission, string]>([
    [true, 'granted', 'active'],
    [true, 'provisional', 'active'],
    [true, 'denied', 'blocked'],
    [true, 'notDetermined', 'notEnabled'],
    [true, 'unsupported', 'unsupported'],
    [false, 'granted', 'off'],
    [false, 'denied', 'off'],
    [false, 'notDetermined', 'off'],
  ])('preference %s with %s is %s', (preference, permission, expected) => {
    expect(reminderDelivery(preference, permission)).toBe(expected);
  });

  it('a default-on preference after "Maybe later" does not count as active', () => {
    expect(reminderDelivery(true, 'notDetermined')).not.toBe('active');
  });
});

describe('invitation rules', () => {
  const deferred = markInviteShown(NO_INVITES, NOW);

  it('first install: invites once the OS can still ask', () => {
    expect(decideInvite(NO_INVITES, 'notDetermined', NOW)).toBe('requestPermission');
  });

  it('never invites when notifications are already allowed', () => {
    expect(decideInvite(NO_INVITES, 'granted', NOW)).toBe('none');
    expect(decideInvite(NO_INVITES, 'provisional', NOW)).toBe('none');
    expect(decideInvite(deferred, 'granted', NOW + 30 * DAY)).toBe('none');
    expect(decideInvite(deferred, 'provisional', NOW + 30 * DAY)).toBe('none');
  });

  it('never invites on a platform without notifications', () => {
    expect(decideInvite(NO_INVITES, 'unsupported', NOW)).toBe('none');
  });

  it('gives no unasked pop-up to someone already denied at OS level', () => {
    expect(decideInvite(NO_INVITES, 'denied', NOW)).toBe('none');
  });

  it('counts an invitation as soon as it is shown', () => {
    expect(deferred).toEqual({ count: 1, lastAt: NOW, lastOutcome: 'deferred' });
  });

  it('stays quiet until a full seven days after "Maybe later"', () => {
    expect(decideInvite(deferred, 'notDetermined', NOW)).toBe('none');
    expect(decideInvite(deferred, 'notDetermined', NOW + 6 * DAY)).toBe('none');
    expect(decideInvite(deferred, 'notDetermined', NOW + REINVITE_DELAY_MS - 1)).toBe('none');
    expect(decideInvite(deferred, 'notDetermined', NOW + REINVITE_DELAY_MS)).toBe('requestPermission');
  });

  it('a clock set backwards does not bring the invitation forward', () => {
    expect(decideInvite(deferred, 'notDetermined', NOW - 30 * DAY)).toBe('none');
  });

  it('sends a person denied by now to the OS settings, not to a dead OS dialog', () => {
    expect(decideInvite(deferred, 'denied', NOW + 8 * DAY)).toBe('openSettings');
  });

  it('asks nothing further after a second "Maybe later"', () => {
    const second = markInviteShown(deferred, NOW + 8 * DAY);
    expect(second.count).toBe(MAX_INVITES);
    expect(decideInvite(second, 'notDetermined', NOW + 365 * DAY)).toBe('none');
    expect(decideInvite(second, 'denied', NOW + 365 * DAY)).toBe('none');
  });

  it('does not invite again after the OS dialog was answered with no', () => {
    const accepted = markInviteAccepted(deferred);
    expect(decideInvite(accepted, 'denied', NOW + 365 * DAY)).toBe('none');
    expect(decideInvite(accepted, 'notDetermined', NOW + 365 * DAY)).toBe('none');
  });

  it('survives a restart: the stored record gives the same answer', () => {
    const restored = parseInviteState(serializeInviteState(deferred));
    expect(restored).toEqual(deferred);
    expect(decideInvite(restored, 'notDetermined', NOW + 7 * DAY)).toBe('requestPermission');
  });

  it('starts fresh without a record, and never nags on an unreadable one', () => {
    expect(parseInviteState(null)).toEqual(NO_INVITES);
    for (const bad of ['{broken', '"text"', '{}', '{"count":-1}', '{"count":"1"}']) {
      expect(decideInvite(parseInviteState(bad), 'notDetermined', NOW)).toBe('none');
    }
  });
});

describe('migrating the old one-time flag', () => {
  it('still undetermined means "Not now": one more invitation, seven days from now', () => {
    const migrated = migrateLegacyPrompt('notDetermined', NOW);
    expect(decideInvite(migrated, 'notDetermined', NOW + 6 * DAY)).toBe('none');
    expect(decideInvite(migrated, 'notDetermined', NOW + 7 * DAY)).toBe('requestPermission');
  });

  it('denied means the OS dialog already ran: no invitation', () => {
    expect(decideInvite(migrateLegacyPrompt('denied', NOW), 'denied', NOW + 365 * DAY)).toBe('none');
  });
});

describe('the agreed limits', () => {
  it('waits seven days and invites at most twice', () => {
    expect(REINVITE_DELAY_MS).toBe(7 * DAY);
    expect(MAX_INVITES).toBe(2);
  });
});
