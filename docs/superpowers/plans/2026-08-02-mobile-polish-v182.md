# BallPicker v1.8.2 Mobile Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the remaining mobile UX gaps in the Daily/Tournament/Pack result flows (image placement, fullscreen viewing, back navigation, title spoilers, network/image errors), ship a first-version Friends system, and let users hide finished tournaments while keeping them in a new Profile history section.

**Architecture:** Mobile changes are additive components (`FullscreenImageViewer`, `ResultImageSection`, `HeaderExitButton`) plus small edits to existing screens — no redesign, no restyling of what already works. Backend changes are three new feature areas: friends (2 new tables + 1 new user column), a per-user "hidden completed tournament" flag on the existing `league_members` pivot, and a couple of resource-shape additions. Every migration is guarded with `Schema::hasColumn` / `Schema::hasTable` so it is safe to run on production data.

**Tech Stack:** Expo SDK 56 (React Native 0.85.3, React 19.2.3, `@react-navigation/native-stack` v7, TypeScript 5.9), Laravel 12 / PHP 8.2, Sanctum, PHPUnit.

## Global Constraints

- **NEVER run `php artisan migrate:fresh`** (or `migrate:refresh`, or any command that drops production tables). Use `php artisan migrate` only.
- All new migrations must be idempotent and additive: wrap column adds in `if (!Schema::hasColumn(...))` and table creates in `if (!Schema::hasTable(...))`, matching `database/migrations/2026_07_22_000001_add_preferences_to_users.php`.
- Do not break: login/register/2FA, email verification, Daily, Packs, Profile, Trophy Room, tournaments, XP, badges, admin.
- Backend work is TDD: write the failing test, run it, see it fail, then implement.
- Mobile has **no test runner**. The mobile verification command is `npx tsc --noEmit` run from `mobile/`. Treat a clean typecheck as the gate for every mobile task.
- Expo: before adding or configuring any Expo package, read the exact versioned docs at `https://docs.expo.dev/versions/v56.0.0/` (per `mobile/AGENTS.md`). Install with `npx expo install <pkg>` so SDK-56-compatible versions are resolved — never hand-pin an Expo package version.
- Keep the current BallPicker visual style. Result screens (`ResultScreen`, `DailyResultScreen`) currently use the static `../theme/colors` palette rather than `useTheme` — **keep using `colors` in code shared by those screens** so nothing changes visually. Pack/Daily-guess screens use `useTheme`; keep them themed.
- User-facing button copy for the fullscreen action is exactly `View fullscreen`.
- Tournament-removal confirm copy is exactly:
  - title `Remove tournament?`
  - message `This will remove it from your list. Your result/history will stay saved.`
  - confirm `Remove`, cancel `Cancel`
- Never expose `email`, `password`, `is_admin`, `friend_code` (of another user), or any auth/session data on public profile endpoints.
- Every new API route lives inside the existing `auth:sanctum` + `verified` group in `backend/routes/api.php`.
- Commit after every task.

---

## File Structure

**New mobile files**

| File | Responsibility |
|---|---|
| `mobile/src/components/FullscreenImageViewer.tsx` | Dark full-screen modal image viewer: `contain` fit, X button, tap-to-close, Android hardware back. |
| `mobile/src/components/FullscreenButton.tsx` | The single "View fullscreen" button definition, used by all five image surfaces. |
| `mobile/src/components/ResultImageSection.tsx` | The reveal image + "View fullscreen" button + guess-vs-ball legend block shared by `ResultScreen` and `DailyResultScreen`. |
| `mobile/src/components/HeaderExitButton.tsx` | Themed header-left button used by game-mode screens to leave to Home/Packs. |
| `mobile/src/app/navigationActions.ts` | `goHome()` / `goPacks()` stack resets + `useHardwareBack()` hook. |
| `mobile/src/api/friendsApi.ts` | Friends + public-profile HTTP calls. |
| `mobile/src/types/friend.ts` | Friend, friend-request and public-profile types. |
| `mobile/src/screens/FriendsScreen.tsx` | Friend code + QR + copy + add-by-code + requests + friends list. |
| `mobile/src/screens/FriendProfileScreen.tsx` | Public profile of one player + remove-friend. |
| `mobile/src/screens/ScanFriendCodeScreen.tsx` | Camera QR scanner (permission asked here only). |
| `mobile/src/components/ProfileHistoryCard.tsx` | Compact completed-tournament history list for Profile. |

**New backend files**

| File | Responsibility |
|---|---|
| `backend/database/migrations/2026_08_02_000001_add_friend_code_to_users.php` | `users.friend_code` + safe backfill. |
| `backend/database/migrations/2026_08_02_000002_create_friend_requests_table.php` | `friend_requests`. |
| `backend/database/migrations/2026_08_02_000003_create_friendships_table.php` | `friendships` (two rows per friendship, one per direction). |
| `backend/database/migrations/2026_08_02_000004_add_hidden_at_to_league_members.php` | Per-user hidden completed tournament flag. |
| `backend/app/Models/FriendRequest.php` | Friend request model + status constants. |
| `backend/app/Models/Friendship.php` | Directed friendship row. |
| `backend/app/Http/Controllers/Api/FriendController.php` | List / request / accept / reject / remove / friend-code. |
| `backend/app/Http/Controllers/Api/PublicProfileController.php` | `GET /api/users/{user}/public-profile`. |
| `backend/tests/Feature/FriendsTest.php` | Friends system feature tests. |
| `backend/tests/Feature/PublicProfileTest.php` | Public profile privacy tests. |
| `backend/tests/Feature/LeagueHideTest.php` | Hide-completed-tournament tests. |
| `backend/tests/Feature/ImageUrlTest.php` | Absolute storage-URL tests. |

**Modified files** are named per task.

---

## Task 1: Fullscreen image viewer + shared trigger button

**Files:**
- Create: `mobile/src/components/FullscreenImageViewer.tsx`
- Create: `mobile/src/components/FullscreenButton.tsx`

**Interfaces:**
- Consumes: nothing (leaf components).
- Produces:
  - `export function FullscreenImageViewer(props: { visible: boolean; imageUri: string | null; onClose: () => void }): React.ReactElement | null`
  - `export function FullscreenButton(props: { onPress: () => void; variant?: 'themed' | 'static'; compact?: boolean }): React.ReactElement`

The button is a single shared component on purpose: five screens trigger the viewer, and duplicating the markup and styles five times is exactly the defect a reviewer would (correctly) flag.

- [ ] **Step 1: Create the component**

Create `mobile/src/components/FullscreenImageViewer.tsx`:

```tsx
import React from 'react';
import { Modal, View, Text, Image, StyleSheet, Pressable, useWindowDimensions } from 'react-native';

interface Props {
  visible: boolean;
  /** Null renders nothing — callers can pass a possibly-missing image safely. */
  imageUri: string | null;
  onClose: () => void;
}

/**
 * Dark full-screen image viewer. Closes on the X button, on a tap anywhere
 * (the image itself is pointer-transparent so taps reach the backdrop), and on
 * the Android hardware back button via Modal's onRequestClose.
 */
export function FullscreenImageViewer({ visible, imageUri, onClose }: Props) {
  const { width, height } = useWindowDimensions();

  if (!imageUri) return null;

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
          onPress={onClose}
          accessibilityRole="button"
          accessibilityLabel="Close fullscreen image"
        />

        <View style={styles.imageWrap} pointerEvents="none">
          <Image source={{ uri: imageUri }} style={{ width, height }} resizeMode="contain" />
        </View>

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
  imageWrap: { ...StyleSheet.absoluteFillObject, alignItems: 'center', justifyContent: 'center' },
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
});
```

- [ ] **Step 2: Create the shared trigger button**

Create `mobile/src/components/FullscreenButton.tsx`. The two variants exist because the result screens use the static `theme/colors` palette while the pack/daily screens are themed — one component, two palettes, no duplicated markup:

```tsx
import React from 'react';
import { Pressable, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  onPress: () => void;
  /**
   * 'themed' follows the user's selected theme (pack / daily screens).
   * 'static' uses the fixed palette the result screens already use, so
   * nothing changes visually there.
   */
  variant?: 'themed' | 'static';
  /** Tighter padding for the guess screens, where vertical space is scarce. */
  compact?: boolean;
}

/** The single "View fullscreen" button. Used by every image surface. */
export function FullscreenButton({ onPress, variant = 'themed', compact = false }: Props) {
  const { theme } = useTheme();
  const surface = variant === 'static' ? colors.surface : theme.surface;
  const border  = variant === 'static' ? colors.border  : theme.border;
  const accent  = variant === 'static' ? colors.primary : theme.primary;

  return (
    <Pressable
      onPress={onPress}
      style={[
        styles.button,
        compact ? styles.compact : styles.regular,
        { backgroundColor: surface, borderColor: border },
      ]}
      accessibilityRole="button"
      accessibilityLabel="View fullscreen"
    >
      <Text style={[styles.text, compact && styles.textCompact, { color: accent }]}>
        ⛶  View fullscreen
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: { alignSelf: 'center', marginTop: spacing.sm, borderRadius: 10, borderWidth: 1 },
  regular: { paddingVertical: spacing.sm, paddingHorizontal: spacing.lg },
  compact: { paddingVertical: spacing.xs, paddingHorizontal: spacing.md },
  text: { fontSize: 14, fontWeight: '700' },
  textCompact: { fontSize: 13 },
});
```

- [ ] **Step 3: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/components/FullscreenImageViewer.tsx mobile/src/components/FullscreenButton.tsx
git commit -m "feat(mobile): add FullscreenImageViewer and shared FullscreenButton"
```

---

## Task 2: Shared result image section + reorder Daily/Tournament result screens

**Files:**
- Create: `mobile/src/components/ResultImageSection.tsx`
- Modify: `mobile/src/screens/ResultScreen.tsx`
- Modify: `mobile/src/screens/DailyResultScreen.tsx`

**Interfaces:**
- Consumes: `FullscreenImageViewer` (Task 1), `ImageGuessPicker`/`Marker` from `mobile/src/components/ImageGuessPicker.tsx`.
- Produces: `export function ResultImageSection(props: { imageUri: string; markers: Marker[]; isRevealImage: boolean; guessXRatio: number; guessYRatio: number; ballXRatio: number; ballYRatio: number; title?: string | null }): React.ReactElement`

The legend markup and styles below are copied verbatim from the current `ResultScreen`/`DailyResultScreen` (they are identical in both) so the visual result is unchanged.

- [ ] **Step 1: Create the shared section**

Create `mobile/src/components/ResultImageSection.tsx`:

```tsx
import React, { useState } from 'react';
import { View, Text, StyleSheet, Pressable } from 'react-native';
import { ImageGuessPicker, Marker } from './ImageGuessPicker';
import { FullscreenImageViewer } from './FullscreenImageViewer';
import { FullscreenButton } from './FullscreenButton';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  imageUri: string;
  markers: Marker[];
  isRevealImage: boolean;
  guessXRatio: number;
  guessYRatio: number;
  ballXRatio: number;
  ballYRatio: number;
  /** Shown under the legend — safe here, the answer is already revealed. */
  title?: string | null;
}

function pct(ratio: number): string {
  if (!Number.isFinite(ratio)) return '?';
  return `${Math.round(ratio * 100)}%`;
}

/**
 * Reveal image + "View fullscreen" action + guess-vs-ball legend. Rendered
 * directly under the score card on the Daily and Tournament result screens.
 */
export function ResultImageSection({
  imageUri, markers, isRevealImage,
  guessXRatio, guessYRatio, ballXRatio, ballYRatio, title,
}: Props) {
  const [fullscreen, setFullscreen] = useState(false);

  return (
    <View>
      {isRevealImage ? (
        <View style={styles.revealHint}>
          <Text style={styles.revealHintText}>Reveal photo — the real ball is visible in the image</Text>
        </View>
      ) : null}

      <Pressable
        onPress={() => setFullscreen(true)}
        accessibilityRole="button"
        accessibilityLabel="Open image fullscreen"
      >
        <View pointerEvents="none">
          <ImageGuessPicker imageUri={imageUri} interactive={false} markers={markers} />
        </View>
      </Pressable>

      <FullscreenButton onPress={() => setFullscreen(true)} variant="static" />

      <View style={styles.legend}>
        <View style={styles.legendRow}>
          <View style={styles.legendGhostIcon}>
            <Text style={styles.legendGhostEmoji}>⚽</Text>
          </View>
          <View>
            <Text style={styles.legendTitle}>Your guess</Text>
            <Text style={styles.legendCoord}>{pct(guessXRatio)}, {pct(guessYRatio)}</Text>
          </View>
        </View>
        <View style={styles.legendDivider} />
        <View style={styles.legendRow}>
          {isRevealImage ? <View style={styles.legendGlowIcon} /> : <View style={styles.legendDefaultIcon} />}
          <View>
            <Text style={styles.legendTitle}>Ball position</Text>
            <Text style={styles.legendCoord}>{pct(ballXRatio)}, {pct(ballYRatio)}</Text>
          </View>
        </View>
        {isRevealImage ? (
          <Text style={styles.legendHint}>Your ghost ball shows your guess. The real ball is visible in the photo.</Text>
        ) : (
          <Text style={styles.legendHint}>The marker shows the approximate ball position.</Text>
        )}
        {title ? <Text style={styles.challengeTitle}>{title}</Text> : null}
      </View>

      <FullscreenImageViewer
        visible={fullscreen}
        imageUri={imageUri}
        onClose={() => setFullscreen(false)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  revealHint: { marginBottom: 6, paddingHorizontal: spacing.xs },
  revealHintText: { fontSize: 11, color: colors.textMuted, fontStyle: 'italic', textAlign: 'right' },
  legend: {
    backgroundColor: colors.surface,
    borderRadius: 12,
    padding: spacing.md,
    marginTop: spacing.sm,
    marginBottom: spacing.md,
  },
  legendRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.xs },
  legendGhostIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 2, borderColor: 'rgba(255,255,255,0.85)',
    opacity: 0.72, alignItems: 'center', justifyContent: 'center',
  },
  legendGhostEmoji: { fontSize: 16 },
  legendGlowIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: 'transparent', borderWidth: 3, borderColor: '#00E676',
    shadowColor: '#00E676', shadowOffset: { width: 0, height: 0 }, shadowOpacity: 0.8, shadowRadius: 6,
  },
  legendDefaultIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: 'rgba(0,230,118,0.85)', borderWidth: 3, borderColor: '#ffffff',
  },
  legendTitle: { fontSize: 13, fontWeight: '600', color: colors.text },
  legendCoord: { fontSize: 12, color: colors.textSecondary },
  legendDivider: { height: 1, backgroundColor: colors.border, marginVertical: spacing.xs },
  legendHint: { fontSize: 11, color: colors.textMuted, fontStyle: 'italic', marginTop: spacing.xs, textAlign: 'center' },
  challengeTitle: { fontSize: 13, fontWeight: '700', color: colors.textSecondary, textAlign: 'center', marginTop: spacing.sm },
});
```

- [ ] **Step 2: Reorder `DailyResultScreen`**

In `mobile/src/screens/DailyResultScreen.tsx`:

1. Replace the `ImageGuessPicker` import line with:

```tsx
import { Marker } from '../components/ImageGuessPicker';
import { ResultImageSection } from '../components/ResultImageSection';
```

2. Add a title lookup next to the existing `categoryName` line:

```tsx
const challengeTitle = today?.daily_challenge?.challenge?.title ?? null;
```

3. Replace everything from `{/* New badges unlocked */}` down to the closing `) : null}` of the image block (i.e. the whole badges → rank-insight → image sequence) with this ordered block — image first, then XP/rank/badges:

```tsx
      {/* Reveal image + legend — directly under the score card */}
      {displayImageUrl ? (
        <ResultImageSection
          imageUri={displayImageUrl}
          markers={markers}
          isRevealImage={isRevealImage}
          guessXRatio={result.guess_x_ratio}
          guessYRatio={result.guess_y_ratio}
          ballXRatio={result.ball_x_ratio}
          ballYRatio={result.ball_y_ratio}
          title={challengeTitle}
        />
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>Image unavailable</Text>
        </View>
      )}

      {/* Rank up / XP progress / new badges */}
      {rankUp ? <RankUpCard rankUp={rankUp} /> : null}
      {rankProgress ? <RankProgressCard progress={rankProgress} /> : null}
      {newBadges && newBadges.length > 0 ? <NewBadgesCard badges={newBadges} /> : null}

      {/* Rank / percentile insight */}
      {typeof result.total_players === 'number' ? (
        <RankInsight
          rank={result.rank}
          totalPlayers={result.total_players}
          betterThanPercentage={result.better_than_percentage}
        />
      ) : null}
