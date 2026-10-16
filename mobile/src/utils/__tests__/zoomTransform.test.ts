/**
 * Coordinate synchronisation between the fullscreen viewer (zoomed, panned,
 * rotated) and the normal picker: a guess is stored as image ratios, and the
 * maths that maps taps and markers must round-trip under every transform.
 */
import { containRect, ratiosToPoint } from '../imageLayout';
import {
  IDENTITY,
  MAX_SCALE,
  MIN_SCALE,
  TAP_SLOP_PX,
  Transform,
  clampTransform,
  isTap,
  panTransform,
  pinchTransform,
  ratiosToScreenPoint,
  screenPointToRatios,
  screenToUnzoomed,
  touchDistance,
  touchMidpoint,
} from '../zoomTransform';

// Portrait phone, 4:3 landscape image -> 400x300 letterboxed at y=250.
const viewport = { width: 400, height: 800 };
const rect = containRect(viewport.width, viewport.height, 4 / 3);

const RATIOS = [
  { x: 0, y: 0 }, { x: 1, y: 1 }, { x: 0.5, y: 0.5 }, { x: 0.25, y: 0.75 }, { x: 0.9, y: 0.1 }, { x: 0.333, y: 0.667 },
];

describe('identity transform (no zoom)', () => {
  it('maps exactly like the plain contain geometry the viewer always used', () => {
    expect(screenPointToRatios(rect, viewport, IDENTITY, 200, 400)).toEqual({ xRatio: 0.5, yRatio: 0.5 });
    expect(ratiosToScreenPoint(rect, viewport, IDENTITY, 0, 0)).toEqual({ x: 0, y: 250 });
    expect(ratiosToScreenPoint(rect, viewport, IDENTITY, 1, 1)).toEqual({ x: 400, y: 550 });
  });

  it('ignores taps on the letterbox, as before', () => {
    expect(screenPointToRatios(rect, viewport, IDENTITY, 200, 100)).toBeNull();
    expect(screenPointToRatios(rect, viewport, IDENTITY, 200, 700)).toBeNull();
  });

  it('rejects non-finite taps', () => {
    expect(screenPointToRatios(rect, viewport, IDENTITY, NaN, 400)).toBeNull();
    expect(screenPointToRatios(rect, viewport, IDENTITY, 200, Infinity)).toBeNull();
  });
});

describe('a guess keeps its image position under zoom and pan', () => {
  const transforms: Transform[] = [
    IDENTITY,
    { scale: 2, tx: 0, ty: 0 },
    { scale: 3, tx: -150, ty: 40 },
    { scale: MAX_SCALE, tx: 300, ty: -200 },
    { scale: 1.37, tx: 12.5, ty: 0 },
  ].map((t) => clampTransform(t, rect, viewport));

  it.each(transforms)('tap -> ratios -> marker -> ratios round-trips under %o', (t) => {
    for (const r of RATIOS) {
      const marker = ratiosToScreenPoint(rect, viewport, t, r.x, r.y);
      const back = screenPointToRatios(rect, viewport, t, marker.x, marker.y);
      expect(back).not.toBeNull();
      expect(back!.xRatio).toBeCloseTo(r.x, 9);
      expect(back!.yRatio).toBeCloseTo(r.y, 9);
    }
  });

  it('the marker sits over the same image pixel whatever the zoom', () => {
    // The unzoomed position of the marker is the one the identity mapping gives.
    for (const t of transforms) {
      for (const r of RATIOS) {
        const marker = ratiosToScreenPoint(rect, viewport, t, r.x, r.y);
        const unzoomed = screenToUnzoomed(marker, t, viewport);
        const plain = ratiosToPoint(rect, r.x, r.y);
        expect(unzoomed.x).toBeCloseTo(plain.x, 9);
        expect(unzoomed.y).toBeCloseTo(plain.y, 9);
      }
    }
  });

  it('a tap while zoomed selects the image point under the finger, not the unzoomed one', () => {
    const t = clampTransform({ scale: 2, tx: 100, ty: 0 }, rect, viewport);
    // Screen (200, 400) is the viewport centre; under scale 2 + tx 100 the
    // image point there is 50px left of the image centre in unzoomed space.
    const r = screenPointToRatios(rect, viewport, t, 200, 400)!;
    expect(r.xRatio).toBeCloseTo((200 - 50) / 400, 9);
    expect(r.yRatio).toBeCloseTo(0.5, 9);
  });

  it('survives a change of viewport dimensions (rotation / other phone)', () => {
    const landscape = { width: 800, height: 400 };
    const rect2 = containRect(landscape.width, landscape.height, 4 / 3); // 533x400 centred
    for (const r of RATIOS) {
      const p = ratiosToScreenPoint(rect2, landscape, IDENTITY, r.x, r.y);
      const back = screenPointToRatios(rect2, landscape, IDENTITY, p.x, p.y)!;
      expect(back.xRatio).toBeCloseTo(r.x, 9);
      expect(back.yRatio).toBeCloseTo(r.y, 9);
    }
  });

  it('returns to the normal picker unchanged: ratios x picker size = marker centre', () => {
    // ImageGuessPicker renders left = x_ratio * width, top = y_ratio * height.
    const picker = { width: 343, height: 257.25 };
    const r = screenPointToRatios(rect, viewport, { scale: 2.5, tx: -80, ty: 30 }, 160, 420)!;
    const inPicker = { x: r.xRatio * picker.width, y: r.yRatio * picker.height };
    expect(inPicker.x / picker.width).toBeCloseTo(r.xRatio, 12);
    expect(inPicker.y / picker.height).toBeCloseTo(r.yRatio, 12);
  });
});

