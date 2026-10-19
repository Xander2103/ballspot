# BallPicker Clubs — real-device QA checklist (iOS + Android)

Run against **staging only** (`docs/clubs-staging-plan.md`), with the QA data from `ClubsStagingSeeder`. Mark each case ✅ / ❌ /
⏭ (n/a) per platform. Record build number, commit and staging URL at the top of every run.

| Run | Date | Tester | App build / commit | Staging URL | iOS device + OS | Android device + OS |
|---|---|---|---|---|---|---|
| | | | | | | |

**Device matrix (minimum):**
- one small iPhone (SE / mini class) and one recent iPhone, on the current iOS and on iOS current − 1;
- one mid-range Android on Android 13/14 and one recent Pixel or Samsung on the latest Android.
- Portrait only. Tablets are out of scope.

**Accounts** (password shared out of band; emails are `@staging.ballpicker.test`, so read every mail in Mailpit):
- `qa_player_01`–`12`: members of QA FC Alpha.
- `qa_many_clubs`: member of 30 clubs.
- `qa_outsider`: member of no club.
- `qa_owner_alpha` and `qa_admin_alpha`: club roles.
- `qa_banned`, `qa_left`.
- `qa_nl`, `qa_fr`, `qa_de`, `qa_es`.
- `qa_admin`: platform admin.

**Severity:**
- **S1** blocks release: data leak, wrong scores/standings, crash, lost progress.
- **S2** major function broken with a workaround.
- **S3** minor or cosmetic.
- **S4** suggestion.

**Bug template:** ID · case ID · platform/device/OS · build · steps · expected · actual · screenshot/video · staging timestamp (for log lookup) · severity.

---

## A. Install, environment, navigation
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| A-01 | Install the dev build; start Metro with the staging `EXPO_PUBLIC_*`; open the app | The first request appears in the staging nginx log (`docker compose … logs -f web`), not in production | | |
| A-02 | Sign in as `qa_player_01` | Login (+ 2FA mail in Mailpit if enabled) works; Play tab opens | | |
| A-03 | Check the tab bar | 5 tabs in the order Play · Tournaments · **Clubs** · Friends · Profile; Clubs icon renders; no truncated labels | | |
| A-04 | Switch the language to NL, FR, DE, ES (Profile) | Tab label: Clubs / Clubs / Vereine / Clubes; no English leftovers on Clubs screens; no layout overflow on the small phone | | |
| A-05 | Admin sets `BALLSPOT_CLUBS_ENABLED=false` (restart app container), then relaunch the app | Clubs tab hidden; the rest of the app works; switch back on → tab returns after a foreground/refresh | | |
| A-06 | Cycle all 5 themes + system dark mode on Clubs screens | Readable contrast everywhere, incl. leaderboards, modals, QR sheet | | |
| A-07 | Raise the OS font size to the maximum (Dynamic Type / Font size) | Club cards, buttons, rules card remain usable (no clipped primary actions) | | |
| A-08 | VoiceOver / TalkBack quick pass on My Clubs, Join, Club detail | Buttons have meaningful labels; focus order is logical | | |

## B. My Clubs (tab)
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| B-01 | `qa_outsider` opens Clubs | Empty state with explanation, "Join with code" and "Scan QR"; no club directory anywhere | | |
| B-02 | `qa_many_clubs` opens Clubs; scroll to the end | 30 clubs load incrementally (no duplicates, no gaps); pull-to-refresh works | | |
| B-03 | Type a city/name fragment in search (e.g. "Gent", "07") | Results filter server-side after a short debounce; no flicker; clear (✕) restores the list | | |
| B-04 | Search "Alpha" as `qa_outsider` | "No joined clubs match…" — QA FC Alpha is **not** shown (no directory) | | |
| B-05 | Card content | Logo/initial, name, city, role chip, pack count, active/upcoming competition counts correct vs admin | | |
| B-06 | Suspended club (QA Beta Hockey) for a member | Shown dimmed "temporarily unavailable"; opens read-only | | |
| B-07 | Airplane mode, open Clubs; then reconnect and retry | Error state with Retry; retry recovers; no crash | | |
| B-08 | Scroll deep, switch tabs and come back | Scroll position kept (no jump to top) | | |

