export interface Badge {
  id: number;
  code: string;
  name: string;
  description: string;
  icon: string;
  category: string;
  rarity: string;
  sort_order: number;
}

export interface EarnedBadge extends Badge {
  earned_at: string | null;
  earned: boolean;
}

export interface BadgeCollection {
  earned_count: number;
  total_count: number;
  badges: EarnedBadge[];
}

/**
 * A user's placement in a CLOSED monthly/weekly competition period (Trophy
 * Room). Only ever written when a period is closed — never the live period.
 */
export interface CompetitionFinish {
  id: number;
  placement: number;
  period_type: string;
  period_label: string;
  period_start: string | null;
  period_end: string | null;
  total_score: number;
  total_players: number;
  xp_awarded: number;
  awarded_at: string | null;
}

/** A user's placement in a completed tournament (Trophy Room). */
export interface TournamentFinish {
  id: number;
  placement: number;
  total_score: number;
  rounds_played: number | null;
  total_players: number | null;
  league: { id: number; name: string } | null;
  completed_at: string | null;
}
