export interface User {
  id: number;
  name: string;
  username: string;
  email?: string;
}

export interface AuthState {
  user: User | null;
  token: string | null;
}

export interface ProfileStats {
  tournaments_count: number;
  completed_tournaments_count: number;
  guesses_count: number;
  total_score: number;
  average_score: number;
  daily_challenges_played: number;
  average_daily_score: number;
  best_daily_score: number;
  current_daily_streak: number;
  best_daily_streak: number;
}
