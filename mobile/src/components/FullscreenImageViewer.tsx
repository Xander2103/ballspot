import React, { useEffect, useState } from 'react';
import {
  Modal,
  View,
  Text,
  Image,
  StyleSheet,
  Pressable,
  useWindowDimensions,
  GestureResponderEvent,
} from 'react-native';
import { containRect, pointToRatios, ratiosToPoint } from '../utils/imageLayout';

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
}

/**
 * Dark full-screen image viewer. View-only mode closes on a tap anywhere (the
 * image is pointer-transparent). Selectable mode maps taps on the displayed
 * image rectangle (resizeMode="contain" letterboxing accounted for) to guess
 * ratios and only closes via the X button or hardware back.
 */
export function FullscreenImageViewer({
  visible,
  imageUri,
  onClose,
  selectable = false,
  selectedPoint = null,
  onSelectPoint,
}: Props) {
  const { width, height } = useWindowDimensions();
  const [aspect, setAspect] = useState<number>(FALLBACK_ASPECT);

  // Probe natural dimensions so the letterbox math matches the rendered image.
  useEffect(() => {
    if (!imageUri) return;
    let cancelled = false;
    Image.getSize(
      imageUri,
      (w, h) => {
        if (!cancelled && w > 0 && h > 0) setAspect(w / h);
      },
      () => {
        // One failed probe only — the fallback aspect keeps taps usable.
      }
    );
    return () => { cancelled = true; };
  }, [imageUri]);

  if (!imageUri) return null;

  const rect = containRect(width, height, aspect);
  const canSelect = selectable && !!onSelectPoint;

  function handleImagePress(e: GestureResponderEvent) {
    if (!canSelect) return;
    // The pressable spans the whole window, so location == window coords.
    const { locationX, locationY, pageX, pageY } = e.nativeEvent;
    const px = Number.isFinite(locationX) ? locationX : pageX;
    const py = Number.isFinite(locationY) ? locationY : pageY;
    const mapped = pointToRatios(rect, px, py);
    if (mapped) onSelectPoint!(mapped.xRatio, mapped.yRatio);
  }

  const marker =
    selectedPoint && rect.width > 0
      ? ratiosToPoint(rect, selectedPoint.x, selectedPoint.y)
      : null;

  return (
    <Modal
      visible={visible}
      animationType="fade"
      onRequestClose={onClose}
      statusBarTranslucent
      supportedOrientations={['portrait', 'landscape']}
    >
      <View style={styles.backdrop}>
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={canSelect ? handleImagePress : onClose}
          accessibilityRole={canSelect ? 'image' : 'button'}
          accessibilityLabel={
            canSelect ? 'Tap the image to place your guess' : 'Close fullscreen image'
          }
        />

        <View style={styles.imageWrap} pointerEvents="none">
          <Image source={{ uri: imageUri }} style={{ width, height }} resizeMode="contain" />
        </View>

        {marker && (
          <View
            pointerEvents="none"
            style={[
              styles.marker,
              { left: marker.x - MARKER_SIZE / 2, top: marker.y - MARKER_SIZE / 2 },
            ]}
          >
            <Text style={styles.markerEmoji}>⚽</Text>
          </View>
        )}

        {canSelect && (
          <View style={styles.hintBar} pointerEvents="none">
            <Text style={styles.hintText}>
              {selectedPoint ? 'Tap again to adjust your guess' : 'Tap the image to place your guess'}
            </Text>
          </View>
        )}

        <Pressable
          style={styles.closeBtn}
          onPress={onClose}
          hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }}
          accessibilityRole="button"
          accessibilityLabel="Close"
        >
          <Text style={styles.closeText}>✕</Text>
        </Pressable>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: '#000000' },
  imageWrap: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  closeBtn: {
    position: 'absolute',
    top: 44,
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
    bottom: 40,
    left: 0,
    right: 0,
    alignItems: 'center',
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
});
