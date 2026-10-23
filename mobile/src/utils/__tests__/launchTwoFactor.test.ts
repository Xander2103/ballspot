/**
 * Launch policy: login 2FA is not part of the public launch. The Profile
 * toggle that turns it ON must stay hidden unless the backend explicitly
 * allows new opt-ins (GET /api/config login_two_factor_opt_in), and the
 * client-side default must fail closed. Static source checks, same approach
 * as src/i18n/__tests__/screens.test.ts (RN components cannot render here).
 */
import * as fs from 'fs';
import * as path from 'path';

const SRC = path.resolve(__dirname, '..', '..');
const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

describe('login 2FA opt-in is off for launch', () => {
  it('the config client defaults the opt-in flag to false (fail closed)', () => {
    const source = read('api/configApi.ts');
    expect(source).toMatch(/login_two_factor_opt_in\?: boolean/);
    expect(source).toMatch(/login_two_factor_opt_in:\s*false/);
  });

  it('SettingsScreen only shows the toggle when the backend allows opt-in or 2FA is already on', () => {
    const source = read('screens/SettingsScreen.tsx');
    expect(source).toMatch(/const showTwoFactor = twoFactorOptIn \|\| twoFactorOn;/);
    expect(source).toMatch(/\{showTwoFactor \? \(/);
    expect(source).toMatch(/configApi\s*\.get\(\)/);
    expect(source).toMatch(/cfg\.login_two_factor_opt_in === true/);
    // The Switch must live inside the gated block, not render unconditionally.
    const gated = source.indexOf('{showTwoFactor ? (');
    const toggle = source.indexOf('onValueChange={handleTwoFactorToggle}');
    expect(gated).toBeGreaterThan(-1);
    expect(toggle).toBeGreaterThan(gated);
  });

  it('the 2FA screens and copy are kept for a later version (nothing removed)', () => {
    expect(fs.existsSync(path.join(SRC, 'screens/LoginVerificationScreen.tsx'))).toBe(true);
    expect(read('screens/LoginScreen.tsx')).toMatch(/isTwoFactorRequired\(result\)/);
    for (const file of ['en/errors.ts', 'nl.ts', 'fr.ts', 'de.ts', 'es.ts']) {
      expect(read(path.join('i18n/locales', file))).toMatch(/two_factor_required:/);
    }
  });
});