```

4. Delete the now-unused styles from the `StyleSheet.create` block: `revealHint`, `revealHintText`, `legend`, `legendRow`, `legendGhostIcon`, `legendGhostEmoji`, `legendGlowIcon`, `legendDefaultIcon`, `legendTitle`, `legendCoord`, `legendDivider`, `legendHint`. Add:

```tsx
    noImage: { alignItems: 'center', justifyContent: 'center', paddingVertical: spacing.xl },
    noImageText: { color: colors.textMuted, fontSize: 14, fontStyle: 'italic' },
```

- [ ] **Step 3: Reorder `ResultScreen`**

In `mobile/src/screens/ResultScreen.tsx` apply the same treatment:

1. Imports:

```tsx
import { Marker } from '../components/ImageGuessPicker';
import { ResultImageSection } from '../components/ResultImageSection';
```

2. Read the new optional route param alongside the existing ones (the param itself is added in Task 5):

```tsx
const { roundId, leagueId, imageUrl, leagueName, categoryName, challengeTitle, newBadges, rankProgress, rankUp, tournamentCompletion } = route.params;
```

3. Replace the block running from `{/* Tournament completion ... */}` through the end of the image block with:

```tsx
      {/* Reveal image + legend — directly under the score card */}
      {displayImageUrl ? (
        <ResultImageSection
          imageUri={displayImageUrl}
          markers={markers}
          isRevealImage={isRevealImage}
          guessXRatio={result.guess_x_ratio}
          guessYRatio={result.guess_y_ratio}
          ballXRatio={result.ball_x_ratio}
          ballYRatio={result.ball_y_ratio}
          title={challengeTitle ?? null}
        />
      ) : (
        <View style={styles.noImage}>
          <Text style={styles.noImageText}>Image unavailable</Text>
        </View>
      )}

      {/* Tournament completion (only on the finishing round) */}
      {tournamentCompletion?.is_completed ? <TournamentCompletionCard completion={tournamentCompletion} /> : null}

      {/* Rank up / XP progress / new badges */}
      {rankUp ? <RankUpCard rankUp={rankUp} /> : null}
      {rankProgress ? <RankProgressCard progress={rankProgress} /> : null}
      {newBadges && newBadges.length > 0 ? <NewBadgesCard badges={newBadges} /> : null}

      {/* Rank / percentile insight */}
      {typeof result.total_players === 'number' ? (
        <RankInsight
          rank={result.rank ?? 1}
          totalPlayers={result.total_players}
          betterThanPercentage={result.better_than_percentage ?? 0}
        />
      ) : null}
```

4. Delete the same now-unused legend/revealHint styles and add the same `noImage` / `noImageText` styles as in Step 2.

- [ ] **Step 4: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: one error about `challengeTitle` not existing on the `Result` route params — that param is added in Task 5. If you want a clean gate now, add it to `RootStackParamList` here (it is idempotent with Task 5): in `mobile/src/app/AppNavigator.tsx` add `challengeTitle?: string | null;` to the `Result:` entry. Re-run; expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/components/ResultImageSection.tsx mobile/src/screens/ResultScreen.tsx mobile/src/screens/DailyResultScreen.tsx mobile/src/app/AppNavigator.tsx
git commit -m "feat(mobile): move reveal image under score card and add fullscreen viewer"
```

---

## Task 3: Fullscreen on pack result and the guess screens

**Files:**
- Modify: `mobile/src/screens/PackResultScreen.tsx`
- Modify: `mobile/src/screens/GuessScreen.tsx`
- Modify: `mobile/src/screens/DailyChallengeScreen.tsx`
- Modify: `mobile/src/screens/PackGuessScreen.tsx`

**Interfaces:**
- Consumes: `FullscreenImageViewer`, `FullscreenButton` (Task 1).
- Produces: nothing new.

The guess screens keep their tap-to-place-guess behaviour on the image, so fullscreen there is reachable only through the explicit button. All four screens use the shared `FullscreenButton` — do not re-declare the button markup or its styles anywhere.

- [ ] **Step 1: PackResultScreen — image directly under score + fullscreen**

In `mobile/src/screens/PackResultScreen.tsx`:

1. Change the React import and add the shared component imports:

```tsx
import React, { useState } from 'react';
import { FullscreenImageViewer } from '../components/FullscreenImageViewer';
import { FullscreenButton } from '../components/FullscreenButton';
```

2. Inside the component, after `const styles = createStyles(theme);` add:

```tsx
  const [fullscreen, setFullscreen] = useState(false);
```

3. Replace the image card block with a pressable version plus the button (the score card already sits above it, so the order is already correct):

```tsx
        {imageUrl ? (
          <View style={styles.imageCard}>
            <Pressable
              onPress={() => setFullscreen(true)}
              accessibilityRole="button"
              accessibilityLabel="Open image fullscreen"
            >
              <View pointerEvents="none">
                <ImageGuessPicker imageUri={imageUrl} markers={markers} interactive={false} />
              </View>
            </Pressable>
            <FullscreenButton onPress={() => setFullscreen(true)} />
            <View style={styles.legendRow}>
              <Text style={styles.legend}>🔵 Your guess {pct(r.guessed_x)}, {pct(r.guessed_y)}</Text>
              <Text style={styles.legend}>🎯 Actual {pct(r.ball_x_ratio)}, {pct(r.ball_y_ratio)}</Text>
            </View>
          </View>
        ) : (
          <View style={styles.noImage}><Text style={styles.noImageText}>Image unavailable</Text></View>
        )}
```

4. Add `Pressable` to the `react-native` import list.

5. Render the viewer just before the closing `</Screen>`:

```tsx
      <FullscreenImageViewer visible={fullscreen} imageUri={imageUrl} onClose={() => setFullscreen(false)} />
```

6. Add styles to `createStyles`:

```tsx
    noImage: { alignItems: 'center', justifyContent: 'center', paddingVertical: spacing.xl },
    noImageText: { color: theme.textMuted, fontSize: 14, fontStyle: 'italic' },
```

- [ ] **Step 2: GuessScreen — add the fullscreen button under the image**

In `mobile/src/screens/GuessScreen.tsx`:

1. Add to imports:

```tsx
import { FullscreenImageViewer } from '../components/FullscreenImageViewer';
import { FullscreenButton } from '../components/FullscreenButton';
```

2. Add state next to the other `useState` calls:

```tsx
  const [fullscreen, setFullscreen] = useState(false);
```

3. Inside the `styles.imageCard` `View`, directly after `<ImageGuessPicker ... />`, add (this screen uses the static palette, like the rest of `GuessScreen`):

```tsx
          <FullscreenButton onPress={() => setFullscreen(true)} variant="static" compact />
```

4. Before the closing `</Screen>`:

```tsx
      <FullscreenImageViewer
        visible={fullscreen}
        imageUri={round.challenge.hidden_image_url}
        onClose={() => setFullscreen(false)}
      />
```

No new styles are needed — the button carries its own.

- [ ] **Step 3: DailyChallengeScreen — same button**

Apply the equivalent change in `mobile/src/screens/DailyChallengeScreen.tsx`. It is themed, so use the default `themed` variant:

- imports: `import { FullscreenImageViewer } from '../components/FullscreenImageViewer';` and `import { FullscreenButton } from '../components/FullscreenButton';`
- state: `const [fullscreen, setFullscreen] = useState(false);`
- inside `styles.imageCard`, after `<ImageGuessPicker ... />`:

```tsx
          <FullscreenButton onPress={() => setFullscreen(true)} compact />
```

- before `</Screen>`: `<FullscreenImageViewer visible={fullscreen} imageUri={imageUrl} onClose={() => setFullscreen(false)} />`
- no new styles.

- [ ] **Step 4: PackGuessScreen — same button**

Apply exactly the Step 3 change to `mobile/src/screens/PackGuessScreen.tsx`, using `challenge.hidden_image_url` as the `imageUri`.

- [ ] **Step 5: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add mobile/src/screens/PackResultScreen.tsx mobile/src/screens/GuessScreen.tsx mobile/src/screens/DailyChallengeScreen.tsx mobile/src/screens/PackGuessScreen.tsx
git commit -m "feat(mobile): add View fullscreen action to pack result and guess screens"
```

---

## Task 4: Game-mode back button behaviour

**Files:**
- Create: `mobile/src/app/navigationActions.ts`
- Create: `mobile/src/components/HeaderExitButton.tsx`
- Modify: `mobile/src/app/AppNavigator.tsx`
- Modify: `mobile/src/screens/GuessScreen.tsx`
- Modify: `mobile/src/screens/ResultScreen.tsx`
- Modify: `mobile/src/screens/DailyChallengeScreen.tsx`
- Modify: `mobile/src/screens/DailyResultScreen.tsx`
- Modify: `mobile/src/screens/PackGuessScreen.tsx`
- Modify: `mobile/src/screens/PackResultScreen.tsx`

**Interfaces:**
- Consumes: `RootStackParamList` from `mobile/src/app/AppNavigator.tsx`.
- Produces:
  - `export function goHome(navigation: GameNavigation): void`
  - `export function goPacks(navigation: GameNavigation): void`
  - `export function useHardwareBack(handler: () => void): void`
  - `export function HeaderExitButton(props: { label: string; onPress: () => void }): React.ReactElement`

Stack **resets** (not `navigate`) are used deliberately: `navigate` would push a second `Home` on top of the game screen when `Home` is not already in the stack, which is exactly the nested-back-loop problem being fixed.

- [ ] **Step 1: Create the navigation helpers**

Create `mobile/src/app/navigationActions.ts`:

```ts
import { useCallback } from 'react';
import { BackHandler, Platform } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import type { NavigationProp } from '@react-navigation/native';
import type { RootStackParamList } from './AppNavigator';

export type GameNavigation = Pick<NavigationProp<RootStackParamList>, 'reset'>;

/**
 * Leave a game flow for the Home screen. A full reset (rather than navigate)
 * guarantees no game screen is left underneath, so Home's back is a true exit.
 */
export function goHome(navigation: GameNavigation): void {
  navigation.reset({ index: 0, routes: [{ name: 'Home' }] });
}

/** Leave a pack flow for the Packs list, with Home underneath it. */
export function goPacks(navigation: GameNavigation): void {
  navigation.reset({ index: 1, routes: [{ name: 'Home' }, { name: 'Packs' }] });
}

/**
 * Route the Android hardware back button to `handler` while the screen is
 * focused. No-op on iOS/web, where there is no hardware back button.
 */
