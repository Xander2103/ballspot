/** Club admin in the app (Wave F): invite share text, code status/sorting, forms, member actions, photos, reports. */
import { registerTranslations, translate, hasTranslation } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';
import { fr } from '../../i18n/locales/fr';
import { de } from '../../i18n/locales/de';
import { es } from '../../i18n/locales/es';
import {
  buildInviteCodeBody, buildInviteShareMessage, buildReportBody, canGrantAdmin, canUploadPhotos, clubAdminEntries,
  expiresAtFor, inviteCodeMeta, inviteCodeStatus, inviteStatusKey, isReportRateLimited, memberActions, photoStatusKey,
  photoStatusTone, PHOTO_STATUSES, REPORT_REASONS, sortInviteCodes, stopsPhotoQueue,
} from '../clubAdmin';
import type { InviteCode } from '../../types/club';

beforeAll(() => registerTranslations({ en, nl, fr, de, es }, 'en'));

const NOW = Date.parse('2026-10-20T10:00:00Z');
const code = (over: Partial<InviteCode> = {}): InviteCode => ({
  id: 1, code: 'ABCD-EFGH', qr_payload: 'ballpicker:club:ABCDEFGH', label: null, grants_role: 'member',
  uses_count: 0, max_uses: null, expires_at: null, revoked_at: null, is_usable: true, created_at: '2026-10-01T00:00:00Z', ...over,
});