describe('clampTransform', () => {
  it('is the identity at scale 1, whatever translation was asked for', () => {
    expect(clampTransform({ scale: 1, tx: 120, ty: -40 }, rect, viewport)).toEqual(IDENTITY);
  });

  it('never zooms out below 1 or in beyond MAX_SCALE', () => {
    expect(clampTransform({ scale: 0.4, tx: 0, ty: 0 }, rect, viewport).scale).toBe(MIN_SCALE);
    expect(clampTransform({ scale: 99, tx: 0, ty: 0 }, rect, viewport).scale).toBe(MAX_SCALE);
  });

  it('allows panning only as far as the zoomed image still covers the viewport edge', () => {
    // scale 2: image 800x600 in a 400x800 viewport -> 200px of play horizontally, none vertically
    expect(clampTransform({ scale: 2, tx: 500, ty: 500 }, rect, viewport)).toEqual({ scale: 2, tx: 200, ty: 0 });
    expect(clampTransform({ scale: 2, tx: -500, ty: -500 }, rect, viewport)).toEqual({ scale: 2, tx: -200, ty: 0 });
    // scale 3: image 1200x900 -> 400 horizontally, 50 vertically
    expect(clampTransform({ scale: 3, tx: 0, ty: 90 }, rect, viewport)).toEqual({ scale: 3, tx: 0, ty: 50 });
  });

  it('treats NaN as "no transform"', () => {
    expect(clampTransform({ scale: NaN, tx: NaN, ty: NaN }, rect, viewport)).toEqual(IDENTITY);
  });
});

describe('pinchTransform', () => {
  const a = { x: 50, y: 400 };
  const b = { x: 150, y: 400 };
  const start = { transform: IDENTITY, focal: touchMidpoint(a, b), distance: touchDistance(a, b) };

  it('keeps the image point between the fingers anchored while zooming', () => {
    const anchored = screenPointToRatios(rect, viewport, IDENTITY, start.focal.x, start.focal.y)!;
    const t = pinchTransform(start, start.focal, start.distance * 2, rect, viewport);
    expect(t.scale).toBe(2);
    const now = screenPointToRatios(rect, viewport, t, start.focal.x, start.focal.y)!;
    expect(now.xRatio).toBeCloseTo(anchored.xRatio, 9);
    expect(now.yRatio).toBeCloseTo(anchored.yRatio, 9);
  });

  it('pans when both fingers move', () => {
    const anchored = screenPointToRatios(rect, viewport, IDENTITY, start.focal.x, start.focal.y)!;
    const movedFocal = { x: start.focal.x + 60, y: start.focal.y };
    const t = pinchTransform(start, movedFocal, start.distance * 2, rect, viewport);
    const now = screenPointToRatios(rect, viewport, t, movedFocal.x, movedFocal.y)!;
    expect(now.xRatio).toBeCloseTo(anchored.xRatio, 9);
    expect(t.tx).toBeCloseTo(160, 9); // (100-200) - (100-200)*2 + 60
  });

  it('cannot zoom out below the fitted image and is capped at MAX_SCALE', () => {
    expect(pinchTransform(start, start.focal, start.distance / 2, rect, viewport)).toEqual(IDENTITY);
    expect(pinchTransform(start, start.focal, start.distance * 50, rect, viewport).scale).toBe(MAX_SCALE);
  });

  it('is harmless for a degenerate start (fingers on the same spot)', () => {
    const degenerate = { transform: { scale: 2, tx: 10, ty: 0 }, focal: a, distance: 0 };
    expect(pinchTransform(degenerate, a, 80, rect, viewport)).toEqual(clampTransform(degenerate.transform, rect, viewport));
  });

  it('a stored guess does not drift through a pinch', () => {
    const guess = { x: 0.7, y: 0.4 };
    let t: Transform = IDENTITY;
    for (const d of [1.2, 1.6, 2.4, 3.1]) {
      t = pinchTransform({ transform: IDENTITY, focal: start.focal, distance: start.distance }, { x: 90, y: 410 }, start.distance * d, rect, viewport);
      const marker = ratiosToScreenPoint(rect, viewport, t, guess.x, guess.y);
      const back = screenPointToRatios(rect, viewport, t, marker.x, marker.y)!;
      expect(back.xRatio).toBeCloseTo(guess.x, 9);
      expect(back.yRatio).toBeCloseTo(guess.y, 9);
    }
  });
});

describe('panTransform', () => {
  it('does nothing at scale 1 (a drag on the fitted image is not a pan)', () => {
    expect(panTransform(IDENTITY, 80, -30, rect, viewport)).toEqual(IDENTITY);
  });

  it('moves a zoomed image and stops at the edge', () => {
    const zoomed = { scale: 2, tx: 0, ty: 0 };
    expect(panTransform(zoomed, 120, 0, rect, viewport)).toEqual({ scale: 2, tx: 120, ty: 0 });
    expect(panTransform(zoomed, 900, 0, rect, viewport)).toEqual({ scale: 2, tx: 200, ty: 0 });
  });
});

describe('tap detection and touch helpers', () => {
  it('a touch that barely moved is a tap; a drag or a pinch is not', () => {
    expect(isTap(3, -4, false)).toBe(true);
    expect(isTap(TAP_SLOP_PX, 0, false)).toBe(false);
    expect(isTap(0, 0, true)).toBe(false);
    expect(isTap(NaN, 0, false)).toBe(false);
  });

  it('distance and midpoint', () => {
    expect(touchDistance({ x: 0, y: 0 }, { x: 3, y: 4 })).toBe(5);
    expect(touchMidpoint({ x: 0, y: 0 }, { x: 10, y: 20 })).toEqual({ x: 5, y: 10 });
  });
});
