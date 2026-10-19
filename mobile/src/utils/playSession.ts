/**
 * Play-session adapter (pure part, unit tested).
 *
 * The pack screens (PackDetail → PackGuess → PackResult → PackComplete) are
 * shared by every way of playing a set of challenges. Their route params
 * carry an optional `session` that says WHICH play track this is; the screens
 * never branch on it themselves — they ask src/api/playSessionApi.ts for the
 * API calls and this module for the navigation target / labels.
 *
 *   undefined / { kind: 'pack', packSlug }          public pack (unchanged behaviour)
 *   { kind: 'pack', packSlug, clubId, clubName }     normal track of a club pack
 *   (follow-up) { kind: 'competition', … }           competition attempt — add the
 *                                                    variant here + its API in playSessionApi.ts
 *
 * A missing session always means "public pack", so every existing navigate()
 * call (and any params restored from an old navigation state) keeps working.
 */

export interface PackPlaySession {
  kind: 'pack';
  packSlug: string;
  /** Set for a club pack: the club's public id. */
  clubId?: string | null;
  /** Club name, only used as the ClubDetail header title when returning there. */
  clubName?: string | null;
}

/**
 * Every play track. The competition follow-up extends this union, e.g.
 *   | { kind: 'competition'; packSlug: string; clubId: string; clubName?: string | null;
 *       competitionId: string; attemptId: number; attemptNumber: number }
 */
export type PlaySession = PackPlaySession;

/** The session for a route, defaulting to the public pack identified by `slug`. */
export function resolvePlaySession(params: { slug: string; session?: PlaySession | null }): PlaySession {
  if (params.session && params.session.packSlug) return params.session;
  return { kind: 'pack', packSlug: params.slug };
}

/** Club id of a session, or null for public play. */
export function sessionClubId(session: PlaySession | null | undefined): string | null {
  return session?.clubId ? session.clubId : null;
}

export function isClubSession(session: PlaySession | null | undefined): boolean {
  return sessionClubId(session) !== null;
}

/**
 * Where "leave this play flow" goes (header exit, Back buttons, Android back):
 * a full reset so no game screen stays underneath.
 *  - public pack → Home + Packs (exactly the old goPacks)
 *  - club pack   → Home (Clubs tab) + that club's detail
 */
export type ExitRoutes =
  | { index: 1; routes: [{ name: 'Home' }, { name: 'Packs' }] }
  | { index: 1; routes: [{ name: 'Home'; params: { screen: 'Clubs' } }, { name: 'ClubDetail'; params: { clubId: string; name?: string } }] };

export function exitRoutesFor(session: PlaySession | null | undefined): ExitRoutes {
  const clubId = sessionClubId(session);
  if (clubId) {
    const name = session?.clubName ?? undefined;
    return {
      index: 1,
      routes: [
        { name: 'Home', params: { screen: 'Clubs' } },
        { name: 'ClubDetail', params: name ? { clubId, name } : { clubId } },
      ],
    };
  }
  return { index: 1, routes: [{ name: 'Home' }, { name: 'Packs' }] };
}

/** i18n key of the header "‹ …" exit label. */
export function exitLabelKey(session: PlaySession | null | undefined): string {
  return isClubSession(session) ? 'nav.exit.club' : 'nav.exit.packs';
}

/**
 * i18n key of a "Back to …" button on the result / complete screens: the
 * screen's existing public-pack key, or "Back to club" for a club session.
 */
export function backToOriginKey(session: PlaySession | null | undefined, publicKey: string): string {
  return isClubSession(session) ? 'clubs.play.backToClub' : publicKey;
}
