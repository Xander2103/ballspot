/**
 * Clubs feature flag, display helpers and source-level guards (RN components
 * cannot be rendered under this runner, so wiring is pinned by reading the
 * source, like releaseAuditGuards.test.ts).
 */
import * as fs from 'fs';
import * as path from 'path';
import { isClubsFeatureEnabled } from '../features';
import { clubHeaderParts, clubMetaParts, clubPackState, competitionSubtitle, formatClubDate, roleLabel } from '../clubDisplay';
import type { CompetitionCard } from '../../types/club';

const SRC = path.resolve(__dirname, '..', '..');
const read = (rel: string) => fs.readFileSync(path.join(SRC, rel), 'utf8');

describe('features.clubs fails closed', () => {
  it.each([
    [undefined, false],
    [null, false],
    [{}, false],
    [{ features: null }, false],
    [{ features: {} }, false],
    [{ features: { clubs: 'true' } }, false],
    [{ features: { clubs: 1 } }, false],
    [{ features: { clubs: false } }, false],
    [{ features: { clubs: true } }, true],
  ])('%j → %s', (config, expected) => {
    expect(isClubsFeatureEnabled(config)).toBe(expected);
  });

  it('the default app config keeps Clubs off', () => {
    expect(read('api/configApi.ts')).toMatch(/features: \{ clubs: false \}/);
  });
});

