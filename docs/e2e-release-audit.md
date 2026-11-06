# BallPicker Sports — End-to-end test & release audit

Datum: 2026-11-04 (06:45–19:50 CET) · Auditor: Claude Code (autonome sessie) · Repo: `ballspot`, worktree `../ballspot-integration` op `master`. De audit begon op 38fbad6 (= origin/master) met 48 niet-gecommitte paden; die zijn tijdens de audit (18:46, buiten deze sessie) gecommit en gepusht als 08a521a "IC" — de geteste code is dus 08a521a (= origin/master). Clubs: `feature/ballpicker-clubs` @ 7debb4c.

Status-legenda per scenario: **PASS** = werkelijk end-to-end uitgevoerd en geslaagd · **FAIL** = uitgevoerd en mislukt · **BLOCKED** = niet uitvoerbaar (toestel/account/configuratie/omgeving) · **NOT TESTED** = niet uitgevoerd.
Bewijsniveau per scenario: `unit` · `api` (echte HTTP + databasecontrole) · `ui` (schermopname) · `sim-e2e` (simulator/emulator + echte backend + database) · `device` (fysiek toestel) · `prod` (read-only productiecontrole).

---

## 0. Samenvatting en releasebeslissing

| Release-onderdeel | Beslissing | Reden |
|---|---|---|
| 1. Backend-release (master 08a521a) | **GO** | 1300 PHPUnit groen, alle API-integratietests PASS (auth, isolatie, score-manipulatie, concurrency, rate limiting, packs, toernooien, XP/share, notificaties, maandcontract, uploads); geen P0/P1. Vereist nog: deploy + migrate (`push_receipts`) + `config:cache` + fpm-reload volgens docs/release-combined-test-2026-11.md. |
| 2. iOS TestFlight | **GO** | Simulator-E2E PASS voor login, eerste login (sportkeuze), Daily spelen → score/XP/badges/streak = database, resultaat heropenen, maandklassement = API = database, trofeeën, statistieken, 5 talen, sessieherstel, verlopen token. Geen P0/P1. Pushmeldingen op een echt toestel blijven onbewezen (zie §8). |
| 3. Android interne test | **GO voor interne test, met voorbehoud** | Emulator-E2E PASS (lokaal gebouwde debug-APK, API 37): login, Daily spelen → score 99 = database, rank-up, badges, sessieherstel, maandklassement. Twee UI-defecten op Android (P2 header onder de statusbalk, P3 terugknop plakt aan titel) moeten vóór een publieke release worden opgelost; FCM-levering en notificatie-icoon niet op een toestel bewezen. |
| 4. Publieke productie-release | **NO-GO (nog niet)** | Openstaand: (a) pushmeldingen nooit op een fysiek toestel bewezen (iOS én Android); (b) Android P2-layoutdefect; (c) productie-maandklassement kan door mij niet geverifieerd worden (geen testaccount op prod, `competition-period-audit` niet gedeployed); (d) productie mist HSTS/X-Content-Type-Options/X-Frame-Options en toont de nginx-versie (P2 hardening); (e) de daggrens/maandgrens loopt op UTC, niet op Belgische middernacht (bewust? zie §4). Na (a)–(d) en een TestFlight-ronde: GO. |
| 5. Clubs-featurebranch | **GO voor staging/interne test, NO-GO voor productie** | Volledige club-workflow via API PASS op een geïsoleerde MariaDB (club aanmaken, codes, rechten, foto → beoordeling → balpositie → lokale balverwijdering → vergelijken → afkeuren/goedkeuren → challenge in clubpack → spelen → clubcompetitie → finalize; isolatie 22 routes; concurrency). Mobiele Clubs-UI op de simulator: zie §7.3. Geen betaalde OpenAI-aanroep gedaan. Niet gemerged. |

Gevonden defecten: 0 × P0, 0 × P1, 3 × P2 (waarvan 1 te bevestigen productkeuze), 15 × P3 (volledige lijst §12).

Wat NIET is gedaan (zoals gevraagd): geen commit, push, merge, deploy, productie-build, store-submissie, productie-mutatie, betaalde AI-aanroep. Productie is uitsluitend met niet-geauthenticeerde GET-requests benaderd.

---

## 1. Veiligheid en voorbereiding

| Controle | Resultaat |
|---|---|
| Repository/branch | `../ballspot-integration` op `master`; bij de start 38fbad6 (= `origin/master`) + 48 niet-gecommitte paden (push receipts, period-audit, reminderPlan, google-services.json, docs, …); om 18:46 door een andere sessie gecommit en gepusht als 08a521a "IC" (= origin/master). Niets daarvan is door de audit gewijzigd; alleen `docs/e2e-release-audit.md` is nieuw (ongecommit). |
| Andere branches/worktrees | `feature/ballpicker-clubs` 7debb4c in `~/Documents/ballspot` (actieve ontwikkel-worktree, 4 niet-gerelateerde ongecommitte paden; niet aangeraakt). Voor de Clubs-tests is de schone, losstaande checkout `~/Documents/ballspot-staging` (zelfde commit, 0 wijzigingen) gebruikt. Verder: `feature/home-polish-friends-redesign`, `feature/home-screen-redesign` (prunable), `agents/eas-build-fix-hermes-bundle-id`. Geen conflicten gecreëerd; `ballspot` en `ballspot-integration` zijn niet vermengd. |
| Testomgevingen | Nieuwe, geïsoleerde stack (§2). Bestaande processen ongemoeid gelaten: Metro :8081 (Clubs-worktree), php :8091 (polish-qa SQLite), staging-Docker-stack (web :8088, db :33070, mailpit :8025, image van 19 okt). Eén uitzondering: de verouderde Metro :8082 van 24 okt (zelfde worktree, wees naar API :8091) is vervangen door een eigen Metro :8082 (§2, root-cause). |
| Simulators | iOS 26.5: "BP Home — iPhone 17" (EFC56651), "BP Home — iPhone SE" (9B71D8AB), + 17 Pro Max, SE 3 (audit), iPads. Android: emulator `Medium_Phone_API_37.0` (Android 17, API 37, arm64) draaide al (staging-app van een andere sessie). |
| Backend-DB/testconfig | PHPUnit op SQLite in-memory; E2E op MariaDB 11.4 (container). Prod draait PHP 8.3, lokaal PHP 8.5.11 (verschil genoteerd). |
| Expo/EAS | Expo SDK 56.0.23, RN 0.85.3; `eas whoami` ingelogd; `eas build:list` (read-only): iOS 1.0.2 builds 8 en 9 FINISHED (1 nov), Android 1.0.2 build 6 FINISHED. `app.json`: version 1.0.2, ios buildNumber 35, android versionCode 3, `googleServicesFile` ./google-services.json (project `ballpicker-sports-2026`, package `be.vanmalderstudio.ballpicker`). |
| Tooling | Maestro 2.11.0, Docker, adb 1.0.41, JDK 21/27 (+ lokaal gedownloade Temurin 17 voor de Android-build), geen Detox. |
| Productie-veiligheid | Alleen GET op publieke endpoints en `/api/health`, `/api/config`. Geen account aangemaakt op prod, geen SSH, geen writes. |

