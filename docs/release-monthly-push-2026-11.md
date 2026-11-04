# BallPicker — Monthly + pushnotificaties (2026-11-03)

Basis: `master` 38fbad6 + alle bestaande ongecommitte wijzigingen (behouden, niets gereset/gestasht). Niets gecommit, gepusht, gedeployed of gebuild. Voorgaand pushrapport: `docs/push-notifications-audit-2026-11.md` (blijft geldig).

**Beperking van deze sessie:** geen productietoegang. `ssh deploy@168.119.248.14` → `Permission denied (publickey,password)`, er is geen Chrome-extensie verbonden en er is geen testtoestel. Wat hieronder "bevestigd" heet, is bewezen uit code, de build 9-IPA en publieke HTTP. Wat op de server of de iPhone moet gebeuren, staat expliciet als **open**.

---

## A. Monthly

### Wat vaststaat (bewijs)

| Schakel | Bewijs | Resultaat |
|---|---|---|
| Build 9 die op de iPhone staat | IPA `~/Documents/ballspot-backups/2026-11-03-build9-ipa`: `CFBundleShortVersionString 1.0.2`, `CFBundleVersion 9` | juiste build |
| API-adres in build 9 | `main.jsbundle` bevat precies één API-URL: `https://ballpicker.vanmalderstudio.be/api` | geen staging/oud domein |
| DNS / server | `ballpicker.vanmalderstudio.be` → `168.119.248.14` (geen AAAA, geen CDN), `Server: nginx/1.24.0 (Ubuntu)`, `/api/health` → `environment: production` | dezelfde VPS als waar `.env`/tinker gecontroleerd zijn |
| Knoptekst in de app | `DailyResultScreen` → `competitionCopyKeys(competitionPeriodType(stats?.period))`. Bundle bevat zowel "View Weekly Leaderboard" als "View Monthly Leaderboard" | **"Weekly" verschijnt alleen als `GET /api/daily/stats` letterlijk `period.period_type = "weekly"` terugstuurt.** Mislukt de call of ontbreekt `period`, dan toont de app "View Leaderboard", nooit Weekly (test `competitionPeriodApiChain.test.ts`, 5 talen) |
| Backend-default | `config/ballspot.php`: `env('BALLPICKER_COMPETITION_PERIOD', 'monthly')` sinds de eerste versie (4f0df4f) | er bestaat geen codepad dat zonder expliciete "weekly"-waarde weekly geeft |
| HTTP-caching | `/api/daily/stats` antwoordt `Cache-Control: no-cache, private`, geen proxy ertussen | geen gecachte oude response |

**Conclusie:** het webproces (nginx → php-fpm) dat de iPhone bedient, heeft een **expliciete** `weekly`-waarde, terwijl tinker in `/var/www/ballpicker/backend` `monthly` ziet. Na een FPM-8.3-reload blijft dat zo. OPcache van FPM 8.3 is dus niet de oorzaak (een reload leegt die). Er blijven precies vier mogelijke bronnen over, en die zijn alleen op de server te onderscheiden:

1. **nginx stuurt naar een andere PHP-FPM** (bv. `php8.2-fpm.sock`) die niet herladen is, of naar een **andere map** (oude checkout/kopie met eigen `.env` of `bootstrap/cache/config.php`).
2. **Een env-variabele buiten `.env`**: `env[BALLPICKER_COMPETITION_PERIOD]` in een FPM-pool, `fastcgi_param BALLPICKER_COMPETITION_PERIOD` in nginx, of `Environment=` in systemd. Zonder config-cache wint die (Dotenv is immutable), en tinker ziet hem niet.
3. **`bootstrap/cache/config.php` in de map die FPM gebruikt** bevat nog `'period' => 'weekly'`.
4. **`.env` bevat de sleutel twee keer** (eerste wint), alleen relevant als tinker vanuit een andere map draaide.

**Bevestigde oorzaak: OPEN.** Ik kan die niet bevestigen zonder serveruitvoer, en ik ga niet gokken.

### Uitgevoerde correctie (diagnose-instrumenten, geen gedragswijziging)

