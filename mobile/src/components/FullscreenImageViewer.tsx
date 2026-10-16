import React, { useEffect, useRef, useState } from 'react';
import {
  Modal,
  View,
  Text,
  Image,
  StyleSheet,
  Pressable,
  PanResponder,
  useWindowDimensions,
  GestureResponderEvent,
  LayoutChangeEvent,
  PanResponderGestureState,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { AppButton } from './AppButton';
import { containRect } from '../utils/imageLayout';
import {
  IDENTITY,
  PinchStart,
  Size,
  Transform,
  clampTransform,
  isTap,
  panTransform,
  pinchTransform,
  ratiosToScreenPoint,
  screenPointToRatios,
  touchDistance,
  touchMidpoint,
} from '../utils/zoomTransform';
import { getSportObjectIcon, getSportObjectMarkerStyle } from '../utils/sportObject';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';

const FALLBACK_ASPECT = 4 / 3;
const MARKER_SIZE = 42;

interface Props {
  visible: boolean;
  /** Null renders nothing — callers can pass a possibly-missing image safely. */
  imageUri: string | null;
  onClose: () => void;
  /** Enable tap-to-place-guess on the fullscreen image. */
  selectable?: boolean;
  /** Current guess as 0..1 image ratios; shows the ghost-ball marker. */
  selectedPoint?: { x: number; y: number } | null;
  /** Called with 0..1 image ratios when the user taps the image (selectable). */
  onSelectPoint?: (xRatio: number, yRatio: number) => void;
  /** Slug of the challenge's sport — picks the marker object (default football). */
  sportSlug?: string | null;
  /**
   * Submit straight from fullscreen. Pass the SAME handler, title, in-flight
   * flag and error message the screen's own submit button uses — the viewer
   * has no submission logic of its own, it only renders that button again.
   */
  onSubmit?: () => void;
  submitTitle?: string;
  submitting?: boolean;
  submitError?: string;
}

interface GestureMemory {
  /** Transform when the current one-finger drag started (re-based after a pinch). */
  start: Transform;
  /** gestureState.dx/dy at the moment the drag was (re-)based. */
  base: { dx: number; dy: number };
  pinch: PinchStart | null;
  /** A second finger was seen at any point — the release is never a tap. */
  multi: boolean;
}

const IDLE: GestureMemory = { start: IDENTITY, base: { dx: 0, dy: 0 }, pinch: null, multi: false };

/**
 * Dark full-screen image viewer with pinch-zoom and pan (plain PanResponder,
 * no native dependency). View-only mode closes on a tap on the fitted image.
 * Selectable mode maps a tap — at any zoom level — through the current
 * transform and the resizeMode="contain" letterbox to 0..1 image ratios, so a
 * guess placed here and a guess placed in ImageGuessPicker are the same
 * numbers; the marker is re-derived from those ratios on every render and
 * therefore never drifts when zooming, panning or rotating. The optional
 * submit bar re-renders the screen's own AppButton inside the safe area.
 */
export function FullscreenImageViewer({
  visible,
  imageUri,
  onClose,
  selectable = false,
  selectedPoint = null,
  onSelectPoint,
  sportSlug,
  onSubmit,
  submitTitle,
  submitting = false,
  submitError = '',
}: Props) {
  const { t } = useI18n();
  const insets = useSafeAreaInsets();
  const window = useWindowDimensions();
  const [aspect, setAspect] = useState<number>(FALLBACK_ASPECT);
  // The gesture layer's own size: identical to the image view's frame, so
  // touch coordinates relative to it need no status-bar/offset corrections.
  const [layout, setLayout] = useState<Size>({ width: window.width, height: window.height });
  const [transform, setTransform] = useState<Transform>(IDENTITY);
  // Estimated until the bar reports its layout, so the hint never starts under it.
  const [barHeight, setBarHeight] = useState(100);

  const canSelect = selectable && !!onSelectPoint;

  // Live values for the gesture handlers (created once, so they must not
  // close over render-time state).
  const layoutRef = useRef(layout);
  const aspectRef = useRef(aspect);
  const transformRef = useRef<Transform>(IDENTITY);
  const gesture = useRef<GestureMemory>(IDLE);
  const callbacks = useRef({ canSelect, onSelectPoint, onClose });
  callbacks.current = { canSelect, onSelectPoint, onClose };

  // Probe natural dimensions so the letterbox math matches the rendered image.
  useEffect(() => {
    if (!imageUri) return;
    let cancelled = false;
    Image.getSize(
      imageUri,
      (w, h) => {
        if (!cancelled && w > 0 && h > 0) {
          aspectRef.current = w / h;
          setAspect(w / h);
        }
      },
      () => {
        // One failed probe only — the fallback aspect keeps taps usable.
      }
    );
    return () => { cancelled = true; };
  }, [imageUri]);

  // Every (re)open starts fitted; the guess itself lives in the screen state.
  useEffect(() => {
    transformRef.current = IDENTITY;
    gesture.current = IDLE;
    setTransform(IDENTITY);
  }, [visible, imageUri]);

  function currentRect() {
    const l = layoutRef.current;
    return containRect(l.width, l.height, aspectRef.current);
  }

  function applyTransform(next: Transform) {
    const clamped = clampTransform(next, currentRect(), layoutRef.current);
    transformRef.current = clamped;
    setTransform(clamped);
  }

  function handleTap(x: number, y: number) {
    const cb = callbacks.current;
    if (cb.canSelect) {
      const mapped = screenPointToRatios(currentRect(), layoutRef.current, transformRef.current, x, y);
      if (mapped) cb.onSelectPoint!(mapped.xRatio, mapped.yRatio);
    } else if (transformRef.current.scale <= 1) {
      cb.onClose();
    }
  }

  const responder = useRef(
    PanResponder.create({
      onStartShouldSetPanResponder: () => true,
      onMoveShouldSetPanResponder: () => true,
      onPanResponderTerminationRequest: () => false,
      onPanResponderGrant: () => {
        gesture.current = { start: transformRef.current, base: { dx: 0, dy: 0 }, pinch: null, multi: false };
      },
      onPanResponderMove: (e: GestureResponderEvent, gs: PanResponderGestureState) => {
        const g = gesture.current;
        const touches = e.nativeEvent.touches;
        if (touches.length >= 2) {
          const a = { x: touches[0].locationX, y: touches[0].locationY };
          const b = { x: touches[1].locationX, y: touches[1].locationY };
          const focal = touchMidpoint(a, b);
          const distance = touchDistance(a, b);
          if (!g.pinch) {
            g.multi = true;
            g.pinch = { transform: transformRef.current, focal, distance };
            return;
          }
          applyTransform(pinchTransform(g.pinch, focal, distance, currentRect(), layoutRef.current));
          return;
        }
        if (g.pinch) {
          // One finger lifted after a pinch: the remaining finger pans from here.
          g.pinch = null;
          g.start = transformRef.current;
          g.base = { dx: gs.dx, dy: gs.dy };
          return;
        }
        if (transformRef.current.scale > 1) {
          applyTransform(panTransform(g.start, gs.dx - g.base.dx, gs.dy - g.base.dy, currentRect(), layoutRef.current));
        }
      },
      onPanResponderRelease: (e: GestureResponderEvent, gs: PanResponderGestureState) => {
        const g = gesture.current;
        gesture.current = IDLE;
        if (!isTap(gs.dx, gs.dy, g.multi)) return;
        const { locationX, locationY } = e.nativeEvent;
        if (Number.isFinite(locationX) && Number.isFinite(locationY)) {
          handleTap(locationX, locationY);
        }
      },
      onPanResponderTerminate: () => {
        gesture.current = IDLE;
      },
    })
  ).current;

  function handleLayout(e: LayoutChangeEvent) {
    const { width, height } = e.nativeEvent.layout;
    if (width <= 0 || height <= 0) return;
    const prev = layoutRef.current;
    layoutRef.current = { width, height };
    setLayout({ width, height });
    if (prev.width !== width || prev.height !== height) {
      // Rotation / split view: refit. Ratios are size-independent, so the
      // marker lands on the same image pixel in the new frame.
      transformRef.current = IDENTITY;
      setTransform(IDENTITY);
    }
  }

  if (!imageUri) return null;

  const rect = containRect(layout.width, layout.height, aspect);
  const marker =
    selectedPoint && rect.width > 0
      ? ratiosToScreenPoint(rect, layout, transform, selectedPoint.x, selectedPoint.y)
      : null;
  const showSubmit = canSelect && !!onSubmit;
  const hintBottom = showSubmit ? barHeight + spacing.sm : 40;

  return (
    <Modal
      visible={visible}
      animationType="fade"
      onRequestClose={onClose}
      statusBarTranslucent
      supportedOrientations={['portrait', 'landscape']}
    >
      <View style={styles.backdrop}>
        <View
          style={[
            styles.imageWrap,
            { transform: [{ translateX: transform.tx }, { translateY: transform.ty }, { scale: transform.scale }] },
          ]}
          pointerEvents="none"
        >
          <Image source={{ uri: imageUri }} style={{ width: layout.width, height: layout.height }} resizeMode="contain" />
        </View>

        {marker && (
          <View
            pointerEvents="none"
            style={[
              styles.marker,
              { left: marker.x - MARKER_SIZE / 2, top: marker.y - MARKER_SIZE / 2 },
            ]}
          >
            <Text style={[styles.markerEmoji, getSportObjectMarkerStyle(sportSlug)]}>{getSportObjectIcon(sportSlug)}</Text>
          </View>
        )}

        {/* Gesture layer: pinch, pan and tap. Sits under the hint, the submit
            bar and the close button, which therefore never steal a pinch. */}
        <View
          style={StyleSheet.absoluteFill}
          onLayout={handleLayout}
          {...responder.panHandlers}
          accessible
          accessibilityRole={canSelect ? 'image' : 'button'}
          accessibilityLabel={canSelect ? t('game.image.tapToPlace') : t('game.image.closeFullscreenA11y')}
          onAccessibilityTap={canSelect ? undefined : onClose}
        />

        {canSelect && (
          <View style={[styles.hintBar, { bottom: hintBottom }]} pointerEvents="none">
            <Text style={styles.hintText}>
              {selectedPoint ? t('game.image.tapToAdjust') : t('game.image.tapToPlace')}
            </Text>
            <Text style={styles.hintSub}>{t('game.image.pinchToZoom')}</Text>
          </View>
        )}

        {showSubmit && (
          <View
            style={[styles.submitBar, { paddingBottom: Math.max(insets.bottom, spacing.md) }]}
            onLayout={(e) => setBarHeight(e.nativeEvent.layout.height)}
          >
            {submitError ? <Text style={styles.submitError}>{submitError}</Text> : null}
            <AppButton
              title={submitTitle ?? t('game.buttons.submitGuess')}
              onPress={onSubmit!}
              loading={submitting}
              disabled={!selectedPoint || submitting}
            />
          </View>
        )}

        <Pressable
          style={[styles.closeBtn, { top: Math.max(44, insets.top + 8) }]}
          onPress={onClose}
          hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }}
          accessibilityRole="button"
          accessibilityLabel={t('common.buttons.close')}
        >
          <Text style={styles.closeText}>✕</Text>
        </Pressable>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: '#000000', overflow: 'hidden' },
  imageWrap: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  closeBtn: {
    position: 'absolute',
    right: 16,
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.14)',
  },
  closeText: { color: '#ffffff', fontSize: 20, fontWeight: '700', lineHeight: 22 },
  // Same ghost-ball look as ImageGuessPicker so the guess reads identically.
  marker: {
    position: 'absolute',
    width: MARKER_SIZE,
    height: MARKER_SIZE,
    borderRadius: MARKER_SIZE / 2,
    alignItems: 'center',
    justifyContent: 'center',
    opacity: 0.72,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 2,
    borderColor: 'rgba(255,255,255,0.85)',
  },
  markerEmoji: { fontSize: 20 },
  hintBar: {
    position: 'absolute',
    left: 0,
    right: 0,
    alignItems: 'center',
    gap: 4,
  },
  hintText: {
    color: '#ffffff',
    fontSize: 13,
    fontWeight: '600',
    backgroundColor: 'rgba(0,0,0,0.55)',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 14,
    overflow: 'hidden',
  },
  hintSub: {
    color: 'rgba(255,255,255,0.75)',
    fontSize: 11,
    fontWeight: '500',
    backgroundColor: 'rgba(0,0,0,0.45)',
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: 10,
    overflow: 'hidden',
  },
  // The screen's footer, in the viewer's dark palette: same AppButton, same
  // error line, bottom inset respected on both platforms.
  submitBar: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
    gap: spacing.sm,
    backgroundColor: 'rgba(0,0,0,0.6)',
  },
  submitError: {
    color: colors.error,
    fontSize: 13,
    textAlign: 'center',
  },
});