Incidenten tijdens de audit (geen app-defecten): de iOS-simulators werden drie keer van buiten deze sessie afgesloten (18:50, 18:58, 19:10) — telkens opnieuw gestart; CoreSimulator-crashes zijn bij een parallelle sessie eerder gemeld.

---

## 2. Testinfrastructuur

Alles staat in de QA-kit `/Users/FOTOTHEEK/Documents/ballspot-e2e-qa/` (buiten de repo): `run.sh`, `switch-metro.sh`, `maestro/*.yaml`, `shots/` (iOS), `shots-android/`, `runs/` (Maestro-artefacten per run incl. `commands.json`, `maestro.log`, hiërarchie en faal-screenshots), `api/` (API-resultaten + ruwe responses, chmod 600), `clubs/` (Clubs-resultaten + ruwe responses), `logs/` (backend/Metro/Gradle/suites), `NOTES.md` (feitenlog), `.env-e2e` / `.env-clubs` (throwaway-omgevingen, chmod 600).

| Component | Instelling |
|---|---|
| Database | Docker `bp-e2e-mariadb` (mariadb:11.4) op 127.0.0.1:33094; databases `ballpicker_e2e` (master) en `ballpicker_clubs_e2e` (Clubs). Gemigreerd + geseed (`db:seed`: sports, categories, 6 demo-challenges, daily van vandaag, 42 badges, dev-admin). |
| Backend master | `php artisan serve` 127.0.0.1:8093 vanuit `../ballspot-integration/backend`, env via exports (MAIL=log, CACHE=database, QUEUE=sync, `BALLPICKER_AUTO_ACTIVATE_DAILIES=true`, periode = default `monthly`). Mail (verificatiecodes) in `storage/logs/laravel.log`. |
| Backend Clubs | `php artisan serve` 127.0.0.1:8094 vanuit `../ballspot-staging/backend` (composer install gedaan), `BALLSPOT_CLUBS_ENABLED=true`, `BALLPICKER_AI_IMAGE_ENABLED=true`, provider `local` (gratis GD-patch), runner handmatig (`ballspot:clubs:process-photo-edits`), beta-gate uit. |
| Mobiel iOS | Debug-shell `BallPicker.app` (build 33-shell uit `../ballspot-notif-qa`, RCTMetroPort 8082) op de twee BP Home-simulators; JS live van Metro :8082 uit `../ballspot-integration/mobile` met `EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8093/api` (bundle geverifieerd: bevat exact die URL). Geen release-IPA: dit is een echte native build met de huidige JS. |
| Mobiel Android | Debug-APK lokaal gebouwd (`mobile/android` prebuild, Gradle 9.3.1, JDK 17-toolchain) → 209 MB `app-debug.apk`, geïnstalleerd als `be.vanmalderstudio.ballpicker` naast de staging-app; `adb reverse 8082/8093`; dev-server pref `debug_http_host=localhost:8082`. |
| Accounts (allemaal lokaal, wegwerp) | master: `e2e.player.{a,b,c,d,e,f,ui}@e2e.ballspot.local` + 1 junk; Clubs: `e2e.club.{cadmin,m1,m2}@…` + 1 outsider; dev-admin `admin@ballspot.local` (door de agents in de test-DB op geverifieerd gezet, zie P3). |
| Metro root-cause | De NSUserDefaults-override `RCT_jsLocation` werd door RN niet gehonoreerd → de app laadde tot 18:45 de JS van de oude Metro :8082 (API :8091, polish-qa) en alle simulatorresultaten vóór dat moment waren ongeldig. Bewijs: `~/Documents/ballspot-polish-qa/metro-integration.log` kreeg vandaag "[api] POST /login -> 422 invalid_credentials". Opgelost door Metro :8082 zelf te starten; daarna zijn alle flows opnieuw gedraaid. |

Maestro-lessen (voor volgende sessies): login-velden selecteren via `below: "Email"` / `below: "Password"` (puntcoördinaten verschuiven door foutbanners); `hideKeyboard` faalt op iOS; op de iPhone SE krijgt het secure wachtwoordveld geen invoer (bekende beperking) → keychain van de ingelogde iPhone 17 gekopieerd; Android `inputText` stopt bij "@" → login via `adb shell input`. Het demo-seed gebruikt SVG-balfoto's die de app niet rendert ("Image unavailable") → challenge 1 kreeg in de test-DB een gegenereerde JPG.

---

## 3. Unit- en statische tests (geen E2E)

| Suite | Resultaat | Bewijs |
|---|---|---|
| Backend PHPUnit (SQLite in-memory, `vendor/bin/phpunit`) | 1300 tests, 8893 assertions, OK, 2 skipped, 1 PHPUnit-deprecation | `logs/phpunit-master.log` |
| Mobile Jest | 39 suites, 584 tests, alles geslaagd | `logs/jest-master.log` |
| TypeScript `tsc --noEmit` | schoon | `logs/tsc-master.log` |
| Clubs-branch suites | NOT TESTED door mij (andere sessie vandaag: backend 1769 groen, Photos/API op MariaDB 85/85, jest 705 — zie docs/clubs-ai-ball-removal.md) | — |

