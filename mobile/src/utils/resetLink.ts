/**
 * Password-reset link handling.
 *
 * The reset email carries a link like
 *   https://ballpicker.vanmalderstudio.be/reset-password?token=<64 chars>&email=<urlencoded>
 * and the web page offers the same as ballpicker://reset-password?token=…&email=….
 *
 * Users paste whatever they have: the whole link, just the token, or the
 * token with whitespace around it. This turns any of those into the
 * { token, email } pair the API expects. Pure TS so it is unit-tested.
 */

export interface ResetLinkParts {
  token: string;
  email: string | null;
}

const TOKEN_PATTERN = /^[A-Za-z0-9._~-]{20,}$/;

function decode(value: string): string {
  try {
    return decodeURIComponent(value.replace(/\+/g, ' '));
  } catch {
    return value;
  }
}

function queryOf(input: string): Record<string, string> | null {
  const qIndex = input.indexOf('?');
  if (qIndex === -1) return null;
  const query = input.slice(qIndex + 1).split('#')[0];
  const out: Record<string, string> = {};
  for (const pair of query.split('&')) {
    if (!pair) continue;
    const eq = pair.indexOf('=');
    const key = decode(eq === -1 ? pair : pair.slice(0, eq)).trim();
    const val = decode(eq === -1 ? '' : pair.slice(eq + 1)).trim();
    if (key) out[key] = val;
  }
  return out;
}

/**
 * Extract the reset token (and email, when present) from user input.
 * Returns null when the input carries nothing usable.
 */
export function parseResetInput(raw: string): ResetLinkParts | null {
  const input = (raw ?? '').trim();
  if (!input) return null;

  const query = queryOf(input);
  if (query) {
    const token = (query.token ?? '').trim();
    if (!token) return null;
    const email = (query.email ?? '').trim();
    return { token, email: email || null };
  }

  // Bare token pasted from the link.
  if (TOKEN_PATTERN.test(input)) {
    return { token: input, email: null };
  }

  return null;
}

/** True when the string looks like a URL rather than a bare token. */
export function looksLikeResetLink(raw: string): boolean {
  const input = (raw ?? '').trim();
  return /^(https?:\/\/|ballpicker:\/\/)/i.test(input) || input.includes('reset-password');
}
