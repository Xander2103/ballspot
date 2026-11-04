# BallPicker — gecombineerde testrelease (2026-11-03)

Basis: `master` 38fbad6 (= `origin/master`), met **47 ongecommitte paden** (30 gewijzigd, 17 nieuw). Alles is behouden. Er is niets gecommit, gepusht, gedeployed, gebuild of ingediend.
Voorgaande rapporten blijven geldig: `docs/push-notifications-audit-2026-11.md` en `docs/release-monthly-push-2026-11.md`.

Inhoud van de release: Android FCM V1, iOS-/Android-notificatiefixes, Daily-reminders voor 7 dagen, Expo push receipts, Monthly-diagnostiek (Runtime-kaart + `competition-period-audit`), Daily Result XP-tekst en de bijgesneden Packs-/Tournaments-PNG's.

---

## 1. Controles in deze sessie

| Controle | Resultaat |
|---|---|
| Git | branch `master`, gelijk met `origin/master` (38fbad6, via `ls-remote` geverifieerd). Geen stash, geen reset |
| Secrets in diff + nieuwe bestanden | **Geen.** De enige treffers zijn test-placeholders (`ExponentPushToken[...]` in tests) en de **publieke** Firebase-client-API-key in `mobile/google-services.json`, die by design in de app zit. Geen `*.p8`/`*.p12`/service-account-JSON in de boom. `backend/.env` bestaat niet in deze worktree |
| `.gitignore` / root-`.easignore` | Weigeren `*firebase-adminsdk*.json`, `*service-account*.json` en `*serviceAccount*.json`. `google-services.json` valt er bewust **niet** onder. Root-`.easignore` sluit `mobile/ios` en `mobile/android` uit. Dat is belangrijk: er staat lokaal een oude `mobile/android/` (prebuild van 28 okt), en die mag niet mee. `mobile/.easignore` wordt door eas-cli genegeerd |
| EAS | `appVersionSource: remote`, `autoIncrement` op production iOS + Android. Productie-API-URL's staan in alle profielen. iOS submit `ascAppId 6796491992`. **Android submit-key: geen** (er is geen `submit.production.android` en geen Play-serviceaccount in EAS) |
| app.json | `version 1.0.2`, `android.googleServicesFile ./google-services.json` (nieuw), projectId `ad828c83-…`, plugin `expo-notifications`. De lokale `buildNumber`/`versionCode` worden genegeerd (remote): de volgende Android-build wordt versionCode 7 |
| google-services.json | project `ballpicker-sports-2026`, package `be.vanmalderstudio.ballpicker`, 1 client. Het bestand is untracked, maar zit wel in de EAS-upload (eerder geverifieerd met `eas build:inspect`) |
| FCM V1-key in EAS | gekoppeld (vorige sessie, via GraphQL geverifieerd). In deze sessie niet opnieuw opgevraagd |
| Migraties | precies **1** nieuwe: `2026_11_03_000001_create_push_receipts_table`. Alleen additief (nieuwe tabel, FK `nullOnDelete`). Eerder getest op MySQL 8.4 en MariaDB 11.4 (migrate, rollback, re-migrate). De commits sinds de laatst bekende prod-commit 10e020b bevatten **geen** migraties |
| Scheduler | nieuw: `ballspot:check-push-receipts` elke 15 min (doet niets zolang er niets verstuurd is) |
| Backend-tests | `vendor/bin/phpunit` (SQLite, tijdelijke APP_KEY): **1294 tests OK**, 8872 asserties, 1 skip, 1 PHPUnit-deprecation (die bestonden al) |
| Mobile | `tsc --noEmit` schoon, **jest 578/578** (39 suites) |
| Compatibiliteit | De nieuwe app gebruikt geen nieuwe API-velden en werkt dus ook tegen de huidige backend. Omgekeerd werken oude apps tegen de nieuwe backend, want die voegt alleen toe |
| Productie (publiek, read-only) | `/api/health` 200, `environment: production`, alle checks true. `/download` 200. `/api/daily/stats` 401 zonder token. De deployde commit is **niet** publiek af te lezen |