describe('invite share text', () => {
  it('names the club and carries the formatted code in every language', () => {
    for (const locale of ['en', 'nl', 'fr', 'de', 'es'] as const) {
      const msg = buildInviteShareMessage({ clubName: 'KFC Example', code: 'abcdefgh' }, locale);
      expect(msg).toContain('KFC Example');
      expect(msg).toContain('ABCD-EFGH');
      expect(msg).not.toMatch(/\{\{|https?:\/\//);
    }
  });
});

describe('invite code status + sorting', () => {
  it.each([
    [code(), 'active'],
    [code({ revoked_at: '2026-10-02T00:00:00Z', is_usable: false }), 'revoked'],
    [code({ expires_at: '2026-10-19T00:00:00Z', is_usable: false }), 'expired'],
    [code({ max_uses: 10, uses_count: 10, is_usable: false }), 'exhausted'],
    [code({ is_usable: false }), 'expired'],
  ] as const)('%#', (c, expected) => {
    expect(inviteCodeStatus(c, NOW)).toBe(expected);
    expect(translate(inviteStatusKey(expected))).not.toBe(inviteStatusKey(expected));
  });

  it('usable first, server order kept within each group', () => {
    const list = [code({ id: 1, is_usable: false }), code({ id: 2 }), code({ id: 3, is_usable: false }), code({ id: 4 })];
    expect(sortInviteCodes(list).map((c) => c.id)).toEqual([2, 4, 1, 3]);
  });

  it('meta line', () => {
    expect(inviteCodeMeta(code({ uses_count: 41, max_uses: 100 }), 'en')).toEqual(['41 / 100 uses']);
    expect(inviteCodeMeta(code({ uses_count: 1, grants_role: 'admin' }), 'en')).toEqual(['1 use', 'Grants admin']);
    expect(inviteCodeMeta(code({ expires_at: '2026-11-01T10:00:00Z' }), 'en')[1]).toMatch(/^Expires .*2026/);
  });
});

describe('create-code form', () => {
  const base = { label: '', maxUses: '', expiry: 'never' as const, grantsAdmin: false };

  it('builds a minimal member code', () => {
    expect(buildInviteCodeBody(base, 'admin', NOW)).toEqual({ ok: true, body: { grants_role: 'member' } });
  });

  it('label, max uses and expiry preset', () => {
    const res = buildInviteCodeBody({ label: '  U15 parents ', maxUses: '100', expiry: '7d', grantsAdmin: false }, 'admin', NOW);
    expect(res).toEqual({ ok: true, body: { grants_role: 'member', label: 'U15 parents', max_uses: 100, expires_at: '2026-10-27T10:00:00.000Z' } });
    expect(expiresAtFor('never', NOW)).toBeUndefined();
    expect(expiresAtFor('1d', NOW)).toBe('2026-10-21T10:00:00.000Z');
  });

  it('rejects bad input', () => {
    expect(buildInviteCodeBody({ ...base, maxUses: '0' }, 'owner', NOW)).toEqual({ ok: false, errorKey: 'clubs.invites.form.maxUsesInvalid' });
    expect(buildInviteCodeBody({ ...base, maxUses: '1.5' }, 'owner', NOW)).toEqual({ ok: false, errorKey: 'clubs.invites.form.maxUsesInvalid' });
    expect(buildInviteCodeBody({ ...base, maxUses: '20000' }, 'owner', NOW)).toEqual({ ok: false, errorKey: 'clubs.invites.form.maxUsesInvalid' });
    expect(buildInviteCodeBody({ ...base, label: 'x'.repeat(61) }, 'owner', NOW)).toEqual({ ok: false, errorKey: 'clubs.invites.form.labelTooLong' });
  });

  it('admin-granting codes are owner-only', () => {
    expect(canGrantAdmin('owner')).toBe(true);
    expect(canGrantAdmin('admin')).toBe(false);
    expect(buildInviteCodeBody({ ...base, grantsAdmin: true }, 'admin', NOW)).toEqual({ ok: false, errorKey: 'clubs.invites.form.adminOwnerOnly' });
    expect(buildInviteCodeBody({ ...base, grantsAdmin: true }, 'owner', NOW)).toEqual({ ok: true, body: { grants_role: 'admin' } });
  });
});

describe('admin entries + member actions', () => {
  it('admin entries fail closed', () => {
    expect(clubAdminEntries(undefined)).toEqual({ invites: false, members: false, photos: false });
    expect(clubAdminEntries({ view_members: false, manage_codes: true, upload_photos: true, manage_members: true }))
      .toEqual({ invites: true, members: true, photos: true });
    expect(clubAdminEntries({ view_members: true })).toEqual({ invites: false, members: true, photos: false });
  });

  const flags = { change_role: true, remove: true, ban: true };
  it('owner viewing an admin / a member', () => {
    expect(memberActions({ user_id: 2, role: 'admin', actions: flags }, 1)).toEqual(['makeMember', 'remove', 'ban', 'report']);
    expect(memberActions({ user_id: 3, role: 'member', actions: flags }, 1)).toEqual(['makeAdmin', 'remove', 'ban', 'report']);
  });

  it('admin (no role changes) and plain member (report only)', () => {
    expect(memberActions({ user_id: 3, role: 'member', actions: { change_role: false, remove: true, ban: true } }, 1)).toEqual(['remove', 'ban', 'report']);
    expect(memberActions({ user_id: 3, role: 'member', actions: { change_role: false, remove: false, ban: false } }, 1)).toEqual(['report']);
    expect(memberActions({ user_id: 3, role: 'member' }, null)).toEqual(['report']);
  });

  it('never on yourself, never role/remove/ban on an owner', () => {
    expect(memberActions({ user_id: 1, role: 'admin', actions: flags }, 1)).toEqual([]);
    expect(memberActions({ user_id: 5, role: 'owner', actions: flags }, 1)).toEqual(['report']);
  });
});

describe('photos', () => {
  it('every status has a label and a tone', () => {
    for (const s of PHOTO_STATUSES) {
      expect(hasTranslation(photoStatusKey(s), 'en')).toBe(true);
      expect(hasTranslation(photoStatusKey(s), 'de')).toBe(true);
    }
    expect(photoStatusKey('weird')).toBe('clubs.photos.status.pending');
    expect(photoStatusTone('converted')).toBe('success');
    expect(photoStatusTone('rejected')).toBe('error');
    expect(photoStatusTone('in_review')).toBe('warning');
    expect(photoStatusTone('pending')).toBe('info');
  });

  it('upload needs the consent and something left to send', () => {
    expect(canUploadPhotos(false, ['queued'])).toBe(false);
    expect(canUploadPhotos(true, [])).toBe(false);
    expect(canUploadPhotos(true, ['uploaded'])).toBe(false);
    expect(canUploadPhotos(true, ['uploaded', 'failed'])).toBe(true);
  });

  it('stops the batch on limit / throttle / lost access, continues on a single bad photo', () => {
    expect(stopsPhotoQueue({ status: 422, code: 'photo_pending_limit' })).toBe(true);
    expect(stopsPhotoQueue({ status: 429 })).toBe(true);
    expect(stopsPhotoQueue({ status: 403, code: 'club_forbidden' })).toBe(true);
    expect(stopsPhotoQueue({ status: 422, code: 'photo_duplicate' })).toBe(false);
    expect(stopsPhotoQueue({ status: 422, code: 'photo_invalid' })).toBe(false);
    expect(stopsPhotoQueue(new TypeError('Network request failed'))).toBe(false);
  });
});

describe('report form', () => {
  it('requires one of the five reasons', () => {
    expect(REPORT_REASONS).toEqual(['inappropriate', 'offensive', 'spam', 'privacy', 'other']);
    expect(buildReportBody({ type: 'club' }, null, '')).toEqual({ ok: false, errorKey: 'clubs.report.reasonRequired' });
    expect(buildReportBody({ type: 'club' }, 'nope' as never, '')).toEqual({ ok: false, errorKey: 'clubs.report.reasonRequired' });
  });

  it('club / competition / member bodies', () => {
    expect(buildReportBody({ type: 'club' }, 'spam', '  ')).toEqual({ ok: true, body: { subject_type: 'club', reason: 'spam' } });
    expect(buildReportBody({ type: 'competition', id: '01JCOMP' }, 'other', ' late ')).toEqual({
      ok: true, body: { subject_type: 'competition', subject_id: '01JCOMP', reason: 'other', details: 'late' },
    });
    expect(buildReportBody({ type: 'member', id: 33 }, 'offensive', '')).toEqual({
      ok: true, body: { subject_type: 'member', subject_id: '33', reason: 'offensive' },
    });
    expect(buildReportBody({ type: 'member' }, 'offensive', '').ok).toBe(false);
  });

  it('details are capped at 500 characters', () => {
    expect(buildReportBody({ type: 'club' }, 'other', 'x'.repeat(500)).ok).toBe(true);
    expect(buildReportBody({ type: 'club' }, 'other', 'x'.repeat(501))).toEqual({ ok: false, errorKey: 'clubs.report.detailsTooLong' });
  });

  it('every reason has a label in every language; 429 is recognised', () => {
    for (const r of REPORT_REASONS) {
      for (const l of ['en', 'nl', 'fr', 'de', 'es'] as const) expect(hasTranslation(`clubs.report.reasons.${r}`, l)).toBe(true);
    }
    expect(isReportRateLimited({ status: 429 })).toBe(true);
    expect(isReportRateLimited({ status: 422 })).toBe(false);
  });
});
