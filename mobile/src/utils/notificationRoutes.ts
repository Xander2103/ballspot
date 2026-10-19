/**
 * Push notification tap → app route (pure, unit tested).
 *
 * Only known payload types are routable, and only inside the signed-in app:
 * a tap never lands a signed-out user on an authenticated screen (the same
 * rule as deep links in AppNavigator).
 */

export type NotificationRoute = {
  name: 'ClubCompetition';
  params: { clubId: string; competitionId: string };
};

function nonEmptyId(value: unknown): string | null {
  if (typeof value === 'string' && value.trim()) return value.trim();
  if (typeof value === 'number' && Number.isFinite(value)) return String(value);
  return null;
}

/** `data: { type: "club_competition", club_id, competition_id }` → ClubCompetition. Anything else → null. */
export function routeForNotificationData(data: unknown): NotificationRoute | null {
  if (!data || typeof data !== 'object') return null;
  const d = data as Record<string, unknown>;
  if (d.type !== 'club_competition') return null;
  const clubId = nonEmptyId(d.club_id);
  const competitionId = nonEmptyId(d.competition_id);
  if (!clubId || !competitionId) return null;
  return { name: 'ClubCompetition', params: { clubId, competitionId } };
}

/**
 * May a notification open an authenticated screen right now? Only with a
 * stored session AND the navigator already inside the signed-in app (the
 * tab host `Home` is in the root stack — not Login, verification or the
 * onboarding sport picker).
 */
export function canOpenFromNotification(rootRouteNames: string[], hasToken: boolean): boolean {
  return hasToken && rootRouteNames.includes('Home') && !rootRouteNames.some((n) => AUTH_GATE_ROUTES.has(n));
}

const AUTH_GATE_ROUTES = new Set([
  'Login', 'LoginVerification', 'EmailVerification', 'Register', 'ForgotPassword', 'ResetPassword',
]);
