export type LeagueStatus = 'lobby' | 'active' | 'completed' | 'cancelled';

export interface LobbyMember {
  id: number;
  name: string;
  username: string;
  is_owner: boolean;
  joined_at: string | null;
}

export interface League {
  id: number;
  name: string;
  join_code: string;
  duration_days: number;
  rounds_per_day: number;
  status: LeagueStatus;
  owner_user_id: number;
  is_owner: boolean;
  members_count: number;
  rounds_count: number;
  completed_rounds_count: number;
  remaining_rounds_count: number;
  progress_pct: number;
  starts_at: string | null;
  ends_at: string | null;
  members?: LobbyMember[];
}