---

## 2. Monthly toont nog Weekly — analyse

### Wat de code toelaat (opnieuw nagelopen)

- De knop op Daily Result: `competitionCopyKeys(competitionPeriodType(stats?.period))`. Dat is **alleen** `GET /api/daily/stats` → `period.period_type`. Zonder geldige waarde toont de app "View Leaderboard", nooit "Weekly".
- Backend: `CompetitionPeriodService::type()` = `config('ballspot.competition.period')`, default `monthly`, met als enige bron `env('BALLPICKER_COMPETITION_PERIOD')`. Er is geen database-instelling, geen admin-override en geen tweede codepad.
- Uitgesloten in deze sessie: de oude `leaderboard.period_label` (default 'Weekly', verwijderd in 89d856a) werd door geen enkel backend-endpoint gebruikt. Daar komt het dus niet vandaan.
- Let op het verschil met de **Trophy Room** ("Weekly Competition"): die toont `competition_finishes.period_type` van eerder **afgesloten** weken. Dat is historische data en terecht. Gaat het over de Daily Result-knop of de leaderboardtitel, dan geldt de analyse hieronder.

### Waarom de FPM-reload niets veranderde

Een reload leegt OPcache, maar **leest geen andere configuratie**. Wat overblijft:

| Toestand op de server | Effect van FPM-reload | Effect van `config:cache` + reload |
|---|---|---|
| (a) nginx → **andere PHP-FPM** (bv. 8.2) of een **andere map** | geen (je herlaadt de verkeerde) | geen |
| (b) env-injectie (`env[...]` in pool, `fastcgi_param`, systemd) en **geen** config-cache | geen (de waarde wint van `.env`) | **lost op** (gecachte config negeert env) |
| (c) verouderde `bootstrap/cache/config.php` in de FPM-map | geen | **lost op**, als het in díe map gebeurt |
| (d) `.env` heeft de sleutel twee keer | geen | lost op na het opschonen van `.env` |

Het deploy-script draait zelf `config:cache` (als www-data in `/var/www/ballpicker/backend`) en daarna `reload php8.3-fpm`. Daaruit volgt een harde conclusie:

> **Toont de app na de deploy nog steeds Weekly, dan is het (a)**: het verkeer gaat naar een andere PHP-versie of een andere map dan `/var/www/ballpicker/backend`.

De Runtime-kaart op `/admin/settings` (komt mee in deze deploy) toont dat rechtstreeks vanuit FPM: `base_path` + realpath, PHP-versie/SAPI, config-cache aan/uit, en de waarde in `getenv`/`$_SERVER`/`.env`.

**Bevestigde oorzaak: nog OPEN.** Er is geen SSH-toegang (eerder: `Permission denied`) en geen admin-sessie. De read-only servercommando's uit `docs/release-monthly-push-2026-11.md` §A beslissen het meteen, ook vóór de deploy. Het snelste commando:

```bash
sudo nginx -T 2>/dev/null | grep -nE "server_name|root |fastcgi_pass|fastcgi_param +BALLPICKER"
sudo grep -rnE "BALLPICKER_COMPETITION|^\s*env\[" /etc/php/*/fpm/ /etc/nginx/ /etc/systemd/system/ 2>/dev/null
```

---

## 3. Releaseplan

Volgorde: **commit → push → backup → backend-deploy → serverchecks → Monthly-check → push-test iPhone met de huidige build → builds → device-tests → (later) store.**

### Stap 0 — Commit en push (jij)

Het deploy-script doet `git reset --hard origin/master`. De backend-wijzigingen moeten dus eerst op `origin/master` staan. Voorstel (vanuit `../ballspot-integration`):

