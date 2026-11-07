import React, { useRef, useState, useCallback } from 'react';
import { View, StyleSheet, Pressable, Text, LayoutChangeEvent, ActivityIndicator } from 'react-native';
import { Image, ImageLoadEventData } from 'expo-image';
import { colors } from '../theme/colors';
import { useI18n } from '../i18n';
import { getSportObjectIcon, getSportObjectMarkerStyle } from '../utils/sportObject';
import { imageAspect } from '../utils/imagePrefetch';

const GHOST_SIZE = 42;
const GLOW_SIZE  = 60;
const DEFAULT_SIZE = 40;
const FALLBACK_ASPECT = 4 / 3;

export type MarkerType = 'ghost-ball' | 'glow' | 'default';

export interface Marker {
  x_ratio: number;
  y_ratio: number;
  type: MarkerType;
  /** Overrides the picker-level sport for this marker (rarely needed). */
  sportSlug?: string | null;
}

interface Props {
  imageUri: string;
  onGuess?: (xRatio: number, yRatio: number) => void;
  markers?: Marker[];
  interactive?: boolean;
  /**
   * Controlled guess marker (0..1 ratios). When the prop is provided the
   * marker always renders from it — so a guess placed elsewhere (e.g. the
   * fullscreen viewer) shows up here too. Omit for the classic uncontrolled
   * behavior.
   */
  selectedPoint?: { x: number; y: number } | null;
  /**
   * Slug of the CHALLENGE's sport (never the pack's/tournament's) — picks the
   * object drawn in the ghost marker. Missing/unknown → football.
   */
  sportSlug?: string | null;
  /**
   * width/height of the served image when the API knows it (newer backends):
   * the frame gets its final shape before the download, so nothing jumps.
   * The decoded image's own size always wins once it is loaded.
   */
  aspectHint?: number | null;
}

/**
 * The photo the player guesses on (and the result photo with its markers).
 *
 * Loaded with expo-image: persistent disk cache + memory cache, so a photo
 * that was prefetched (Home, previous pack step) or seen before renders
 * without a download, and going back/forward never re-downloads it. The
 * container takes the image's exact aspect ratio (from `aspectHint`, then
 * from the decoded image itself — no separate size probe/download), so the
 * photo is never cropped and 0..1 tap ratios equal image ratios.
 */
