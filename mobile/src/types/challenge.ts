export interface ChallengeCategory {
  id: number;
  name: string;
  slug: string;
}

export interface Challenge {
  id: number;
  title: string;
  difficulty: 'easy' | 'medium' | 'hard';
  hidden_image_url: string;
  /** Pixel size of the served guess image (newer backends; absent/null = unknown). */
  hidden_image_width?: number | null;
  hidden_image_height?: number | null;
  category: ChallengeCategory | null;
  /** The challenge's own sport (drives the guess marker). Older backends omit it. */
  sport?: { slug: string; name: string; emoji: string; object_name?: string | null; primary_color?: string | null } | null;
}

export interface LeagueRound {
  id: number;
  round_number: number;
  status: 'open' | 'closed';
  challenge: Challenge;
}

export interface CurrentRoundProgress {
  completed: number;
  total: number;
  remaining: number;
  pct: number;
}

export interface CurrentRoundResponse {
  current_round: LeagueRound | null;
  has_current_round: boolean;
  completed: boolean;
  reason: 'has_pending_round' | 'all_rounds_complete' | 'no_rounds_yet' | 'daily_limit_reached';
  message?: string;
  next_available_at: string | null;
  progress: CurrentRoundProgress;
  rounds_per_day: number;
  played_today_count: number;
  remaining_today_count: number;
}
