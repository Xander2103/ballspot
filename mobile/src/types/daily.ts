import type { Badge } from './badge';

export interface DailyChallengeEntry {
  id: number;
  challenge_date: string;
  challenge: {
    id: number;
    title: string;
    difficulty: string;
    hidden_image_url: string | null;
    category: { id: number; name: string; slug: string } | null;
  };
}

export interface TodayResponse {
  has_daily: boolean;
  already_played: boolean;
  reason?: string;
  daily_challenge?: DailyChallengeEntry;
}

export interface DailyGuessResult {
  id: number;
  score: number;
  distance: number;
  guess_x_ratio: number;
  guess_y_ratio: number;
  ball_x_ratio: number;
  ball_y_ratio: number;
  reveal_image_url: string | null;
  // Rank / percentile insight for this daily challenge.
  rank: number;
  total_players: number;
  better_than_percentage: number;
  // Optional: badges newly unlocked by this guess (see gamification).
  new_badges?: Badge[];
}

export interface WeeklyLeaderboardEntry {
  rank: number;
  user_id: number;
  username: string;
  name: string;
  total_score: number;
  challenges_played: number;
  avg_score: number;
  is_current_user: boolean;
}

export interface LeaderboardMeta<TEntry = WeeklyLeaderboardEntry> {
  total_players: number;
  current_user_rank: number | null;
  current_user_score: number | null;
  current_user_average: number | null;
  better_than_percentage: number | null;
  top_users: TEntry[];
  nearby_users: TEntry[];
}

export interface WeeklyLeaderboard {
  data: WeeklyLeaderboardEntry[];
  week_start: string;
  week_end: string;
  meta: LeaderboardMeta;
}

export interface DailyStats {
  current_streak: number;
  best_streak: number;
  total_played: number;
  average_score: number;
  best_score: number;
  weekly_rank: number | null;
}
