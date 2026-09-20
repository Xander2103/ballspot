import * as fs from 'fs';
import * as path from 'path';
import { resolveNotice, pickNoticeMessage, noticePalette } from '../notice';

const SRC = path.resolve(__dirname, '..', '..');
const theme = { accent: '#2979ff', warning: '#ffab40', success: '#00c853', text: '#fff' };

describe('resolveNotice (Home reads the active notice)', () => {
  it('renders the notice the API returns', () => {
    expect(resolveNotice({ notice: { placement: 'home_daily_card', type: 'warning', message: 'Daily login starts tomorrow' } }))
      .toEqual({ placement: 'home_daily_card', type: 'warning', message: 'Daily login starts tomorrow' });
    // A bare notice body works too.
    expect(resolveNotice({ placement: 'home_daily_card', type: 'success', message: 'New Daily Challenge starts soon' })?.type).toBe('success');
  });

  it('hides the notice when the API returns null or nothing usable', () => {
    expect(resolveNotice({ notice: null })).toBeNull();
    expect(resolveNotice(null)).toBeNull();
    expect(resolveNotice(undefined)).toBeNull();
    expect(resolveNotice('')).toBeNull();
    expect(resolveNotice({ notice: { type: 'info', message: '   ' } })).toBeNull();
    expect(resolveNotice({ notice: { type: 'info' } })).toBeNull();
    expect(resolveNotice([])).toBeNull();
  });

  it('falls back to English, then any language, when the localized text is missing', () => {
    const messages = { nl: '', fr: null, en: 'Daily login starts tomorrow' };
    expect(resolveNotice({ notice: { type: 'info', messages } }, 'nl')?.message).toBe('Daily login starts tomorrow');
    expect(resolveNotice({ notice: { type: 'info', messages: { es: 'Empieza mañana' } } }, 'de')?.message).toBe('Empieza mañana');
    expect(resolveNotice({ notice: { type: 'info', messages: { nl: 'Start morgen', en: 'Starts tomorrow' } } }, 'nl')?.message).toBe('Start morgen');
    expect(pickNoticeMessage({ nl: ' ', en: ' ' }, 'nl')).toBeNull();
    expect(pickNoticeMessage('nope', 'nl')).toBeNull();
  });

  it('normalizes unknown type and placement to safe values', () => {
    const n = resolveNotice({ notice: { placement: 'somewhere_else', type: 'danger', message: 'Hi' } });
    expect(n).toEqual({ placement: 'home_daily_card', type: 'info', message: 'Hi' });
  });
});

describe('noticePalette (type → colour)', () => {
  it('maps info / warning / success onto the theme tokens', () => {
    expect(noticePalette('info', theme).accent).toBe(theme.accent);
    expect(noticePalette('warning', theme).accent).toBe(theme.warning);
    expect(noticePalette('success', theme).accent).toBe(theme.success);
  });
});

describe('Home wiring', () => {
  const home = fs.readFileSync(path.join(SRC, 'screens', 'HomeScreen.tsx'), 'utf8');

  it('fetches the notice next to the daily data without blocking it (allSettled) and renders it right above the Daily card', () => {
    expect(home).toMatch(/noticesApi\.active\('home_daily_card'\)/);
    expect(home).toMatch(/Promise\.allSettled\(\[\s*dailyApi\.today\([^)]*\),\s*dailyApi\.stats\(\),\s*noticesApi\.active/);
    expect(home).toMatch(/resolveNotice\(/);
    // NoticeCard is rendered before the daily card block, inside the same scroll content.
    const noticeAt = home.indexOf('<NoticeCard');
    const dailyAt = home.indexOf('{dailyLoading ? (');
    expect(noticeAt).toBeGreaterThan(-1);
    expect(dailyAt).toBeGreaterThan(noticeAt);
  });

  it('renders nothing at all when there is no notice, so the daily card layout is unchanged', () => {
    expect(home).toMatch(/if \(!notice\) return null;/);
    // The daily card keeps its own style object; the notice has its own.
    expect(home).toMatch(/dailyCard: \{\s*backgroundColor: theme\.surface, borderRadius: 16, padding: spacing\.md,\s*marginBottom: spacing\.md, borderWidth: 1, borderColor: theme\.border,\s*\}/);
    expect(home).toMatch(/noticeCard: \{/);
  });
});
