/**
 * Backend feature flags from GET /api/config (pure, unit tested).
 * Every flag fails CLOSED: missing, malformed or anything but literal `true`
 * means off, so an older backend or a failed request never shows a feature
 * the server is not serving.
 */

export function isClubsFeatureEnabled(config: unknown): boolean {
  if (!config || typeof config !== 'object') return false;
  const features = (config as { features?: unknown }).features;
  if (!features || typeof features !== 'object') return false;
  return (features as { clubs?: unknown }).clubs === true;
}
