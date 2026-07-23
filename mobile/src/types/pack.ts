import type { SportSummary } from './daily';
import type { Badge } from './badge';
import type { RankProgress, RankUp } from './auth';

/** The user's progress on a pack, shown on discovery cards. */
export interface PackProgress {
  status: 'active' | 'completed' | 'abandoned';
  completed_count: number;
  total_challenges: number;
}

export interface ChallengePackSummary {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  sport: SportSummary | null;
  cover_image_url: string | null;
  difficulty: string | null;
  challenge_count: number;
  is_featured: boolean;
  /** Present on the list endpoint; null if the user has never played it. */
  progress?: PackProgress | null;
}

/** Safe, non-sensitive challenge summary (never includes the ball position). */
export interface PackChallengeSummary {
  id: number;
  title: string;
  difficulty: string;
  hidden_image_url: string | null;
  sport: { slug: string; name: string; emoji: string } | null;
  category: { name: string; slug: string } | null;
}

export interface ChallengePackDetail extends ChallengePackSummary {
  challenges: PackChallengeSummary[];
}

// --- Pack play mode -------------------------------------------------------

export interface PackAttemptState {
  id: number;
  status: 'active' | 'completed' | 'abandoned';
  current_index: number;
  total_score: number;
  completed_count: number;
  total_challenges: number;
  started_at?: string | null;
  completed_at?: string | null;
}

export interface PackAttemptResponse {
  attempt: PackAttemptState | null;
  challenge: PackChallengeSummary | null;
}

export interface PackStartResponse {
  attempt: PackAttemptState;
  challenge: PackChallengeSummary | null;
}

export interface PackGuessResult {
  result: {
    score: number;
    distance: number;
    guessed_x: number;
    guessed_y: number;
    ball_x_ratio: number;
    ball_y_ratio: number;
    reveal_image_url: string | null;
  };
  progress: PackAttemptState;
  next_challenge: PackChallengeSummary | null;
  rank_progress: RankProgress;
  rank_up: RankUp | null;
  new_badges: Badge[];
  pack_completed: boolean;
  final_score: number | null;
  completion_xp: number | null;
}

export interface PackCompletion {
  id: number;
  pack: { id: number; name: string; slug: string } | null;
  total_score: number;
  challenge_count: number;
  is_perfect: boolean;
  completed_at: string | null;
}