describe('display helpers', () => {
  it('club card meta line', () => {
    expect(clubMetaParts({ city: 'Gent', packs_count: 6, competitions: { active: 1, upcoming: 2 } }, 'en'))
      .toEqual(['Gent', '6 packs', '1 live', '2 upcoming']);
    expect(clubMetaParts({ city: null, packs_count: 1, competitions: { active: 0, upcoming: 0 } }, 'en')).toEqual(['1 pack']);
  });

  it('club header line', () => {
    expect(clubHeaderParts({ city: 'Gent', country_code: 'BE', club_type: 'sports_club', members_count: 214 }, 'en'))
      .toEqual(['Gent, BE', 'Sports club', '214 members']);
    expect(clubHeaderParts({ city: null, country_code: null, club_type: 'weird', members_count: 1 }, 'en')).toEqual(['1 member']);
  });

  it('role labels fall back to Member', () => {
    expect(roleLabel('owner', 'en')).toBe('Owner');
    expect(roleLabel('admin', 'en')).toBe('Admin');
    expect(roleLabel('something', 'en')).toBe('Member');
  });

  it('pack state: competition-only wins over progress', () => {
    expect(clubPackState({ reserved_for_competition: true, progress: { status: 'active', completed_count: 1, total_challenges: 3, total_score: 10 } })).toBe('reserved');
    expect(clubPackState({ reserved_for_competition: false, progress: { status: 'completed', completed_count: 3, total_challenges: 3, total_score: 10 } })).toBe('completed');
    expect(clubPackState({ reserved_for_competition: false, progress: null })).toBe('new');
  });

  it('competition subtitle shows my rank only once it has started', () => {
    const base: CompetitionCard = {
      id: 'C', name: 'Autumn Cup', description: null, phase: 'active',
      starts_at: '2026-11-01T09:00:00Z', ends_at: '2026-11-30T21:00:00Z', timezone: 'Europe/Brussels',
      rules: { replay_mode: 'single_attempt', max_attempts: 1, score_rule: 'first', normal_play_policy: 'blocked' },
      packs_count: 2, participants_count: 61, me: { rank: 4, total_score: 2140, packs_completed: 1 },
    };
    expect(competitionSubtitle(base, 'en')).toMatch(/^Ends .+ · You #4 of 61$/);
    expect(competitionSubtitle({ ...base, phase: 'upcoming', me: null }, 'en')).toMatch(/^Starts .+ · 61 players$/);
    expect(competitionSubtitle({ ...base, phase: 'finalized' }, 'en')).toMatch(/^Ended /);
  });

  it('dates never throw on bad input', () => {
    expect(formatClubDate(null)).toBe('');
    expect(formatClubDate('not a date')).toBe('');
    expect(formatClubDate('2026-10-20T10:00:00Z', 'en')).toMatch(/2026/);
  });
});

describe('Clubs wiring', () => {
  it('the Clubs tab sits between Tournaments and Friends and only exists behind the flag', () => {
    const source = read('app/MainTabs.tsx');
    expect(source).toMatch(/useClubsFeature\(\)/);
    const tournaments = source.indexOf('name="Tournaments"');
    const clubs = source.indexOf('name="Clubs"');
    const friends = source.indexOf('name="Friends"');
    expect(tournaments).toBeGreaterThan(-1);
    expect(clubs).toBeGreaterThan(tournaments);
    expect(friends).toBeGreaterThan(clubs);
    expect(source).toMatch(/\{clubsEnabled \? \(\s*<Tab\.Screen\s+name="Clubs"/);
    expect(read('hooks/useClubsFeature.ts')).toMatch(/isClubsFeatureEnabled\(cfg\)/);
  });

  it('there is no club directory: the API only lists joined clubs and joins by code', () => {
    const api = read('api/clubApi.ts');
    expect(api).not.toMatch(/discover|directory|\/clubs\/search|\/clubs\/public|\/clubs\/all/i);
    expect(api).toMatch(/`\/clubs\$\{query\(/);
    expect(api).toMatch(/'\/clubs\/join'/);
  });

  it('the API client accepts a caller abort signal and keeps its own timeout', () => {
    const client = read('api/client.ts');
    expect(client).toMatch(/REQUEST_TIMEOUT_MS = 20000/);
    expect(client).toMatch(/callerSignal\?\.addEventListener\?\.\('abort'/);
    expect(client).toMatch(/throw new TypeError\('Network request failed'\)/);
  });

  it('the friend scanner keeps its flow on the shared camera component', () => {
    const friend = read('screens/ScanFriendCodeScreen.tsx');
    expect(friend).toMatch(/<QrCodeScanner/);
    expect(friend).toMatch(/ballpicker:friend:/);
    expect(friend).toMatch(/navigation\.navigate\('Home', \{ screen: 'Friends', params: \{ scannedCode: code \} \}\)/);
    expect(read('screens/ScanClubCodeScreen.tsx')).toMatch(/parseClubCode\(raw\)/);
  });

  it('pack screens route every API call through the play-session adapter', () => {
    for (const file of ['screens/PackDetailScreen.tsx', 'screens/PackGuessScreen.tsx', 'screens/PackCompleteScreen.tsx']) {
      const source = read(file);
      expect({ file, usesAdapter: /playSessionApi\(/.test(source), directPackApi: /packApi\./.test(source) })
        .toEqual({ file, usesAdapter: true, directPackApi: false });
    }
    for (const file of ['screens/PackGuessScreen.tsx', 'screens/PackResultScreen.tsx', 'screens/PackCompleteScreen.tsx', 'app/AppNavigator.tsx']) {
      expect({ file, goPacks: /goPacks\(/.test(read(file)) }).toEqual({ file, goPacks: false });
    }
  });
});

describe('competitions wiring', () => {
  it('competition play goes through the adapter: start is idempotent per attempt number, resume uses GET attempt', () => {
    const adapter = read('api/playSessionApi.ts');
    expect(adapter).toMatch(/clubApi\.competitionAttempt\(session\.attemptId\)/);
    expect(adapter).toMatch(/clubApi\.startCompetitionAttempt\(clubId, competitionId, packSlug, session\.attemptNumber\)/);
    expect(adapter).toMatch(/toPackGuessResult\(await clubApi\.competitionGuess\(/);
    const api = read('api/clubApi.ts');
    expect(api).toMatch(/JSON\.stringify\(\{ attempt_number: attemptNumber \}\)/);
    expect(api).toMatch(/`\/club-competition-attempts\/\$\{attemptId\}\/guess`/);
  });

  it('the competition screen starts the server-provided next attempt number and guards double taps', () => {
    const source = read('screens/ClubCompetitionScreen.tsx');
    expect(source).toMatch(/competitionPackAction\(pack\.state, detail\.phase, detail\.rules\)/);
    expect(source).toMatch(/handleStart\(pack, action\.attemptNumber\)/);
    expect(source).toMatch(/if \(startInFlight\.current\) return;/);
  });

  it('a competition attempt ends on its own complete view', () => {
    expect(read('screens/PackCompleteScreen.tsx')).toMatch(/isCompetitionSession\(props\.route\.params\.session\)\) return <CompetitionCompleteView/);
    expect(read('screens/CompetitionCompleteView.tsx')).toMatch(/clubs\.competition\.complete\.noXp/);
  });

  it('club trophies come from the player\'s own /me/club-finishes and stay hidden when empty', () => {
    const source = read('components/TrophyRoom.tsx');
    expect(source).toMatch(/clubApi\.myClubFinishes\(\)/);
    expect(source).toMatch(/clubFinishes\.length > 0/);
    expect(read('api/clubApi.ts')).toMatch(/'\/me\/club-finishes'/);
  });

  it('ConfirmModal follows the active theme', () => {
    const source = read('components/ConfirmModal.tsx');
    expect(source).not.toMatch(/theme\/colors/);
    expect(source).toMatch(/useTheme\(\)/);
  });
});

describe('club admin + reports + notifications wiring (Wave F)', () => {
  it('club admin entries in the club menu follow the abilities; Report is open to everyone', () => {
    const source = read('screens/ClubDetailScreen.tsx');
    expect(source).toMatch(/clubAdminEntries\(club\.abilities\)/);
    expect(source).toMatch(/navigation\.navigate\('ClubInvites'/);
    expect(source).toMatch(/navigation\.navigate\('ClubPhotos'/);
    expect(source).toMatch(/navigation\.navigate\('ClubReport', \{ clubId, subjectType: 'club'/);
    expect(read('screens/ClubCompetitionScreen.tsx')).toMatch(/subjectType: 'competition', subjectId: competitionId/);
    expect(read('screens/ClubMembersScreen.tsx')).toMatch(/subjectType: 'member', subjectId: String\(member\.user_id\)/);
  });

  it('member actions come from the per-item flags and are optimistic with revert', () => {
    const source = read('screens/ClubMembersScreen.tsx');
    expect(source).toMatch(/memberActions\(item, meId\)/);
    expect(source).toMatch(/clubApi\.changeMemberRole\(/);
    expect(source).toMatch(/role: previous/);
  });

  it('invites: QR from qr_payload, copy, share text', () => {
    const source = read('screens/ClubInviteScreen.tsx');
    expect(source).toMatch(/<QRCode value=\{qrCode\.qr_payload\}/);
    expect(source).toMatch(/Clipboard\.setStringAsync\(code\.code\)/);
    expect(source).toMatch(/Share\.share\(\{ message: buildInviteShareMessage\(/);
  });

  it('photos: library picker (images, multiple), consent sent with every upload', () => {
    const source = read('screens/ClubPhotosScreen.tsx');
    expect(source).toMatch(/launchImageLibraryAsync\(\{\s*mediaTypes: \['images'\],\s*allowsMultipleSelection: true/);
    expect(source).toMatch(/uploadPhoto\(clubId, photo, \{ rightsConfirmed: rights, note \}\)/);
    expect(read('api/clubApi.ts')).toMatch(/form\.append\('rights_confirmed'/);
  });

  it('push taps route through the auth gate', () => {
    const source = read('app/notificationRouting.ts');
    expect(source).toMatch(/routeForNotificationData\(response\.notification\.request\.content\.data\)/);
    expect(source).toMatch(/canOpenFromNotification\(names, !!token\)/);
    expect(source).toMatch(/addNotificationResponseReceivedListener/);
    expect(read('app/AppNavigator.tsx')).toMatch(/useNotificationRouting\(navigationRef, navReady\)/);
  });

  it('the Club updates toggle binds club_updates_enabled and hides on backends without it', () => {
    const source = read('components/NotificationSettingsCard.tsx');
    expect(source).toMatch(/key: 'club_updates_enabled'/);
    expect(source).toMatch(/typeof settings\[toggle\.key\] === 'boolean'/);
  });
});