- `backend/app/Services/RuntimeFingerprintService.php` (nieuw): wat *dit* PHP-proces gebruikt: SAPI, PHP-versie/ini, proces-user, `base_path` (+ realpath), git-commit van die checkout, config-cache aan/uit + mtime, de effectieve periode, de waarde in `getenv`/`$_SERVER`/`$_ENV` en alle toewijzingen van die ene sleutel in `.env`, en de OPcache-instellingen. Geen geheimen, alleen deze sleutel.
- `/admin/settings` → nieuwe kaart **"Runtime (this web process)"**: dezelfde velden, gezien **vanuit php-fpm**. Alleen voor admins, geen publieke endpoint.
- `php artisan ballspot:competition-period-audit [--user=<id>]` (nieuw): toont dezelfde velden vanuit de CLI. Met `--user` maakt het (na bevestiging) een tijdelijk API-token voor jouw account aan, doet een **echte HTTP-call** naar `APP_URL/api/daily/stats` en `/daily/leaderboard/weekly` (publieke hostnaam → nginx → FPM, net als de iPhone), toont alleen de `period`-blokken en verwijdert het token weer. Bij een verschil met de CLI: exit 1 + `MISMATCH`.
- Tests: `tests/Feature/CompetitionPeriodAuditTest.php` (7).

### Wat jij nu op de server draait (read-only, werkt al vóór de deploy)

```bash
cd /var/www/ballpicker/backend

# 1. Welke PHP en welke map krijgt het verzoek?
sudo nginx -T 2>/dev/null | grep -nE "server_name|root |fastcgi_pass|fastcgi_param +BALLPICKER"
ls -l /run/php/ ; systemctl list-units --type=service --all 'php*-fpm*' --no-pager

# 2. Wordt de periode buiten .env geïnjecteerd?
sudo grep -rnE "BALLPICKER_COMPETITION|clear_env|^\s*env\[" /etc/php/*/fpm/ /etc/nginx/ /etc/environment /etc/systemd/system/ 2>/dev/null

# 3. Config-cache en .env van deze map (alleen deze sleutel)
ls -l bootstrap/cache/ ; sudo grep -o "'period' => '[a-z]*'" bootstrap/cache/config.php
grep -n "BALLPICKER_COMPETITION" .env

# 4. Andere kopieën van de app op de server?
sudo find / -xdev -name artisan -not -path "*/vendor/*" 2>/dev/null

# 5. Wat kreeg de iPhone echt? (statuscodes, geen inhoud)
sudo grep "/api/daily/stats" /var/log/nginx/access.log | tail -5
```

Echte HTTP-response vóór de deploy (vervang `ID` door jouw eigen user-id; het token komt nooit op het scherm):

```bash
sudo -u www-data php artisan tinker --execute='file_put_contents("/tmp/pa", App\Models\User::findOrFail(ID)->createToken("period-audit (temporary)")->plainTextToken);'
curl -s -H "Accept: application/json" -H "Authorization: Bearer $(sudo cat /tmp/pa)" https://ballpicker.vanmalderstudio.be/api/daily/stats | python3 -c 'import json,sys; print(json.load(sys.stdin).get("period"))'
sudo -u www-data php artisan tinker --execute='Laravel\Sanctum\PersonalAccessToken::where("name","period-audit (temporary)")->delete();'; sudo rm -f /tmp/pa
```

Na de deploy kan dit in één keer: `sudo -u www-data php artisan ballspot:competition-period-audit --user=ID`, en vergelijk dan met `/admin/settings` → Runtime.

### Correctie per uitkomst

| Uitkomst | Correctie |
|---|---|
| `fastcgi_pass` wijst naar een andere PHP-versie | nginx naar `php8.3-fpm.sock` zetten, of die versie herladen; `sudo nginx -t && sudo systemctl reload nginx` |
| nginx-`root` wijst naar een andere map | root naar `/var/www/ballpicker/backend/public`; de oude map pas opruimen na een back-up |
| Periode geïnjecteerd in pool/nginx/systemd | die regel weghalen, `config:cache`, FPM-reload |
| Cache in de FPM-map zegt weekly | in díe map: `sudo -u www-data php artisan config:cache` + FPM-reload |
| `.env` heeft de sleutel twee keer | één regel `=monthly` laten staan, `config:cache`, reload |

