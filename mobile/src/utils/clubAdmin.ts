/**
 * Club admin in the app (contracts §9 Wave F) — pure, unit tested:
 * invite share text, invite code status/sorting, the create-code form,
 * member action availability, photo submission status and the report form.
 * The backend enforces every ability; this only decides what to SHOW.
 */
import { translate } from '../i18n/core';
import type { LanguageCode } from './language';
import { formatClubCode, normaliseClubCode } from './clubCode';
import { formatClubDate } from './clubDisplay';
import type {
  ClubAbilities, ClubMember, ClubReportBody, ClubRole, CreateInviteCodeBody, InviteCode, PhotoSubmissionStatus,
  ReportReason, ReportSubjectType,
} from '../types/club';

// --- Invite codes --------------------------------------------------------------

/**
 * The friendly invite text for the Share sheet: club name, the formatted code
 * and where to enter it. Never contains a link (codes are joined in the app).
 */
export function buildInviteShareMessage(params: { clubName: string; code: string }, locale?: LanguageCode): string {
  const code = formatClubCode(normaliseClubCode(params.code));
  return translate('clubs.invites.shareMessage', { club: params.clubName, code }, locale);
}

export type InviteCodeStatus = 'active' | 'revoked' | 'expired' | 'exhausted';

/** Revoked wins, then expired, then used up; otherwise active (the server's `is_usable` is the source of truth). */
export function inviteCodeStatus(code: Pick<InviteCode, 'revoked_at' | 'expires_at' | 'max_uses' | 'uses_count' | 'is_usable'>, nowMs: number): InviteCodeStatus {
  if (code.revoked_at) return 'revoked';
  if (code.expires_at && Date.parse(code.expires_at) <= nowMs) return 'expired';
  if (code.max_uses !== null && code.max_uses !== undefined && code.uses_count >= code.max_uses) return 'exhausted';
  return code.is_usable ? 'active' : 'expired';
}

export function inviteStatusKey(status: InviteCodeStatus): string {
  return `clubs.invites.status.${status}`;
}

/** Usable first (server order kept within each group — the sort is stable). */
export function sortInviteCodes<T extends Pick<InviteCode, 'is_usable'>>(codes: T[]): T[] {
  return codes
    .map((c, i) => ({ c, i }))
    .sort((a, b) => (a.c.is_usable === b.c.is_usable ? a.i - b.i : a.c.is_usable ? -1 : 1))
    .map((x) => x.c);
}

/** "41 / 100 uses" · "41 uses" · "Expires 1 Nov 2026". */
export function inviteCodeMeta(code: Pick<InviteCode, 'uses_count' | 'max_uses' | 'expires_at' | 'grants_role'>, locale?: LanguageCode): string[] {
  const parts: string[] = [];
  parts.push(code.max_uses
    ? translate('clubs.invites.usesOf', { used: code.uses_count, max: code.max_uses }, locale)
    : translate('clubs.invites.uses', { count: code.uses_count }, locale));
  if (code.expires_at) parts.push(translate('clubs.invites.expires', { date: formatClubDate(code.expires_at, locale) }, locale));
  if (code.grants_role === 'admin') parts.push(translate('clubs.invites.grantsAdmin', undefined, locale));
  return parts;
}

export type ExpiryPreset = 'never' | '1d' | '7d' | '30d';
export const EXPIRY_PRESETS: { key: ExpiryPreset; days: number | null }[] = [
  { key: 'never', days: null },
  { key: '1d', days: 1 },
  { key: '7d', days: 7 },
  { key: '30d', days: 30 },
];

export function expiresAtFor(preset: ExpiryPreset, nowMs: number): string | undefined {
  const days = EXPIRY_PRESETS.find((p) => p.key === preset)?.days ?? null;
  return days ? new Date(nowMs + days * 86400000).toISOString() : undefined;
}

export const INVITE_LABEL_MAX = 60;
export const INVITE_MAX_USES_LIMIT = 10000;

export interface InviteFormInput {
  label: string;
  maxUses: string;
  expiry: ExpiryPreset;
  grantsAdmin: boolean;
}

/** Validates the create-code form and builds the request body. */
export function buildInviteCodeBody(
  input: InviteFormInput,
  callerRole: ClubRole | string | null | undefined,
  nowMs: number,
): { ok: true; body: CreateInviteCodeBody } | { ok: false; errorKey: string } {
  const label = input.label.trim();
  if (label.length > INVITE_LABEL_MAX) return { ok: false, errorKey: 'clubs.invites.form.labelTooLong' };
  const rawUses = input.maxUses.trim();
  let maxUses: number | undefined;
  if (rawUses) {
    if (!/^\d+$/.test(rawUses)) return { ok: false, errorKey: 'clubs.invites.form.maxUsesInvalid' };
    maxUses = Number(rawUses);
    if (maxUses < 1 || maxUses > INVITE_MAX_USES_LIMIT) return { ok: false, errorKey: 'clubs.invites.form.maxUsesInvalid' };
  }
  if (input.grantsAdmin && !canGrantAdmin(callerRole)) return { ok: false, errorKey: 'clubs.invites.form.adminOwnerOnly' };
  const body: CreateInviteCodeBody = { grants_role: input.grantsAdmin ? 'admin' : 'member' };
  if (label) body.label = label;
  if (maxUses !== undefined) body.max_uses = maxUses;
  const expiresAt = expiresAtFor(input.expiry, nowMs);
  if (expiresAt) body.expires_at = expiresAt;
  return { ok: true, body };
}