---

## 4. Authenticatie en accounts

Omgeving: stack §2, `api` = curl + MariaDB-query; `sim-e2e` = Maestro/adb op simulator/emulator.

| # | Scenario | Niveau | Status | Resultaat / bewijs |
|---|---|---|---|---|
| A1 | Registratie | api + sim-e2e(UI, zie A9) | PASS | `POST /register` → 201, users-rij, `email_verification_codes`-rij, code gemaild (log). 7 accounts aangemaakt. |
| A2 | E-mailverificatie | api | PASS | juiste code → 200 `email_verified:true`; foute code → 422 "Invalid or expired verification code"; resend → 200 + nieuwe code; ongeverifieerd: `/daily/today` en `/daily/stats` → 403 "Your email address is not verified", `/me` → 200. |
| A3 | Login / foutieve gegevens | api + sim-e2e | PASS | juiste → 200 + token (`personal_access_tokens`); fout wachtwoord → 422 `invalid_credentials` (UI: "Invalid email or password."); lege velden (UI): "Please enter your email and password." |
| A4 | Logout | api | PASS | `POST /logout` verwijdert alleen dat token (DB), `GET /me` daarna 401, andere tokens van dezelfde user blijven werken. UI-logout: zie A10. |
| A5 | Sessieherstel | sim-e2e | PASS | App afsluiten en herstarten → direct Home met "Completed today · 1 day streak" (iOS `i17-10-home-restored.png`, Android `and-09-relaunch-home.png`). |
| A6 | Tokenverval / ongeldig token | api + sim-e2e | PASS | garbage/forged/ontbrekende bearer → 401 `session_invalid`; app met verouderd keychain-token toont "Your session has expired. Please log in again." (`shots/00-boot-ip17-c.png`). Sanctum-expiration 129600 min (90 d) per config — verlopen na tijd niet gesimuleerd. |
| A7 | Wachtwoordherstel | api | PASS | `forgot-password` → 200 + reset-token in mail; bogus token 422; echte → 200, alle sessies ingetrokken (0 tokens), oud wachtwoord 422, nieuw 200, token single-use. |
| A8 | Gebruikersisolatie | api | PASS | zie §10. |
| A9 | Registratie via de UI | sim-e2e | PASS (formulier, toetsenbord, client-validatie) / BLOCKED (verzending, test-tooling) | iPhone 17: Create Account-scherm, naam/gebruikersnaam/e-mail getypt, toetsenbord laat de velden zichtbaar en het formulier scrolt (`i17-20…24`, `i17-29`); client-validatie "Please confirm your age and agree to the Terms…" en "Passwords do not match." zichtbaar. Maestro typt onbetrouwbaar in de secure wachtwoordvelden (14 vs 15 tekens) en raakt de custom checkbox niet → verzending naar de backend niet via de UI afgerond (API-registratie A1 = PASS). Geen app-defect gezien. |
| A10 | Logout via de UI | sim-e2e | PASS | Profiel → Settings → "Log out" (geen bevestigingsdialoog) → loginscherm (`i17-30…32`); DB: 0 tokens voor de gebruiker na logout. |
| A11 | Verlopen/ingetrokken token via de UI | sim-e2e | PASS | `personal_access_tokens` van c verwijderd → herstart → loginscherm met "Your session has expired. Please log in again." (`i17-40-session-expired.png`). |
| A12 | Backend onbereikbaar (offline/time-out) | sim-e2e | PASS | ingelogd, backend gestopt, herstart → volledig scherm "📡 Couldn't reach BallPicker — Check your connection and try again, or log out and sign in later." met Retry / Log out, geen crash, geen verouderde data (`i17-50/51`). |

---

## 5. Daily Challenge — kritieke flow

