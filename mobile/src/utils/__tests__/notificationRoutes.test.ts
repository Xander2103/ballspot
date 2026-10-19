/** Push payload → route, and the auth gate for opening it. */
import { canOpenFromNotification, routeForNotificationData } from '../notificationRoutes';

describe('routeForNotificationData', () => {
  it('club_competition → ClubCompetition', () => {
    expect(routeForNotificationData({ type: 'club_competition', club_id: '01JCLUB', competition_id: '01JCOMP' }))
      .toEqual({ name: 'ClubCompetition', params: { clubId: '01JCLUB', competitionId: '01JCOMP' } });
  });

  it.each([
    [null],
    [undefined],
    ['club_competition'],
    [{}],
    [{ type: 'daily_reminder' }],
    [{ type: 'club_competition', club_id: '01JCLUB' }],
    [{ type: 'club_competition', club_id: '', competition_id: '01JCOMP' }],
    [{ type: 'club_competition', club_id: { x: 1 }, competition_id: '01JCOMP' }],
  ])('ignores %j', (data) => {
    expect(routeForNotificationData(data)).toBeNull();
  });
});

describe('canOpenFromNotification (auth gate)', () => {
  it('only inside the signed-in app with a stored session', () => {
    expect(canOpenFromNotification(['Home'], true)).toBe(true);
    expect(canOpenFromNotification(['Home', 'ClubDetail'], true)).toBe(true);
    expect(canOpenFromNotification(['Home'], false)).toBe(false);
    expect(canOpenFromNotification(['Login'], true)).toBe(false);
    expect(canOpenFromNotification(['EmailVerification'], true)).toBe(false);
    expect(canOpenFromNotification(['SportSelection'], true)).toBe(false);
    expect(canOpenFromNotification([], true)).toBe(false);
  });
});