/** Admin-granting codes are owner-only (same rule as the portal). */
export function canGrantAdmin(role: ClubRole | string | null | undefined): boolean {
  return role === 'owner';
}

// --- Club admin entry points ---------------------------------------------------

export interface ClubAdminEntries {
  invites: boolean;
  members: boolean;
  photos: boolean;
}

/** Which admin entries the club "⋯" menu shows. Missing abilities = hidden (fail closed). */
export function clubAdminEntries(abilities: Partial<ClubAbilities> | null | undefined): ClubAdminEntries {
  return {
    invites: abilities?.manage_codes === true,
    members: abilities?.view_members === true || abilities?.manage_members === true,
    photos: abilities?.upload_photos === true,
  };
}

// --- Member actions ------------------------------------------------------------

export type MemberAction = 'makeAdmin' | 'makeMember' | 'remove' | 'ban' | 'report';

/**
 * Actions on one member row, from the server's per-item `actions` flags:
 * role changes only between admin and member (never on an owner), remove /
 * ban as flagged, and "Report" for anyone but yourself.
 */
export function memberActions(member: Pick<ClubMember, 'role' | 'actions' | 'user_id'>, currentUserId: number | null): MemberAction[] {
  const out: MemberAction[] = [];
  const isSelf = currentUserId !== null && member.user_id === currentUserId;
  const flags = member.actions;
  if (flags?.change_role && !isSelf && member.role !== 'owner') {
    out.push(member.role === 'admin' ? 'makeMember' : 'makeAdmin');
  }
  if (flags?.remove && !isSelf && member.role !== 'owner') out.push('remove');
  if (flags?.ban && !isSelf && member.role !== 'owner') out.push('ban');
  if (!isSelf) out.push('report');
  return out;
}

export const MEMBER_REASON_MAX = 500;

// --- Photos --------------------------------------------------------------------

export const PHOTO_STATUSES: PhotoSubmissionStatus[] = ['pending', 'in_review', 'in_preparation', 'converted', 'rejected'];

export type StatusTone = 'info' | 'warning' | 'success' | 'error';

export function photoStatusKey(status: PhotoSubmissionStatus | string): string {
  return (PHOTO_STATUSES as string[]).includes(status) ? `clubs.photos.status.${status}` : 'clubs.photos.status.pending';
}

export function photoStatusTone(status: PhotoSubmissionStatus | string): StatusTone {
  if (status === 'converted') return 'success';
  if (status === 'rejected') return 'error';
  if (status === 'in_review' || status === 'in_preparation') return 'warning';
  return 'info';
}

/** How many photos one pick may add (and the queue may hold). */
export const MAX_PHOTOS_PER_BATCH = 10;
export const PHOTO_NOTE_MAX = 500;

export type UploadState = 'queued' | 'uploading' | 'uploaded' | 'failed';

/** Upload is possible with the consent ticked and at least one photo still to send. */
export function canUploadPhotos(rightsConfirmed: boolean, states: UploadState[]): boolean {
  return rightsConfirmed && states.some((s) => s === 'queued' || s === 'failed');
}

/**
 * Errors after which the rest of the batch is pointless (would fail the same
 * way): the club's open-submission limit, the upload throttle, lost access.
 */
export function stopsPhotoQueue(e: unknown): boolean {
  if (!e || typeof e !== 'object') return false;
  const { status, code } = e as { status?: unknown; code?: unknown };
  if (code === 'photo_pending_limit' || code === 'photo_rights_required' || code === 'club_forbidden' || code === 'club_not_found') return true;
  return status === 429 || status === 403 || status === 404;
}

// --- Reports -------------------------------------------------------------------

export const REPORT_REASONS: ReportReason[] = ['inappropriate', 'offensive', 'spam', 'privacy', 'other'];
export const REPORT_DETAILS_MAX = 500;

export interface ReportSubject {
  type: ReportSubjectType;
  /** Competition public_id or member user id. */
  id?: string | number | null;
}

/** Validates the report form and builds the request body. */
export function buildReportBody(
  subject: ReportSubject,
  reason: ReportReason | null,
  details: string,
): { ok: true; body: ClubReportBody } | { ok: false; errorKey: string } {
  if (!reason || !REPORT_REASONS.includes(reason)) return { ok: false, errorKey: 'clubs.report.reasonRequired' };
  const text = details.trim();
  if (text.length > REPORT_DETAILS_MAX) return { ok: false, errorKey: 'clubs.report.detailsTooLong' };
  if (subject.type !== 'club' && (subject.id === null || subject.id === undefined || subject.id === '')) {
    return { ok: false, errorKey: 'clubs.report.failed' };
  }
  const body: ClubReportBody = { subject_type: subject.type, reason };
  if (subject.type !== 'club') body.subject_id = String(subject.id);
  if (text) body.details = text;
  return { ok: true, body };
}

/** The report endpoint is throttled (5/hour): a 429 gets calm, specific copy. */
export function isReportRateLimited(e: unknown): boolean {
  return !!e && typeof e === 'object' && (e as { status?: unknown }).status === 429;
}
