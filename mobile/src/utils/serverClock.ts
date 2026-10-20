/**
 * One shared clock for anything that counts down to a backend time (the Home
 * "Next Daily in" badge). The API client feeds it the `Date` header of every
 * successful response; `serverNow()` is the device clock corrected by the last
 * measured offset, so a phone with a wrong clock still counts down correctly.
 *
 * Pure TypeScript (no React Native) so it runs under the plain ts-jest runner.
 */

/**
 * The Date header has one-second resolution and the response took some time
 * to arrive, so offsets below this are noise: keep the device clock then.
 */
const NOISE_MS = 2000;

let offsetMs = 0;

/** Record the server's `Date` header (RFC 7231). Ignores missing or unparsable values. */
export function noteServerDate(header: string | null | undefined, receivedAtMs: number = Date.now()): void {
  if (!header) return;
  const serverMs = Date.parse(header);
  if (!Number.isFinite(serverMs)) return;
  const offset = serverMs - receivedAtMs;
  offsetMs = Math.abs(offset) < NOISE_MS ? 0 : offset;
}

/** Current time in ms, on the server's clock as far as we know it. */
export function serverNow(): number {
  return Date.now() + offsetMs;
}

/** Test hook. */
export function resetServerClockForTests(): void {
  offsetMs = 0;
}