**Resultaat op de iPhone: OPEN.** Verwacht na de correctie, zonder nieuwe build: app volledig afsluiten → openen → Daily Result → **"View Monthly Leaderboard"**. Build 9 is hiervoor correct.

---

## B. iOS-notificaties

### Lokaal versus push: wat bestaat er

| Soort | Waar ontstaat hij | Status in productie / build 9 |
|---|---|---|
| Lokale Daily-reminder | iPhone zelf (expo-notifications), om `reminder_time` (default 19:00, toesteltijdzone) | **Kapot in build 9:** `syncSchedules` plant alleen `if (daily_reminder_enabled && !dailyCompleted && …)`, met één herhalende DAILY-trigger. Home annuleert eerst alles. Wie de Daily speelt en daarna Home opent, heeft **geen enkele** reminder meer, ook niet voor morgen. Hersteld in de ongecommitte code (`reminderPlan.ts`: 7 losse datums) → **nieuwe iOS-build nodig** |
| Lokale toernooimelding | iPhone, DAILY-trigger om `reminder_time` | Alleen als er een actief toernooi is met openstaande rondes |
| Server-Daily-reminder (push) | `ballspot:send-daily-reminders`, elke 15 min | Draait wel, maar verstuurt niets zolang `BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=false` (default) |
| Toernooi-/competitiepush | — | Bestaat niet |
| Testmelding | `ballspot:push-test` (nieuw, nog niet gedeployed) | Nog nooit verstuurd |
| Admin-aankondiging | `/admin/notifications` | Enige server-push die in productie bestaat |

### Bevestigde oorzaak "iOS ontvangt niets"

1. **Er wordt in de praktijk geen server-push verstuurd**: de reminder-push staat uit en er zijn geen andere automatische pushes. "0 jobs/0 failed" zegt hier niets: push gaat synchroon, niet via de queue.
2. **De enige lokale melding die een actieve speler zou krijgen, wordt in build 9 telkens geannuleerd** zodra hij de Daily gespeeld heeft (zie tabel, bevestigd in `HEAD:mobile/src/services/notifications.ts`).

**Nog niet uitgesloten:** levering via APNs. De configuratie klopt (APNs-key `KJ9***` op de bundle-id, `aps-environment=production` in build 9, projectId ingebakken, Expo push security uit), maar of de 5 tokens echte iOS-tokens zijn, en of APNs ze accepteert, is pas te zien met `push-audit` + `push-test` + receipt.

Niet gewijzigd in deze sessie: de pushfixes uit de vorige audit (receipts, per-account `daily_reminder_push_active`, reminderPlan) staan er al en zijn behouden.

### End-to-end-test (exact één melding, alleen na jouw akkoord, door jou uitgevoerd)

Voorwaarde: backend gedeployed + `php artisan migrate --force` (alleen de tabel `push_receipts`).

```bash
sudo -u www-data php artisan ballspot:push-audit                 # tokens per platform, last seen (geen persoonsgegevens)
sudo -u www-data php artisan ballspot:push-test ID               # dry run: jouw toestellen, gemaskeerd
# iPhone: app op de achtergrond (of vergrendeld), Focus/Niet storen uit
sudo -u www-data php artisan ballspot:push-test ID --send [--token=<id>]   # vraagt bevestiging, 1 melding, toont ticket + receipt
```

Bewijs = de melding staat **zichtbaar op de iPhone** én de receipt is `ok`. Een `ok`-ticket alleen is geen bewijs. Bij `DeviceNotRegistered`: app openen → Home (token wordt opnieuw geregistreerd), en daarna opnieuw testen. Bij `InvalidCredentials`: APNs-key in `npx eas credentials -p ios` vernieuwen.

Lokale reminder testen (pas mogelijk in een nieuwe build): reminder-tijd in Instellingen op over 3 minuten zetten → Daily spelen → Home → app sluiten → de melding moet komen.

Checklist op de iPhone: Instellingen → Meldingen → BallPicker → Sta toe + Toegangsscherm + Berichtgeving aan; geen Focus actief; app-instelling "Daily reminder" aan.

**Resultaat echte E2E-test: NIET UITGEVOERD.** Er was geen server- of toesteltoegang.

---

## C. Android (FCM)

