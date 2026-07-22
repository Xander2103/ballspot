import type { Sport } from './sport';

export interface User {
  id: number;
  name: string;
  username: string;
  email?: string;
  email_verified?: boolean;
  selected_theme?: string;
  avatar_url?: string | null;
  preferred_sport?: Sport | null;
}

export interface AuthState {
  user: User | null;
  token: string | null;
}

/** Full auth result: register, or login after verification. */
export interface AuthResponse {
  user: User;
  token: string;
  email_verified?: boolean;
}

/** Login result — forced 2FA is on; a login code was emailed, no token yet. */
export interface TwoFactorRequired {
  requires_2fa: true;
  verification_id: string;
  message: string;
}

/** Login/register result — the email is not verified yet. A token IS issued so
 * the app can drive the verification screen, but full access is gated. */
export interface EmailVerificationRequired {
  requires_email_verification: true;
  email_verified: false;
  token: string;
  user: User;
  message: string;
}

export type LoginResult = AuthResponse | TwoFactorRequired | EmailVerificationRequired;

export function isTwoFactorRequired(result: LoginResult): result is TwoFactorRequired {
  return (result as TwoFactorRequired).requires_2fa === true;
}

export function isEmailVerificationRequired(result: LoginResult): result is EmailVerificationRequired {
  return (result as EmailVerificationRequired).requires_email_verification === true;
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