```bash
git add backend/ .gitignore .easignore
git commit -m "backend: Expo push receipts, push-test/push-audit, per-account reminder push flag, runtime + period audit"

git add mobile/src mobile/assets/home
git commit -m "mobile: 7-day local daily reminders, notification fixes, Daily Result XP copy, cropped home cards"

git add mobile/app.json mobile/google-services.json
git commit -m "android: Firebase/FCM V1 client config (google-services.json)"

git add docs/push-notifications-audit-2026-11.md docs/release-monthly-push-2026-11.md docs/release-combined-test-2026-11.md
git commit -m "docs: push audit, monthly diagnostics, combined test release plan"

git status --short          # moet leeg zijn
git push origin master
```

Controleer vóór de push: `git show --stat HEAD~3..HEAD` toont geen `.env`, `*.p8` of service-account-JSON.

### Stap 1 — Backup (jij, op de server)

```bash
cd /var/www/ballpicker/backend
git rev-parse --short HEAD          # noteer: de rollback-commit
sudo bash scripts/backup-mysql.sh --check && sudo bash scripts/backup-mysql.sh   # leest root's ~/.my.cnf; daarna: ls -l /var/backups/ballpicker/latest.sql.gz (> 0 bytes)
head -40 /var/www/ballpicker/deploy.sh   # is dit de wrapper die backend/scripts/deploy.sh aanroept? (runbook §5.2)
```

### Stap 2 — Backend-deploy (jij)

```bash
/var/www/ballpicker/deploy.sh       # exit 0 = OK; 2 = live maar een post-check faalde; 1 = stap faalde (STATE-regel lezen)
```

Verwacht in de uitvoer: stap 7 migreert precies `create_push_receipts_table`, `Pending migrations: 0`, de health-JSON is helemaal true, en `schedule:list` toont `ballspot:check-push-receipts`.
Rollback: `/var/www/ballpicker/deploy.sh --ref=<commit uit stap 1>`. De tabel `push_receipts` mag blijven staan; die wordt door de oude code niet gebruikt.

### Stap 3 — Serverchecks na de deploy (jij)

```bash
sudo -u www-data php artisan migrate:status | grep push_receipts      # Ran
sudo -u www-data php artisan ballspot:push-audit                       # tokens per platform, nog geen android verwacht
sudo -u www-data php artisan ballspot:competition-period-audit --user=ID   # vraagt bevestiging; tijdelijk token, wordt verwijderd
```

En in de browser: `/admin/settings` → kaart **Runtime (this web process)**.

### Stap 4 — Monthly (jij)

- `competition-period-audit` geeft geen `MISMATCH` en de HTTP-period is `monthly`: iPhone-app volledig afsluiten → openen → Daily Result → **"View Monthly Leaderboard"**. Daar is geen nieuwe build voor nodig.
- Nog steeds `weekly`: dan is het oorzaak (a) uit §2. De Runtime-kaart toont welke map en welke PHP. Corrigeer de nginx-`root`/`fastcgi_pass` (`sudo nginx -t && sudo systemctl reload nginx`) en test opnieuw.

### Stap 5 — Push-test iPhone met de huidige build (jij, vóór een nieuwe build)

De APNs-levering hangt niet af van de nieuwe app, dus dit kan meteen:

```bash
sudo -u www-data php artisan ballspot:push-test ID                 # dry run, gemaskeerd
# iPhone: app op de achtergrond of vergrendeld, Focus uit
sudo -u www-data php artisan ballspot:push-test ID --send          # bevestigen; 1 melding, ticket + receipt
```

Bewijs = de melding staat zichtbaar op de iPhone **én** de receipt is `ok`. Bij `DeviceNotRegistered`: app openen → Home, en daarna opnieuw testen. Bij `InvalidCredentials`: de APNs-key via `npx eas credentials -p ios`.

### Stap 6 — Builds (jij, vanuit `../ballspot-integration/mobile`, nooit vanuit de Clubs-checkout)

```bash
cd /Users/FOTOTHEEK/Documents/ballspot-integration/mobile
git status --short                                        # leeg (alles gecommit)
CI=1 npx eas build:inspect -p android -e production --stage archive --output /tmp/insp-a --force   # optioneel: google-services.json aanwezig, geen mobile/android
npx eas build -p ios -e production
npx eas build -p android -e production                    # AAB, versionCode 7
```

