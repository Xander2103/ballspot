import { parseResetInput, looksLikeResetLink } from '../resetLink';

const TOKEN = 'a3f9c1e2b4d6a8f0c2e4b6d8a0f2c4e6b8d0a2f4c6e8b0d2a4f6c8e0b2d4a6f8';

describe('parseResetInput', () => {
  it('extracts token and email from the full https link in the email', () => {
    expect(
      parseResetInput(`https://ballpicker.vanmalderstudio.be/reset-password?token=${TOKEN}&email=jane%40example.com`),
    ).toEqual({ token: TOKEN, email: 'jane@example.com' });
  });

  it('extracts token and email from the ballpicker:// deep link', () => {
    expect(parseResetInput(`ballpicker://reset-password?token=${TOKEN}&email=jane%40example.com`)).toEqual({
      token: TOKEN,
      email: 'jane@example.com',
    });
  });

  it('accepts a bare token with surrounding whitespace', () => {
    expect(parseResetInput(`  ${TOKEN}\n`)).toEqual({ token: TOKEN, email: null });
  });

  it('handles a link without an email parameter', () => {
    expect(parseResetInput(`https://x.test/reset-password?token=${TOKEN}`)).toEqual({ token: TOKEN, email: null });
  });

  it('ignores a fragment and decodes plus-encoded emails', () => {
    expect(parseResetInput(`https://x.test/reset-password?email=a%2Bb%40x.test&token=${TOKEN}#top`)).toEqual({
      token: TOKEN,
      email: 'a+b@x.test',
    });
  });

  it('returns null for empty input, a short code or a link without a token', () => {
    expect(parseResetInput('')).toBeNull();
    expect(parseResetInput('123456')).toBeNull();
    expect(parseResetInput('https://x.test/reset-password?email=jane%40example.com')).toBeNull();
    expect(parseResetInput('not a token at all!')).toBeNull();
  });
});

describe('looksLikeResetLink', () => {
  it('detects http(s) and app-scheme links', () => {
    expect(looksLikeResetLink('https://x.test/reset-password?token=abc')).toBe(true);
    expect(looksLikeResetLink('ballpicker://reset-password?token=abc')).toBe(true);
  });

  it('does not flag a bare token', () => {
    expect(looksLikeResetLink(TOKEN)).toBe(false);
  });
});