## C. Join a club
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| C-01 | Join with a usable member code typed lowercase with spaces/dash | Code is normalised to ABCD-EFGH; joins; opens Club detail | | |
| C-02 | Join again with the same code | No error; opens the club (already member) | | |
| C-03 | Expired / revoked / exhausted / unknown code | Same friendly "invalid code" message for all | | |
| C-04 | 15+ wrong codes in a row | Locked message ("too many attempts"); no crash | | |
| C-05 | `qa_banned` uses a valid Alpha code | Generic invalid-code message (no "banned" signal) | | |
| C-06 | Scan the QR from the **admin** print sheet (printed paper + screen) | Camera permission prompt text mentions friend and club QR codes; scan joins | | |
| C-07 | Scan the QR from the **portal** print sheet and from another phone's app QR (Club invites screen) | Joins | | |
| C-08 | Scan a friend QR in the club scanner, and a club QR in the friend scanner | Sensible "not a club code" / "not a friend code" handling; friend flow unchanged | | |
| C-09 | Deny camera permission, then retry | Explains how to enable; manual code entry still possible | | |

## D. Club detail, packs, normal play
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| D-01 | Open QA FC Alpha | Header (logo, city/type, members, my role); Competitions segments Active/Upcoming/Completed; Packs with progress; Leaderboard preview | | |
| D-02 | "See all" packs; open "QA Training Ground"; play to completion | Images load (signed URLs), guess/reveal/result flow identical to public packs; XP shown on the **first** completion only | | |
| D-03 | Replay a completed club pack | Replay blocked (same as public packs), completion summary shown | | |
| D-04 | Open "QA Derby Pack" (linked to the active blocked competition) | Marked competition-only; normal start refused with a friendly message | | |
| D-05 | Exit mid-pack, then return | Resumes the same attempt; leaving returns to the club, not the public Packs list | | |
| D-06 | Lose network while submitting a guess; resend | No double submission; the result is consistent | | |
| D-07 | Members (as plain member, visibility "members") / Leave club | Members list paginated with search; Leave asks confirmation; club disappears from My Clubs | | |
| D-08 | Sole owner (`qa_owner_beta` or a test owner) tries Leave | Explanation that ownership must be transferred | | |
| D-09 | Public gameplay regression: Daily, Tournaments, public Packs, Friends, Profile, Trophy Room | Unchanged behaviour; **no club packs/badges in public lists or the public profile** | | |

## E. Competitions
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| E-01 | Open the active competition (multiple attempts, best of 3) | Window in **club time zone (Brussels)** with countdown; rules card "best of 3"; my position | | |
| E-02 | Start attempt 1, play all; start attempt 2 with a lower score | Counted score = best; attempts used/remaining correct; "no XP" note on non-rewarded runs | | |
| E-03 | Double-tap "Start attempt" | Only one attempt is created | | |
| E-04 | Kill the app mid-attempt; reopen; Continue | Resumes at the right challenge | | |
| E-05 | Upcoming competition | Countdown, packs without play buttons | | |
| E-06 | Finalized competition + Trophy Room | Final placements (shared ranks shown as equal); "Club trophies" section shows the trophy | | |
| E-07 | Leaderboard "See all" | Shared ranks (1, 2, 2, 4); "you" highlighted; pagination | | |
| E-08 | Admin extends the end date while a player is on the screen | Pull-to-refresh shows the new end; play continues | | |
| E-09 | Device time zone set to a different zone (e.g. New York) | Times still shown in the competition's zone (verifies Hermes Intl time zones); otherwise falls back without crashing — record which | | |
| E-10 | Window ends during an attempt (use a short test competition) | Further guesses refused with "competition ended"; partial score kept | | |
| E-11 | "No replay" competition with a pack the player already played | Clear blocked reason; no start button | | |

