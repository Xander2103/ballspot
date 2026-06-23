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
}