| # | Scenario | Niveau | Status | Resultaat / bewijs |
|---|---|---|---|---|
| D1 | Daily ophalen | api | PASS | `GET /daily/today` → `has_daily`, `daily_challenge{id, challenge_date, daily_month_index 4/30, challenge{title, difficulty, hidden_image_url, category, sport}}`. |
| D2 | Verborgen balpositie blijft geheim | api | PASS | key-dump van de pre-guess-payload bevat **geen** `ball_x_ratio`/`ball_y_ratio`/origineel; ook `/packs/{slug}` toont alleen verborgen afbeeldingen. |
| D3 | Foto weergeven | sim-e2e | PASS (met seed-kanttekening) | JPG wordt getoond op iOS en Android (`i17-02-daily-before.png`, `and-05-daily.png`). SVG-demo-seed → "Image unavailable" (alleen dev-seed; productie uploadt JPG/PNG). |
| D4 | Balpositie aanduiden + inzending | sim-e2e | PASS | iOS: tik in foto → "Submit Guess" → `POST /daily/1/guess` → DB-rij user 4 guess 0.1297/0.8115 score 90. Android: user 3 guess 0.1205/0.8447 score 99. |
| D5 | Score berekenen | api + sim-e2e | PASS | exacte gok (0.12/0.85) → 100, distance 0 (agent); 0.20/0.80 → 76, distance 0.0943; geïnjecteerde `score`/`distance`/`ball_*` genegeerd (DB = serverwaarde). |
| D6 | Resultaat + onthulling | sim-e2e | PASS | "YOUR SCORE 90 · Perfect spot! · 4.0% away · Very close · 🔥 1 day streak", balmarker op 12%/85%, "Your guess 13%, 81%", "You beat 25% of players #3 of 4" (`i17-04…05b`). Android: "99 · 0.5% away · Right on it!", "RANK UP! You reached Amateur", "+299 XP", "New badges unlocked" (`and-07`, `and-08`). P3-copy: "Perfect spot!" ook bij 90 punten. |
| D7 | XP bijwerken | api + sim-e2e | PASS | `xp_events`: `daily_guess` 90 + 4 × `badge_unlock` 100; `rank_progress.xp_gained` in de response; profiel toont 490 XP / "2,010 XP para Amateur" (ES). |
| D8 | Streak bijwerken | api + sim-e2e | PASS | `/daily/stats` current_streak 1, best 1; UI "1 day streak" op resultaat, Home en Statistieken. |
| D9 | Klassement bijwerken | api + sim-e2e | PASS | zie §6. |
| D10 | Tweede inzending blokkeren | api | PASS | tweede `POST /guess` → 422 "You have already played today's challenge."; 6 parallelle POSTs → exact 1 rij + 1 XP-event (unieke index + `UniqueConstraintViolationException`). UI toont na spelen "View Today's Result" i.p.v. "Play". |
| D11 | Resultaat opnieuw openen | sim-e2e | PASS | Home → "View Today's Result" → zelfde gegevens (`i17-11-result-reopened.png`). |
| D12 | Historische resultaten | sim-e2e | PASS (beperkt) | Profiel → Daily Challenge-tab: 1 day streak, Played 1, avg 90, best 90 (`i17-17-daily-history.png`). Slechts één speeldag in de test-DB. |
| D13 | Nieuwe Daily na de dagwissel | api (tijdprobe) + config | PASS (gedrag bevestigd) / NOT TESTED (echte dagwissel) | `today()` = UTC-datum; `BALLPICKER_AUTO_ACTIVATE_DAILIES=true` + scheduler 00:05/00:10 (app-tijdzone). Geen echte dagwissel afgewacht. |
| D14 | Tijdzonegrenzen België, zomer-/wintertijd | api (Carbon-probe via tinker) | PASS (gemeten) — zie bevinding | `config/app.php` timezone = `UTC` (hardcoded), `competition.timezone` null → UTC. Probe: Belgisch 2026-11-01 00:30 CET = 2026-10-31 23:30 UTC → `today()` = 31 oktober en periode 1–31 oktober; nieuwe dag/maand pas om 01:00 CET (02:00 CEST in de zomer; DST-dagen 29 maart / 25 oktober gedragen zich consistent in UTC). De Daily wisselt dus niet om Belgische middernacht. Dit is consistent tussen backend, scheduler en app (de app toont de servercountdown), maar is een productkeuze die bevestigd moet worden (P2 als het wél middernacht moest zijn). |

---

## 6. Monthly Leaderboard — release blocker

Contractcontrole over de hele keten (HTTP-body ↔ databasequery ↔ scherm), lokaal:

| Bron | period_type | period_label | period_start | period_end | week_start/week_end | Rangen |
|---|---|---|---|---|---|---|
| CLI (`CompetitionPeriodService`, tinker) | monthly | Monthly | 2026-11-01 | 2026-11-30 | — | — |
| `GET /daily/stats` (user a, c) | monthly | Monthly | 2026-11-01 | 2026-11-30 | — | `weekly_rank` 1 → later 3 (c) |
| `GET /daily/leaderboard/weekly` | monthly | Monthly | 2026-11-01 | 2026-11-30 | 2026-11-01 / 2026-11-30 (BC-sleutels spiegelen de periode) | e 100, b 99, f 93, c 90, a 76 |
| SQL `sum(score) … where challenge_date between period_start and period_end group by user_id` | — | — | — | — | — | identiek (100/99/93/90/76) |
| `ballspot:competition-period-audit --user=2 --yes` (echte HTTP naar 127.0.0.1:8093) | monthly = CLI | Monthly | 2026-11-01 | 2026-11-30 | = periode | totalen = DB |
| iOS-scherm (`i17-13-leaderboard.png`) | "Monthly Leaderboard" | "Monthly · 1 Nov – 30 Nov" | — | — | — | "#3 of 4 · 90 pts this month", rijen e/f/c/a |
| Android-scherm (`and-13-leaderboard.png`) | "Monthly Leaderboard" | "Monthly · 1 Nov – 30 Nov" | — | — | — | "#2 of 5 · 99 pts this month", rijen e/b/f/c/a |
| Resultaatscherm-CTA | "View Monthly Leaderboard" (iOS + Android), NL "Bekijk het maandklassement" | | | | | |
| Admin → Settings Runtime-kaart (webproces) | "Competition period (served to the app): monthly", config monthly, venster 2026-11-01 → 2026-11-30 (UTC) | | | | | |

| # | Scenario | Niveau | Status | Opmerking |
|---|---|---|---|---|
| M1 | Actuele periode + `period_type/start/end` | api | PASS | tabel hierboven |
| M2 | Scoreselectie binnen de periode = DB | api | PASS | SQL identiek aan API-totalen |
| M3 | Gebruikersrang / leaderboardpositie | api + sim-e2e | PASS | `weekly_rank` = rang in de lijst = scherm |
| M4 | App volgt de API (geen hardcoded label) | sim-e2e + unit | PASS | `competitionPeriod.ts` is pass-through (hardcode op 2026-11-04 teruggedraaid); unit-test `competitionPeriodApiChain.test.ts` bewijst "Weekly" alleen als de body weekly zegt. Een echte `weekly`-run van de backend is NOT TESTED (zou een herstart van :8093 vergen terwijl de agents liepen). |
| M5 | Historische periodes | api | BLOCKED | geen afgesloten periode in de test-DB (`competition_finishes` leeg, `/me/competition-finishes` → `[]`). |
| M6 | Trophy Room | sim-e2e | PASS | 4/43 earned = `user_badges` (`i17-15-trophy-room.png`); "Competition Top 10"-badge (hernoemd) zichtbaar. |
| M7 | Alle vijf talen | sim-e2e | PASS (NL/FR/DE/ES/EN schermen) — periode-CTA per taal: zie §11 (languages3) | |
| M8 | Productie: served period_type | prod | BLOCKED | `/daily/stats` vereist een token; er is bewust geen account op productie aangemaakt. De audit-command is niet gedeployed. De gebruiker meldde eerder dat .env, config-cache, tinker en de service "monthly" zeggen; het app-gedrag is daarmee consistent (M4). Aanbeveling: na deploy `php artisan ballspot:competition-period-audit --user=<qa-id> --yes` op de server draaien. |

