/** AT-MOB-01: club invite code normalising / formatting / parsing. */
import {
  clubQrPayload, formatClubCode, isFriendQrPayload, isValidClubCode, normaliseClubCode, parseClubCode,
} from '../clubCode';

describe('normaliseClubCode', () => {
  it.each([
    ['abcd-efgh', 'ABCDEFGH'],
    ['ABCD EFGH', 'ABCDEFGH'],
    ['  ab cd - ef gh ', 'ABCDEFGH'],
    ['abcd–efgh', 'ABCDEFGH'], // en dash from a pasted message
    ['abcd_efgh', 'ABCDEFGH'],
    ['', ''],
  ])('%j → %j', (raw, expected) => {
    expect(normaliseClubCode(raw)).toBe(expected);
  });

  it('tolerates null/undefined', () => {
    expect(normaliseClubCode(null)).toBe('');
    expect(normaliseClubCode(undefined)).toBe('');
  });
});

describe('formatClubCode (as-you-type)', () => {
  it.each([
    ['a', 'A'],
    ['abcd', 'ABCD'],
    ['abcde', 'ABCD-E'],
    ['abcdefgh', 'ABCD-EFGH'],
    ['ABCD-EFGH', 'ABCD-EFGH'],
    ['abcdefghxyz', 'ABCD-EFGH'], // capped at 8
    ['ab!cd?ef#gh', 'ABCD-EFGH'], // punctuation dropped
    ['ballpicker:club:abcd-efgh', 'ABCD-EFGH'], // pasted QR payload
  ])('%j → %j', (raw, expected) => {
    expect(formatClubCode(raw)).toBe(expected);
  });

  it('is idempotent', () => {
    expect(formatClubCode(formatClubCode('wxyz2345'))).toBe('WXYZ-2345');
  });
});

describe('parseClubCode', () => {
  it.each([
    ['ABCDEFGH', 'ABCDEFGH'],
    ['abcd-efgh', 'ABCDEFGH'],
    [' ab cd ef gh ', 'ABCDEFGH'],
    ['ballpicker:club:ABCD-EFGH', 'ABCDEFGH'],
    ['BALLPICKER:CLUB:abcdefgh', 'ABCDEFGH'],
    ['  ballpicker:club:wxyz2345  ', 'WXYZ2345'],
  ])('accepts %j', (raw, expected) => {
    expect(parseClubCode(raw)).toBe(expected);
  });

  it.each([
    ['ABCDEFG'], // too short
    ['ABCDEFGHJ'], // too long
    ['ABCDEFG0'], // 0 is not in the alphabet
    ['ABCDEFGI'], // I is not in the alphabet
    ['ABCDEFGO'], // O is not in the alphabet
    ['ABCDEFG1'], // 1 is not in the alphabet
    ['ballpicker:friend:ABCDEFGH'],
    ['https://example.com/ABCDEFGH'],
    [''],
  ])('rejects %j', (raw) => {
    expect(parseClubCode(raw)).toBeNull();
  });

  it('round-trips the QR payload', () => {
    expect(parseClubCode(clubQrPayload('abcd-efgh'))).toBe('ABCDEFGH');
    expect(clubQrPayload('abcd-efgh')).toBe('ballpicker:club:ABCDEFGH');
  });
});

describe('helpers', () => {
  it('isValidClubCode only accepts normalised 8-character codes', () => {
    expect(isValidClubCode('ABCDEFGH')).toBe(true);
    expect(isValidClubCode('abcdefgh')).toBe(false);
    expect(isValidClubCode('ABCD-EFGH')).toBe(false);
  });

  it('recognises a friend QR so the club scanner can say so', () => {
    expect(isFriendQrPayload('ballpicker:friend:ABC234')).toBe(true);
    expect(isFriendQrPayload('ballpicker:club:ABCDEFGH')).toBe(false);
  });
});
