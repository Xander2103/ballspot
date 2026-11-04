# BallPicker — audit pushnotificaties (2026-11-03)

Basis: `master` 38fbad6 + bestaande ongecommitte wijzigingen (behouden). Niets gecommit, gepusht, gedeployed of gebuild.

## Kort

| | iOS | Android |
|---|---|---|
| Push-credentials in EAS | ✅ APNs-key `KJ9***`, team `JF3PTL4U56`, gekoppeld aan `be.vanmalderstudio.ballpicker` | ❌ **geen FCM V1-key** (`googleServiceAccountKeyForFcmV1: null`, ook geen legacy FCM) |
| App-config | ✅ build 9: `aps-environment=production`, juiste bundle-id/team, projectId ingebakken | ❌ **geen `google-services.json`**, geen `android.googleServicesFile` → Firebase niet geïnitialiseerd → `getExpoPushTokenAsync` faalt (fout wordt stil ingeslikt) |
| Server-push kan aankomen | Ja (admin-aankondigingen), niet end-to-end bewezen | **Nee**: geen token, en Expo kan zonder FCM-credentials niet afleveren |
| Lokale daily-reminder | ❌ verdwijnt na een gespeelde daily (bug, nu hersteld) | ❌ zelfde bug |

Enhanced push security in Expo staat **uit** (`pushSecurityEnabled: false`), dus de backend heeft geen access token nodig.

## 1. Wat bestaat er echt

| Melding | Mechanisme | Status |
|---|---|---|
| Directe testmelding | — | **Bestond niet.** Nu: `ballspot:push-test` (één toestel, één account) |
| Daily reminder | Server: `ballspot:send-daily-reminders` (elke 15 min) | **Staat uit** (`BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=false`, standaard). In de praktijk dus een *lokale* notificatie op het toestel |
| Toernooimelding | Alleen lokaal (herhalende dagtrigger zolang er een actief toernooi met open rondes is) | Geen server-push |
| Competitiemelding | — | **Bestaat niet** (geen code, alleen de maandelijkse close-competition zonder melding) |
| Admin-aankondiging | `/admin/notifications` → `ExpoPushService::sendAnnouncement`, synchroon | Werkt tot aan het Expo-ticket; tickets werden niet opgevolgd. `send_at` wordt opgeslagen maar niet verwerkt (UI biedt het ook niet aan) |

Keten: app (`useNotificationInvite` op Home) → OS-permissie → `getExpoPushTokenAsync()` (projectId uit manifest) → `POST /api/me/push-tokens` → `push_tokens` → (scheduler / admin) → `ExpoPushService` → `exp.host/--/api/v2/push/send` → APNs/FCM. Geen queue: push is synchroon, dus Supervisor/queueworker en `failed_jobs` spelen geen rol. `/api/health` op productie: scheduler ✅.

## 2. Bevestigde oorzaken

1. **Android: geen Firebase/FCM.** Geen `google-services.json` in de repo of git-historie en geen FCM V1-serviceaccount in EAS. Android-toestellen kunnen geen Expo-token krijgen; `register()` slikt de fout stil in en probeert het bij elke focus opnieuw. Ook met token zou Expo `InvalidCredentials` geven.
2. **Daily reminder (beide platforms): verdwijnt na spelen.** Home annuleert bij elke focus alle lokale reminders en plande de herhalende daily-reminder alleen als de daily van vandaag nog open stond. Na een gespeelde daily bestond er dus géén reminder meer tot de speler de app zelf weer opende, precies de spelers voor wie een reminder bedoeld is.
3. **Server-daily-reminders staan uit**, wat zo bedoeld was. Maar `daily_reminder_push_active` was **globaal**: de vlag aanzetten zou op álle toestellen de lokale reminder uitschakelen, ook voor accounts zonder token (alle Android, en iPhones waar registratie faalde). Die zouden dan helemaal niets meer krijgen. Nu per account (alleen `true` met een geregistreerd token).
4. **Geen receipt-verwerking.** Een `ok`-ticket werd als "verzonden" geteld. APNs/FCM-fouten (`InvalidCredentials`, `MismatchSenderId`, laat `DeviceNotRegistered`) bleven onzichtbaar.

