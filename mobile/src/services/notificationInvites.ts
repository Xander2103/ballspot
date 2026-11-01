import { localFlags } from '../storage/localFlags';
import {
  NO_INVITES,
  migrateLegacyPrompt,
  parseInviteState,
  serializeInviteState,
  type DevicePermission,
  type InviteState,
} from '../utils/notificationPermission';

/** The old one-time flag, read once to carry its answer over. */
const LEGACY_PROMPT_SEEN = 'notif_prompt_seen';

// Per account and kept on logout: signing out and back in must not reset the
// invitation count, while another account on this device gets its own.
const keyFor = (userId: number) => `notif_invite_${userId}`;

export const notificationInvites = {
  async load(userId: number, permission: DevicePermission): Promise<InviteState> {
    const stored = await localFlags.get(keyFor(userId));
    if (stored) return parseInviteState(stored);

    if (!(await localFlags.get(LEGACY_PROMPT_SEEN))) return NO_INVITES;
    const migrated = migrateLegacyPrompt(permission, Date.now());
    await this.save(userId, migrated);
    await localFlags.remove(LEGACY_PROMPT_SEEN);
    return migrated;
  },

  save(userId: number, state: InviteState): Promise<void> {
    return localFlags.set(keyFor(userId), serializeInviteState(state));
  },
};