---

## 7. Competities, toernooien en Clubs

### 7.1 Toernooien (leagues) — api, master

| Scenario | Status | Bewijs (api/api-results.md §7) |
|---|---|---|
| Aanmaken (422 zolang < 7 geschikte challenges; daarna 201, 6-tekens code, `rounds_per_day` → 1) | PASS | league 1 |
| Deelnamecodes / rechten (niet-lid 403 ×6, foute code 404/422, join idempotent, eigenaar-only remove/start/cancel, 1 actief toernooi per host) | PASS | |
| Gelijktijdige joins (e + f op hetzelfde moment) | PASS | 200/200, precies één `league_members`-rij per user |
| Start → 7 rondes zonder herhaling; guess 201 (score berekend, geïnjecteerde score genegeerd), dubbel 422, niet-lid 403, daglimiet 422 | PASS | `league_rounds` 7, `guesses` 2 |
| Leaderboard = SQL | PASS | b 100, a 30 |
| Annuleren (`DELETE` door eigenaar) → cancelled, leden zien hem niet meer, guess 422 | PASS | P3: ook mogelijk met al ingediende guesses |
| Deelnemerslimiet (8) en einduitslag/`tournament_finishes` | BLOCKED | te weinig accounts (rate-limit) resp. een 7-daags toernooi kan niet binnen de sessie eindigen |
| UI toernooien (aanmaken/joinen op het scherm) | NOT TESTED | prioriteit lag bij Daily/Monthly/Clubs |

### 7.2 Clubs — api, `feature/ballpicker-clubs` @ 7debb4c (clubs/clubs-results.md)

| Stap | Status | Kernbewijs |
|---|---|---|
| 1 Platform-admin maakt club "E2E FC", code, clubpack (web-admin, CSRF-sessie) | PASS | `clubs` 1, `club_invite_codes` 1 (member/10), pack 1 + audit-rijen |
| 2 Toetreden met code (cadmin, m1 met kleine letters + streep, m2), re-join `joined:false`, foute code 422 `club_code_invalid` ×2 + tellers, niet-lid 404 op 8 routes | PASS | `club_members` 3, `uses_count` 3 |
| 3 Clubadminrechten: admin maakt/lijst codes (201/200), ledenlijst; rolwijziging en admin-code owner-only (403); m1 (gewoon lid) 403 `club_forbidden` op 14/14 adminacties | PASS | |
| 4 Foto's: upload 201 (rights verplicht, duplicaat 422), alleen eigen inzendingen zichtbaar (m2 ziet niets, 403 op detail), admin approve → `in_preparation`, balpositie (validatie), edit 202 (unieke `active_key`, dubbele tik → 200 zelfde id), `process-photo-edits` → processed, signed URL's (ongeldig/vervallen 403), pixelvergelijking (alleen binnen het masker gewijzigd, buiten masker Δ 7.84 ≤ JPEG-baseline 9.97), attempt afkeuren (bestand verwijderd) → tweede poging → goedkeuren → convert 201 → challenge 7 (`usage_pool club`, bal 0.500/0.550 = gemarkeerde plek) toegevoegd aan het pack; afkeuren met reden, intrekken door lid, lid-kan-niet-goedkeuren | PASS | 0 OpenAI-aanroepen (log) |
| 5 Clubpack spelen door m2 (reveal op gemarkeerde plek), clubleaderboard #1; competitie (single_attempt, normal play blocked → 409), m1 158 / m2 200, 2e poging 422, finalize → `club_competition_finishes`, standings, void | PASS | |
| 6 Isolatie: buitenstaander 404 op 22 routes incl. upload/pack; andermans pending foto's verborgen; signed-URL TTL 60 min | PASS | |
| 7 Concurrency: gelijktijdige joins (1 rij elk), gelijktijdige edit-starts (1 actieve), parallelle processors (1× verwerkt) | PASS | |
| 8 Feature-flag uit | BLOCKED (code gelezen) | `feature:clubs` → 404 `feature_disabled` (API + portal), admin bewust niet gegated |

### 7.3 Clubs — mobiele UI (iPhone 17, Metro 8082 → Clubs-checkout, API 8094)

| Scenario | Status | Bewijs |
|---|---|---|
| Toetreden met code via de UI (m2, eerder via API uit de club gestapt) | PASS | Clubs-tab → "+ Join" → "Join a club" → code → "Join club" → "Welcome! You joined E2E FC." → clubdetail "E2E FC · Gent, BE · 5 members · Member", "No competition running", Packs "E2E FC pack ✓ Completed · 200 pts", "Upcoming & finished competitions 0 upcoming · 1 finished" (`i17-c01…c06`). DB: `club_members` user 4 → active, `uses_count` 6 → 7. |
| Clubslijst en clubdetail als eigenaar (cadmin) | PASS | "E2E FC · Owner · Gent · 1 pack"; detail "5 members · Owner", pack "2 challenges"; actiemenu "Club actions: Club leaderboard / Members / Invite codes / Submit photos / Report club / Leave club / Cancel" (`i17-a00…a01b`). |
| Foto-beoordelingsscherm (lijst, balpositie, lokale verwijdering, vergelijken, goed-/afkeuren) in de app | NOT TESTED (tooling) | het native actiemenu is door Maestro niet aan te tikken; de volledige flow is via de API bewezen (§7.2). De UI-checklist van ~/Documents/ballspot-clubs-photo-qa/UI-CHECKLIST.md blijft open. |
| Gewone leden zonder adminacties (UI) | PASS (indirect) | lid m2 ziet alleen packs/competities; adminroutes 403 via API (§7.2 stap 3). |

---

## 8. Pushnotificaties en reminders