export function ImageGuessPicker({ imageUri, onGuess, markers = [], interactive = true, selectedPoint, sportSlug, aspectHint }: Props) {
  const { t } = useI18n();
  const containerRef = useRef<View>(null);
  const [dims, setDims]       = useState({ width: 0, height: 0 });
  const [guess, setGuess]     = useState<{ xRatio: number; yRatio: number } | null>(null);
  // Keyed by URI: a new photo is never shown with the previous one's state.
  const [loaded, setLoaded]   = useState<{ uri: string; aspect: number | null } | null>(null);
  const [failedUri, setFailedUri] = useState<string | null>(null);

  // Development-only timing (Metro console): mount → decoded, and from which cache.
  const startedAt = useRef<{ uri: string; at: number } | null>(null);
  if (startedAt.current?.uri !== imageUri) startedAt.current = { uri: imageUri, at: Date.now() };

  const isLoaded = loaded?.uri === imageUri;
  const failed = failedUri === imageUri;
  const aspect = (isLoaded ? loaded!.aspect : null) ?? aspectHint ?? FALLBACK_ASPECT;

  const handleLoad = useCallback((e: ImageLoadEventData) => {
    setLoaded({ uri: imageUri, aspect: imageAspect(e?.source?.width, e?.source?.height) });
    if (__DEV__) {
      // eslint-disable-next-line no-console
      console.log(`[img] ${Date.now() - (startedAt.current?.at ?? Date.now())}ms cache=${e?.cacheType} ${e?.source?.width}x${e?.source?.height} ${imageUri.split('/').pop()}`);
    }
  }, [imageUri]);

  const handleLayout = useCallback((e: LayoutChangeEvent) => {
    const { width, height } = e.nativeEvent.layout;
    setDims({ width, height });
  }, []);

  function applyGuess(tapX: number, tapY: number) {
    if (!onGuess || dims.width === 0 || dims.height === 0) return;
    const xRatio = Math.min(1, Math.max(0, tapX / dims.width));
    const yRatio = Math.min(1, Math.max(0, tapY / dims.height));
    if (!Number.isFinite(xRatio) || !Number.isFinite(yRatio)) return;
    setGuess({ xRatio, yRatio });
    onGuess(xRatio, yRatio);
  }

  function handlePress(e: any) {
    if (!interactive || !onGuess || !isLoaded || dims.width === 0) return;
    const { locationX, locationY, pageX, pageY } = e.nativeEvent;
    if (
      Number.isFinite(locationX) && Number.isFinite(locationY) &&
      locationX >= -2 && locationX <= dims.width + 2 &&
      locationY >= -2 && locationY <= dims.height + 2
    ) {
      applyGuess(locationX, locationY);
    } else if (Number.isFinite(pageX) && Number.isFinite(pageY)) {
      containerRef.current?.measureInWindow((cx, cy) => {
        applyGuess(pageX - cx, pageY - cy);
      });
    }
  }

  function renderMarker(m: Marker, key: number | string) {
    if (!Number.isFinite(m.x_ratio) || !Number.isFinite(m.y_ratio)) return null;
    const size = m.type === 'glow' ? GLOW_SIZE : m.type === 'ghost-ball' ? GHOST_SIZE : DEFAULT_SIZE;
    const half = size / 2;
    const left = m.x_ratio * dims.width  - half;
    const top  = m.y_ratio * dims.height - half;
    if (!Number.isFinite(left) || !Number.isFinite(top)) return null;
    const markerStyle = m.type === 'ghost-ball' ? styles.markerGhostBall
                      : m.type === 'glow'       ? styles.markerGlow
                      : styles.markerDefault;
    return (
      <View
        key={key}
        pointerEvents="none"
        style={[styles.markerBase, markerStyle, { width: size, height: size, borderRadius: half, left, top }]}
      >
        {m.type === 'ghost-ball' && (
          <Text style={[styles.ghostEmoji, getSportObjectMarkerStyle(m.sportSlug ?? sportSlug)]}>
            {getSportObjectIcon(m.sportSlug ?? sportSlug)}
          </Text>
        )}
      </View>
    );
  }

  return (
    <View
      ref={containerRef}
      style={[styles.container, { aspectRatio: aspect }]}
      onLayout={handleLayout}
    >
      <View style={StyleSheet.absoluteFill} pointerEvents="none">
        {failed ? (
          <View style={styles.failed}>
            <Text style={styles.failedText}>{t('game.image.unavailable')}</Text>
          </View>
        ) : (
          <>
            <Image
              source={{ uri: imageUri }}
              style={StyleSheet.absoluteFill}
              contentFit="cover"
              cachePolicy="memory-disk"
              priority="high"
              recyclingKey={imageUri}
              transition={{ duration: 120, effect: 'cross-dissolve' }}
              onLoad={handleLoad}
              // One failed load only — never retried, so a dead URL cannot loop.
              onError={() => setFailedUri(imageUri)}
              accessible={false}
            />
            {!isLoaded ? (
              <View style={styles.loading}>
                <ActivityIndicator color={colors.textMuted} />
              </View>
            ) : null}
          </>
        )}
      </View>

      {interactive && (
        // Taps only count once the photo is visible at its real aspect ratio.
        <Pressable style={StyleSheet.absoluteFill} onPress={handlePress} disabled={!isLoaded} />
      )}

      {(() => {
        const shownGuess = selectedPoint !== undefined
          ? (selectedPoint ? { xRatio: selectedPoint.x, yRatio: selectedPoint.y } : null)
          : guess;
        return shownGuess && interactive && isLoaded && dims.width > 0 && renderMarker(
          { x_ratio: shownGuess.xRatio, y_ratio: shownGuess.yRatio, type: 'ghost-ball' },
          'guess'
        );
      })()}

      {isLoaded && dims.width > 0 && markers.map((m, i) => renderMarker(m, i))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: '100%',
    backgroundColor: colors.surface,
    borderRadius: 12,
    overflow: 'hidden',
  },
  markerBase: {
    position: 'absolute',
    alignItems: 'center',
    justifyContent: 'center',
  },
  markerGhostBall: {
    opacity: 0.72,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 2,
    borderColor: 'rgba(255,255,255,0.85)',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.4,
    shadowRadius: 3,
    elevation: 4,
  },
  markerGlow: {
    opacity: 0.65,
    backgroundColor: 'transparent',
    borderWidth: 1.5,
    borderColor: 'rgba(0,230,118,0.55)',
    shadowColor: '#00E676',
    shadowOffset: { width: 0, height: 0 },
    shadowOpacity: 0.45,
    shadowRadius: 5,
    elevation: 4,
  },
  markerDefault: {
    backgroundColor: 'rgba(0,230,118,0.7)',
    borderWidth: 2,
    borderColor: '#ffffff',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.4,
    shadowRadius: 3,
    elevation: 5,
  },
  ghostEmoji: {
    fontSize: 20,
  },
  failed: {
    ...StyleSheet.absoluteFill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surfaceElevated,
  },
  failedText: { color: colors.textMuted, fontSize: 14, fontStyle: 'italic' },
  loading: {
    ...StyleSheet.absoluteFill,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
