import { Platform } from 'react-native';
import { Image } from 'expo-image';
import { createImagePrefetcher } from '../utils/imagePrefetch';

/**
 * Warms expo-image's persistent disk cache (and memory cache) for a photo the
 * player is about to see, so the game/result screen renders from cache.
 * Never throws and never blocks navigation. What may be prefetched (and when)
 * is decided in utils/imagePrefetch.ts — see the security note there.
 *
 * Measured 2026-11-06 (iOS simulator): Image.prefetch re-downloads a URL that
 * is already in the disk cache, so the cache is checked first. getCachePathAsync
 * resolves only when the file really exists (SDImageCache.diskImageExists on
 * iOS, Glide onlyRetrieveFromCache on Android).
 */
async function warm(url: string): Promise<boolean> {
  try {
    if (await Image.getCachePathAsync(url)) return true;
  } catch {
    // Unknown cache state: fall through to a normal prefetch.
  }
  return Image.prefetch(url, { cachePolicy: 'memory-disk' });
}

export const prefetchImage = createImagePrefetcher(warm);

/**
 * Warm a photo that the VERY NEXT screen renders straight away (a reveal
 * right after the guess is scored). iOS (SDWebImage) joins the screen's load
 * onto the in-flight prefetch, which saves the result screen's set-up time.
 * Android (Glide) keys the full-size prefetch differently from the view's
 * screen-sized load, so an unfinished prefetch was downloaded twice
 * (measured 2026-11-06) — there the screen loads it itself, once.
 */
export function prefetchImminentImage(url: string | null | undefined): void {
  if (Platform.OS === 'android') return;
  prefetchImage(url);
}
