/**
 * Play-session adapter (pure part, unit tested).
 *
 * The pack screens (PackDetail → PackGuess → PackResult → PackComplete) are
 * shared by every way of playing a set of challenges. Their route params
 * carry an optional `session` that says WHICH play track this is; the screens
 * never branch on API calls themselves — they ask src/api/playSessionApi.ts
 * for the calls and this module for navigation targets / labels.
 *
 *   undefined / { kind: 'pack', packSlug }          public pack (unchanged behaviour)
 *   { kind: 'pack', packSlug, clubId, clubName }     normal track of a club pack
 *   { kind: 'competition', clubId, competitionId,    a competition attempt (separate
 *     packSlug, attemptId, … }                       play track, plan §5.1)
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

export interface CompetitionPlaySession {
  kind: 'competition';
  packSlug: string;
  clubId: string;
  clubName?: string | null;
  competitionId: string;
  competitionName?: string | null;
  /** The attempt being played (set once started; resume uses GET /club-competition-attempts/{id}). */
  attemptId?: number | null;
  /** Used only when no attemptId is known yet: start is idempotent per attempt number. */
  attemptNumber?: number | null;
}

/** Every play track. */
export type PlaySession = PackPlaySession | CompetitionPlaySession;

/** The session for a route, defaulting to the public pack identified by `slug`. */
export function resolvePlaySession(params: { slug: string; session?: PlaySession | null }): PlaySession {
  if (params.session && params.session.packSlug) return params.session;
  return { kind: 'pack', packSlug: params.slug };
}

export function isCompetitionSession(session: PlaySession | null | undefined): session is CompetitionPlaySession {
  return session?.kind === 'competition';
}

/** Club id of a session, or null for public play. */
export function sessionClubId(session: PlaySession | null | undefined): string | null {
  return session?.clubId ? session.clubId : null;
}

export function isClubSession(session: PlaySession | null | undefined): boolean {
  return sessionClubId(session) !== null;
}

type ClubDetailRoute = { name: 'ClubDetail'; params: { clubId: string; name?: string } };
type CompetitionRoute = {
  name: 'ClubCompetition';
  params: { clubId: string; competitionId: string; name?: string; clubName?: string };
};

/**
 * Where "leave this play flow" goes (header exit, Back buttons, Android back):
 * a full reset so no game screen stays underneath.
 *  - public pack      → Home + Packs (exactly the old goPacks)
 *  - club pack        → Home (Clubs tab) + that club's detail
 *  - competition play → Home (Clubs tab) + club detail + the competition
 */
export type ExitRoutes =
  | { index: 1; routes: [{ name: 'Home' }, { name: 'Packs' }] }
  | { index: 1; routes: [{ name: 'Home'; params: { screen: 'Clubs' } }, ClubDetailRoute] }
  | { index: 2; routes: [{ name: 'Home'; params: { screen: 'Clubs' } }, ClubDetailRoute, CompetitionRoute] };

function clubDetailRoute(clubId: string, clubName: string | null | undefined): ClubDetailRoute {
  return { name: 'ClubDetail', params: clubName ? { clubId, name: clubName } : { clubId } };
}

/** The competition screen of a competition session. */
export function competitionRouteFor(session: CompetitionPlaySession): CompetitionRoute {
  const params: CompetitionRoute['params'] = { clubId: session.clubId, competitionId: session.competitionId };
  if (session.competitionName) params.name = session.competitionName;
  if (session.clubName) params.clubName = session.clubName;
  return { name: 'ClubCompetition', params };
}

export function exitRoutesFor(session: PlaySession | null | undefined): ExitRoutes {
  if (isCompetitionSession(session)) {
    return {
      index: 2,
      routes: [
        { name: 'Home', params: { screen: 'Clubs' } },
        clubDetailRoute(session.clubId, session.clubName),
        competitionRouteFor(session),
      ],
    };
  }
  const clubId = sessionClubId(session);
  if (clubId) {
    return { index: 1, routes: [{ name: 'Home', params: { screen: 'Clubs' } }, clubDetailRoute(clubId, session?.clubName)] };
  }
  return { index: 1, routes: [{ name: 'Home' }, { name: 'Packs' }] };
}

/**
 * The "detail" screen of a session, used when the guess screen bounces back
 * (nothing left to play / error) or the player taps "Leave pack":
 * PackDetail for packs, the competition screen for competition attempts.
 */
export type DetailRoute =
  | { name: 'PackDetail'; params: { slug: string; name: string; session?: PlaySession } }
  | CompetitionRoute;

export function detailRouteFor(session: PlaySession | null | undefined, slug: string, packName: string): DetailRoute {
  if (isCompetitionSession(session)) return competitionRouteFor(session);
  return { name: 'PackDetail', params: session ? { slug, name: packName, session } : { slug, name: packName } };
}

/** i18n key of the header "‹ …" exit label. */
export function exitLabelKey(session: PlaySession | null | undefined): string {
  if (isCompetitionSession(session)) return 'nav.exit.competition';
  return isClubSession(session) ? 'nav.exit.club' : 'nav.exit.packs';
}

/**
 * i18n key of a "Back to …" button on the result / complete screens: the
 * screen's existing public-pack key, or "Back to club" / "Back to competition".
 */
export function backToOriginKey(session: PlaySession | null | undefined, publicKey: string): string {
  if (isCompetitionSession(session)) return 'clubs.play.backToCompetition';
  return isClubSession(session) ? 'clubs.play.backToClub' : publicKey;
}

/** The same session, now bound to a started attempt (so resumes use GET attempt). */
export function withAttempt(session: CompetitionPlaySession, attemptId: number): CompetitionPlaySession {
  return { ...session, attemptId, attemptNumber: null };
}