| # | Scenario | Niveau | Status | Resultaat |
|---|---|---|---|---|
| N1 | iOS-permissie-uitnodiging | sim-e2e | PASS (UI) | In-app uitnodiging na eerste login (`i17-00-notif-invite.png`, "Later" werkt); Settings-kaart "Notifications on this device · Not enabled yet · Allow notifications" (NL/FR/DE). Het OS-dialoog zelf is in deze run niet geaccepteerd (eerder op 1 nov wel, zie memory/notif-qa). |
| N2 | Android-permissie | sim-e2e | NOT TESTED | de uitnodiging verscheen niet voor account b op Android (al eerder per account afgehandeld?) — niet verder onderzocht. |
| N3 | Expo-pushtokenregistratie (API) | api | PASS | `POST /me/push-tokens` 201 → `push_tokens`-rij; ongeldig token/platform 422; `DELETE` verwijdert. P3: hetzelfde token door een andere user → rij wordt stil overgenomen. |
| N4 | Echte Expo-token op toestel | device | BLOCKED | geen fysiek toestel; simulators/emulator leveren geen Expo-token. |
| N5 | Firebase/FCM V1-configuratie | config | PASS (statisch) | `google-services.json` aanwezig (project `ballpicker-sports-2026`, package klopt), `android.googleServicesFile` gezet; FCM V1-sleutel in EAS volgens memory 2026-11-03 (niet opnieuw opgevraagd). Levering NOT TESTED. |
| N6 | APNs-configuratie | config | PASS (statisch, uit memory 2026-11-03) | APNs-key op de prod-bundle, build 9 met `aps-environment=production`. Levering NOT TESTED. |
| N7 | Tokenopslag backend | api | PASS | zie N3. |
| N8 | Expo-tickets en receipts | unit + api | PASS (unit) / NOT TESTED (echt) | `push_receipts`-tabel + `ballspot:check-push-receipts` (PHPUnit `PushDeliveryTest`); `ballspot:push-audit` draait (read-only). Geen echte push verstuurd (bewust: nooit naar andere gebruikers). |
| N9 | Daily-reminder na gespeelde Daily | unit | PASS (unit) | `reminderPlan.test.ts` (7 gedateerde lokale triggers, herplannen na spelen); serverreminders per account alleen met token (`daily_reminder_push_active` false op deze stack omdat de flag uit staat). `ballspot:send-daily-reminders` niet gedraaid (zou Expo aanroepen). |
| N10 | Planning komende 7 dagen | unit | PASS (unit) | idem. Niet op een toestel geverifieerd. |
| N11 | Notificatie-instellingen | api + ui | PASS | GET/PUT `/me/notification-settings` (DB-rij, 422 op ongeldige tijd), Settings-scherm toont reminders-toggle en tijd. |
| N12 | Gedrag open/achtergrond/afgesloten | device | BLOCKED | vereist een fysiek toestel met echte pushes. |
| N13 | Android-notificatie-icoon/kleur | config | P3 | `expo-notifications`-plugin zonder icon/color-configuratie in app.json (standaardicoon). |

Conclusie: pushmeldingen zijn end-to-end **niet bewezen** (geen toestel). Blokkerend voor de publieke release, niet voor TestFlight/interne test.

---

## 9. Packs, XP en gebruikersprofiel (api + sim-e2e)

| Scenario | Status | Bewijs |
|---|---|---|
| Packs: lijst, detail (alleen verborgen afbeeldingen), start (idempotent), attempt, guess per challenge (score berekend, injectie genegeerd), replay 409/no new row, voltooiing 133 pt → `completion_xp` 250 + 2 badges, `xp_gained` 633; andere user 403; verkeerde challenge 422 | PASS | pack via admin aangemaakt (seed had geen packs); geen ontgrendel-/aankooplogica aanwezig |
| XP-ledger, badges, ranks, profielstatistieken, `/me/rank` | PASS | 490 XP (c) zichtbaar in profiel; rank-up naar Amateur op Android bij 2 699 XP |
| Delen: eenmalige beloning | PASS | `POST /share` #1 → 310 XP (first 200 + daily 10 + badge 100), #2 → 0 XP; precies één `share_first`/`share_daily`-event in DB; UI-CTA "+225 XP: +200 first share · +10 daily share · +15 high score" (de share-sheet zelf is op de simulator niet geopend). |
| Badges dubbel claimen | PASS | unieke `user_badges`, First Share badge één keer |
| Profiel/Trophy Room/Statistieken/Historie (UI) | PASS | `i17-14…18`, `and-14-profile.png` |
| Vrienden (incidenteel) | PASS | vriendschap a↔b via API (403 voor derden), Android Friends-scherm toont de vriend en de friend code (`and-16-settings-2.png`). |

---

## 10. Beveiliging en betrouwbaarheid (api, lokaal; geen destructieve tests tegen productie)

| Scenario | Status | Bewijs |
|---|---|---|
| Ongeautoriseerde API-requests | PASS | 401 `session_invalid` zonder/with garbage/forged token; prod: `/api/daily/today`, `/api/me` → 401 |
| IDOR / isolatie | PASS | b krijgt 404 op a's result, publiek profiel zonder e-mail, preference-tampering (`user_id`, `is_admin`, `email`) genegeerd, friend-request accept door derden 403, league-endpoints niet-lid 403 ×6; Clubs 22 routes 404 voor buitenstaanders |
| Adminrechten | PASS | bearer-token niet geldig voor web-admin; normale user `not_admin`; admin-pagina's 200 na login; Runtime-kaart leest monthly |
| Scoremanipulatie | PASS | out-of-range/non-numeric 422; geïnjecteerde velden genegeerd (Daily, packs, toernooien) |
| Dubbele inzendingen / gelijktijdigheid | PASS | 6 parallelle guesses → 1 rij; gelijktijdige league-joins; Clubs-joins/edits/processors |
| Rate limiting | PASS | login 5/min per e-mail+IP → 429 `Retry-After 58`; register 5 successen/uur → 429 `retry_after 3442` + 5 req/min; honeypot niet getriggerd; andere accounts blijven werken |
| Ongeldige uploads | PASS | tekst/fake PNG 422 (content-sniffing), 3.6 MB 422, 9.7 MB 413, 8×8 PNG 200 + bestand + DELETE verwijdert; Clubs: rights verplicht, duplicaat 422 |
| API-timeouts / verbroken verbinding (UI) | PASS | A12: foutscherm met Retry/Log out bij onbereikbare backend; geen crash |
| Gelijktijdige DB-wijzigingen | PASS | zie concurrency hierboven; `push_receipts`-migratie eerder op MySQL 8.4 + MariaDB 11.4 geverifieerd (memory 2026-11-03) |
| Foutieve/verouderde tokens | PASS | A6 |
| Malformed JSON | P3 | wordt als lege body behandeld (PUT 200 no-op) i.p.v. 400 |
| Productie-headers/TLS | P2 | alleen `Referrer-Policy`; geen HSTS, `X-Content-Type-Options`, `X-Frame-Options`, CSP; `Server: nginx/1.24.0 (Ubuntu)`; Let's Encrypt-certificaat geldig tot 2026-12-28 |

