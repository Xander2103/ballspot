import { packApi } from './packApi';
import { clubApi } from './clubApi';
import type {
  ChallengePackDetail, PackAttemptResponse, PackGuessResult, PackStartResponse,
} from '../types/pack';
import type { ClubPackCompetitionRef } from '../types/club';
import { isCompetitionSession, sessionClubId, type CompetitionPlaySession, type PlaySession } from '../utils/playSession';
import { toPackGuessResult, toPackStartResponse } from '../utils/competition';

/** Pack detail as the shared PackDetailScreen renders it (club fields optional). */
export type SessionPackDetail = ChallengePackDetail & {
  reserved_for_competition?: boolean;
  competitions?: ClubPackCompetitionRef[];
};

/**
 * The API calls one play track needs. PackDetail/PackGuess/PackResult/
 * PackComplete only talk to this interface, so a new track (competition
 * attempts) is one more implementation here — no screen forks.
 */
export interface PlaySessionApi {
  detail(): Promise<{ data: SessionPackDetail }>;
  /** Start a new attempt or resume the active one (409 = already completed, see isPackAlreadyCompleted). */
  start(): Promise<PackStartResponse>;
  /** Active or latest attempt (+ completion once completed). */
  attempt(): Promise<PackAttemptResponse>;
  submitGuess(attemptId: number, challengeId: number, guessedX: number, guessedY: number): Promise<PackGuessResult>;
}

/**
 * Competition track (plan §5.1): a separate attempt per (competition, player,
 * pack). The attempt payloads are mapped onto the pack shapes so the shared
 * guess / result screens render them unchanged.
 *  - start(): resumes the known attempt (GET), else starts attempt N (POST,
 *    idempotent per attempt number — a retry returns the same attempt).
 *  - submitGuess(): retries are safe; a replayed guess comes back `replayed`.
 */
function competitionApi(session: CompetitionPlaySession): PlaySessionApi {
  const { clubId, competitionId, packSlug } = session;
  const startOrResume = async () => {
    if (session.attemptId) return toPackStartResponse(await clubApi.competitionAttempt(session.attemptId));
    if (!session.attemptNumber) throw { status: 409, code: 'attempt_not_active' };
    return toPackStartResponse(await clubApi.startCompetitionAttempt(clubId, competitionId, packSlug, session.attemptNumber));
  };
  return {
    // Not used by the competition flow (it starts from the competition screen);
    // kept so the interface stays total.
    detail: () => clubApi.pack(clubId, packSlug),
    start: startOrResume,
    attempt: async () => {
      const res = await startOrResume();
      return { attempt: res.attempt, challenge: res.challenge, completion: null };
    },
    submitGuess: async (attemptId, challengeId, guessedX, guessedY) =>
      toPackGuessResult(await clubApi.competitionGuess(attemptId, challengeId, guessedX, guessedY)),
  };
}

export function playSessionApi(session: PlaySession): PlaySessionApi {
  if (isCompetitionSession(session)) return competitionApi(session);
  const slug = session.packSlug;
  const clubId = sessionClubId(session);

  if (clubId) {
    // Normal track of a club pack: club-scoped detail/start/attempt; the guess
    // endpoint is the shared /pack-attempts/{id}/guess (re-checks club access).
    return {
      detail: () => clubApi.pack(clubId, slug),
      start: () => clubApi.packStart(clubId, slug),
      attempt: () => clubApi.packAttempt(clubId, slug),
      submitGuess: packApi.submitGuess,
    };
  }

  // Public pack: exactly the calls the screens made before sessions existed.
  return {
    detail: () => packApi.get(slug),
    start: () => packApi.start(slug),
    attempt: () => packApi.attempt(slug),
    submitGuess: packApi.submitGuess,
  };
}