export function useHardwareBack(handler: () => void): void {
  useFocusEffect(
    useCallback(() => {
      if (Platform.OS !== 'android') return;
      const sub = BackHandler.addEventListener('hardwareBackPress', () => {
        handler();
        return true; // handled — do not pop the stack
      });
      return () => sub.remove();
    }, [handler])
  );
}
```

- [ ] **Step 2: Create the header button**

Create `mobile/src/components/HeaderExitButton.tsx`:

```tsx
import React from 'react';
import { Text, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';

interface Props {
  label: string;
  onPress: () => void;
}

/** Header-left "leave this flow" button used by the game-mode screens. */
export function HeaderExitButton({ label, onPress }: Props) {
  const { theme } = useTheme();
  return (
    <TouchableOpacity
      onPress={onPress}
      hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
      accessibilityRole="button"
      accessibilityLabel={`Back to ${label}`}
    >
      <Text style={{ color: theme.primary, fontSize: 16, fontWeight: '700' }}>‹ {label}</Text>
    </TouchableOpacity>
  );
}
```

- [ ] **Step 3: Wire the header buttons in AppNavigator**

In `mobile/src/app/AppNavigator.tsx` add imports:

```tsx
import { HeaderExitButton } from '../components/HeaderExitButton';
import { goHome, goPacks } from './navigationActions';
```

Replace the six game-mode `<Stack.Screen>` entries with:

```tsx
        <Stack.Screen
          name="PackGuess"
          component={PackGuessScreen}
          options={({ route, navigation }) => ({
            title: route.params.packName,
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Packs" onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen
          name="PackResult"
          component={PackResultScreen}
          options={({ navigation }) => ({
            title: 'Pack Result',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Packs" onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen
          name="Guess"
          component={GuessScreen}
          options={({ navigation }) => ({
            title: 'Make Your Guess',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="Result"
          component={ResultScreen}
          options={({ navigation }) => ({
            title: 'Round Result',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="DailyChallenge"
          component={DailyChallengeScreen}
          options={({ navigation }) => ({
            title: 'Daily Ball Challenge',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="DailyResult"
          component={DailyResultScreen}
          options={({ navigation }) => ({
            title: 'Daily Result',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
```

Leave every other `<Stack.Screen>` untouched — normal back behaviour is preserved on non-game screens.

- [ ] **Step 4: Wire Android hardware back in each game screen**

In `GuessScreen.tsx`, `ResultScreen.tsx`, `DailyChallengeScreen.tsx`, `DailyResultScreen.tsx` add:

```tsx
import { goHome, useHardwareBack } from '../app/navigationActions';
```

and, as the first statement inside the component body:

```tsx
  useHardwareBack(useCallback(() => goHome(navigation), [navigation]));
```

Add `useCallback` to the `react` import in each file.

In `PackGuessScreen.tsx` and `PackResultScreen.tsx` do the same with `goPacks`:

```tsx
import { goPacks, useHardwareBack } from '../app/navigationActions';
...
  useHardwareBack(useCallback(() => goPacks(navigation), [navigation]));
```

- [ ] **Step 5: Point the in-screen exit buttons at the same targets**

- `ResultScreen.tsx`: change the two `navigation.navigate('LeagueDetail', ...)` calls' *secondary* button — keep "Play Next Round" and "Back to Tournament" pointing at `LeagueDetail` (that is a deliberate in-flow action, not a back button), and change the `!result` fallback button from `navigation.goBack()` to `goHome(navigation)` with title `Back Home`.
- `DailyResultScreen.tsx`: the existing `Back Home` button becomes `onPress={() => goHome(navigation)}`.
- `GuessScreen.tsx`: the two `Alert.alert(..., { onPress: () => navigation.goBack() })` handlers become `() => goHome(navigation)`.

- [ ] **Step 6: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add mobile/src/app/navigationActions.ts mobile/src/components/HeaderExitButton.tsx mobile/src/app/AppNavigator.tsx mobile/src/screens/
git commit -m "feat(mobile): game-mode back button exits to Home/Packs instead of nesting"
```

---

## Task 5: Guess-screen spoiler cleanup

**Files:**
- Modify: `mobile/src/screens/GuessScreen.tsx`
- Modify: `mobile/src/screens/DailyChallengeScreen.tsx`
- Modify: `mobile/src/screens/PackGuessScreen.tsx`
- Modify: `mobile/src/screens/HomeScreen.tsx`
- Modify: `mobile/src/app/AppNavigator.tsx`

**Interfaces:**
- Consumes: nothing new.
- Produces: `RootStackParamList['Result']` gains `challengeTitle?: string | null` (consumed by `ResultScreen` from Task 2).

- [ ] **Step 1: Remove the title from the tournament guess screen**

In `mobile/src/screens/GuessScreen.tsx`:

- Delete the line `<Text style={styles.challengeTitle}>{round.challenge.title}</Text>`.
- Delete the now-unused `challengeTitle` style from `StyleSheet.create`.
- Round number, progress, daily context, category and difficulty all stay.

- [ ] **Step 2: Remove the title from the daily guess screen**

In `mobile/src/screens/DailyChallengeScreen.tsx`:

- Delete the line `<Text style={styles.challengeTitle}>{challenge.challenge.title}</Text>`.
- Delete the `challengeTitle` style from `createStyles`.
- Date, sport, category, difficulty and tags stay.

- [ ] **Step 3: Remove the title from the pack guess screen**

In `mobile/src/screens/PackGuessScreen.tsx`:

- Delete the line `<Text style={styles.title}>{challenge.title}</Text>`.
- Delete the `title` style from `createStyles`.
- `Challenge {step} / {total}` and the progress bar stay; the pack name is still the header title.

- [ ] **Step 4: Remove the pre-play daily title from Home**

In `mobile/src/screens/HomeScreen.tsx`, inside `DailyCard`, replace the unplayed-challenge info block so the title is not shown before playing:

```tsx
      {today.daily_challenge?.challenge && (
        <Text style={styles.dailyChallengeInfo}>
          {today.daily_challenge.challenge.category ? `${today.daily_challenge.challenge.category.name} · ` : ''}
          {today.daily_challenge.challenge.difficulty}
        </Text>
      )}
```

- [ ] **Step 5: Pass the title to the tournament result screen**

In `mobile/src/app/AppNavigator.tsx`, add `challengeTitle?: string | null;` to the `Result:` entry of `RootStackParamList` (skip if already added in Task 2 Step 4).

In `mobile/src/screens/GuessScreen.tsx`, add `challengeTitle` to both `navigation.replace('Result', { ... })` calls:

- in the already-guessed redirect: `challengeTitle: res.current_round.challenge.title,`
- in `handleSubmit`: `challengeTitle: round!.challenge.title,`

(If Task 6 has already removed the already-guessed redirect, only the `handleSubmit` call remains — that is expected.)

- [ ] **Step 6: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add mobile/src/screens/GuessScreen.tsx mobile/src/screens/DailyChallengeScreen.tsx mobile/src/screens/PackGuessScreen.tsx mobile/src/screens/HomeScreen.tsx mobile/src/app/AppNavigator.tsx
git commit -m "fix(mobile): hide challenge title on guess screens to avoid spoilers"
```

---

## Task 6: Stop the expected-404 result probe and harden result loading

**Files:**
- Modify: `mobile/src/screens/GuessScreen.tsx`
- Modify: `mobile/src/screens/ResultScreen.tsx`

**Interfaces:**
- Consumes: `roundApi` (`mobile/src/api/roundApi.ts`), `goHome` (Task 4).
- Produces: nothing new.

**Root cause (verified):** `GET /api/leagues/{league}/current-round` already excludes rounds the user has guessed (`whereDoesntHave('guesses', ...)` in `LeagueController::currentRound`). So when `GuessScreen` confirms `res.current_round.id === roundId`, the user provably has **not** guessed — yet the screen then calls `roundApi.result(roundId)`, which can only ever return `404 No guess found` (`RoundController::result`). That probe is the 404 in the network log. It is dead code and is removed.

- [ ] **Step 1: Remove the guaranteed-404 probe**

In `mobile/src/screens/GuessScreen.tsx`, replace the whole `.then(async (res) => { ... })` body with:

```tsx
      .then((res) => {
        if (cancelled) return;

        // current-round already excludes rounds this user has guessed, so a
        // matching id proves the round is still unplayed. No result probe is
        // needed (it could only ever 404) — a mismatch means the round moved on.
        if (!res.current_round || res.current_round.id !== roundId) {
          Alert.alert('Round unavailable', 'This round is no longer available. It may already be played.', [
            { text: 'OK', onPress: () => goHome(navigation) },
          ]);
          setLoading(false);
          return;
        }

        setRound(res.current_round);
        setProgress(res.progress ?? null);
        setDailyContext({
          roundsPerDay: res.rounds_per_day,
          playedToday: res.played_today_count,
          remainingToday: res.remaining_today_count,
        });
        setLoading(false);
      })
```

Also change the `.catch` handler so a failed load does not leave a blank screen:

```tsx
      .catch(() => {
        if (cancelled) return;
        Alert.alert('Connection problem', 'Could not load this round. Check your connection and try again.', [
          { text: 'OK', onPress: () => goHome(navigation) },
        ]);
        setLoading(false);
      })
```

Delete the now-redundant `.finally(...)` block.

- [ ] **Step 2: Distinguish "no result yet" from a network failure in ResultScreen**

In `mobile/src/screens/ResultScreen.tsx`, replace the result-loading effect and the `!result` branch:

```tsx
  const [loadError, setLoadError] = useState<'missing' | 'network' | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    if (!roundId) { setLoading(false); setLoadError('missing'); return; }
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    roundApi.result(roundId)
      .then((r) => { if (!cancelled) setResult(r); })
      .catch((e: unknown) => {
        if (cancelled) return;
        // 404 = this round has no guess from this user (nothing to retry).
        setLoadError((e as { status?: number })?.status === 404 ? 'missing' : 'network');
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [roundId, reloadKey]);
```

```tsx
  if (!result) {
    return (
      <Screen padding>
        <Text style={{ color: colors.text }}>
          {loadError === 'network'
            ? 'Could not load this result. Check your connection.'
            : 'No result found for this round.'}
        </Text>
        {loadError === 'network' ? (
          <AppButton
            title="Try again"
            onPress={() => setReloadKey((k) => k + 1)}
            style={{ marginTop: spacing.lg }}
          />
        ) : null}
        <AppButton
          title="Back Home"
          onPress={() => goHome(navigation)}
          variant={loadError === 'network' ? 'secondary' : 'primary'}
          style={{ marginTop: spacing.sm }}
        />
      </Screen>
    );
  }
```

The retry is user-driven only — there is no automatic retry loop.

- [ ] **Step 3: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Manual verification**

Play one tournament round on a device/simulator with the network inspector open.
Expected: no `GET /api/rounds/{id}/result` 404 before submitting; exactly one `result` request after submitting, returning 200.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/screens/GuessScreen.tsx mobile/src/screens/ResultScreen.tsx
git commit -m "fix(mobile): remove guaranteed-404 result probe and add result retry state"
```

---

## Task 7: Broken-image fallback + absolute storage URL guarantee

**Files:**
- Modify: `mobile/src/components/ImageGuessPicker.tsx`
- Create: `backend/tests/Feature/ImageUrlTest.php`
- Modify: `docs/store-readiness.md` (add the `storage:link` / `APP_URL` checklist section)

**Interfaces:**
- Consumes: nothing new.
- Produces: `ImageGuessPicker` renders an "Image unavailable" placeholder instead of an endlessly-broken image.

- [ ] **Step 1: Write the failing backend test**

Create `backend/tests/Feature/ImageUrlTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storage URLs handed to the mobile app must be absolute and rooted at APP_URL.
 * A relative or localhost URL renders as a broken image on a real device.
 */
class ImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_today_returns_absolute_hidden_image_url(): void
    {
        config(['app.url' => 'https://api.example.test']);

        $sport = Sport::create([
            'slug' => 'football', 'name' => 'Football', 'emoji' => '⚽',
            'primary_color' => '#00E676', 'status' => 'active',
        ]);
        $category = ChallengeCategory::create(['name' => 'Test', 'slug' => 'test']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id,
            'challenge_category_id' => $category->id,
            'title' => 'Hidden Ball',
            'hidden_image_path' => 'challenges/hidden.jpg',
            'original_image_path' => 'challenges/original.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active',
        ]);
        \App\Models\DailyChallenge::create([
            'challenge_id' => $challenge->id,
            'challenge_date' => now()->toDateString(),
            'sport_id' => $sport->id,
        ]);

        $user = User::factory()->create(['preferred_sport_id' => $sport->id]);
        $token = $user->createToken('test')->plainTextToken;

        $url = $this->withToken($token)->getJson('/api/daily/today')
            ->assertOk()
            ->json('daily_challenge.challenge.hidden_image_url');

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://api.example.test/storage/', $url);
    }
}
```

Adjust the `Sport`/`ChallengeCategory`/`DailyChallenge` create payloads to match the actual columns if the create call errors — read `database/migrations/*_create_sports_table.php`, `*_create_challenge_categories_table.php` and `*_create_daily_challenges_table.php` and use the required columns. Prefer existing factories if they exist (`database/factories/`).

- [ ] **Step 2: Run the test**

Run from `backend/`: `php artisan test --filter=ImageUrlTest`
Expected: PASS if `asset()` already resolves against `APP_URL` (it should — `DailyChallengeController` line ~86 uses `asset('storage/'.$path)`), FAIL if a relative URL leaks. If it FAILS, fix the offending resource/controller to use `asset('storage/'.$path)` and re-run.

This test is a regression guard: it is what makes a future `APP_URL` misconfiguration a red build rather than a broken image in production.

- [ ] **Step 3: Add the image-error fallback to ImageGuessPicker**

In `mobile/src/components/ImageGuessPicker.tsx`:

1. Add a failure state next to the existing state:

```tsx
  const [failed, setFailed] = useState(false);
```

2. Reset it in the existing `useEffect` on `imageUri`, and mark failure when `Image.getSize` errors:

```tsx
  useEffect(() => {
    if (!imageUri) return;
    let cancelled = false;
    setDimensionsLoaded(false);
    setFailed(false);
    Image.getSize(
      imageUri,
      (w, h) => {
        if (!cancelled) {
          if (w > 0 && h > 0) setAspect(w / h);
          setDimensionsLoaded(true);
        }
      },
      () => {
        // One failed probe only — never retried, so a dead URL cannot loop.
        if (!cancelled) { setDimensionsLoaded(true); setFailed(true); }
      }
    );
    return () => { cancelled = true; };
  }, [imageUri]);
```

3. Add `onError` to the `<Image>` and render the placeholder instead of the image when it failed:

```tsx
      <View style={StyleSheet.absoluteFill} pointerEvents="none">
        {failed ? (
          <View style={styles.failed}>
            <Text style={styles.failedText}>Image unavailable</Text>
          </View>
        ) : (
          <Image
            source={{ uri: imageUri }}
            style={StyleSheet.absoluteFill}
            resizeMode="cover"
            onError={() => setFailed(true)}
          />
        )}
      </View>
```

4. Add styles:

```tsx
  failed: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surfaceElevated,
  },
  failedText: { color: colors.textMuted, fontSize: 14, fontStyle: 'italic' },
```

- [ ] **Step 4: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Document the storage prerequisites**

In `docs/store-readiness.md`, add a "Media / storage" checklist section:

```markdown
### Media / storage

- `APP_URL` in the production `.env` must be the public HTTPS base URL — `asset('storage/…')`
  builds every challenge/avatar image URL from it. A wrong value renders as broken images in
  the mobile app while the admin (same-origin) still looks fine.
- `php artisan storage:link` must have been run on the server (creates `public/storage`).
- `FILESYSTEM_DISK=public` for uploaded challenge and avatar images.
- Regression guard: `backend/tests/Feature/ImageUrlTest.php`.
```

- [ ] **Step 6: Run the full backend suite**

Run from `backend/`: `php artisan test`
Expected: all tests pass (334 + the new one).

- [ ] **Step 7: Commit**

```bash
git add backend/tests/Feature/ImageUrlTest.php mobile/src/components/ImageGuessPicker.tsx docs/store-readiness.md
git commit -m "fix: clean fallback for broken images and guard absolute storage URLs"
```

---

## Task 8: Friends database schema and models

**Files:**
- Create: `backend/database/migrations/2026_08_02_000001_add_friend_code_to_users.php`
- Create: `backend/database/migrations/2026_08_02_000002_create_friend_requests_table.php`
- Create: `backend/database/migrations/2026_08_02_000003_create_friendships_table.php`
- Create: `backend/app/Models/FriendRequest.php`
- Create: `backend/app/Models/Friendship.php`
- Modify: `backend/app/Models/User.php`
- Create: `backend/tests/Feature/FriendsTest.php` (first two tests only)

**Interfaces:**
- Produces:
  - `User::generateFriendCode(): string` (static, collision-checked)
  - `User::$friend_code` (string, unique, in `$hidden`)
  - `User::friendships(): HasMany` (rows where `user_id` = this user)
  - `User::friends(): BelongsToMany` (via `friendships`)
  - `FriendRequest::STATUS_PENDING|STATUS_ACCEPTED|STATUS_REJECTED|STATUS_CANCELLED`
  - `FriendRequest::requester(): BelongsTo`, `FriendRequest::recipient(): BelongsTo`
  - `Friendship` with `$fillable = ['user_id', 'friend_id']`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/FriendsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendsTest extends TestCase
{
    use RefreshDatabase;

    private function auth(array $attrs = []): array
    {
        $user = User::factory()->create($attrs);
        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_friend_code_is_generated_on_user_creation(): void
    {
        $user = User::factory()->create();

        $this->assertNotEmpty($user->friend_code);
        $this->assertSame(8, strlen($user->friend_code));
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $user->friend_code);
    }

    public function test_friend_codes_are_unique_across_users(): void
    {
        $codes = User::factory()->count(25)->create()->pluck('friend_code');

        $this->assertCount(25, $codes->unique());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php artisan test --filter=FriendsTest`
Expected: FAIL — `friend_code` is null / column does not exist.

- [ ] **Step 3: Write the users migration with a safe backfill**

Create `backend/database/migrations/2026_08_02_000001_add_friend_code_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Unambiguous alphabet — no O/0, I/1, so codes survive being read aloud. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'friend_code')) {
                $table->string('friend_code', 12)->nullable()->unique()->after('username');
            }
        });

        // Backfill existing accounts. chunkById keys off the primary key, so
        // rows updated inside the loop cannot be skipped. Safe to re-run.
        DB::table('users')->whereNull('friend_code')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)->update([
                        'friend_code' => $this->uniqueCode(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'friend_code')) {
                $table->dropColumn('friend_code');
            }
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (DB::table('users')->where('friend_code', $code)->exists());

        return $code;
    }
};
```

- [ ] **Step 4: Write the friend_requests migration**

Create `backend/database/migrations/2026_08_02_000002_create_friend_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('friend_requests')) {
            return;
        }

        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            // One row per direction per pair; re-requesting after a rejection
            // updates the existing row rather than inserting a duplicate.
            $table->unique(['requester_id', 'recipient_id']);
            $table->index(['recipient_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_requests');
    }
};
```

- [ ] **Step 5: Write the friendships migration**

Create `backend/database/migrations/2026_08_02_000003_create_friendships_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('friendships')) {
            return;
        }

        // Two rows per friendship (one per direction) so "my friends" is a
        // single indexed lookup: where user_id = me.
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'friend_id']);
            $table->index('friend_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
```

- [ ] **Step 6: Create the models**

Create `backend/app/Models/FriendRequest.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FriendRequest extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['requester_id', 'recipient_id', 'status'];

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_id'); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
}
```

Create `backend/app/Models/Friendship.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One direction of a friendship. Accepting a request writes both directions. */
class Friendship extends Model
{
    protected $fillable = ['user_id', 'friend_id'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function friend(): BelongsTo { return $this->belongsTo(User::class, 'friend_id'); }
}
```

- [ ] **Step 7: Extend the User model**

In `backend/app/Models/User.php`:

1. Add `friend_code` to `$hidden` (it is a share secret — it must never leak through a raw serialization of another user):

```php
    protected $hidden = ['password', 'remember_token', 'is_admin', 'friend_code'];
```

`friend_code` is deliberately **not** in `$fillable` so it can never be mass-assigned from a request payload.

2. Add the generator and the boot hook:

```php
    /** Unambiguous alphabet — no O/0, I/1, so codes survive being read aloud. */
    private const FRIEND_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->friend_code)) {
                $user->friend_code = static::generateFriendCode();
            }
        });
    }

    /** A fresh 8-character code that is not already taken. */
    public static function generateFriendCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::FRIEND_CODE_ALPHABET[random_int(0, strlen(self::FRIEND_CODE_ALPHABET) - 1)];
            }
        } while (static::where('friend_code', $code)->exists());

        return $code;
    }

    /** Directed friendship rows owned by this user. */
    public function friendships(): HasMany
    {
        return $this->hasMany(Friendship::class, 'user_id');
    }

    /** The users this user is friends with. */
    public function friends(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'friendships', 'user_id', 'friend_id')->withTimestamps();
    }

    /** True when `$other` is already a confirmed friend. */
    public function isFriendsWith(User $other): bool
    {
        return Friendship::where('user_id', $this->id)->where('friend_id', $other->id)->exists();
    }
```

- [ ] **Step 8: Migrate and run the tests**

Run from `backend/`:

```bash
php artisan migrate
php artisan test --filter=FriendsTest
```

Expected: migrations run (no `migrate:fresh`), both tests PASS.

- [ ] **Step 9: Run the full suite**

Run from `backend/`: `php artisan test`
Expected: all tests pass — in particular `MigrationIntegrityTest`.

- [ ] **Step 10: Commit**

```bash
git add backend/database/migrations/2026_08_02_00000{1,2,3}_*.php backend/app/Models/ backend/tests/Feature/FriendsTest.php
git commit -m "feat(api): friend code, friend_requests and friendships schema"
```

---

## Task 9: Friends API endpoints

**Files:**
- Create: `backend/app/Http/Controllers/Api/FriendController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/tests/Feature/FriendsTest.php`
- Modify: `docs/security-hardening.md`
- Modify: `docs/api-contract.md`

**Interfaces:**
- Consumes: `User::generateFriendCode()`, `User::isFriendsWith()`, `FriendRequest`, `Friendship` (Task 8); `PlayerRankService::forUser()`.
- Produces these routes (all inside `auth:sanctum` + `verified`):
  - `GET /api/me/friend-code` → `{ friend_code: string }`
  - `GET /api/friends` → `{ data: FriendSummary[] }`
  - `GET /api/friends/requests` → `{ incoming: RequestItem[], outgoing: RequestItem[] }`
  - `POST /api/friends/requests` body `{ friend_code }` → 201 `{ data: RequestItem }`
  - `POST /api/friends/requests/{friendRequest}/accept` → 200 `{ data: FriendSummary }`
  - `POST /api/friends/requests/{friendRequest}/reject` → 200 `{ message }`
  - `DELETE /api/friends/{user}` → 204
- `FriendSummary` = `{ id, name, username, avatar_url, rank_name, level, total_xp }`
- `RequestItem` = `{ id, status, created_at, user: FriendSummary }` where `user` is the *other* party.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/FriendsTest.php` (inside the class):

```php
    public function test_all_friend_endpoints_require_auth(): void
    {
        $this->getJson('/api/friends')->assertUnauthorized();
        $this->getJson('/api/friends/requests')->assertUnauthorized();
        $this->postJson('/api/friends/requests', ['friend_code' => 'ABCDEFGH'])->assertUnauthorized();
        $this->postJson('/api/friends/requests/1/accept')->assertUnauthorized();
        $this->postJson('/api/friends/requests/1/reject')->assertUnauthorized();
        $this->deleteJson('/api/friends/1')->assertUnauthorized();
        $this->getJson('/api/me/friend-code')->assertUnauthorized();
    }

    public function test_user_can_read_own_friend_code(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)->getJson('/api/me/friend-code')
            ->assertOk()
            ->assertJsonPath('friend_code', $user->friend_code);
    }

    public function test_can_send_friend_request_by_friend_code(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->withToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $target->id);

        $this->assertDatabaseHas('friend_requests', [
            'recipient_id' => $target->id,
            'status'       => 'pending',
        ]);
    }

    public function test_friend_code_lookup_is_case_insensitive(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->withToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => strtolower($target->friend_code)])
            ->assertCreated();
    }

    public function test_unknown_friend_code_returns_404(): void
    {
        [, $token] = $this->auth();

        $this->withToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => 'ZZZZZZZZ'])
            ->assertNotFound();
    }

    public function test_cannot_send_friend_request_to_self(): void
    {
        [$user, $token] = $this->auth();

        $this->withToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => $user->friend_code])
            ->assertStatus(422);

        $this->assertDatabaseCount('friend_requests', 0);
    }

    public function test_cannot_send_duplicate_pending_request(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->withToken($token)->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])->assertCreated();
        $this->withToken($token)->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])->assertStatus(422);

        $this->assertDatabaseCount('friend_requests', 1);
    }

    public function test_cannot_request_someone_who_is_already_a_friend(): void
    {
        [$user, $token] = $this->auth();
        $friend = User::factory()->create();
        \App\Models\Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        \App\Models\Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        $this->withToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => $friend->friend_code])
            ->assertStatus(422);
    }

    public function test_accepting_a_request_creates_a_two_way_friendship(): void
    {
        [$requester, $requesterToken] = $this->auth();
        [$recipient, $recipientToken] = $this->auth();

        $id = $this->withToken($requesterToken)
            ->postJson('/api/friends/requests', ['friend_code' => $recipient->friend_code])
            ->assertCreated()->json('data.id');

        $this->withToken($recipientToken)->postJson("/api/friends/requests/{$id}/accept")->assertOk();

        $this->assertDatabaseHas('friendships', ['user_id' => $requester->id, 'friend_id' => $recipient->id]);
        $this->assertDatabaseHas('friendships', ['user_id' => $recipient->id, 'friend_id' => $requester->id]);
        $this->assertDatabaseHas('friend_requests', ['id' => $id, 'status' => 'accepted']);

        $this->withToken($requesterToken)->getJson('/api/friends')
            ->assertOk()->assertJsonPath('data.0.id', $recipient->id);
    }

    public function test_only_the_recipient_can_accept_a_request(): void
    {
        [, $requesterToken] = $this->auth();
        $recipient = User::factory()->create();
        $stranger  = User::factory()->create();

        $id = $this->withToken($requesterToken)
            ->postJson('/api/friends/requests', ['friend_code' => $recipient->friend_code])
            ->json('data.id');

        $this->withToken($stranger->createToken('t')->plainTextToken)
            ->postJson("/api/friends/requests/{$id}/accept")
            ->assertForbidden();

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_rejecting_a_request_does_not_create_a_friendship(): void
    {
        [, $requesterToken] = $this->auth();
        [$recipient, $recipientToken] = $this->auth();

        $id = $this->withToken($requesterToken)
            ->postJson('/api/friends/requests', ['friend_code' => $recipient->friend_code])
            ->json('data.id');

        $this->withToken($recipientToken)->postJson("/api/friends/requests/{$id}/reject")->assertOk();

        $this->assertDatabaseCount('friendships', 0);
        $this->assertDatabaseHas('friend_requests', ['id' => $id, 'status' => 'rejected']);
    }

    public function test_removing_a_friend_deletes_both_directions(): void
    {
        [$user, $token] = $this->auth();
        $friend = User::factory()->create();
        \App\Models\Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        \App\Models\Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        $this->withToken($token)->deleteJson("/api/friends/{$friend->id}")->assertNoContent();

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_removing_a_non_friend_returns_404(): void
    {
        [, $token] = $this->auth();
        $stranger = User::factory()->create();

        $this->withToken($token)->deleteJson("/api/friends/{$stranger->id}")->assertNotFound();
    }

    public function test_requests_endpoint_separates_incoming_and_outgoing(): void
    {
        [$user, $token] = $this->auth();
        $target = User::factory()->create();
        $sender = User::factory()->create();

        $this->withToken($token)->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])->assertCreated();
        \App\Models\FriendRequest::create([
            'requester_id' => $sender->id, 'recipient_id' => $user->id, 'status' => 'pending',
        ]);

        $res = $this->withToken($token)->getJson('/api/friends/requests')->assertOk();

        $res->assertJsonPath('incoming.0.user.id', $sender->id);
        $res->assertJsonPath('outgoing.0.user.id', $target->id);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php artisan test --filter=FriendsTest`
Expected: FAIL — the routes do not exist (404s where 200/201 expected).

- [ ] **Step 3: Write the controller**

Create `backend/app/Http/Controllers/Api/FriendController.php`:

```php
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use App\Services\PlayerRankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendController extends Controller
{
    /** Hard cap on any self-scoped list, mirroring ProfileController. */
    public const MAX_LIST_ROWS = 200;

    public function __construct(private PlayerRankService $rankService) {}

    // GET /api/me/friend-code
    public function friendCode(Request $request): JsonResponse
    {
        $user = $request->user();

        // Defensive: accounts created before the backfill migration.
        if (empty($user->friend_code)) {
            $user->friend_code = User::generateFriendCode();
            $user->save();
        }

        return response()->json(['friend_code' => $user->friend_code]);
    }

    // GET /api/friends
    public function index(Request $request): JsonResponse
    {
        $friends = $request->user()->friends()
            ->orderBy('username')
            ->limit(self::MAX_LIST_ROWS)
            ->get();

        return response()->json([
            'data' => $friends->map(fn (User $u) => $this->summary($u))->values(),
        ]);
    }

    // GET /api/friends/requests
    public function requests(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $incoming = FriendRequest::where('recipient_id', $userId)
            ->where('status', FriendRequest::STATUS_PENDING)
            ->with('requester')
            ->orderByDesc('created_at')
            ->limit(self::MAX_LIST_ROWS)
            ->get()
            ->map(fn (FriendRequest $r) => $this->requestItem($r, $r->requester));

        $outgoing = FriendRequest::where('requester_id', $userId)
            ->where('status', FriendRequest::STATUS_PENDING)
            ->with('recipient')
            ->orderByDesc('created_at')
            ->limit(self::MAX_LIST_ROWS)
            ->get()
            ->map(fn (FriendRequest $r) => $this->requestItem($r, $r->recipient));

        return response()->json([
            'incoming' => $incoming->values(),
            'outgoing' => $outgoing->values(),
        ]);
    }

    // POST /api/friends/requests
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'friend_code' => ['required', 'string', 'min:4', 'max:12'],
        ]);

        $me     = $request->user();
        $code   = strtoupper(trim($data['friend_code']));
        $target = User::where('friend_code', $code)->first();

        if (!$target) {
            return response()->json(['message' => 'No player found with that friend code.'], 404);
        }
        if ((int) $target->id === (int) $me->id) {
            return response()->json(['message' => 'You cannot add yourself as a friend.'], 422);
        }
        if ($me->isFriendsWith($target)) {
            return response()->json(['message' => 'You are already friends with this player.'], 422);
        }

        $existing = FriendRequest::where('status', FriendRequest::STATUS_PENDING)
            ->where(function ($q) use ($me, $target) {
                $q->where(fn ($w) => $w->where('requester_id', $me->id)->where('recipient_id', $target->id))
                  ->orWhere(fn ($w) => $w->where('requester_id', $target->id)->where('recipient_id', $me->id));
            })
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'There is already a pending request with this player.'], 422);
        }

        // updateOrCreate so a previously rejected/cancelled request in the same
        // direction is reopened instead of colliding with the unique index.
        $friendRequest = FriendRequest::updateOrCreate(
            ['requester_id' => $me->id, 'recipient_id' => $target->id],
            ['status' => FriendRequest::STATUS_PENDING],
        );

        return response()->json(['data' => $this->requestItem($friendRequest, $target)], 201);
    }

    // POST /api/friends/requests/{friendRequest}/accept
    public function accept(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        $me = $request->user();

        if ((int) $friendRequest->recipient_id !== (int) $me->id) {
            return response()->json(['message' => 'This request is not addressed to you.'], 403);
        }
        if (!$friendRequest->isPending()) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $requester = $friendRequest->requester;

        DB::transaction(function () use ($friendRequest, $me, $requester) {
            $friendRequest->update(['status' => FriendRequest::STATUS_ACCEPTED]);
            Friendship::firstOrCreate(['user_id' => $me->id,        'friend_id' => $requester->id]);
            Friendship::firstOrCreate(['user_id' => $requester->id, 'friend_id' => $me->id]);
        });

        return response()->json(['data' => $this->summary($requester)]);
    }

    // POST /api/friends/requests/{friendRequest}/reject
    public function reject(Request $request, FriendRequest $friendRequest): JsonResponse
    {
        if ((int) $friendRequest->recipient_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'This request is not addressed to you.'], 403);
        }
        if (!$friendRequest->isPending()) {
            return response()->json(['message' => 'This request is no longer pending.'], 422);
        }

        $friendRequest->update(['status' => FriendRequest::STATUS_REJECTED]);

        return response()->json(['message' => 'Request rejected.']);
    }

    // DELETE /api/friends/{user}
    public function destroy(Request $request, User $user)
    {
        $me = $request->user();

        $deleted = Friendship::where(function ($q) use ($me, $user) {
            $q->where(fn ($w) => $w->where('user_id', $me->id)->where('friend_id', $user->id))
              ->orWhere(fn ($w) => $w->where('user_id', $user->id)->where('friend_id', $me->id));
        })->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'You are not friends with this player.'], 404);
        }

        return response()->noContent();
    }

    /** Public-safe summary of another player. Never includes email/auth data. */
    private function summary(User $user): array
    {
        $rank = $this->rankService->forUser($user);

        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'avatar_url' => $user->avatarUrl(),
            'rank_name'  => $rank['name'],
            'level'      => $rank['level'],
            'total_xp'   => $rank['total_xp'],
        ];
    }

    private function requestItem(FriendRequest $r, User $other): array
    {
        return [
            'id'         => $r->id,
            'status'     => $r->status,
            'created_at' => $r->created_at?->toISOString(),
            'user'       => $this->summary($other),
        ];
    }
}
```

- [ ] **Step 4: Add the rate limiter**

In `backend/app/Providers/AppServiceProvider.php`, inside `configureRateLimiters()`, add:

```php
        // Friend writes: request/accept/reject/remove. Well above human use,
        // low enough to stop friend-code enumeration scripts.
        RateLimiter::for('friends', function (Request $request) {
            return Limit::perMinute(20)->by('friends|' . ($request->user()?->id ?: $request->ip()));
        });
```

- [ ] **Step 5: Register the routes**

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\Api\FriendController;
```

and inside the `Route::middleware('verified')->group(...)` block:

```php
        // Friends — first version: codes, requests, list. No chat, no realtime.
        Route::get('/me/friend-code', [FriendController::class, 'friendCode']);
        Route::get('/friends',          [FriendController::class, 'index']);
        Route::get('/friends/requests', [FriendController::class, 'requests']);
        Route::post('/friends/requests',                    [FriendController::class, 'store'])->middleware('throttle:friends');
        Route::post('/friends/requests/{friendRequest}/accept', [FriendController::class, 'accept'])->middleware('throttle:friends');
        Route::post('/friends/requests/{friendRequest}/reject', [FriendController::class, 'reject'])->middleware('throttle:friends');
        Route::delete('/friends/{user}', [FriendController::class, 'destroy'])->middleware('throttle:friends');
```

Place `/friends/requests` **before** any wildcard `/friends/{...}` GET route to avoid shadowing (there is none here, but keep the order).

- [ ] **Step 6: Run the tests**

Run from `backend/`: `php artisan test --filter=FriendsTest`
Expected: all PASS.

- [ ] **Step 7: Document the new limiter and routes**

- In `docs/security-hardening.md`, add a `friends` row to the rate-limiter table: `friends | 20/min | per user id (IP fallback) | POST/DELETE friend endpoints`.
- In `docs/api-contract.md`, add a `## Friends` section listing the seven routes with their request/response shapes from the **Interfaces** block above.

- [ ] **Step 8: Run the full suite**

Run from `backend/`: `php artisan test`
Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Http/Controllers/Api/FriendController.php backend/routes/api.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/FriendsTest.php docs/security-hardening.md docs/api-contract.md
git commit -m "feat(api): friends endpoints (code, request, accept, reject, remove)"
```

---

## Task 10: Public profile endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Api/PublicProfileController.php`
- Create: `backend/tests/Feature/PublicProfileTest.php`
- Modify: `backend/routes/api.php`
- Modify: `docs/api-contract.md`

**Interfaces:**
- Consumes: `PlayerRankService::forUser()`, `User::isFriendsWith()`, `FriendRequest`.
- Produces: `GET /api/users/{user}/public-profile` →

```json
{
  "data": {
    "id": 1, "name": "…", "username": "…", "avatar_url": null,
    "rank": { "name": "…", "level": 3, "total_xp": 1200, "...": "full PlayerRank payload" },
    "total_xp": 1200,
    "stats": {
      "tournaments_played": 0, "tournaments_completed": 0,
      "guesses_count": 0, "total_score": 0, "average_score": 0,
      "daily_challenges_played": 0, "best_daily_score": 0
    },
    "badges": { "earned_count": 0, "total_count": 26 },
    "is_friend": false,
    "has_pending_request": false
  }
}
```

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/PublicProfileTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_requires_auth(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/users/{$user->id}/public-profile")->assertUnauthorized();
    }

    public function test_public_profile_returns_safe_fields(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create(['username' => 'targetplayer']);

        $res = $this->withToken($viewer->createToken('t')->plainTextToken)
            ->getJson("/api/users/{$target->id}/public-profile")
            ->assertOk();

        $res->assertJsonPath('data.id', $target->id);
        $res->assertJsonPath('data.username', 'targetplayer');
        $res->assertJsonStructure([
            'data' => [
                'id', 'name', 'username', 'avatar_url', 'total_xp',
                'rank' => ['name', 'level', 'total_xp'],
                'stats' => ['tournaments_played', 'guesses_count', 'total_score', 'average_score', 'daily_challenges_played', 'best_daily_score'],
                'badges' => ['earned_count', 'total_count'],
                'is_friend', 'has_pending_request',
            ],
        ]);
    }

    public function test_public_profile_never_exposes_private_data(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        $payload = $this->withToken($viewer->createToken('t')->plainTextToken)
            ->getJson("/api/users/{$target->id}/public-profile")
            ->assertOk()
            ->json('data');

        foreach (['email', 'password', 'remember_token', 'is_admin', 'friend_code', 'email_verified_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload, "{$forbidden} must not appear in a public profile");
        }

        $this->assertStringNotContainsString($target->email, json_encode($payload));
        $this->assertStringNotContainsString($target->friend_code, json_encode($payload));
    }

    public function test_public_profile_reports_friendship_state(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $viewer->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $viewer->id]);

        $this->withToken($viewer->createToken('t')->plainTextToken)
            ->getJson("/api/users/{$friend->id}/public-profile")
            ->assertOk()
            ->assertJsonPath('data.is_friend', true);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php artisan test --filter=PublicProfileTest`
Expected: FAIL — route not defined (404).

- [ ] **Step 3: Write the controller**

Create `backend/app/Http/Controllers/Api/PublicProfileController.php`:

```php
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Guess;
use App\Models\User;
use App\Services\PlayerRankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only public view of another player. Deliberately hand-built rather than
 * reusing UserResource: every field here is an explicit allow-list decision.
 */
class PublicProfileController extends Controller
{
    public function __construct(private PlayerRankService $rankService) {}

    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        $rank   = $this->rankService->forUser($user);

        $guessAgg = Guess::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(score), 0) as total_score, AVG(score) as avg_score')
            ->first();
        $guessesCount = (int) $guessAgg->total;

        $dailyAgg = DailyChallengeGuess::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as total, MAX(score) as best_score')
            ->first();

        $pending = FriendRequest::where('status', FriendRequest::STATUS_PENDING)
            ->where(function ($q) use ($viewer, $user) {
                $q->where(fn ($w) => $w->where('requester_id', $viewer->id)->where('recipient_id', $user->id))
                  ->orWhere(fn ($w) => $w->where('requester_id', $user->id)->where('recipient_id', $viewer->id));
            })
            ->exists();

        return response()->json([
            'data' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'username'   => $user->username,
                'avatar_url' => $user->avatarUrl(),
                'rank'       => $rank,
                'total_xp'   => $rank['total_xp'],
                'stats'      => [
                    'tournaments_played'      => $user->leagues()->count(),
                    'tournaments_completed'   => $user->leagues()->where('status', 'completed')->count(),
                    'guesses_count'           => $guessesCount,
                    'total_score'             => (int) $guessAgg->total_score,
                    'average_score'           => $guessesCount > 0 ? round((float) $guessAgg->avg_score, 1) : 0.0,
                    'daily_challenges_played' => (int) $dailyAgg->total,
                    'best_daily_score'        => (int) ($dailyAgg->best_score ?? 0),
                ],
                'badges' => [
                    'earned_count' => $user->badges()->count(),
                    'total_count'  => Badge::count(),
                ],
                'is_friend'           => $viewer->isFriendsWith($user),
                'has_pending_request' => $pending,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `backend/routes/api.php`, add the import `use App\Http\Controllers\Api\PublicProfileController;` and inside the `verified` group:

```php
        Route::get('/users/{user}/public-profile', [PublicProfileController::class, 'show']);
```

- [ ] **Step 5: Run the tests**

Run from `backend/`: `php artisan test --filter=PublicProfileTest`
Expected: all PASS.

- [ ] **Step 6: Document it**

Add the `GET /api/users/{id}/public-profile` shape to the `## Friends` section of `docs/api-contract.md`, and note in `docs/privacy-data-inventory.md` that username, display name, avatar, rank/XP, aggregate gameplay stats and badge counts are visible to any authenticated player who has the user's id.

- [ ] **Step 7: Run the full suite and commit**

```bash
php artisan test
git add backend/app/Http/Controllers/Api/PublicProfileController.php backend/routes/api.php backend/tests/Feature/PublicProfileTest.php docs/api-contract.md docs/privacy-data-inventory.md
git commit -m "feat(api): public profile endpoint with explicit field allow-list"
```

---

## Task 11: Mobile friends dependencies, types and API layer

**Files:**
- Modify: `mobile/package.json` (via `npx expo install`)
- Modify: `mobile/app.json`
- Create: `mobile/src/types/friend.ts`
- Create: `mobile/src/api/friendsApi.ts`

**Interfaces:**
- Consumes: `apiClient` from `mobile/src/api/client.ts`, `PlayerRank` from `mobile/src/types/auth.ts`.
- Produces: `friendsApi` with `myCode`, `list`, `requests`, `sendRequest`, `accept`, `reject`, `remove`, `publicProfile`; types `FriendSummary`, `FriendRequestItem`, `FriendRequestsResponse`, `PublicProfile`.

- [ ] **Step 1: Read the Expo 56 docs**

Read these before installing (per `mobile/AGENTS.md`):
- `https://docs.expo.dev/versions/v56.0.0/sdk/camera/`
- `https://docs.expo.dev/versions/v56.0.0/sdk/clipboard/`

Confirmed API surface for SDK 56: `import { CameraView, useCameraPermissions } from 'expo-camera'`, with `barcodeScannerSettings={{ barcodeTypes: ['qr'] }}` and `onBarcodeScanned={(result) => …}` (`result.data` is the payload); `import * as Clipboard from 'expo-clipboard'` with `await Clipboard.setStringAsync(text)`.

- [ ] **Step 2: Install the dependencies**

Run from `mobile/`:

```bash
npx expo install expo-camera expo-clipboard react-native-svg
npm install react-native-qrcode-svg
```

`react-native-qrcode-svg` is a plain JS package that renders through `react-native-svg`, so it needs no config plugin.

- [ ] **Step 3: Configure the camera plugin**

In `mobile/app.json`, add to the `plugins` array (keep the existing entries):

```json
      [
        "expo-camera",
        {
          "cameraPermission": "BallPicker needs your camera only to scan a friend's QR code."
        }
      ]
```

Also add the matching iOS usage string next to the existing `NSPhotoLibraryUsageDescription` in `ios.infoPlist`:

```json
        "NSCameraUsageDescription": "BallPicker needs your camera only to scan a friend's QR code."
```

- [ ] **Step 4: Create the types**

Create `mobile/src/types/friend.ts`:

```ts
import type { PlayerRank } from './auth';

/** Public-safe summary of another player. */
export interface FriendSummary {
  id: number;
  name: string;
  username: string;
  avatar_url: string | null;
  rank_name: string;
  level: number;
  total_xp: number;
}

export interface FriendRequestItem {
  id: number;
  status: 'pending' | 'accepted' | 'rejected' | 'cancelled';
  created_at: string | null;
  /** The other party — the sender for incoming, the target for outgoing. */
  user: FriendSummary;
}

export interface FriendRequestsResponse {
  incoming: FriendRequestItem[];
  outgoing: FriendRequestItem[];
}

export interface PublicProfileStats {
  tournaments_played: number;
  tournaments_completed: number;
  guesses_count: number;
  total_score: number;
  average_score: number;
  daily_challenges_played: number;
  best_daily_score: number;
}

export interface PublicProfile {
  id: number;
  name: string;
  username: string;
  avatar_url: string | null;
  rank: PlayerRank;
  total_xp: number;
  stats: PublicProfileStats;
  badges: { earned_count: number; total_count: number };
  is_friend: boolean;
  has_pending_request: boolean;
}
```

- [ ] **Step 5: Create the API client**

Create `mobile/src/api/friendsApi.ts`:

```ts
import { apiClient } from './client';
import type {
  FriendRequestItem, FriendRequestsResponse, FriendSummary, PublicProfile,
} from '../types/friend';

export const friendsApi = {
  myCode: () =>
    apiClient.request<{ friend_code: string }>('/me/friend-code').then((r) => r.friend_code),

  list: () =>
    apiClient.request<{ data: FriendSummary[] }>('/friends').then((r) => r.data),

  requests: () =>
    apiClient.request<FriendRequestsResponse>('/friends/requests'),

  sendRequest: (friendCode: string) =>
    apiClient.request<{ data: FriendRequestItem }>('/friends/requests', {
      method: 'POST',
      body: JSON.stringify({ friend_code: friendCode.trim().toUpperCase() }),
    }).then((r) => r.data),

  accept: (requestId: number) =>
    apiClient.request<{ data: FriendSummary }>(`/friends/requests/${requestId}/accept`, { method: 'POST' })
      .then((r) => r.data),

  reject: (requestId: number) =>
    apiClient.request<{ message: string }>(`/friends/requests/${requestId}/reject`, { method: 'POST' }),

  remove: (userId: number) =>
    apiClient.request<void>(`/friends/${userId}`, { method: 'DELETE' }),

  publicProfile: (userId: number) =>
    apiClient.request<{ data: PublicProfile }>(`/users/${userId}/public-profile`).then((r) => r.data),
};
```

- [ ] **Step 6: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors. If `react-native-qrcode-svg` has no bundled types, add `mobile/src/types/react-native-qrcode-svg.d.ts`:

```ts
declare module 'react-native-qrcode-svg' {
  import type { ComponentType } from 'react';
  const QRCode: ComponentType<{
    value: string;
    size?: number;
    color?: string;
    backgroundColor?: string;
  }>;
  export default QRCode;
}
```

- [ ] **Step 7: Commit**

```bash
git add mobile/package.json mobile/package-lock.json mobile/app.json mobile/src/types/friend.ts mobile/src/api/friendsApi.ts mobile/src/types/react-native-qrcode-svg.d.ts
git commit -m "feat(mobile): friends types, api client and QR/camera/clipboard deps"
```

---

## Task 12: Friends screen

**Files:**
- Create: `mobile/src/screens/FriendsScreen.tsx`
- Modify: `mobile/src/app/AppNavigator.tsx`
- Modify: `mobile/src/screens/HomeScreen.tsx`

**Interfaces:**
- Consumes: `friendsApi` and friend types (Task 11), `Avatar`, `AppButton`, `AppInput`, `ConfirmModal`, `Screen`.
- Produces: `RootStackParamList` gains `Friends: undefined`, `FriendProfile: { userId: number; username: string }`, `ScanFriendCode: undefined`.

There are no bottom tabs in this app (it is a single native-stack navigator), so Friends is reached from a Home card and a Profile row rather than a tab. Adding a tab navigator would restructure every screen — out of scope for a polish sprint.

- [ ] **Step 1: Add the routes**

In `mobile/src/app/AppNavigator.tsx`:

1. Add to `RootStackParamList` (the `scannedCode` param is how `ScanFriendCodeScreen` hands a scanned code back):

```tsx
  Friends: { scannedCode?: string } | undefined;
  FriendProfile: { userId: number; username: string };
  ScanFriendCode: undefined;
```

2. Add imports for `FriendsScreen`, `FriendProfileScreen`, `ScanFriendCodeScreen` (the latter two are created in Task 13 — create empty placeholder files now if you want a clean typecheck between tasks, or do Steps 1–2 of Task 13 first).

3. Add the screens:

```tsx
        <Stack.Screen name="Friends" component={FriendsScreen} options={{ title: 'Friends' }} />
        <Stack.Screen name="FriendProfile" component={FriendProfileScreen} options={({ route }) => ({ title: `@${route.params.username}` })} />
        <Stack.Screen name="ScanFriendCode" component={ScanFriendCodeScreen} options={{ title: 'Scan friend code' }} />
```

- [ ] **Step 2: Create the Friends screen**

Create `mobile/src/screens/FriendsScreen.tsx`:

```tsx
import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity } from 'react-native';
import * as Clipboard from 'expo-clipboard';
import QRCode from 'react-native-qrcode-svg';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { AppInput } from '../components/AppInput';
import { Avatar } from '../components/Avatar';
import { ConfirmModal } from '../components/ConfirmModal';
import { friendsApi } from '../api/friendsApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { FriendRequestItem, FriendSummary } from '../types/friend';

type Props = NativeStackScreenProps<RootStackParamList, 'Friends'>;

export function FriendsScreen({ navigation, route }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [code, setCode] = useState<string | null>(null);
  const [friends, setFriends] = useState<FriendSummary[]>([]);
  const [incoming, setIncoming] = useState<FriendRequestItem[]>([]);
  const [outgoing, setOutgoing] = useState<FriendRequestItem[]>([]);
  const [loading, setLoading] = useState(true);

  const [input, setInput] = useState('');
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState('');
  const [addNotice, setAddNotice] = useState('');
  const [copied, setCopied] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [removeTarget, setRemoveTarget] = useState<FriendSummary | null>(null);
  const [removing, setRemoving] = useState(false);
  const [removeError, setRemoveError] = useState('');

  const load = useCallback(async () => {
    const [codeRes, listRes, reqRes] = await Promise.allSettled([
      friendsApi.myCode(),
      friendsApi.list(),
      friendsApi.requests(),
    ]);
    if (codeRes.status === 'fulfilled') setCode(codeRes.value);
    if (listRes.status === 'fulfilled') setFriends(listRes.value);
    if (reqRes.status === 'fulfilled') {
      setIncoming(reqRes.value.incoming);
      setOutgoing(reqRes.value.outgoing);
    }
    setLoading(false);
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => navigation.addListener('focus', () => { load(); }), [navigation, load]);

  // The scanner hands the code back through route params.
  const scanned = (route.params as { scannedCode?: string } | undefined)?.scannedCode;
  useEffect(() => {
    if (scanned) {
      setInput(scanned);
      navigation.setParams({ scannedCode: undefined } as never);
    }
  }, [scanned, navigation]);

  async function handleCopy() {
    if (!code) return;
    await Clipboard.setStringAsync(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  async function handleAdd() {
    const value = input.trim().toUpperCase();
    if (!value) return;
    setAdding(true);
    setAddError('');
    setAddNotice('');
    try {
      await friendsApi.sendRequest(value);
      setInput('');
      setAddNotice('Friend request sent.');
      await load();
    } catch (e: unknown) {
      const err = e as { message?: string };
      setAddError(err?.message ?? 'Could not send that friend request.');
    } finally {
      setAdding(false);
    }
  }

  async function handleAccept(item: FriendRequestItem) {
    setBusyId(item.id);
    try { await friendsApi.accept(item.id); await load(); }
    catch { setAddError('Could not accept that request.'); }
    finally { setBusyId(null); }
  }

  async function handleReject(item: FriendRequestItem) {
    setBusyId(item.id);
    try { await friendsApi.reject(item.id); await load(); }
    catch { setAddError('Could not reject that request.'); }
    finally { setBusyId(null); }
  }

  async function handleRemove() {
    if (!removeTarget || removing) return;
    setRemoving(true);
    setRemoveError('');
    try {
      await friendsApi.remove(removeTarget.id);
      setFriends((prev) => prev.filter((f) => f.id !== removeTarget.id));
      setRemoveTarget(null);
    } catch {
      setRemoveError('Could not remove this friend. Please try again.');
    } finally {
      setRemoving(false);
    }
  }

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  return (
    <Screen scroll padding>
      {/* My friend code + QR */}
      <Text style={styles.sectionTitle}>Your friend code</Text>
      <View style={styles.codeCard}>
        <Text style={styles.code}>{code ?? '········'}</Text>
        {code ? (
          <View style={styles.qrWrap}>
            <QRCode value={code} size={168} color="#000000" backgroundColor="#ffffff" />
          </View>
        ) : null}
        <Text style={styles.codeHint}>Share this code (or the QR) so other players can add you.</Text>
        <AppButton
          title={copied ? 'Copied!' : 'Copy friend code'}
          onPress={handleCopy}
          variant="secondary"
          disabled={!code}
        />
      </View>

      {/* Add a friend */}
      <Text style={styles.sectionTitle}>Add a friend</Text>
      <View style={styles.addCard}>
        <AppInput
          label="Friend code"
          value={input}
          onChangeText={(t) => setInput(t.toUpperCase())}
          autoCapitalize="characters"
          autoCorrect={false}
          placeholder="ABCD2345"
          maxLength={12}
        />
        {addError ? <Text style={styles.error}>{addError}</Text> : null}
        {addNotice ? <Text style={styles.notice}>{addNotice}</Text> : null}
        <AppButton title="Send request" onPress={handleAdd} loading={adding} disabled={!input.trim() || adding} />
        <AppButton
          title="Scan QR code"
          onPress={() => navigation.navigate('ScanFriendCode')}
          variant="secondary"
          style={{ marginTop: spacing.sm }}
        />
      </View>

      {/* Incoming */}
      <Text style={styles.sectionTitle}>Incoming requests</Text>
      {incoming.length === 0 ? (
        <View style={styles.emptyCard}><Text style={styles.emptyText}>No incoming requests.</Text></View>
      ) : (
        incoming.map((item) => (
          <View key={item.id} style={styles.row}>
            <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
            <View style={styles.rowText}>
              <Text style={styles.rowName}>{item.user.name}</Text>
              <Text style={styles.rowSub}>@{item.user.username} · {item.user.rank_name}</Text>
            </View>
            <TouchableOpacity onPress={() => handleAccept(item)} disabled={busyId === item.id} style={styles.actionBtn}>
              <Text style={styles.acceptText}>Accept</Text>
            </TouchableOpacity>
            <TouchableOpacity onPress={() => handleReject(item)} disabled={busyId === item.id} style={styles.actionBtn}>
              <Text style={styles.rejectText}>Reject</Text>
            </TouchableOpacity>
          </View>
        ))
      )}

      {/* Outgoing */}
      <Text style={styles.sectionTitle}>Sent requests</Text>
      {outgoing.length === 0 ? (
        <View style={styles.emptyCard}><Text style={styles.emptyText}>No pending sent requests.</Text></View>
      ) : (
        outgoing.map((item) => (
          <View key={item.id} style={styles.row}>
            <Avatar uri={item.user.avatar_url} name={item.user.name} size={40} />
            <View style={styles.rowText}>
              <Text style={styles.rowName}>{item.user.name}</Text>
              <Text style={styles.rowSub}>@{item.user.username} · pending</Text>
            </View>
          </View>
        ))
      )}

      {/* Friends */}
      <Text style={styles.sectionTitle}>Your friends ({friends.length})</Text>
      {friends.length === 0 ? (
        <View style={styles.emptyCard}><Text style={styles.emptyText}>No friends yet. Share your code to get started.</Text></View>
      ) : (
        friends.map((f) => (
          <TouchableOpacity
            key={f.id}
            style={styles.row}
            activeOpacity={0.8}
            onPress={() => navigation.navigate('FriendProfile', { userId: f.id, username: f.username })}
          >
            <Avatar uri={f.avatar_url} name={f.name} size={40} />
            <View style={styles.rowText}>
              <Text style={styles.rowName}>{f.name}</Text>
              <Text style={styles.rowSub}>@{f.username} · {f.rank_name} · {f.total_xp} XP</Text>
            </View>
            <TouchableOpacity onPress={() => setRemoveTarget(f)} style={styles.actionBtn} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
              <Text style={styles.rejectText}>Remove</Text>
            </TouchableOpacity>
          </TouchableOpacity>
        ))
      )}

      <ConfirmModal
        visible={!!removeTarget}
        title="Remove friend?"
        message={`${removeTarget?.name ?? 'This player'} will be removed from your friends list. You can add each other again later.`}
        confirmLabel="Remove"
        cancelLabel="Cancel"
        onConfirm={handleRemove}
        onCancel={() => { setRemoveTarget(null); setRemoveError(''); }}
        loading={removing}
        errorText={removeError}
        destructive
      />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm, marginTop: spacing.md },
    codeCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center', gap: spacing.sm },
    code: { fontSize: 30, fontWeight: '800', letterSpacing: 4, color: theme.primary },
    qrWrap: { backgroundColor: '#ffffff', padding: spacing.sm, borderRadius: 12 },
    codeHint: { fontSize: 12, color: theme.textMuted, textAlign: 'center' },
    addCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md },
    error: { color: theme.danger, fontSize: 13, marginBottom: spacing.sm },
    notice: { color: theme.success, fontSize: 13, marginBottom: spacing.sm },
    emptyCard: { backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center' },
    emptyText: { fontSize: 13, color: theme.textMuted, fontStyle: 'italic' },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border, padding: spacing.md, marginBottom: spacing.sm },
    rowText: { flex: 1 },
    rowName: { fontSize: 15, fontWeight: '700', color: theme.text },
    rowSub: { fontSize: 12, color: theme.textSecondary, marginTop: 1 },
    actionBtn: { paddingHorizontal: spacing.sm, paddingVertical: 4 },
    acceptText: { fontSize: 13, fontWeight: '700', color: theme.primary },
    rejectText: { fontSize: 13, fontWeight: '700', color: theme.danger },
  });
}
```

Check `mobile/src/components/AppInput.tsx` for its actual prop names before wiring `AppInput` — if it does not accept `maxLength`/`autoCorrect`/`autoCapitalize`, drop those props rather than changing the shared component.

- [ ] **Step 3: Add the Home entry point**

In `mobile/src/screens/HomeScreen.tsx`, directly after the Challenge Packs card inside `ListHeaderComponent`, add:

```tsx
              {/* Friends entry point */}
              <TouchableOpacity
                style={styles.packsCard}
                activeOpacity={0.85}
                onPress={() => navigation.navigate('Friends')}
              >
                <Text style={styles.packsEmoji}>👥</Text>
                <View style={styles.packsText}>
                  <Text style={styles.packsTitle}>Friends</Text>
                  <Text style={styles.packsSubtitle}>Share your code and see how your friends rank.</Text>
                </View>
                <Text style={styles.packsChevron}>›</Text>
              </TouchableOpacity>
```

- [ ] **Step 4: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors (once Task 13's screens exist).

- [ ] **Step 5: Commit**

```bash
git add mobile/src/screens/FriendsScreen.tsx mobile/src/app/AppNavigator.tsx mobile/src/screens/HomeScreen.tsx
git commit -m "feat(mobile): Friends screen with code, QR, requests and friends list"
```

---

## Task 13: QR scanner and friend profile screens

**Files:**
- Create: `mobile/src/screens/ScanFriendCodeScreen.tsx`
- Create: `mobile/src/screens/FriendProfileScreen.tsx`
- Modify: `mobile/src/screens/ProfileScreen.tsx`

**Interfaces:**
- Consumes: `friendsApi`, `PublicProfile` (Task 11); `RootStackParamList['Friends' | 'FriendProfile' | 'ScanFriendCode']` (Task 12).
- Produces: nothing new.

QR payload is the raw friend code string. The scanner also accepts a `ballpicker:friend:<CODE>` prefix defensively, so a future deep-link format does not break older scanners.

- [ ] **Step 1: Create the scanner**

Create `mobile/src/screens/ScanFriendCodeScreen.tsx`:

```tsx
import React, { useRef, useState } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'ScanFriendCode'>;

/** Accepts a bare code or a `ballpicker:friend:CODE` payload. */
function parseFriendCode(raw: string): string | null {
  const value = raw.trim().replace(/^ballpicker:friend:/i, '').toUpperCase();
  return /^[A-HJ-NP-Z2-9]{6,12}$/.test(value) ? value : null;
}

export function ScanFriendCodeScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const [permission, requestPermission] = useCameraPermissions();
  const [error, setError] = useState('');
  // Guards against onBarcodeScanned firing repeatedly for the same code.
  const handled = useRef(false);

  // Permission is requested here — only when the user opts into scanning.
  if (!permission) {
    return (
      <Screen padding>
        <Text style={styles.body}>Preparing the camera…</Text>
      </Screen>
    );
  }

  if (!permission.granted) {
    return (
      <Screen padding>
        <Text style={styles.title}>Camera access needed</Text>
        <Text style={styles.body}>
          {permission.canAskAgain
            ? 'BallPicker needs your camera to scan a friend’s QR code. Nothing is recorded or uploaded.'
            : 'Camera access is turned off for BallPicker. Enable it in your device settings, or type the friend code manually instead.'}
        </Text>
        {permission.canAskAgain ? (
          <AppButton title="Allow camera" onPress={requestPermission} style={{ marginTop: spacing.lg }} />
        ) : null}
        <AppButton
          title="Enter code manually"
          onPress={() => navigation.navigate('Friends')}
          variant="secondary"
          style={{ marginTop: spacing.sm }}
        />
      </Screen>
    );
  }

  return (
    <View style={styles.fill}>
      <CameraView
        style={styles.fill}
        facing="back"
        barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
        onBarcodeScanned={({ data }) => {
          if (handled.current) return;
          const code = parseFriendCode(String(data ?? ''));
          if (!code) {
            setError('That QR code is not a BallPicker friend code.');
            return;
          }
          handled.current = true;
          navigation.navigate('Friends', { scannedCode: code });
        }}
      />
      <View style={styles.overlay}>
        <Text style={styles.overlayText}>Point the camera at a BallPicker friend QR code.</Text>
        {error ? <Text style={styles.overlayError}>{error}</Text> : null}
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    fill: { flex: 1, backgroundColor: '#000000' },
    title: { fontSize: 20, fontWeight: '800', color: theme.text, marginBottom: spacing.sm },
    body: { fontSize: 14, color: theme.textSecondary, lineHeight: 20 },
    overlay: { position: 'absolute', left: 0, right: 0, bottom: 40, padding: spacing.lg },
    overlayText: { color: '#ffffff', fontSize: 14, textAlign: 'center' },
    overlayError: { color: '#ff8a80', fontSize: 13, textAlign: 'center', marginTop: spacing.sm },
  });
}
```

- [ ] **Step 2: Create the friend profile screen**

Create `mobile/src/screens/FriendProfileScreen.tsx`:

```tsx
import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { Avatar } from '../components/Avatar';
import { ConfirmModal } from '../components/ConfirmModal';
import { friendsApi } from '../api/friendsApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { PublicProfile } from '../types/friend';

type Props = NativeStackScreenProps<RootStackParamList, 'FriendProfile'>;

export function FriendProfileScreen({ route, navigation }: Props) {
  const { userId } = route.params;
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [profile, setProfile] = useState<PublicProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [removeError, setRemoveError] = useState('');

  const load = useCallback(() => {
    setLoadFailed(false);
    return friendsApi.publicProfile(userId)
      .then(setProfile)
      .catch(() => setLoadFailed(true))
      .finally(() => setLoading(false));
  }, [userId]);

  useEffect(() => { load(); }, [load]);

  async function handleRemove() {
    if (removing) return;
    setRemoving(true);
    setRemoveError('');
    try {
      await friendsApi.remove(userId);
      setConfirmRemove(false);
      navigation.goBack();
    } catch {
      setRemoveError('Could not remove this friend. Please try again.');
      setRemoving(false);
    }
  }

  if (loading) {
    return <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>;
  }

  if (!profile) {
    return (
      <Screen padding>
        <Text style={styles.body}>
          {loadFailed ? 'Could not load this profile. Check your connection.' : 'Profile not found.'}
        </Text>
        {loadFailed ? (
          <AppButton title="Try again" onPress={() => { setLoading(true); load(); }} style={{ marginTop: spacing.lg }} />
        ) : null}
      </Screen>
    );
  }

  const s = profile.stats;

  return (
    <Screen scroll padding>
      <View style={styles.header}>
        <Avatar uri={profile.avatar_url} name={profile.name} size={88} />
        <Text style={styles.name}>{profile.name}</Text>
        <Text style={styles.username}>@{profile.username}</Text>
      </View>

      <View style={styles.rankCard}>
        <Text style={styles.rankName}>{profile.rank.name}</Text>
        <Text style={styles.rankMeta}>Level {profile.rank.level} · {profile.total_xp} XP</Text>
      </View>

      <Text style={styles.sectionTitle}>Stats</Text>
      <View style={styles.grid}>
        <Stat styles={styles} label="Tournaments" value={s.tournaments_played} />
        <Stat styles={styles} label="Completed" value={s.tournaments_completed} />
        <Stat styles={styles} label="Guesses" value={s.guesses_count} />
        <Stat styles={styles} label="Total score" value={s.total_score} />
        <Stat styles={styles} label="Avg score" value={s.average_score} />
        <Stat styles={styles} label="Dailies played" value={s.daily_challenges_played} />
        <Stat styles={styles} label="Best daily" value={s.best_daily_score} />
        <Stat styles={styles} label="Badges" value={`${profile.badges.earned_count}/${profile.badges.total_count}`} />
      </View>

      {profile.is_friend ? (
        <AppButton title="Remove friend" onPress={() => setConfirmRemove(true)} variant="danger" />
      ) : null}

      <ConfirmModal
        visible={confirmRemove}
        title="Remove friend?"
        message={`${profile.name} will be removed from your friends list. You can add each other again later.`}
        confirmLabel="Remove"
        cancelLabel="Cancel"
        onConfirm={handleRemove}
        onCancel={() => { setConfirmRemove(false); setRemoveError(''); }}
        loading={removing}
        errorText={removeError}
        destructive
      />
    </Screen>
  );
}

function Stat({ styles, label, value }: { styles: Styles; label: string; value: string | number }) {
  return (
    <View style={styles.statBox}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    body: { fontSize: 14, color: theme.textSecondary },
    header: { alignItems: 'center', marginBottom: spacing.lg },
    name: { fontSize: 22, fontWeight: '800', color: theme.text, marginTop: spacing.sm },
    username: { fontSize: 14, color: theme.textSecondary },
    rankCard: { backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border, padding: spacing.md, alignItems: 'center', marginBottom: spacing.lg },
    rankName: { fontSize: 18, fontWeight: '800', color: theme.primary },
    rankMeta: { fontSize: 13, color: theme.textSecondary, marginTop: 2 },
    sectionTitle: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm },
    grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.xl },
    statBox: { backgroundColor: theme.surface, borderRadius: 12, padding: spacing.md, alignItems: 'center', borderWidth: 1, borderColor: theme.border, minWidth: '45%', flex: 1 },
    statValue: { fontSize: 22, fontWeight: '800', color: theme.primary, marginBottom: 4 },
    statLabel: { fontSize: 12, color: theme.textSecondary, textAlign: 'center' },
  });
}
```

- [ ] **Step 3: Add the Profile entry point**

In `mobile/src/screens/ProfileScreen.tsx`, directly after the Trophy Room card, add:

```tsx
      {/* Friends entry point */}
      <TouchableOpacity
        style={styles.ranksCard}
        activeOpacity={0.8}
        onPress={() => navigation.navigate('Friends')}
      >
        <View style={styles.ranksLeft}>
          <Text style={styles.ranksIcon}>👥</Text>
          <View style={styles.ranksTextWrap}>
            <Text style={styles.ranksTitle}>Friends</Text>
            <Text style={styles.ranksSubtitle}>Your friend code, requests and friends list.</Text>
          </View>
        </View>
        <Text style={styles.ranksAction}>Open ›</Text>
      </TouchableOpacity>
```

- [ ] **Step 4: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Manual verification**

On a device: open Friends → the code and QR render; Copy shows "Copied!"; Scan QR asks for camera permission the first time and shows the "Camera access needed" screen if denied; scanning a second phone's QR fills the input; sending a request, accepting on the other account, and tapping the friend opens the profile.

- [ ] **Step 6: Commit**

```bash
git add mobile/src/screens/ScanFriendCodeScreen.tsx mobile/src/screens/FriendProfileScreen.tsx mobile/src/screens/ProfileScreen.tsx
git commit -m "feat(mobile): friend QR scanner and public friend profile"
```

---

## Task 14: Hide a completed tournament (backend)

**Files:**
- Create: `backend/database/migrations/2026_08_02_000004_add_hidden_at_to_league_members.php`
- Create: `backend/tests/Feature/LeagueHideTest.php`
- Modify: `backend/app/Http/Controllers/Api/LeagueController.php`
- Modify: `backend/routes/api.php`
- Modify: `docs/api-contract.md`
- Modify: `docs/database-schema.md`

**Interfaces:**
- Produces: `POST /api/leagues/{league}/hide` → `204`, and `LeagueController::index` filtered with `wherePivotNull('hidden_at')`.

Storing the flag on the existing `league_members` pivot makes "you can only hide a tournament you are part of" a structural property, not a check that can be forgotten. No guesses, scores, leaderboard rows, XP, trophies or `tournament_finishes` are touched.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/LeagueHideTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueHideTest extends TestCase
{
    use RefreshDatabase;

    private function completedLeagueWithMember(User $user): League
    {
        $league = League::create([
            'name'           => 'Finished Cup',
            'join_code'      => 'ABC123',
            'owner_user_id'  => $user->id,
            'duration_days'  => 3,
            'rounds_per_day' => 1,
            'status'         => 'completed',
        ]);
        $league->members()->attach($user->id, ['joined_at' => now()]);

        return $league;
    }

    public function test_hide_requires_auth(): void
    {
        $user   = User::factory()->create();
        $league = $this->completedLeagueWithMember($user);

        $this->postJson("/api/leagues/{$league->id}/hide")->assertUnauthorized();
    }

    public function test_member_can_hide_a_completed_tournament(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();

        $this->assertDatabaseMissing('league_members', [
            'league_id' => $league->id, 'user_id' => $user->id, 'hidden_at' => null,
        ]);
    }

    public function test_hidden_tournament_disappears_from_the_list(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        $this->withToken($token)->getJson('/api/leagues')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();
        $this->withToken($token)->getJson('/api/leagues')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hiding_does_not_delete_the_league_or_membership_for_others(): void
    {
        $user   = User::factory()->create();
        $other  = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);
        $league->members()->attach($other->id, ['joined_at' => now()]);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();

        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'completed']);
        $this->withToken($other->createToken('t')->plainTextToken)
            ->getJson('/api/leagues')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cannot_hide_a_tournament_you_are_not_part_of(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $league   = $this->completedLeagueWithMember($owner);

        $this->withToken($stranger->createToken('t')->plainTextToken)
            ->postJson("/api/leagues/{$league->id}/hide")
            ->assertForbidden();
    }

    public function test_cannot_hide_an_active_or_lobby_tournament(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);
        $league->update(['status' => 'active']);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertStatus(422);

        $league->update(['status' => 'lobby']);
        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertStatus(422);
    }

    public function test_hiding_is_idempotent(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();
        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();
    }

    public function test_hidden_tournament_still_appears_in_profile_history(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        \App\Models\TournamentFinish::create([
            'league_id'     => $league->id,
            'user_id'       => $user->id,
            'placement'     => 1,
            'total_score'   => 240,
            'rounds_played' => 3,
            'xp_awarded'    => 100,
            'metadata'      => ['total_players' => 4],
        ]);

        $this->withToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();

        $this->withToken($token)->getJson('/api/me/tournament-finishes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.league.name', 'Finished Cup');
    }
}
```

Before running, open `backend/database/migrations/*_create_tournament_finishes_table.php` and adjust the `TournamentFinish::create` payload to the real columns (drop `xp_awarded`/`metadata` if they are named differently).

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php artisan test --filter=LeagueHideTest`
Expected: FAIL — the `/hide` route does not exist.

- [ ] **Step 3: Write the migration**

Create `backend/database/migrations/2026_08_02_000004_add_hidden_at_to_league_members.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_members', function (Blueprint $table) {
            // Per-user "remove from my list" for finished tournaments. The row
            // itself stays, so history, leaderboards and XP are untouched.
            if (!Schema::hasColumn('league_members', 'hidden_at')) {
                $table->timestamp('hidden_at')->nullable();
                $table->index(['user_id', 'hidden_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('league_members', function (Blueprint $table) {
            if (Schema::hasColumn('league_members', 'hidden_at')) {
                $table->dropIndex(['user_id', 'hidden_at']);
                $table->dropColumn('hidden_at');
            }
        });
    }
};
```

- [ ] **Step 4: Update the model relationships**

In `backend/app/Models/User.php`, add `hidden_at` to the leagues pivot:

```php
    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(League::class, 'league_members')->withPivot('joined_at', 'hidden_at');
    }
```

In `backend/app/Models/League.php`, do the same for `members()`:

```php
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'league_members')->withPivot('joined_at', 'hidden_at'); }
```

- [ ] **Step 5: Filter the list and add the hide action**

In `backend/app/Http/Controllers/Api/LeagueController.php`, change `index()`:

```php
    public function index(Request $request)
    {
        $leagues = $request->user()
            ->leagues()
            ->where('status', '!=', 'cancelled')
            // Completed tournaments the user chose to remove from their list.
            // History (tournament_finishes) is unaffected.
            ->wherePivotNull('hidden_at')
            ->with(['rounds', 'members', 'sport'])
            ->get();

        return LeagueResource::collection($leagues);
    }
```

and add the new action:

```php
    /**
     * POST /leagues/{league}/hide — remove a FINISHED tournament from this
     * user's own list. Nothing is deleted: the membership row stays, and so do
     * guesses, scores, leaderboard rows, XP, badges and tournament_finishes.
     */
    public function hide(Request $request, League $league)
    {
        $userId = $request->user()->id;

        if (!$league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }
        if ($league->status !== 'completed') {
            return response()->json(['message' => 'Only finished tournaments can be removed from your list.'], 422);
        }

        // Idempotent: hiding an already-hidden tournament is a no-op success.
        $league->members()->updateExistingPivot($userId, ['hidden_at' => now()]);

        return response()->noContent();
    }
```

- [ ] **Step 6: Register the route**

In `backend/routes/api.php`, next to the other league routes:

```php
        Route::post('/leagues/{league}/hide', [LeagueController::class, 'hide']);
```

- [ ] **Step 7: Migrate and run the tests**

Run from `backend/`:

```bash
php artisan migrate
php artisan test --filter=LeagueHideTest
```

Expected: all PASS.

- [ ] **Step 8: Run the full suite**

Run from `backend/`: `php artisan test`
Expected: all pass — pay attention to `LeagueTest`, `LeagueMemberTest`, `LeagueTournamentLifecycleTest` and `TournamentCompletionTest`, which exercise the list and the owner delete/cancel path.

- [ ] **Step 9: Document it**

- `docs/api-contract.md`: add `POST /api/leagues/{league}/hide` (204; 403 non-member; 422 not completed).
- `docs/database-schema.md`: add `league_members.hidden_at` with the one-line rationale.

- [ ] **Step 10: Commit**

```bash
git add backend/database/migrations/2026_08_02_000004_*.php backend/app/Http/Controllers/Api/LeagueController.php backend/app/Models/User.php backend/app/Models/League.php backend/routes/api.php backend/tests/Feature/LeagueHideTest.php docs/api-contract.md docs/database-schema.md
git commit -m "feat(api): let members hide finished tournaments from their own list"
```

---

## Task 15: Remove-completed-tournament UI

**Files:**
- Modify: `mobile/src/api/leagueApi.ts`
- Modify: `mobile/src/screens/HomeScreen.tsx`

**Interfaces:**
- Consumes: `POST /api/leagues/{league}/hide` (Task 14).
- Produces: `leagueApi.hide(id: number): Promise<void>`.

- [ ] **Step 1: Add the API call**

In `mobile/src/api/leagueApi.ts`, add to the `leagueApi` object:

```ts
  /** Remove a FINISHED tournament from this user's list. History is kept. */
  hide: (id: number) =>
    apiClient.request<void>(`/leagues/${id}/hide`, { method: 'POST' }),
```

- [ ] **Step 2: Add the X button to completed tournament cards**

In `mobile/src/screens/HomeScreen.tsx`, extend `TournamentCard`:

1. Add `onHide?: () => void;` to its props type.
2. In `styles.cardHeader`, render the X after the status badge:

```tsx
        {item.status === 'completed' && onHide ? (
          <TouchableOpacity
            onPress={(e) => { e.stopPropagation(); onHide(); }}
            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
            style={styles.hideBtn}
            accessibilityRole="button"
            accessibilityLabel={`Remove ${item.name} from your list`}
          >
            <Text style={styles.hideBtnText}>✕</Text>
          </TouchableOpacity>
        ) : null}
```

3. Add the styles to `createStyles`:

```tsx
    hideBtn: { marginLeft: spacing.sm, width: 24, height: 24, alignItems: 'center', justifyContent: 'center' },
    hideBtnText: { fontSize: 15, fontWeight: '700', color: theme.textMuted, lineHeight: 18 },
```

- [ ] **Step 3: Wire the confirm modal**

In `HomeScreen`, add state:

```tsx
  const [hideTarget, setHideTarget] = useState<League | null>(null);
  const [hiding, setHiding] = useState(false);
  const [hideError, setHideError] = useState('');
```

Add the handler:

```tsx
  async function handleHide() {
    if (!hideTarget || hiding) return;
    setHiding(true);
    setHideError('');
    const id = hideTarget.id;
    try {
      await leagueApi.hide(id);
      // Optimistic — the server keeps every result, only this list changes.
      setLeagues((prev) => prev.filter((l) => l.id !== id));
      setHideTarget(null);
    } catch {
      setHideError('Could not remove the tournament. Please try again.');
    } finally {
      setHiding(false);
    }
  }
```

Pass `onHide` in `renderItem`:

```tsx
              onHide={() => setHideTarget(item)}
```

And add the modal next to the existing ones, using the exact required copy:

```tsx
      <ConfirmModal
        visible={!!hideTarget}
        title="Remove tournament?"
        message="This will remove it from your list. Your result/history will stay saved."
        confirmLabel="Remove"
        cancelLabel="Cancel"
        onConfirm={handleHide}
        onCancel={() => { setHideTarget(null); setHideError(''); }}
        loading={hiding}
        errorText={hideError}
        destructive
      />
```

- [ ] **Step 4: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/api/leagueApi.ts mobile/src/screens/HomeScreen.tsx
git commit -m "feat(mobile): remove finished tournaments from the Home list"
```

---

## Task 16: Profile history section

**Files:**
- Create: `mobile/src/components/ProfileHistoryCard.tsx`
- Modify: `mobile/src/screens/ProfileScreen.tsx`

**Interfaces:**
- Consumes: `badgeApi.finishes()` (existing, `mobile/src/api/badgeApi.ts`) returning `TournamentFinish[]` from `mobile/src/types/badge.ts`.
- Produces: `export function ProfileHistoryCard(props: { finishes: TournamentFinish[] }): React.ReactElement`

History is backed by `tournament_finishes`, which is written by `TournamentCompletionService` and is entirely independent of the `league_members.hidden_at` flag — so a hidden tournament still shows up here. That is verified server-side by `LeagueHideTest::test_hidden_tournament_still_appears_in_profile_history`.

- [ ] **Step 1: Create the component**

Create `mobile/src/components/ProfileHistoryCard.tsx`:

```tsx
import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { TournamentFinish } from '../types/badge';

interface Props {
  finishes: TournamentFinish[];
}

const MEDAL: Record<number, string> = { 1: '🥇', 2: '🥈', 3: '🥉' };

function formatDate(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Compact completed-tournament history. Sourced from tournament_finishes, so
 * entries survive the user hiding a tournament from their Home list.
 */
export function ProfileHistoryCard({ finishes }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View style={styles.card}>
      {finishes.map((f, i) => (
        <View key={f.id} style={[styles.row, i > 0 && styles.rowDivider]}>
          <Text style={styles.medal}>{MEDAL[f.placement] ?? `#${f.placement}`}</Text>
          <View style={styles.text}>
            <Text style={styles.name} numberOfLines={1}>{f.league?.name ?? 'Tournament'}</Text>
            <Text style={styles.meta}>
              {`#${f.placement}`}
              {f.total_players ? ` of ${f.total_players}` : ''}
              {` · ${f.total_score} pts`}
              {f.rounds_played ? ` · ${f.rounds_played} rounds` : ''}
            </Text>
          </View>
          <Text style={styles.date}>{formatDate(f.completed_at)}</Text>
        </View>
      ))}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, marginBottom: spacing.xl,
    },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.md },
    rowDivider: { borderTopWidth: 1, borderTopColor: theme.border },
    medal: { fontSize: 18, minWidth: 28, textAlign: 'center', color: theme.textSecondary, fontWeight: '700' },
    text: { flex: 1 },
    name: { fontSize: 14, fontWeight: '700', color: theme.text },
    meta: { fontSize: 12, color: theme.textSecondary, marginTop: 1 },
    date: { fontSize: 11, color: theme.textMuted },
  });
}
```

- [ ] **Step 2: Render it on Profile**

In `mobile/src/screens/ProfileScreen.tsx`:

1. Add imports:

```tsx
import { ProfileHistoryCard } from '../components/ProfileHistoryCard';
import { badgeApi } from '../api/badgeApi';
import type { TournamentFinish } from '../types/badge';
```

2. Add state and extend `loadProfile` (history is capped at 10 rows to keep the screen light):

```tsx
  const [history, setHistory] = useState<TournamentFinish[]>([]);
