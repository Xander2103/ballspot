/**
 * BallPicker Clubs — response shapes of the member API (`/api/clubs…`).
 * Mirrors docs/clubs-implementation-contracts.md §5 exactly; keep the two in
 * sync. Clubs and competitions are addressed by their public ULID (`id`),
 * club packs by slug within the club.
 */
import type { ChallengePackDetail, PackChallengeSummary, PackProgress } from './pack';

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
  /** Wave F club admin (absent on older backends → treated as false). */
  manage_codes?: boolean;
  manage_members?: boolean;
  upload_photos?: boolean;
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

/** What the CALLER may do with this member (server-computed; all false for plain members). */
export interface ClubMemberActions {
  change_role: boolean;
  remove: boolean;
  ban: boolean;
}

export interface ClubMember {
  user_id: number;
  username: string;
  avatar_url: string | null;
  role: ClubRole;
  joined_at: string | null;
  actions?: ClubMemberActions;
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

// --- Competitions (contracts §5) -------------------------------------------

/** Why a competition pack cannot be started right now (server-computed). */
export type CompetitionBlockedReason =
  | 'not_started'
  | 'ended'
  | 'limit_reached'
  | 'already_played'
  | 'attempt_in_progress'
  | 'club_unavailable';

/** The player's per-pack eligibility in a competition. The app only renders it; every start re-checks. */
export interface CompetitionPackState {
  attempts_used: number;
  attempts_remaining: number;
  next_attempt_number: number | null;
  can_start: boolean;
  blocked_reason: CompetitionBlockedReason | null;
  active_attempt_id: number | null;
  counted_score: number | null;
  best_score: number | null;
  first_score: number | null;
}

export interface CompetitionPack {
  id: number;
  slug: string;
  name: string;
  cover_image_url: string | null;
  challenge_count: number;
  state: CompetitionPackState;
}

export interface CompetitionMyEntry {
  rank: number;
  total_score: number;
  packs_completed: number;
  attempts_used: number;
  score_reached_at: string | null;
}

/** `GET /api/clubs/{club}/competitions/{competition}` */
export interface CompetitionDetail extends CompetitionCard {
  packs: CompetitionPack[];
  my_entry: CompetitionMyEntry | null;
  leaderboard_preview: ClubLeaderboardEntry[];
}

export type CompetitionAttemptStatus = 'active' | 'completed' | 'expired' | 'voided';

export interface CompetitionAttempt {
  id: number;
  competition_id: string;
  pack_slug: string;
  attempt_number: number;
  status: CompetitionAttemptStatus;
  current_index: number;
  completed_count: number;
  total_challenges: number;
  total_score: number;
  /** False for replays / extra attempts: they score for the competition but earn no global XP (D3). */
  is_rewarded_run: boolean;
  started_at: string | null;
  completed_at: string | null;
}

/** Start/resume and GET attempt: the attempt + its current challenge (pack challenge payload shape). */
export interface CompetitionAttemptResponse {
  attempt: CompetitionAttempt;
  challenge: PackChallengeSummary | null;
}

/** The competition block of a competition guess response. */
export interface CompetitionGuessBlock {
  counted_score: number | null;
  total_score: number | null;
  rank: number | null;
  attempts_remaining: number | null;
}

export interface CompetitionLeaderboardMeta extends ClubLeaderboardMeta {
  rules: CompetitionRules;
  phase: CompetitionPhase;
}

/** `GET /api/me/club-finishes` — the player's own club competition placements (club-only trophies). */
export interface ClubFinish {
  competition: { id: string; name: string };
  club: { id: string; name: string; logo_url: string | null };
  placement: number;
  total_score: number;
  total_players: number;
  finalized_at: string | null;
}

/** What PackResult hands to the competition complete view (competition attempts have no pack completion summary). */
export interface CompetitionOutcome {
  competition: CompetitionGuessBlock | null;
  finalScore: number;
  totalChallenges: number;
  isRewardedRun: boolean;
  completionXp: number;
}

// --- Club admin in the app (contracts §9 Wave F) ------------------------------

export interface InviteCode {
  id: number;
  /** Display form, e.g. "ABCD-EFGH". */
  code: string;
  /** QR content, e.g. "ballpicker:club:ABCDEFGH". */
  qr_payload: string;
  label: string | null;
  grants_role: 'member' | 'admin';
  uses_count: number;
  max_uses: number | null;
  expires_at: string | null;
  revoked_at: string | null;
  is_usable: boolean;
  created_at: string | null;
}

export interface CreateInviteCodeBody {
  label?: string;
  max_uses?: number;
  expires_at?: string;
  grants_role?: 'member' | 'admin';
}

export type PhotoSubmissionStatus = 'pending' | 'in_review' | 'in_preparation' | 'converted' | 'rejected';

export interface PhotoSubmission {
  id: number;
  status: PhotoSubmissionStatus;
  club_note: string | null;
  rejection_reason: string | null;
  /** Signed, short-lived URL. */
  thumbnail_url: string | null;
  created_at: string | null;
}

export type ReportSubjectType = 'club' | 'competition' | 'member';
export type ReportReason = 'inappropriate' | 'offensive' | 'spam' | 'privacy' | 'other';

export interface ClubReportBody {
  subject_type: ReportSubjectType;
  /** Competition public_id or member user id (as a string). Absent for the club itself. */
  subject_id?: string;
  reason: ReportReason;
  details?: string;
}
