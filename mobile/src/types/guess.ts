export interface GuessResult {
  id: number;
  score: number;
  distance: number;
  guess_x_ratio: number;
  guess_y_ratio: number;
  ball_x_ratio: number;
  ball_y_ratio: number;
  reveal_image_url: string | null;
  // Rank / percentile insight within this round.
  rank?: number;
  total_players?: number;
  better_than_percentage?: number;
}

/** Present (once) on the guess that finishes a tournament. */
export interface TournamentCompletion {
  is_completed: boolean;
  placement: number;
  total_players: number;
  xp_awarded: number;
}

export interface LeaderboardEntry {
  rank: number;
  user_id: number;
  username: string;
  name: string;
  total_score: number;
  guesses_count: number;
  avg_score: number;
  is_current_user: boolean;
}