```

```tsx
  const loadProfile = useCallback(() => {
    // Profile shows at most 5 recent XP events and 10 history entries.
    return Promise.all([
      authApi.me(),
      authApi.stats(),
      authApi.xpEvents(5).catch(() => null),
      badgeApi.finishes().catch(() => null),
    ])
      .then(([me, s, xp, finishes]) => {
        setUser(me);
        setStats(s);
        if (xp) setXpEvents(xp.data.slice(0, 5));
        if (finishes) setHistory(finishes.slice(0, 10));
      })
      .catch(() => {});
  }, []);
```

3. Render the section directly after the "Recent XP" block:

```tsx
      {/* Tournament history — stays here even if the tournament was removed
          from the Home list. */}
      <Text style={styles.sectionTitle}>History</Text>
      {history.length > 0 ? (
        <ProfileHistoryCard finishes={history} />
      ) : (
        <View style={styles.emptyCard}>
          <Text style={styles.emptyText}>No finished tournaments yet.</Text>
        </View>
      )}
```

`ProfileScreen` already renders inside `<Screen scroll padding>`, so the section scrolls with the rest of the page.

- [ ] **Step 3: Typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/components/ProfileHistoryCard.tsx mobile/src/screens/ProfileScreen.tsx
git commit -m "feat(mobile): add tournament history section to Profile"
```

