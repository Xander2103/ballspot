import * as fs from 'fs';
import * as path from 'path';
import { STAGING_BUNDLE_ID, STAGING_UNCONFIGURED_API_BASE_URL, hostOf, variantFromExtra } from '../appVariant';
import { PRODUCTION_API_BASE_URL, PRODUCTION_WEB_URL, resolveUrls } from '../urls';

const MOBILE = path.resolve(__dirname, '..', '..', '..');

describe('app variant', () => {
  it('only an explicit appVariant "staging" is staging; everything else is production', () => {
    expect(variantFromExtra({ appVariant: 'staging', eas: {} })).toBe('staging');
    for (const extra of [undefined, null, {}, { appVariant: 'production' }, { appVariant: 'Staging' }, 'staging', { eas: { projectId: 'x' } }]) {
      expect(variantFromExtra(extra)).toBe('production');
    }
  });

  it('extracts hosts for the indicator', () => {
    expect(hostOf('https://Foo-Bar.trycloudflare.com/api')).toBe('foo-bar.trycloudflare.com');
    expect(hostOf('http://192.168.1.5:8088/api')).toBe('192.168.1.5');
    expect(hostOf('not a url')).toBeNull();
  });
});

describe('staging builds never fall back to production', () => {
  it('a staging build without URLs resolves to the unroutable sentinel', () => {
    const out = resolveUrls({}, 'staging');
    expect(out.apiBaseUrl).toBe(STAGING_UNCONFIGURED_API_BASE_URL);
    expect(JSON.stringify(out)).not.toContain('vanmalderstudio');
  });

  it('a staging build explicitly pointed at production is refused too', () => {
    const out = resolveUrls({ EXPO_PUBLIC_API_BASE_URL: PRODUCTION_API_BASE_URL, EXPO_PUBLIC_WEB_URL: PRODUCTION_WEB_URL }, 'staging');
    expect(out.apiBaseUrl).toBe(STAGING_UNCONFIGURED_API_BASE_URL);
    const mixed = resolveUrls({ EXPO_PUBLIC_API_BASE_URL: 'https://x.trycloudflare.com/api', EXPO_PUBLIC_WEB_URL: PRODUCTION_WEB_URL }, 'staging');
    expect(mixed.apiBaseUrl).toBe(STAGING_UNCONFIGURED_API_BASE_URL);
  });

  it('a staging build with a staging URL uses it', () => {
    expect(resolveUrls({ EXPO_PUBLIC_API_BASE_URL: 'https://x.trycloudflare.com/api' }, 'staging'))
      .toEqual({ apiBaseUrl: 'https://x.trycloudflare.com/api', webBaseUrl: 'https://x.trycloudflare.com' });
  });

  it('production behaviour is unchanged (fail-safe production fallback)', () => {
    expect(resolveUrls({}).apiBaseUrl).toBe(PRODUCTION_API_BASE_URL);
    expect(resolveUrls({}, 'production').apiBaseUrl).toBe(PRODUCTION_API_BASE_URL);
  });
});

describe('app.config.js', () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const appConfig = require(path.join(MOBILE, 'app.config.js'));
  const base = JSON.parse(fs.readFileSync(path.join(MOBILE, 'app.json'), 'utf8')).expo;

  afterEach(() => { delete process.env.APP_VARIANT; });

  it('returns app.json unchanged for production', () => {
    delete process.env.APP_VARIANT;
    expect(appConfig({ config: base })).toBe(base);
  });

  it('builds a separate staging identity', () => {
    process.env.APP_VARIANT = 'staging';
    const cfg = appConfig({ config: base });
    expect(cfg.ios.bundleIdentifier).toBe(STAGING_BUNDLE_ID);
    expect(cfg.android.package).toBe(STAGING_BUNDLE_ID);
    expect(cfg.ios.bundleIdentifier).not.toBe(base.ios.bundleIdentifier);
    expect(cfg.name).toBe('BallPicker STG');
    expect(cfg.scheme).toBe('ballpicker-staging');
    expect(cfg.extra.appVariant).toBe('staging');
    expect(cfg.extra.eas.projectId).toBe(base.extra.eas.projectId);
    expect(cfg.android.playStoreUrl).toBeUndefined();
    // The input config is not mutated.
    expect(base.ios.bundleIdentifier).toBe('be.vanmalderstudio.ballpicker');
  });
});
