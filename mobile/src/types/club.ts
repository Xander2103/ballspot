/**
 * BallPicker Clubs — response shapes of the member API (`/api/clubs…`).
 * Mirrors docs/clubs-implementation-contracts.md §5 exactly; keep the two in
 * sync. Clubs and competitions are addressed by their public ULID (`id`),
 * club packs by slug within the club.
 */
import type { ChallengePackDetail, PackProgress } from './pack';

export type ClubRole = 'owner' | 'admin' | 'member';
export type ClubStatus = 'active' | 'suspended';
export type ClubType = 'sports_club' | 'school' | 'company' | 'community' | 'other';

export type CompetitionPhase = 'upcoming' | 'active' | 'ended' | 'finalized' | 'cancelled' | 'voided';
export type ReplayMode = 'no_replay' | 'single_attempt' | 'multiple_attempts';
export type ScoreRule = 'first' | 'best';
export type NormalPlayPolicy = 'blocked' | 'allowed';

export interface ClubSport {
  slug: string;
  name: string;
  emoji: string;
}

/** An item of `GET /api/clubs` (My Clubs) and the base of the detail payload. */
export interface ClubCard {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  city: string | null;
  country_code: string | null;
  club_type: ClubType | string;
  sport: ClubSport | null;
  role: ClubRole;
  status: ClubStatus;
  packs_count: number;
  competitions: { active: number; upcoming: number };
  joined_at: string | null;
}

/** Cursor page meta shared by every paginated club list. */
export interface CursorMeta {
  next_cursor: string | null;
  per_page: number;
}

export interface CursorPage<T, M extends CursorMeta = CursorMeta> {
  data: T[];
  meta: M;
}

export interface MyClubsMeta extends CursorMeta {
  total_joined: number;
  query: string | null;
}

/** Progress of the player's NORMAL track on a club pack. */
export interface ClubPackProgress {
  status: PackProgress['status'];
  completed_count: number;
  total_challenges: number;
  total_score: number;
}

export interface ClubPackCompetitionRef {
  id: string;
  name: string;
  phase: CompetitionPhase;
}

/** An item of `GET /api/clubs/{club}/packs` (same as a public pack card, plus club fields). */
export interface ClubPackCard {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  sport: (ClubSport & { primary_color?: string; object_name?: string | null }) | null;
  cover_image_url: string | null;
  difficulty: string | null;
  challenge_count: number;
  is_featured: boolean;
  progress: ClubPackProgress | null;
  best_score: number | null;
  reserved_for_competition: boolean;
  competitions: ClubPackCompetitionRef[];
}

/** `GET /api/clubs/{club}/packs/{slug}`: the public pack detail + club fields. */
export interface ClubPackDetail extends ChallengePackDetail {
  reserved_for_competition: boolean;
  competitions: ClubPackCompetitionRef[];
}

export interface CompetitionRules {
  replay_mode: ReplayMode;
  max_attempts: number;
  score_rule: ScoreRule;
  normal_play_policy: NormalPlayPolicy;
}

export interface CompetitionCard {
  id: string;
  name: string;
  description: string | null;
  phase: CompetitionPhase;
  starts_at: string;
  ends_at: string;
  timezone: string;
  rules: CompetitionRules;
  packs_count: number;
  participants_count: number;
  me: { rank: number; total_score: number; packs_completed: number } | null;
}

/** One leaderboard row (club all-time and competition boards). Ranks may be shared: 1, 2, 2, 4. */
export interface ClubLeaderboardEntry {
  rank: number;
  user_id: number;
  username: string;
  avatar_url: string | null;
  total_score: number;
  packs_completed: number;
  attempts_used: number;
  is_current_user: boolean;
}

export interface ClubLeaderboardMeta extends CursorMeta {
  total_players: number;
  current_user_rank: number | null;
  current_user_score: number | null;
  better_than_percentage: number | null;
  top_users: ClubLeaderboardEntry[];
  nearby_users: ClubLeaderboardEntry[];
}

export interface ClubAbilities {
  view_members: boolean;
  leave: boolean;
  manage: boolean;
}

/** `GET /api/clubs/{club}` */
export interface ClubDetail extends ClubCard {
  description: string | null;
  region: string | null;
  members_count: number;
  abilities: ClubAbilities;
  packs_preview: ClubPackCard[];
  competitions_preview: {
    active: CompetitionCard[];
    upcoming: CompetitionCard[];
    completed_count: number;
  };
  leaderboard_preview: {
    top: ClubLeaderboardEntry[];
    me: ClubLeaderboardEntry | null;
  };
}

export interface ClubMember {
  user_id: number;
  username: string;
  avatar_url: string | null;
  role: ClubRole;
  joined_at: string | null;
}

export interface ClubMembersMeta extends CursorMeta {
  total: number;
}

export interface JoinClubResponse {
  data: ClubCard;
  /** False when the player already was an active member (idempotent join). */
  joined: boolean;
}

/** Filter of `GET /api/clubs/{club}/competitions` (completed = ended + finalized). */
export type CompetitionListPhase = 'active' | 'upcoming' | 'completed';