## F. Club admin in the app (`qa_owner_alpha` / `qa_admin_alpha`)
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| F-01 | Club ⋯ menu as owner vs admin vs member | Invite / Manage / Upload entries only for roles that have them | | |
| F-02 | Create a member code (label, max uses, 7-day expiry); show QR; copy; Share | QR scans on another phone; clipboard contains the code; share sheet text includes the formatted code, localised | | |
| F-03 | As admin (not owner) view an admin-granting code | Masked, no QR/copy/share; revoke possible | | |
| F-04 | Revoke a code | Confirmation; status updates; joining with it fails | | |
| F-05 | Member actions: make admin / make member (owner), remove / ban with reason | Optimistic update; refusals (e.g. on owner) show a message and revert | | |
| F-06 | Upload photos: pick 3 (incl. an **iPhone HEIC** and a large photo) with the consent box unchecked → checked | Without consent the upload is blocked; with consent each photo shows sent; HEIC is accepted (converted to JPEG) | | |
| F-07 | Photo-library permission text | Mentions profile pictures and club photo submissions | | |
| F-08 | Upload on cellular / poor network; airplane mode mid-batch | Per-photo failed state, others continue; retry works | | |
| F-09 | Status list after the admin rejects / converts in admin | Rejection reason visible; converted status shown | | |

## G. Reports
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| G-01 | Report the club, a competition and a member (5 reasons, details ≤ 500) | Thank-you confirmation; report appears in admin Reports queue; reporter not visible to the club (portal audit) | | |
| G-02 | 6 reports within an hour | Friendly limit message | | |

## H. Push notifications (EAS development build only — blocked until approved)
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| H-01 | Fresh install: notification permission prompt | Prompt appears at the expected moment; denying keeps the app usable | | |
| H-02 | Admin publishes a competition (push enabled on staging) | One "new competition" push per member device, in the member's language | | |
| H-03 | Competition start (tick) and finalize | One push each; finalize shows the placement | | |
| H-04 | Tap the push with the app killed / backgrounded / foregrounded | Opens the right ClubCompetition screen; when signed out the tap is ignored | | |
| H-05 | Turn "Club updates" off in notification settings; publish another competition | No push for that user | | |
| H-06 | Publish/cancel 4× quickly | At most 3 "published" pushes per club per day | | |

## I. Club portal on phone browsers (Safari iOS, Chrome Android) and desktop
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| I-01 | `/club/login` as `qa_owner_alpha`; code from Mailpit | Login only after the code; wrong code ×5 locks; resend cooldown | | |
| I-02 | Plain member (`qa_player_01`) tries the portal | Generic refusal | | |
| I-03 | Dashboard, Members, Invite codes (+ print), Photos (upload with consent), Leaderboard (+ CSV), Competitions (create draft → publish; locked after start), Settings (owner only), Audit | All pages usable at phone width; staff actions shown as "BallPicker team" | | |
| I-04 | CSV downloads on phone + open in Numbers/Sheets | Usernames only; no emails; special characters fine | | |

## J. Platform admin (desktop browser)
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| J-01 | Create club → code → photo review → prepare (ball removed image + click ball) → convert into a pack → member plays it on a device | End-to-end works; the device sees the new challenge with signed images | — | — |
| J-02 | Suspend / reactivate / archive a club while a member has the app open | App reflects suspension (read-only) / archive (club disappears) after refresh | | |
| J-03 | Void an attempt; rebuild standings (dry run, then apply) | Device leaderboard updates accordingly | | |

## K. Security & privacy spot checks (on device)
| ID | Steps | Expected | iOS | Android |
|---|---|---|---|---|
| K-01 | Copy a signed image URL from a club challenge (proxy/log) and open it after 60+ minutes or without the signature | Refused | | |
| K-02 | `qa_outsider` tries a known club id / competition id via a crafted deep link or API call | 404 | | |
| K-03 | Delete account (`qa_left` or a fresh QA user) | Memberships removed; leaderboards no longer show the user; data export (Profile) contains own club data only | | |

## Exit criteria
- Every A–G and I–K case is passed on both platforms, or has an accepted ticket, with **no open S1/S2**.
- H (push) is passed on at least one iOS and one Android device using an EAS development build.
- Results, bugs and the staging commit are recorded in `docs/test-report.md` (new "Clubs device QA" section).
