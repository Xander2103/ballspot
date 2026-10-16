/**
 * Pinch-zoom / pan geometry for the fullscreen image viewer.
 *
 * The image is rendered with resizeMode="contain" in a viewport of W x H and
 * occupies `rect` (see imageLayout.containRect). Zooming applies the React
 * Native transform `[{ translateX: tx }, { translateY: ty }, { scale }]` to the
 * whole viewport-sized image view, i.e. a scale about the viewport centre
 * followed by a translation:
 *
 *     screen = centre + scale * (unzoomed - centre) + t
 *
 * A guess is always stored as 0..1 ratios of the image rectangle, never as
 * screen pixels, so it is unaffected by zooming, panning, rotating the phone
 * or returning to the normal (non-fullscreen) picker. These helpers convert
 * between the two for taps (screen -> ratios) and markers (ratios -> screen).
 */
import { pointToRatios, ratiosToPoint, Rect } from './imageLayout';

export interface Transform {
  scale: number;
  tx: number;
  ty: number;
}

export interface Size {
  width: number;
  height: number;
}

export interface Point {
  x: number;
  y: number;
}

export const IDENTITY: Transform = { scale: 1, tx: 0, ty: 0 };
export const MIN_SCALE = 1;
export const MAX_SCALE = 5;
/** A touch that moves less than this (px) between down and up is a tap. */
export const TAP_SLOP_PX = 10;

function finite(n: number): boolean {
  return Number.isFinite(n);
}

/**
 * Keeps scale within [MIN_SCALE, MAX_SCALE] and the translation such that a
 * zoomed image never leaves a gap along an axis where it is larger than the
 * viewport, and stays centred along an axis where it is not. At scale 1 the
 * result is always the identity.
 */
export function clampTransform(t: Transform, rect: Rect, viewport: Size): Transform {
  const scale = finite(t.scale) ? Math.min(MAX_SCALE, Math.max(MIN_SCALE, t.scale)) : 1;
  const maxTx = Math.max(0, (rect.width * scale - viewport.width) / 2);
  const maxTy = Math.max(0, (rect.height * scale - viewport.height) / 2);
  const tx = finite(t.tx) ? Math.min(maxTx, Math.max(-maxTx, t.tx)) : 0;
  const ty = finite(t.ty) ? Math.min(maxTy, Math.max(-maxTy, t.ty)) : 0;
  // `+ 0` turns a -0 from the clamp into 0 (equality in tests and transforms).
  return { scale, tx: tx + 0, ty: ty + 0 };
}

/** Screen (viewport) point -> the point in the unzoomed viewport. */
export function screenToUnzoomed(p: Point, t: Transform, viewport: Size): Point {
  const cx = viewport.width / 2;
  const cy = viewport.height / 2;
  return {
    x: cx + (p.x - t.tx - cx) / t.scale,
    y: cy + (p.y - t.ty - cy) / t.scale,
  };
}

/** Unzoomed viewport point -> where it is drawn on screen under `t`. */
export function unzoomedToScreen(p: Point, t: Transform, viewport: Size): Point {
  const cx = viewport.width / 2;
  const cy = viewport.height / 2;
  return {
    x: cx + (p.x - cx) * t.scale + t.tx,
    y: cy + (p.y - cy) * t.scale + t.ty,
  };
}

/**
 * A tap at screen (px, py) -> image ratios, or null when it lands on the
 * letterbox / outside the image. Identical to imageLayout.pointToRatios when
 * `t` is the identity.
 */
export function screenPointToRatios(
  rect: Rect,
  viewport: Size,
  t: Transform,
  px: number,
  py: number,
): { xRatio: number; yRatio: number } | null {
  if (!finite(px) || !finite(py) || t.scale <= 0) return null;
  const u = screenToUnzoomed({ x: px, y: py }, t, viewport);
  return pointToRatios(rect, u.x, u.y);
}

/** Image ratios -> screen point (marker placement) under `t`. */
export function ratiosToScreenPoint(
  rect: Rect,
  viewport: Size,
  t: Transform,
  xRatio: number,
  yRatio: number,
): Point {
  return unzoomedToScreen(ratiosToPoint(rect, xRatio, yRatio), t, viewport);
}

export interface PinchStart {
  transform: Transform;
  /** Midpoint of the two fingers when the pinch began (screen coords). */
  focal: Point;
  /** Distance between the two fingers when the pinch began. */
  distance: number;
}

/**
 * The transform for the current finger midpoint/distance of a pinch that
 * started at `start`. The image point that was under the fingers' midpoint
 * when the pinch began stays under the midpoint, so zooming feels anchored
 * (and a marker there does not drift); moving both fingers pans.
 */
export function pinchTransform(
  start: PinchStart,
  focal: Point,
  distance: number,
  rect: Rect,
  viewport: Size,
): Transform {
  if (!(start.distance > 0) || !finite(distance) || distance <= 0) {
    return clampTransform(start.transform, rect, viewport);
  }
  const anchor = screenToUnzoomed(start.focal, start.transform, viewport);
  const rawScale = start.transform.scale * (distance / start.distance);
  const scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, rawScale));
  const cx = viewport.width / 2;
  const cy = viewport.height / 2;
  return clampTransform(
    {
      scale,
      tx: focal.x - cx - (anchor.x - cx) * scale,
      ty: focal.y - cy - (anchor.y - cy) * scale,
    },
    rect,
    viewport,
  );
}

/** One-finger drag by (dx, dy) from `start`; only moves a zoomed image. */
export function panTransform(start: Transform, dx: number, dy: number, rect: Rect, viewport: Size): Transform {
  if (start.scale <= 1) return clampTransform(start, rect, viewport);
  return clampTransform({ scale: start.scale, tx: start.tx + dx, ty: start.ty + dy }, rect, viewport);
}

/** True for a short single-finger touch that barely moved. */
export function isTap(dx: number, dy: number, multiTouchSeen: boolean): boolean {
  return !multiTouchSeen && finite(dx) && finite(dy) && Math.hypot(dx, dy) < TAP_SLOP_PX;
}

/** Distance between two touches. */
export function touchDistance(a: Point, b: Point): number {
  return Math.hypot(a.x - b.x, a.y - b.y);
}

/** Midpoint of two touches. */
export function touchMidpoint(a: Point, b: Point): Point {
  return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
}