iOS-transport (APNs-key, entitlements, bundle-id, projectId) is correct geconfigureerd. Of iPhones in productie echt een token hebben geregistreerd, en of APNs ze accepteert, kon ik niet zien: SSH naar `deploy@168.119.248.14` wordt geweigerd en de API vraagt een login. → `ballspot:push-audit` + `ballspot:push-test` hieronder.

## 3. Wijzigingen

Backend
- `database/migrations/2026_11_03_000001_create_push_receipts_table.php`: nieuw, alleen toevoegend. Ticket-id, `push_token_id` (FK, `SET NULL`), platform, bron, status, foutcode. **Geen token, geen inhoud.**
- `app/Models/PushReceipt.php`, `app/Services/PushReceiptService.php`: receipts ophalen (≤1000 ids/request, max. 3000/run, 15 min wachttijd, na 25 u `expired`, opruimen na 30 d). `DeviceNotRegistered` → token verwijderd; `InvalidCredentials`/`MismatchSenderId` → error-log, token blijft.
- `app/Services/ExpoPushService.php`: ok-tickets opgeslagen (fout bij opslaan breekt een verzending nooit); request-level Expo-fouten gelogd; timeout 20 s; retry **alleen** op 429/503 (bij een connectiefout geen retry → nooit dubbel); optionele `EXPO_ACCESS_TOKEN`; bron per verzending.
- `app/Services/DailyReminderService.php`: bron `daily_reminder`.
- `app/Http/Controllers/Api/NotificationSettingsController.php`: `daily_reminder_push_active` per account.
- `app/Console/Commands/CheckPushReceipts.php` + `routes/console.php`: `ballspot:check-push-receipts` elke 15 min.
- `app/Console/Commands/PushTest.php`: `ballspot:push-test {user} [--token=] [--send] [--wait=90]`. Zonder `--send` gebeurt er niets. Weigert bij meerdere toestellen zonder `--token`, bij een token van een ander account, bij verwijderde accounts en bij push uit. Vraagt altijd bevestiging (met `--no-interaction` wordt dus nooit verzonden). Toont het ticket en de receipt, vraagt of de melding op de telefoon verscheen en logt dat antwoord.
- `app/Console/Commands/PushAudit.php`: `ballspot:push-audit`, alleen lezen en tellen (tokens per platform, geldig formaat, gezien 7d/30d, meerdere toestellen, tokens van verwijderde accounts, voorkeuren, aankondigingen, receipts per bron/platform/fout).
- `app/Services/DiagnosticsService.php` + `resources/views/admin/diagnostics/index.blade.php`: tokens per platform + receipts van de laatste 24 u op `/admin/diagnostics`.
- `config/ballspot.php`, `.env.example`: nieuwe optionele sleutels.
- Tests: `tests/Feature/PushDeliveryTest.php` (21 nieuw), `NotificationSettingsTest` (+1, 1 aangepast), `ObservabilityLoggingTest` (returnvorm breder).

Mobile
- `src/utils/reminderPlan.ts`: plant de daily-reminder als losse datum-notificaties voor de komende 7 dagen. Vandaag wordt overgeslagen als de daily al gespeeld is (of er geen is). Elke Home-focus bouwt de lijst opnieuw. Na een week inactiviteit stopt het.
- `src/services/notifications.ts`: gebruikt dat plan (DATE-trigger, Android-kanaal `default`; API gecontroleerd tegen expo-notifications 56.0.26).
- `src/utils/__tests__/reminderPlan.test.ts` (6 nieuw).

Bewust niet gewijzigd: bestaande tokens, voorkeuren, scores, XP en accounts; tokenregistratie/uitloggen/accountwissel (al correct en getest: overname van het token bij accountwissel, verwijdering van alleen dit toestel bij uitloggen, retry bij volgende focus); foreground-handler (toont banner + lijst + geluid).

## 4. Testresultaten

