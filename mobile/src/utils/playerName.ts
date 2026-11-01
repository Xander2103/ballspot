import { translate as t } from '../i18n/core';

/**
 * How another player is named anywhere in the app. Only the username counts:
 * `name` is never read here, so an older backend that still sends the real name
 * cannot put it on screen. Own-account screens read `user.name` directly.
 */
export function publicPlayerName(player: { username?: string | null } | null | undefined): string {
  return player?.username?.trim() || t('tournaments.rivalry.aPlayer');
}
