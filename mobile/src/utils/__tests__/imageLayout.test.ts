import { containRect, pointToRatios, ratiosToPoint } from '../imageLayout';

describe('containRect', () => {
  it('letterboxes top/bottom when image is wider than container', () => {
    // 400x800 window, 2:1 landscape image -> 400x200 centered vertically
    expect(containRect(400, 800, 2)).toEqual({ x: 0, y: 300, width: 400, height: 200 });
  });

  it('letterboxes left/right when image is taller than container', () => {
    // 400x400 window, 1:2 portrait image -> 200x400 centered horizontally
    expect(containRect(400, 400, 0.5)).toEqual({ x: 100, y: 0, width: 200, height: 400 });
  });

  it('fills exactly when aspects match', () => {
    expect(containRect(300, 400, 0.75)).toEqual({ x: 0, y: 0, width: 300, height: 400 });
  });

  it('returns an empty rect for degenerate input', () => {
    expect(containRect(0, 400, 1.5)).toEqual({ x: 0, y: 0, width: 0, height: 0 });
    expect(containRect(400, 0, 1.5)).toEqual({ x: 0, y: 0, width: 0, height: 0 });
    expect(containRect(400, 400, 0)).toEqual({ x: 0, y: 0, width: 0, height: 0 });
  });
});

describe('pointToRatios', () => {
  const rect = { x: 0, y: 300, width: 400, height: 200 };

  it('maps a tap inside the displayed image to 0..1 ratios', () => {
    expect(pointToRatios(rect, 200, 400)).toEqual({ xRatio: 0.5, yRatio: 0.5 });
  });

  it('maps corners exactly', () => {
    expect(pointToRatios(rect, 0, 300)).toEqual({ xRatio: 0, yRatio: 0 });
    expect(pointToRatios(rect, 400, 500)).toEqual({ xRatio: 1, yRatio: 1 });
  });

  it('returns null for taps in the letterbox area', () => {
    expect(pointToRatios(rect, 200, 100)).toBeNull();
    expect(pointToRatios(rect, 200, 700)).toBeNull();
  });

  it('returns null for an empty rect', () => {
    expect(pointToRatios({ x: 0, y: 0, width: 0, height: 0 }, 10, 10)).toBeNull();
  });
});

describe('ratiosToPoint', () => {
  it('is the inverse of pointToRatios', () => {
    const rect = { x: 100, y: 0, width: 200, height: 400 };
    expect(ratiosToPoint(rect, 0.25, 0.5)).toEqual({ x: 150, y: 200 });
  });
});
