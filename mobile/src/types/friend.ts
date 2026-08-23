import type { PlayerRank } from './auth';

/** Public-safe summary of another player. */
export interface FriendSummary {
  id: number;
  name: string;
  username: string;
  avatar_url: string | null;
  rank_name: string;
  level: number;
  total_xp: number;
}

/** A player the user may want to add, plus why they're being suggested. */
export interface FriendSuggestion extends FriendSummary {
  reason: 'same_tournament' | 'active_player';
}

export interface FriendRequestItem {
  id: number;
  status: 'pending' | 'accepted' | 'rejected' | 'cancelled';
  created_at: string | null;
  /** The other party — the sender for incoming, the target for outgoing. */
  user: FriendSummary;
}

export interface FriendRequestsResponse {
  incoming: FriendRequestItem[];
  outgoing: FriendRequestItem[];
}

export interface PublicProfileStats {
  tournaments_played: number;
  tournaments_completed: number;
  guesses_count: number;
  total_score: number;
  average_score: number;
  daily_challenges_played: number;
  best_daily_score: number;
}

/** Safe public trophy data — allow-listed server-side (v1.8.8). */
export interface PublicProfileBadge {
  code: string;
  name: string;
  description: string;
  icon: string;
  category: string;
  rarity: string;
  earned_at: string | null;
}

export interface PublicProfile {
  id: number;
  name: string;
  username: string;
  avatar_url: string | null;
  rank: PlayerRank;
  total_xp: number;
  stats: PublicProfileStats;
  badges: { earned_count: number; total_count: number; earned: PublicProfileBadge[] };
  is_friend: boolean;
  has_pending_request: boolean;
}
