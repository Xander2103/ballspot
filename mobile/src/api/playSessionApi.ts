import { packApi } from './packApi';
import { clubApi } from './clubApi';
import type {
  ChallengePackDetail, PackAttemptResponse, PackGuessResult, PackStartResponse,
} from '../types/pack';
import type { ClubPackCompetitionRef } from '../types/club';
import { sessionClubId, type PlaySession } from '../utils/playSession';

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

export function playSessionApi(session: PlaySession): PlaySessionApi {
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
