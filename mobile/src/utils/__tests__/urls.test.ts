import * as fs from 'fs';
import * as path from 'path';
import { resolveUrls, PRODUCTION_API_BASE_URL, PRODUCTION_WEB_URL } from '../urls';

const SRC = path.resolve(__dirname, '..', '..');

describe('backend URL resolution', () => {
  it('uses the configured env values, trimming trailing slashes', () => {
    expect(resolveUrls({ EXPO_PUBLIC_API_BASE_URL: 'http://192.168.1.5:8000/api/', EXPO_PUBLIC_WEB_URL: 'http://192.168.1.5:8000/' }))
      .toEqual({ apiBaseUrl: 'http://192.168.1.5:8000/api', webBaseUrl: 'http://192.168.1.5:8000' });
  });

  it('falls back to the production https origin, never to localhost', () => {
    const out = resolveUrls({});
    expect(out.apiBaseUrl).toBe(PRODUCTION_API_BASE_URL);
    expect(out.webBaseUrl).toBe(PRODUCTION_WEB_URL);
    expect(out.apiBaseUrl.startsWith('https://')).toBe(true);
    expect(JSON.stringify(out)).not.toMatch(/127\.0\.0\.1|localhost/);
  });

  it('derives the web origin from the API base when only that is set', () => {
    expect(resolveUrls({ EXPO_PUBLIC_API_BASE_URL: 'https://staging.example.com/api' }).webBaseUrl).toBe('https://staging.example.com');
    expect(resolveUrls({ EXPO_PUBLIC_API_BASE_URL: '   ' }).apiBaseUrl).toBe(PRODUCTION_API_BASE_URL);
  });

  it('is the single source for every screen (no per-file localhost fallback remains)', () => {
    for (const rel of ['api/client.ts', 'screens/ProfileScreen.tsx', 'screens/RegisterScreen.tsx', 'app/AppNavigator.tsx']) {
      const source = fs.readFileSync(path.join(SRC, rel), 'utf8');
      expect({ rel, localhost: /127\.0\.0\.1|localhost:8000/.test(source) }).toEqual({ rel, localhost: false });
      expect({ rel, usesShared: /from '\.\.\/utils\/urls'/.test(source) }).toEqual({ rel, usesShared: true });
    }
  });

  it('no screen renders a raw backend error message', () => {
    const dir = path.join(SRC, 'screens');
    const offenders: string[] = [];
    for (const file of fs.readdirSync(dir)) {
      const source = fs.readFileSync(path.join(dir, file), 'utf8');
      const re = /set\w+\(\s*(?:e|err|error)\?\.message\s*(?:\|\||\?\?)/g;
      if (re.test(source)) offenders.push(file);
    }
    expect(offenders).toEqual([]);
  });
});