---

## 11. Mobiele gebruikservaring

Schermopnames: iOS `shots/i17-*.png` (iPhone 17, 1206×2622), `shots/se-*.png` (iPhone SE, 750×1334), Android `shots-android/and-*.png` (1080×2400, Android 17).

| Controle | iPhone 17 | iPhone SE | Android | Opmerking |
|---|---|---|---|---|
| Login, eerste login (sportkeuze), uitnodiging notificaties | PASS | keychain-herstel (zie §2) | PASS (adb-invoer) | Android: geen uitnodiging gezien voor account b |
| Home, Daily, resultaat, klassement, profiel, trofeeën, statistieken, historie | PASS | PASS voor Home, resultaat, CTA, maandklassement (`se-10…13`); profiel/settings niet (sessiekopie mislukte de 2e keer) | PASS (Home/Daily/resultaat/klassement/profiel/vrienden) | |
| Scrollen / knoppen buiten beeld | PASS | PASS (resultaat + klassement, tabbar zichtbaar, niets afgesneden) | PASS | resultaat-CTA's bereikbaar na scrollen |
| Toetsenbord over invoervelden | PASS (login én registratie: velden boven het toetsenbord, formulier scrolt mee, `i17-21/22`) | — | PASS (login) | |
| Loading/empty/error-states | Loading: "Loading daily challenge…"; empty: "No finished tournaments yet.", "No competition running"; error: "Couldn't reach BallPicker" + Retry (`i17-50`), "Image unavailable" bij onlaadbare foto, validatiefouten op login/registratie | | | |
| Navigatie en terugknoppen | PASS | | **P3**: "‹ Home" plakt zonder spatie aan de titel ("‹ HomeDaily Result") | |
| Donker/licht thema | n.v.t. (`userInterfaceStyle: dark`; app heeft 5 in-app kleurthema's, zichtbaar in Settings) | | | |
| Vijf talen | PASS: NL/FR/DE/ES/EN op Settings, Home, Profiel (`i17-l-*`) én resultaat + CTA + maandklassement (`i17-p-*`): NL "Maandklassement · Maandelijks · 1 nov – 30 nov · #4 van 5 · 90 ptn deze maand · Bekijk het maandklassement", DE "Tagesergebnis · Monatsrangliste ansehen · Du hast 20% der Spieler geschlagen", FR "Paramètres…", ES "Perfil · Sala de trofeos…"; tab-bar vertaald | | | P3: ES "Racha de 1 días" (meervoud bij 1) |
| Teksten buiten kaarten | geen gezien | geen gezien | geen gezien | |
| Afbeeldingen/aspectratio | PASS (1600×1200 foto schaalt correct, marker op juiste plek) | | PASS | |
| Statusbalk/safe area | PASS | | **P2**: Home-header (wordmark + level-ring) onder de statusbalk/klok (`and-04`, `and-09`) | |
| App hervatten na achtergrond | PASS (herstart → sessieherstel) | | PASS | echte achtergrond/voorgrond-cyclus met countdown is eerder (20 okt) getest |

### 11.1 Maestro-flows (QA-kit `maestro/`) en uitkomst

| Flow | Toestel | Uitkomst |
|---|---|---|
| daily-play | iPhone 17, Android (adb-variant) | PASS |
| after-play | iPhone 17, SE (t/m klassement) | PASS |
| languages / languages2 / languages3 | iPhone 17 | PASS (15 + 15 schermen) |
| session-expired / logout / offline | iPhone 17 | PASS |
| register-ui (+ register-ui2) | iPhone 17 | formulier/validatie PASS, verzending BLOCKED (tooling) |
| clubs-join | iPhone 17 (Clubs-bundle) | PASS |
| clubs-admin-photos | iPhone 17 (Clubs-bundle) | lijst/detail/menu PASS, fotobeoordeling NOT TESTED (native sheet) |
| se-profile | SE | NOT RUN (sessiekopie mislukt) |

---

## 12. Bevindingen (classificatie)

| Sev | Gebied | Bevinding | Bewijs / actie |
|---|---|---|---|
| P2 | Android UI | Home-header respecteert de statusbalk-inset niet: wordmark en level-ring staan onder klok/batterij. | `shots-android/and-04-after-sport.png`, `and-09`. Fix: SafeAreaView/`useSafeAreaInsets` top-padding op Home (Android edge-to-edge). |
| P2 | Productie-hardening | Geen HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options`/CSP; `Server`-header lekt nginx-versie. | `curl -I https://ballpicker.vanmalderstudio.be/`. Fix in nginx-config (`add_header …`, `server_tokens off`). |
| P2 (te bevestigen) | Product/tijdzone | Daggrens en maandgrens lopen op UTC (`app.timezone` = UTC, hardcoded): nieuwe Daily en nieuwe maand pas om 01:00 CET / 02:00 CEST. Technisch consistent, maar wijkt af van "Belgische middernacht". | tinker-probe §5 D14. Als Belgische middernacht gewenst is: `APP_TIMEZONE`/`BALLPICKER_COMPETITION_TIMEZONE=Europe/Brussels` + hertest van scheduler, streaks en `isToday()`. |
| P3 | Android UI | Stack-header: terugknop "‹ Home" zonder spatie tegen de titel. | `and-05-daily.png`, `and-07-result.png` |
| P3 | Copy | Resultaatkop "Perfect spot!" (NL "Perfecte plek!") bij 90 punten / 4% afstand. | `i17-04-result-top.png` |
| P3 | i18n | ES "Racha de 1 días" (meervoud bij 1 dag). | `i17-l-es-5-profile.png` |
| P3 | Seed/dev | Dev-admin wordt ongeverifieerd geseed terwijl `/admin/login` dat weigert; demo-challenges gebruiken SVG's die de app niet toont. | DatabaseSeeder/ChallengeSeeder; controleer op prod dat de admin `email_verified_at` heeft. |
| P3 | API | Malformed JSON → lege body (PUT 200 no-op) i.p.v. 400. | api-results §11 |
| P3 | API | Upload > `post_max_size` → 413 met PHP-waarschuwing in de body (dev-server); 2–8 MB geeft "failed to upload" i.p.v. de vriendelijke 2 MB-melding; validatie zegt "photo" voor `avatar`. | api-results §11; prod: `display_errors=Off`, limieten afstemmen |
| P3 | Push | Pushtoken van een andere user wordt bij registratie stil overgenomen (`updateOrCreate` op token). | api-results §9 |
| P3 | Toernooien | Eigenaar kan een actief toernooi met guesses annuleren; `/rounds/{id}/result` zonder lidmaatschapscheck (404, geen lek). | api-results §7 |
| P3 | Rate-limit copy | Uurcap zegt "wait a bit" bij `retry_after` 3442 s. | api-results §5 |
| P3 | Enumeratie | Sequentiële user-id's via `/users/{id}/public-profile` en friend-requests (geen PII). | api-results §2 |
| P3 | Clubs | Niet-lid krijgt 422 (validatie) i.p.v. 404 op competition-attempts zonder `attempt_number` (bestaansbevestiging). | clubs-results D1 |
| P3 | Clubs | `ClubJoinFailureCounter::clear()` wordt nooit aangeroepen. | clubs-results D2 |
| P3 | Push/Android | `expo-notifications` zonder icon/color-config. | app.json |
| P3 | Build-tooling | `mobile/android` prebuild (28 okt) vereist JDK 17-toolchain + daemon-JVM 25 (auto-provisioning) → lokaal bouwen faalt out-of-the-box; EAS-builds slagen wel. | logs/android-assembleDebug-*.log |

---

## 13. Niet getest / geblokkeerd (samenvatting)

- Echte pushlevering (APNs/FCM), tickets/receipts met echte tokens, gedrag open/achtergrond/afgesloten, Android-permissiedialoog — **BLOCKED** (geen fysiek toestel; bewust geen pushes verstuurd).
- Productie-maandklassement via een geauthenticeerde call — **BLOCKED** (geen prod-testaccount; audit-command niet gedeployed).
- Historische competitieperiodes, toernooi-einduitslag, 8-spelerslimiet — **BLOCKED** (geen afgesloten periode/toernooi in één sessie).
- Backend in `weekly`-modus tegen de app — **NOT TESTED** (zou de draaiende stack onderbreken; unit-test dekt de app-kant).
- Toernooien via de UI, share-sheet, avatar-upload via de UI, 2FA-login (uit per config), echte dagwissel, UI-registratie t/m verificatie (tooling), Clubs-fotobeoordeling in de app, profiel/settings op de SE — **NOT TESTED**.
- Clubs: feature-flag uit, OpenAI-provider, webportaal-UI, fysiek toestel — **BLOCKED/NOT TESTED** (geen betaalde aanroep toegestaan).
- Clubs-branch unit-suites — door mij niet herhaald (zie §3).

---

## 14. Aanbevolen volgorde naar productie

1. Deploy van 08a521a (plan: docs/release-combined-test-2026-11.md), backup, deploy, `migrate`, `BadgeSeeder`, `config:cache`, fpm-reload.
2. Op de server: `ballspot:competition-period-audit --user=<qa> --yes` en `ballspot:push-audit`; admin → Settings Runtime-kaart controleren.
3. nginx-hardening (P2) en beslissing over de tijdzone (P2 te bevestigen).
4. Android P2-header fixen; nieuwe iOS/Android builds; TestFlight + interne test met een echte `push-test` op de telefoon van de ontwikkelaar (iOS en Android).
5. Pas daarna publieke release. Clubs blijft op de branch tot de mobiele UI-pass en de staging-ronde zijn afgerond.

---

## 15. Achtergelaten toestand (alles wegwerp, buiten de repo)

- Processen die nog draaien: Docker `bp-e2e-mariadb` (127.0.0.1:33094), `php artisan serve` op 127.0.0.1:8093 (master) en 8094 (Clubs-checkout), Metro 8082 (master, API 8093; de verouderde Metro 8082 van 24 okt is vervangen). Stoppen: `docker rm -f bp-e2e-mariadb; kill $(lsof -nP -tiTCP:8093,8094,8082 -sTCP:LISTEN)`.
- Simulators "BP Home — iPhone 17/SE" hebben de debug-app met een e2e-sessie; de SE kreeg een kopie van de iPhone 17-keychain (`ballspot-e2e-qa/se-keychain-backup/` bevat het origineel). Android-emulator (van een andere sessie) heeft extra `be.vanmalderstudio.ballpicker` (debug) + `adb reverse` 8082/8093.
- Repo: de 48 paden zijn om 18:46 buiten deze sessie gecommit (08a521a); alleen `docs/e2e-release-audit.md` is nieuw en ongecommit; `mobile/android/` (gitignored prebuild) bevat nu build-output; `backend/storage/app/public/challenges/hidden/e2e-corner-kick.jpg` en seed-SVG's zijn gegenereerd (storage is gitignored). `../ballspot-staging` kreeg `vendor/` en `node_modules/` (gitignored).
- Rate-limit-cache van de e2e-backends bevat de testteller voor 127.0.0.1 (verloopt binnen het uur).
