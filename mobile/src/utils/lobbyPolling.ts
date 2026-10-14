/**
 * Lobby polling policy for LeagueDetailScreen (pure TS, jest-tested).
 *
 * While a tournament sits in the lobby the screen refreshes every few seconds
 * so the host sees players join. A refresh that fails must stay silent: with
 * the alert-on-every-failure behaviour, going offline in a lobby produced a
 * modal alert every poll tick, forever, until the user left the screen.
 * Only loads the user asked for (first open, returning to the screen, a
 * manual action) may surface an error.
 */

export const LOBBY_POLL_INTERVAL_MS = 3000;

/** Why the screen is loading: who gets to see a failure. */
export type LoadTrigger = 'initial' | 'focus' | 'manual' | 'poll';

/** Background polls never alert; everything the user initiated may. */
export function shouldAlertOnLoadFailure(trigger: LoadTrigger): boolean {
  return trigger !== 'poll';
}
