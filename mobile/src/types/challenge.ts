export interface Challenge {
  id: number;
  title: string;
  difficulty: 'easy' | 'medium' | 'hard';
  hidden_image_url: string;
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
  reason: 'has_pending_round' | 'all_rounds_complete';
  progress: CurrentRoundProgress;
}
