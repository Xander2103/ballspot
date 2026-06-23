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
  category: ChallengeCategory | null;
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