**Ontbreekt (bevestigd):** `mobile/google-services.json` (bestaat nergens, ook niet in de git-historie), `expo.android.googleServicesFile` in `mobile/app.json`, en de FCM V1-serviceaccount-key in EAS (`googleServiceAccountKeyForFcmV1: null`). Zonder deze drie krijgt Android geen Expo-token, en Expo zou zonder key ook niet kunnen afleveren.

Stappen (jouw Google-account):
1. https://console.firebase.google.com → project (nieuw of bestaand) → **Add app → Android** → package name **`be.vanmalderstudio.ballpicker`** (exact) → `google-services.json` downloaden → `mobile/google-services.json`. Dit is de publieke clientconfig, geen geheim. Committen mag. Ook ongecommit gaat hij mee in de EAS-upload (de root-`.easignore` sluit hem niet uit).
2. `mobile/app.json` → `"android": { "package": "be.vanmalderstudio.ballpicker", "googleServicesFile": "./google-services.json", … }`.
3. Firebase → ⚙ Project settings → **Service accounts** → *Generate new private key*. Dit JSON-bestand is **geheim**: niet in de repo bewaren. `.gitignore` en `.easignore` weigeren nu `*firebase-adminsdk*.json` / `*service-account*.json`.
4. `cd mobile && npx eas credentials -p android` → production → *Google Service Account* → *Manage your Google Service Account Key for Push Notifications (FCM V1)* → *Upload* het bestand uit stap 3. Daarna kun je het lokaal verwijderen.
5. Nieuwe Android-build → op het toestel inloggen en meldingen toestaan → `push-audit` moet een android-token tonen → `push-test` zoals bij iOS. `MismatchSenderId` = de key uit stap 3 hoort bij een ander Firebase-project dan het bestand uit stap 1.

Teststatus Android: **niet getest**, en niet testbaar vóór stap 1–5.

---

## D/E. Release

### In deze sessie gewijzigd/toegevoegd
- `backend/app/Services/RuntimeFingerprintService.php` (nieuw)
- `backend/app/Console/Commands/CompetitionPeriodAudit.php` (nieuw)
- `backend/app/Http/Controllers/Admin/SettingsController.php`, `backend/resources/views/admin/settings/index.blade.php` (Runtime-kaart)
- `backend/tests/Feature/CompetitionPeriodAuditTest.php` (nieuw, 7 tests)
- `.gitignore`, `.easignore` (weigeren service-account-keys)
- dit rapport

Alle eerdere ongecommitte wijzigingen (XP-tekst, Packs/Tournaments-PNG's, Monthly-regressietests, notificatie-, reminder- en receiptcode, diagnostics) zijn onaangeroerd.

### Tests
- PHPUnit (SQLite, tijdelijke `APP_KEY` omdat deze worktree geen `backend/.env` heeft): **1294 tests, 0 fouten**, 8872 asserties. Elke "warning" is dezelfde melding over de ontbrekende `.env`.
- Jest: **578/578** (39 suites). `tsc --noEmit`: schoon.
- Toestellen: **geen enkele toesteltest uitgevoerd** (geen iPhone, geen Android, geen server).

### Nodig
| | Nodig? | Waarom |
|---|---|---|
| Backend-deploy + `migrate` | **Ja** | push_receipts, push-test/push-audit, period-audit + Runtime-kaart, per-account reminder-vlag |
| Serverconfig-correctie Monthly | **Ja** | volgens de uitkomst van de commando's in A |
| iOS-build | **Ja** | Daily-reminder-fix, XP-tekst, assets (Monthly zelf heeft geen build nodig) |
| Android-build | **Ja, ná Firebase-stappen C1–C4** | anders blijft push op Android dood |

### GO / NO-GO
- Backend-deploy: **GO** (alleen toevoegend, standaardgedrag ongewijzigd, getest).
- Monthly: **NO-GO tot** de serveruitvoer de bron aanwijst, die gecorrigeerd is én de iPhone "View Monthly Leaderboard" toont.
- iOS-notificaties: **NO-GO tot** `push-test` een zichtbare melding op jouw iPhone geeft, plus een nieuwe build met de reminder-fix die na een gespeelde Daily een reminder toont.
- Android: **NO-GO** (FCM ontbreekt).
- Nieuwe productie-update (store): **NO-GO** zolang een van de drie hierboven openstaat.
