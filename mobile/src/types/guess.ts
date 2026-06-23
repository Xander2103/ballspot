export interface GuessResult {
  id: number;
  score: number;
  distance: number;
  guess_x_ratio: number;
  guess_y_ratio: number;
  ball_x_ratio: number;
  ball_y_ratio: number;
  reveal_image_url: string | null;
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
