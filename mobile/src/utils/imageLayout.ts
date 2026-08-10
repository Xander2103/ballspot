/**
 * Geometry for images rendered with resizeMode="contain" inside a container
 * that may letterbox (e.g. the fullscreen viewer over the whole window). All
 * guess coordinates in the app are 0..1 ratios relative to the displayed
 * image rectangle — these helpers convert between container points and ratios.
 */
export interface Rect {
  x: number;
  y: number;
  width: number;
  height: number;
}

/** The rectangle the image actually occupies inside containerW x containerH. */
export function containRect(containerW: number, containerH: number, aspect: number): Rect {
  if (containerW <= 0 || containerH <= 0 || aspect <= 0 || !Number.isFinite(aspect)) {
    return { x: 0, y: 0, width: 0, height: 0 };
  }
  const containerAspect = containerW / containerH;
  if (containerAspect > aspect) {
    const width = containerH * aspect;
    return { x: (containerW - width) / 2, y: 0, width, height: containerH };
  }
  const height = containerW / aspect;
  return { x: 0, y: (containerH - height) / 2, width: containerW, height };
}

/** Tap (px, py) in container coords -> image ratios; null if outside the image. */
export function pointToRatios(
  rect: Rect,
  px: number,
  py: number,
): { xRatio: number; yRatio: number } | null {
  if (rect.width <= 0 || rect.height <= 0) return null;
  if (px < rect.x || px > rect.x + rect.width || py < rect.y || py > rect.y + rect.height) {
    return null;
  }
  const clamp = (v: number) => Math.min(1, Math.max(0, v));
  return {
    xRatio: clamp((px - rect.x) / rect.width),
    yRatio: clamp((py - rect.y) / rect.height),
  };
}

/** Image ratios -> container coords (marker placement). */
export function ratiosToPoint(rect: Rect, xRatio: number, yRatio: number): { x: number; y: number } {
  return { x: rect.x + xRatio * rect.width, y: rect.y + yRatio * rect.height };
}
