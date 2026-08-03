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

export interface PublicProfile {
  id: number;
  name: string;
  username: string;
  avatar_url: string | null;
  rank: PlayerRank;
  total_xp: number;
  stats: PublicProfileStats;
  badges: { earned_count: number; total_count: number };
  is_friend: boolean;
  has_pending_request: boolean;
}