- PHPUnit SQLite: 1287 tests, 0 fouten (8851 asserties). De "warnings" zijn bij elke test dezelfde melding dat `backend/.env` ontbreekt in deze worktree (gedraaid met een tijdelijke `APP_KEY`).
- PHPUnit MariaDB 11.4 (wegwerpcontainer): 1282 tests, 0 fouten; FK `ON DELETE SET NULL` gecontroleerd. Gedraaid vóór de laatste +1 test.
- `tsc --noEmit`: schoon. Jest: 578/578 (39 suites).
- Een andere sessie werkt tegelijk in deze checkout (`CompetitionPeriodTest.php`, `competitionPeriodApiChain.test.ts`); die tests lopen mee en zijn groen, maar zijn niet van deze audit.

**Toesteltests: niet uitgevoerd.** Er was geen productietoegang en geen testtoestel. Er is niets naar een toestel gestuurd.

## 5. Runbook (na jouw akkoord)

### A. Backend (zonder app-build, geen invloed op spelers)
```bash
git pull && php artisan migrate --force   # alleen de nieuwe tabel push_receipts
php artisan config:cache && php artisan schedule:list | grep push
php artisan ballspot:push-audit           # → aantal tokens per platform (zonder persoonsgegevens)
```
Laat `BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=false` staan tot stap D.

### B. Eind-tot-eind-test iPhone (eigen testaccount)
1. iPhone (build 9 of nieuwer), inloggen met je testaccount, meldingen toestaan, Home openen.
2. `php artisan ballspot:push-test <jouw user-id>`: dry run, toont de toestellen gemaskeerd.
3. App naar de achtergrond → `php artisan ballspot:push-test <id> --send [--token=<id>]` → bevestigen → ticket + receipt → antwoord "yes/no".
4. Herhaal met de app open (verwacht: banner bovenaan).

### C. Android: FCM inrichten (vereist jouw Firebase-account)
Twee **verschillende** Google-credentials:
- **FCM V1-pushkey**: nodig voor notificaties. **Ontbreekt.**
- Google Play-uploadkey / service account voor `eas submit`: alleen voor store-upload, **geen** invloed op push (staat ook niet in EAS).

Stappen:
1. Firebase console → project aanmaken (of bestaand) → Android-app `be.vanmalderstudio.ballpicker` toevoegen → `google-services.json` downloaden naar `mobile/google-services.json`.
2. `mobile/app.json` → `"android": { …, "googleServicesFile": "./google-services.json" }`.
3. Firebase → Project settings → Service accounts → *Generate new private key* (JSON, **geheim**, niet in git).
4. `cd mobile && npx eas credentials -p android` → production → *Google Service Account* → *Manage your Google Service Account Key for Push Notifications (FCM V1)* → JSON uploaden.
5. Nieuwe Android-build. Daarna stap B op Android (achtergrond + open), en `push-audit` moet android-tokens tonen.
Een `MismatchSenderId` in de receipt betekent dat de key uit stap 3 bij een ander Firebase-project hoort dan het bestand uit stap 1.

### D. Server-daily-reminders (optioneel, later)
Pas na B (en C voor Android): `BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=true` + `config:cache`. Eerst `php artisan ballspot:send-daily-reminders --dry-run`. Door de per-account-vlag houden accounts zonder token hun lokale reminder. Uitzondering: één account met een iPhone mét token en een tweede toestel zonder token, dan krijgt dat tweede toestel geen lokale reminder.

## 6. Deployment / build nodig?

- **Backend-deploy + migratie**: nodig voor receipts, test- en auditcommando, per-account-vlag. Veilig voor bestaande apps.
- **Nieuwe iOS-build**: nodig voor de daily-reminder-fix (build 9 heeft de bug). Voor server-push niet nodig.
- **Nieuwe Android-build**: nodig, ná stap C1–C4, anders blijft Android zonder push.

## 7. GO / NO-GO

- **Backend-wijzigingen: GO** om te deployen (getest op SQLite en MariaDB, alleen een tabel toegevoegd, standaard uit/ongewijzigd gedrag).
- **Pushnotificaties iOS: NO-GO tot stap B** een "yes" met receipt `ok` oplevert. Configuratie is correct, maar levering is niet bewezen.
- **Pushnotificaties Android: NO-GO.** Bevestigd kapot door ontbrekende Firebase/FCM V1; vereist C + nieuwe build + toesteltest.
- **Daily-reminders: NO-GO tot een nieuwe build** met de reminder-fix (beide platforms).