---

## Task 17: Full verification and release notes

**Files:**
- Modify: `docs/test-report.md`

**Interfaces:**
- Consumes: everything above.
- Produces: a verified, documented release state.

- [ ] **Step 1: Backend suite**

Run from `backend/`: `php artisan test`
Expected: every test passes. Record the total count.

- [ ] **Step 2: Mobile typecheck**

Run from `mobile/`: `npx tsc --noEmit`
Expected: no output (success).

- [ ] **Step 3: Web export smoke test**

Run from `mobile/`: `npx expo export --platform web`
Expected: the bundle builds. `expo-camera`'s `CameraView` has limited web support — if the export fails on the camera import, guard the scanner screen with `Platform.OS === 'web'` and render the "Enter code manually" fallback instead, then re-run. Report the outcome either way.

- [ ] **Step 4: Manual device checklist**

Work through this list on a real device and record pass/fail for each:

- Login and register still work (including 2FA + email verification).
- Profile scrolls; avatar upload works.
- Daily result: reveal image sits directly under the score card.
- Tapping the result image opens fullscreen; X, background tap and Android back all close it.
- "View fullscreen" button works on Daily result, Tournament result, Pack result and all three guess screens.
- Tournament guess back button goes to Home; tournament result back goes to Home; Android hardware back does the same.
- Pack guess/result back goes to Packs.
- No challenge title on the Daily, Tournament or Pack guess screens, or on the unplayed Home daily card.
- Network inspector shows no `/result` 404 before submitting a guess and no repeated failing image requests.
- Friends screen opens; friend code and QR render; copy works.
- Friend request by code works end-to-end (send → accept on the other account → appears in both friends lists).
- QR scan works, or shows the permission-denied message with a manual-entry fallback.
- A completed tournament can be removed from Home with the "Remove tournament?" modal.
- The removed tournament still appears in Profile → History.

