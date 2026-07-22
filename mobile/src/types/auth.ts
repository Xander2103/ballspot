import type { Sport } from './sport';

export interface User {
  id: number;
  name: string;
  username: string;
  email?: string;
  selected_theme?: string;
  avatar_url?: string | null;
  preferred_sport?: Sport | null;
}

export interface AuthState {
  user: User | null;
  token: string | null;
}

/** Full auth result: register, or login after 2FA verification. */
export interface AuthResponse {
  user: User;
  token: string;
}

/** Step-1 login result — a code was emailed; no token issued yet. */
export interface TwoFactorRequired {
  requires_2fa: true;
  verification_id: string;
  message: string;
}

export type LoginResult = AuthResponse | TwoFactorRequired;

export function isTwoFactorRequired(result: LoginResult): result is TwoFactorRequired {
  return (result as TwoFactorRequired).requires_2fa === true;
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
