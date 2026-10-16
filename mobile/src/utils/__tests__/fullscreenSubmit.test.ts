/**
 * Submitting from the fullscreen viewer: the viewer re-renders the screen's
 * own submit button and calls the screen's own handler — there is no second
 * submission implementation anywhere. RN components cannot be rendered under
 * this runner (see jest.config.js), so, like releaseAuditGuards.test.ts, these
 * checks read the sources.
 */
import * as fs from 'fs';
import * as path from 'path';
import { flattenKeys } from '../../i18n/core';
import { en } from '../../i18n/locales/en';

const SRC = path.resolve(__dirname, '..', '..');
const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

/** The JSX of the one <FullscreenImageViewer … /> element in a screen. */
function viewerElement(source: string): string {
  const m = source.match(/<FullscreenImageViewer[\s\S]*?\/>/g);
  expect(m).toHaveLength(1);
  return m![0];
}

const GAMEPLAY_SCREENS = [
  ['Daily', 'screens/DailyChallengeScreen.tsx', 'submitError'],
  ['Pack', 'screens/PackGuessScreen.tsx', 'error'],
  ['Tournament', 'screens/GuessScreen.tsx', 'submitError'],
] as const;

describe('fullscreen submit reuses the screen submission flow', () => {
  it.each(GAMEPLAY_SCREENS)('%s screen hands its own handler, title, in-flight flag and error to the viewer', (_name, file, errorVar) => {
    const source = read(file);
    const viewer = viewerElement(source);

    expect(viewer).toMatch(/\bselectable\b/);
    expect(viewer).toMatch(/onSelectPoint=\{handleGuess\}/);
    expect(viewer).toMatch(/onSubmit=\{handleSubmit\}/);
    expect(viewer).toMatch(/submitTitle=\{submitTitle\}/);
    expect(viewer).toMatch(/submitting=\{submitting\}/);
    expect(viewer).toMatch(new RegExp(`submitError=\\{${errorVar}\\}`));

    // The footer button uses the very same label variable and handler.
    expect(source).toMatch(/<AppButton[\s\S]*?title=\{submitTitle\}[\s\S]*?onPress=\{handleSubmit\}[\s\S]*?loading=\{submitting\}/);
    // Exactly one submission implementation per screen.
    expect(source.match(/async function handleSubmit\(\)/g)).toHaveLength(1);
    // Double-submit guard still first (pinned by releaseAuditGuards.test.ts too).
    expect(source).toMatch(/async function handleSubmit\(\) \{\s*(\/\/[^\n]*\n\s*)*if \(submitting\) return;/);
  });

  it('Pack keeps its final-challenge label on both buttons', () => {
    const source = read('screens/PackGuessScreen.tsx');
    expect(source).toMatch(/const submitTitle = isFinal \? t\('packs\.guess\.submitFinal'\) : t\('packs\.guess\.submit'\);/);
  });

  it('Daily and Tournament keep the shared "Submit Guess" label', () => {
    for (const file of ['screens/DailyChallengeScreen.tsx', 'screens/GuessScreen.tsx']) {
      expect(read(file)).toMatch(/const submitTitle = t\('game\.buttons\.submitGuess'\);/);
    }
  });
});

describe('the viewer itself', () => {
  const viewer = read('components/FullscreenImageViewer.tsx');

  it('has no submission logic of its own (no API import, no navigation)', () => {
    expect(viewer).not.toMatch(/from '\.\.\/api\//);
    expect(viewer).not.toMatch(/navigation\./);
    expect(viewer).not.toMatch(/submitGuess\(|dailyApi|packApi|roundApi/);
  });

  it('renders the same AppButton with the screen title, loading and disabled rules', () => {
    expect(viewer).toMatch(/import \{ AppButton \} from '\.\/AppButton';/);
    expect(viewer).toMatch(/<AppButton[\s\S]*?title=\{submitTitle \?\? t\('game\.buttons\.submitGuess'\)\}[\s\S]*?onPress=\{onSubmit!\}[\s\S]*?loading=\{submitting\}[\s\S]*?disabled=\{!selectedPoint \|\| submitting\}/);
    expect(viewer).toMatch(/\{submitError \? <Text style=\{styles\.submitError\}>\{submitError\}<\/Text> : null\}/);
  });

  it('keeps the button inside the bottom safe area and out of the gesture layer', () => {
    expect(viewer).toMatch(/useSafeAreaInsets/);
    expect(viewer).toMatch(/paddingBottom: Math\.max\(insets\.bottom, spacing\.md\)/);
    // Gesture layer (pan handlers) is declared before the submit bar, so the bar is on top.
    const gestureAt = viewer.indexOf('{...responder.panHandlers}');
    const barAt = viewer.indexOf('styles.submitBar');
    expect(gestureAt).toBeGreaterThan(0);
    expect(barAt).toBeGreaterThan(gestureAt);
  });

  it('maps taps through the zoom transform and derives the marker from ratios on every render', () => {
    expect(viewer).toMatch(/screenPointToRatios\(currentRect\(\), layoutRef\.current, transformRef\.current, x, y\)/);
    expect(viewer).toMatch(/ratiosToScreenPoint\(rect, layout, transform, selectedPoint\.x, selectedPoint\.y\)/);
    expect(viewer).toMatch(/PanResponder\.create\(/);
    // Zoom/pan is plain React Native: no new native dependency for the release build.
    expect(viewer).not.toMatch(/react-native-gesture-handler|react-native-reanimated/);
  });

  it('uses translation keys that exist', () => {
    const keys = new Set(flattenKeys(en));
    for (const key of ['game.image.pinchToZoom', 'game.image.tapToPlace', 'game.image.tapToAdjust', 'game.image.closeFullscreenA11y', 'game.buttons.submitGuess', 'common.buttons.close']) {
      expect(viewer).toContain(`'${key}'`);
      expect(keys.has(key)).toBe(true);
    }
  });
});

describe('view-only surfaces are untouched', () => {
  it.each(['components/ResultImageSection.tsx', 'screens/PackResultScreen.tsx'])('%s opens the viewer without a submit bar', (file) => {
    const viewer = viewerElement(read(file));
    expect(viewer).not.toMatch(/onSubmit|selectable/);
  });
});