Vooraf beslissen: is **1.0.2 al vrijgegeven in de App Store**? Zo ja, dan weigert App Store Connect nieuwe builds voor die versie. Zet dan eerst `expo.version` op `1.0.3` (en commit).

Installatie op toestellen:
- **iOS:** `npx eas submit -p ios --latest` → TestFlight (interne testers). Dat is een upload naar App Store Connect, geen review.
- **Android, kies één:**
  - (aanbevolen, test het echte store-binary) het AAB handmatig uploaden naar de Play Console → **Interne test**. EAS heeft geen Play-submit-key, dus `eas submit -p android` werkt nog niet.
  - (snel) `npx eas build -p android -e preview` → APK via link installeren. Staat de app al via Play op het toestel, verwijder hem dan eerst (andere ondertekening door Play App Signing).

### Stap 7 — Device-tests (jij)

| # | Test | Geslaagd als |
|---|---|---|
| A1 | Android: inloggen → toestemming voor meldingen (Android 13+) → Home | `push-audit` toont 1 android-token |
| A2 | `push-test ID --send --token=<android-id>`, app op de achtergrond | melding zichtbaar + receipt `ok`. `MismatchSenderId` = key en google-services.json horen bij verschillende projecten |
| A3 | Android lokaal: reminder-tijd = nu + 3 min → Daily spelen → Home → app sluiten | melding komt (noteer de vertraging, zie §4) |
| I1 | iOS nieuwe build: push-test opnieuw | zichtbaar + `ok` |
| I2 | iOS lokaal: zelfde als A3 | melding komt, ook na een gespeelde Daily |
| B1 | Beide: Daily Result | "View Monthly Leaderboard", XP-tekst toont de onderdelen correct |
| B2 | Beide: Home | Packs-/Tournaments-kaarten zonder witte lijn |

`BALLPICKER_DAILY_REMINDER_PUSH_ENABLED` blijft `false` tijdens deze test. De 7-daagse reminders zijn lokaal. Zet de server-reminder pas aan nadat A2 en I1 slagen; dan geldt per account de token-check.

---

## 4. Wat nog ontbreekt (niet gecontroleerd of niet controleerbaar)

1. **Monthly-oorzaak op de server**: open tot de serveruitvoer of de Runtime-kaart er is.
2. **Welke commit prod nu draait**: niet publiek af te lezen. Stap 1 toont het.
3. **Of `/var/www/ballpicker/deploy.sh` de geharde wrapper is** (runbook §5.2 stond sinds 2026-10-15 nog open). Het oude script draaide artisan als `deploy` (de Pack Start 500-incident).
4. **MySQL-backup** vóór de migratie: er is geen geautomatiseerde backup bevestigd.
5. **Echte E2E-push** op iPhone en Android: nooit uitgevoerd.
6. **App Store-versietrein 1.0.2**: open of gesloten, onbekend.
7. **Play-submit-key** ontbreekt in EAS: Android-uploads gaan handmatig.
8. **Android, cosmetisch/gedrag (niet geverifieerd):** er is geen eigen monochroom `notification.icon`, dus Android kan het app-icoon als effen vlak tonen. De server stuurt zonder `priority: high`, dus in Doze kan levering vertraagd zijn. Lokale reminders met een datum-trigger kunnen op Android inexact (later) afgaan zonder exact-alarm-toestemming. Geen van deze punten blokkeert de test. Beoordeel ze bij A2/A3.
9. **Optionele hardening:** beperk de Firebase-client-API-key in Google Cloud tot package `be.vanmalderstudio.ballpicker` + SHA-1 van de upload- en Play-signing-key.

## 5. GO / NO-GO

- Commit + push + backend-deploy: **GO** (additief, getest, rollback beschreven).
- Monthly: **NO-GO** tot de iPhone "View Monthly Leaderboard" toont.
- iOS- en Android-build voor de test: **GO na** stap 3 (backend live) en de beslissing over de versie (1.0.2/1.0.3).
- Store-release (productie): **NO-GO** tot A1–A3, I1–I2 en B1–B2 geslaagd zijn.