- [ ] **Step 5: Update the test report**

In `docs/test-report.md`, add a `## v1.8.2 — mobile polish` section with the backend test count, the mobile typecheck result, the web export result, and the manual checklist outcome.

- [ ] **Step 6: Commit**

```bash
git add docs/test-report.md
git commit -m "docs: v1.8.2 verification results"
```

---

## Deploy commands

Run on the server, in this order. **Never `migrate:fresh`.**

```bash
# Backend
cd backend
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force          # adds friend_code (+backfill), friend_requests, friendships, league_members.hidden_at
php artisan storage:link             # no-op if the symlink already exists
php artisan config:cache
php artisan route:cache
php artisan up

# Mobile — the camera plugin and react-native-svg are native changes, so a
# JS-only OTA update is NOT sufficient. A new build is required.
cd ../mobile
npm install
npx expo prebuild --clean            # only if using bare workflow locally
eas build --platform all --profile production
eas submit --platform all
```

Verify after deploy: `GET /api/health` returns ok, `GET /api/me/friend-code` returns a code for an existing account, and a challenge image URL from `GET /api/daily/today` loads in a browser.

## Known limitations / follow-ups

- **No bottom tab bar.** The app is a single native-stack navigator; Friends is reached from Home and Profile cards. Introducing tabs would restructure every screen and is deliberately out of scope.
- **Friends has no push notification** on an incoming request — the badge only appears when the Friends screen is opened. `ExpoPushService` already exists, so this is a small follow-up.
- **Friend requests cannot be cancelled** by the sender in v1.8.2. The `cancelled` status exists in the schema but has no endpoint yet.
- **Result screens are not themed.** `ResultScreen`, `DailyResultScreen` and `ResultImageSection` use the static `theme/colors` palette (pre-existing); they do not follow the user's selected theme. Migrating them to `useTheme` is a separate, purely visual change.
- **Fullscreen viewer has no pinch-zoom.** It fits the image with `contain`; a zoomable viewer would need `react-native-gesture-handler`.
- **Profile history does not list per-tournament badges.** `tournament_finishes` records placement, score, rounds and date but not which badges that finish unlocked, so the history rows show the medal/placement rather than badge icons. The full badge collection is already one tap away in the Trophy Room. Adding it would mean joining `user_badges` on the finish timestamp — a follow-up, not a polish-sprint change.
- **Public profile is visible to any authenticated player** who knows a user id, not only to friends. This matches the leaderboard, which already exposes username + score, but tightening it to friends-only is a decision worth revisiting.
- **`expo-camera` on web** is limited; the scanner falls back to manual code entry there.
