# BallPicker: finale integratie en releasecontrole (2026-11-01)

## Advies (bijgewerkt na de vergrendeling van gespeelde challenges)

- **Backend: GO**, zodra de werkboom is gecommit en de servercheck in sectie 9 klopt.
- **iOS: GO voor code; eerst een bouwnummer instellen.** Het profiel verhoogt dat nummer niet vanzelf (blocker B2).
- **Android: NO-GO** tot de toestelcheck in sectie 8 is geslaagd.

## Blockers

| # | Blocker | Actie |
|---|---|---|
| B1 | De EAS-builds van vandaag (iOS 8, Android 4 en 5) zijn gemaakt op `4af432f`. Ze missen alle ongecommitte fixes. | **Dien ze niet in.** Bouw opnieuw na de commit. |
| B2 | iOS gebruikt `appVersionSource: remote` zonder `autoIncrement`. De volgende build krijgt weer nummer 8; `34.0.0` in `app.json` wordt genegeerd. | Zoek het hoogste bouwnummer van deze versie in App Store Connect en stel een hoger nummer in. |
| B3 | Android is niet op een echt toestel getest. | Checklist in sectie 8. |
| B4 | `eas.json` heeft geen Android-submitconfiguratie. Of EAS een Play-serviceaccount heeft, kon ik niet zien. | Probeer `eas submit`, of upload de AAB handmatig in Play Console. |
| B5 | Niet te controleren vanaf hier: welke commit op productie draait, en of versie 1.0.2 al live is in de stores. | Controleer beide. Is 1.0.2 al uitgebracht, verhoog dan eerst `version` in `app.json`. |

Niet blokkerend: de `AboveGoalHeader`-output. Dat is een datafix in de admin, na de deployment.

## 1. Git

| | |
|---|---|
| Worktree | `ballspot-integration` |
| Branch | `master` |
| HEAD | `4af432f`, gelijk aan `origin/master` |
| Ongecommit | 97 paden: 81 gewijzigd, 16 nieuw (`??`, deels mappen) |
| Niet gemerged in master | `feature/ballpicker-clubs` (bewust uitgesloten), `agents/eas-build-fix-hermes-bundle-id` (bewust niet opgenomen) |

Productie: eerder gemeld als `10e020b`. Dat is niet meer zeker, want `/download` geeft nu 200
op productie. Controleer dit met `git rev-parse --short HEAD` op de server.

## 2. Geïntegreerde verbeteringen en oorzaken

| Onderwerp | Status | Oorzaak en oplossing |
|---|---|---|
| Numerieke scores | Opgelost in deze ronde | `SUM()` en `AVG()` zijn DECIMAL op MariaDB en PDO geeft die als string; SQLite geeft getallen. Ook `meta.top_users` en `meta.nearby_users` kregen die strings. Gevolg op productie: `typeof score === 'number'` was onwaar, dus de app verborg de puntenregel op de toernooi-positiekaart. Cast nu in `LeaderboardController`; ook `/me/export` is getypeerd. |
| Sortering | Geen bug gevonden | De sortering gebeurde in SQL op de numerieke waarde, niet alfabetisch. Vastgelegd in een regressietest (9, 100, 1000). |
| Tie-break toernooi | Opgelost in deze ronde | De live ranglijst had geen tie-break en kon afwijken van de eindstand. Nu gelijk aan `calculateStandings`: score, vroegste laatste gok, user-id. |
| Rang in `/daily/stats` en badge `weekly_top_10` | Opgelost in deze ronde | Eigen query zonder tie-break en inclusief verwijderde accounts, dus de rang kon afwijken van de zichtbare ranglijst. Beide gebruiken nu `CompetitionStandingsService::rankedUserIds`. Bij een gelijke stand rond plek 10 bepaalt de tie-break nu wie de badge krijgt; eerder was dat willekeurig. |
| Bootstrap JS in admin | Opgelost in deze ronde | De SRI-hash in `admin/layout.blade.php` was sinds juni fout. De browser blokkeerde het script op elke adminpagina die deze layout gebruikt, dus Bootstrap-dropdowns en sluitknoppen van meldingen konden niet werken. Hash gecorrigeerd en vastgelegd in een test; dropdown geopend op desktop en mobiel. Pas actief na deployment. |
| Toernooilijst | Opgelost in deze ronde | Rang `#4` en lager was bijna onzichtbaar (geen tekstkleur). "1 rounds" was niet vervoegd, nu enkelvoud en meervoud in 5 talen. Lange usernames worden afgekapt. Waarschuwing over geneste lijsten verwijderd. |
| Spaanse kolomkop | Opgelost in deze ronde | `PUNTUACIÓN` brak af op kleine iPhones. |
| Datum bij Daily-resultaat | Opgelost in deze ronde | De API en app gaven geen datum. Nu `challenge_date` in de response, zonder datumverschuiving per tijdzone. |
| Competitieperiodes | Al aanwezig, gecontroleerd | Titel, periodebalk, positiekaart en knop volgen `period_type` in 5 talen. Tegenstrijdige labels zijn weg. Badge heet "Competition Top 10" na de seeder. |
| Historische Daily-resultaten | Al aanwezig, gecontroleerd | Het scherm gebruikte `/daily/today`. Het resultaat draagt nu zijn eigen `challenge`-blok. Zie sectie 3. |
| Publieke usernames | Al aanwezig, gecontroleerd | Echte namen stonden in publieke API's. `name` blijft bestaan maar bevat de username, zodat oude apps zonder update goed werken. Nieuwe test loopt alle GET-API-routes af op naam en e-mail. |
| Admin `/admin/players` | Al aanwezig, gecontroleerd | Zie sectie 5. |
| Notificaties | Al aanwezig, gecontroleerd | Geen codewijziging nodig. Zie sectie 5. |
| Test-triggers | Opgelost in deze ronde | Twee tests gebruikten SQLite-syntax en braken op MariaDB. Ze gebruiken nu een query-listener die op beide databases werkt. |

## 3. Aandachtspunten

### `AboveGoalHeader`: bron niet bevestigd

- De naam staat niet in code, seeders, vertalingen, git-geschiedenis of de lokale stagingdata. Titels worden ook niet uit bestandsnamen afgeleid.
- Overblijvende bronnen zijn vrije tekst uit de admin: challengetitel, categorie, subcategorie of tag. Dat is een gevolgtrekking, geen bewijs.
- Productie kon ik niet lezen: SSH wordt geweigerd en de API vereist een login.
- De nieuwe app verbergt zulke namen, nu ook bij tags. Oude apps tonen ze nog tot de rij is aangepast.

Voer dit alleen-lezen script uit op de server en stuur de output door. Lokaal getest: het vond een testcategorie `AboveGoalHeader`. Pas de gevonden rij daarna aan in de admin:

```bash
cd /var/www/ballpicker/backend && sudo -u www-data php artisan tinker --execute='$re="/^[A-Z]?[a-z0-9]+(?:[A-Z][a-z0-9]*)+$|^[A-Za-z0-9]+(?:_[A-Za-z0-9]+)+$|^[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9_]+)+$/"; foreach(["challenges"=>"title","challenge_categories"=>"name","challenge_subcategories"=>"name","tags"=>"name"] as $t=>$c){foreach(DB::table($t)->select("id",$c)->get() as $r){if(preg_match($re,trim((string)$r->$c)))echo "$t #$r->id: {$r->$c}\n";}} echo "done\n";'
```

### Historische Daily Challenges: nu beschermd

Een gok bewaart alleen de eigen gok, afstand, score, tijdstip en `daily_challenge_id`. Foto's,
balpositie, titel en categorie staan op de challenge. Daarom bevriest de kleinste veilige oplossing
de challenge bij de eerste gok. Er is geen migratie en bestaande data wordt niet aangeraakt.

| Route | Was mogelijk | Nu |
|---|---|---|
| Bewerkpagina: bal, foto's, sport | Wijzigen, en oude foto's werden gewist | Geweigerd zodra er een daily-, toernooi- of packgok is. Velden zijn uitgeschakeld in de admin. |
| Bewerkpagina: elke opslag | Balpositie afgerond van 6 naar 3 decimalen, ook bij een titelwijziging | Balpositie wordt niet meer weggeschreven |
| `schedule-daily-challenges --force` | Gespeelde Daily naar een andere foto laten wijzen | Slaat gespeelde dagen over |
| Andere Eloquent-code | Geen controle | Modellen `Challenge` en `DailyChallenge` weigeren de wijziging |
| "Clear Daily History" en `reset-test-daily-history` | Wiste alle gokken, ook op productie (vaste PIN) | Geweigerd op productie, tenzij `BALLPICKER_ALLOW_DAILY_HISTORY_CLEAR=true` |

Bewust bewerkbaar op gespeelde challenges: titel, categorie, tags, moeilijkheid, status en pool.
Die beïnvloeden geen score, en `AboveGoalHeader` moet hersteld kunnen worden. Wijzigingen loggen nu
de veldnamen, zonder waarden.

Gevolgen en grenzen:

- Een foute balpositie na de eerste gok kun je niet meer corrigeren. Maak een nieuwe challenge. Bestaande scores veranderen sowieso niet mee.
- Een titel- of categoriewijziging is zichtbaar op oude resultaten. Dat geldt ook bij het verwijderen van een categorie.
- Rechtstreekse SQL of `Model::query()->update()` omzeilt de controle. De code heeft zo'n schrijfroute niet voor deze velden; beheerders met databasetoegang blijven buiten deze bescherming.
- Spelers-API's en oude apps: geen wijziging. Alleen de admin en beheercommando's zijn aangepast.

### Overige open punten

| Punt | Risico | Actie |
|---|---|---|
| Android niet gedraaid | Layout, kanaal en OS-weigering ongetest | Toestelcheck vóór Android-publicatie |
| Echte pushlevering niet getest | Herinneringen komen mogelijk niet aan | Eén testmelding op een echt toestel |
| `ballspot:audit-usernames` niet op productie gedraaid | Datakwaliteit onbekend; afwijkende usernames krijgen wel een neutraal label | Na deployment draaien (alleen-lezen, toont enkel ids) |
| Klik op "Email this player" wordt niet gelogd | Alleen het openen van het dossier en zoeken worden gelogd | Bewust zo gelaten: het is een gewone `mailto`-link |

## 4. Gewijzigde bestanden

Volledige lijst: `git status --short`.

- **Backend, 26 paden:** API-controllers (leaderboard, daily, friends, publiek profiel, export), resources, `User`, `RegisterRequest`, `Rules/`, `AdminPlayerService`, `Admin/PlayerController`, `CompetitionStandingsService`, `BadgeService`, `AuditUsernames`, admin-views, privacy en voorwaarden, `routes/web.php`.
- **Tests en vertalingen backend, 19 paden:** waaronder nieuw `LeaderboardNumericTest`, `AdminPlayersTest`, `DailyResultHistoryTest`, `PublicPlayerNameTest`, `RegisterUsernameTest`.
- **Mobiel, 37 paden:** schermen, `LeaderboardList`, `YourPositionCard`, locales, `competitionPeriod.ts`, `playerName.ts`, `dailyResultLoad.ts`.
- **Docs:** `api-contract.md`, `privacy-data-inventory.md`, dit rapport.

- **Daarna toegevoegd:** `Challenge`, `DailyChallenge`, `Admin/ChallengeController`, `challenges/edit.blade.php`, `ScheduleDailyChallenges`, `ResetTestDailyHistory`, `DailyHistoryClearService`, `Admin/DiagnosticsController`, `diagnostics/index.blade.php`, `config/ballspot.php`, `.env.example`, `ops-runbook.md`, en nieuw `PlayedChallengeLockTest`.

**Migraties: geen.** `git status` toont geen wijziging in `backend/database/migrations`, en tussen `10e020b` en HEAD zijn er ook geen.

## 5. Testresultaten

| Omgeving | Resultaat |
|---|---|
| PHPUnit op SQLite | 1260 tests, 8711 asserties, 0 fouten, 1 overgeslagen |
| PHPUnit op MariaDB 11.4 (tijdelijke container) | 1260 tests, 8711 asserties, 0 fouten, 1 overgeslagen. Vóór deze ronde: 1 failure en 2 errors. |
| Overgeslagen test apart op MariaDB | `MigrationIntegrityTest` 4 tests OK |
| TypeScript | Schoon |
| Jest | 36 suites, 514 tests geslaagd |
| `expo export` | iOS en Android bundelen zonder fout |
| Vergrendeld formulier in Chromium, desktop en mobiel | 14 van 14 geslaagd: velden uit, klik op foto doet niets, titel opslaan werkt |
| Admin in Chromium, desktop 1440 en mobiel 390 | 57 van 59 geslaagd. De 2 andere zijn een 403 op `x.jpg`, een niet-bestaande afbeelding uit mijn testdata. |
| iPhone SE, 5 talen | Daily Result, competitieklassement, toernooi, leden, vrienden: geen afgebroken tekst na de fixes |
| iPhone 17, Duits | Dezelfde flows geslaagd |
| Notificatiepopup, iPhone SE, 5 talen | Verse installatie, knoppen volledig zichtbaar |
| Weigeren met echte iOS-dialoog (NL) | Instellingen tonen "Geblokkeerd", voorkeuren "Aan, maar geblokkeerd", knop opent de iOS-instellingen |
| Android, echt toestel | **Niet getest** |

Na de laatste layoutfixes is de simulator opnieuw gedraaid in ES, EN en DE. De herhaalstrategie
(maximaal 2 uitnodigingen, minimaal 7 dagen ertussen) is deze ronde alleen via unit tests
gecontroleerd; de code is niet gewijzigd sinds de simulatorronde van eerder vandaag.

Admin gecontroleerd in de browser: zoeken op username, naam (ook omgekeerd), e-mail en id; paginering
met behoud van filter; statusfilters; lege staat; spinner; dossier zonder wachtwoordhash of
vriendcode; `mailto` alleen bij geverifieerd adres; 404; winnaar openen vanuit de competitiepagina;
gewone speler en gast geweigerd. Logs bevatten alleen id's en aantallen, geen zoektekst of e-mail.

Bewijs: `~/Documents/ballspot-backups/2026-11-01-release-qa/` (schermafbeeldingen en testlogs).

## 6. Bestaande gebruikers

- Spelen door zonder herinstallatie: geen migratie, geen wijziging aan tokens of login.
- Oude apps krijgen de username in `name` en zien dus meteen geen echte namen meer. De periodeteksten en de historische-resultaatfix komen pas met de nieuwe build.
- Nieuwe registraties in oude apps volgen de strengere usernameregels; bestaande accounts worden niet opnieuw gevalideerd.
- Scores, XP en rangen worden niet gewijzigd; tests leggen vast dat ranglijsten lezen en geweigerde admin-wijzigingen niets aanpassen.

## 7. Rollback

Alleen code, omdat er geen migraties zijn.

1. Als Brevo actief is: eerst `MAIL_MAILER=smtp` en `php artisan config:cache`.
2. `./deploy.sh --ref=<vorige commit>`.

Oude apps tonen na een rollback weer echte namen, en de vergrendeling van gespeelde challenges vervalt.
Een nieuwe app blijft werken op een teruggedraaide backend, zonder datum en titel op het resultaatscherm.

## 8. Android-checklist op een echt toestel

Doe dit na de backenddeployment, met de preview-APK uit sectie 9. Die praat met **productie**, dus gebruik
een eigen testaccount. Test bij voorkeur op Android 13 of hoger en, als het kan, op een klein scherm.
Stuur voor een pushtest **geen** aankondiging vanuit de admin, want die kan alle spelers bereiken.

**Installatie en sessie**
- [ ] APK installeert en start zonder crash.
- [ ] Inloggen werkt. Na volledig afsluiten en heropenen blijf je ingelogd.
- [ ] Update over een bestaande installatie (Play-versie) houdt de sessie, als de ondertekening dat toelaat.

**Notificaties: eerste uitnodiging**
- [ ] Popup verschijnt op Home; geen afgebroken knoppen; beide knoppen bereikbaar.
- [ ] "Misschien later": geen OS-dialoog. Na herstart niet opnieuw zichtbaar.
- [ ] Android-terugknop sluit de popup als "later".

**Notificaties: weigeren en toestaan** (wis eerst de app-data voor een verse start)
- [ ] "Inschakelen" toont de Android-dialoog.
- [ ] Weigeren: Instellingen tonen "Geblokkeerd" en "Aan, maar geblokkeerd door je toestel", nooit "ingeschakeld".
- [ ] "Instellingen openen" gaat naar de app-pagina van Android.
- [ ] Toestaan in Android en terugkeren: status wordt meteen actief, zonder herstart.
- [ ] Weer uitzetten in Android en terugkeren: status wordt weer geblokkeerd.

**Notificaties: levering**
- [ ] Kanaal `default` staat onder de app-instellingen van Android.
- [ ] Zet de dagelijkse herinnering een paar minuten vooruit terwijl de Daily nog niet gespeeld is, sluit de app en controleer dat er een melding komt.
- [ ] Tik op de melding: de app opent.

**Spelen**
- [ ] Daily spelen: marker plaatsen, zoomen, verzenden; verzendknop blijft bereikbaar.
- [ ] Resultaat: juiste foto, eigen gok, balpositie, score, afstand, datum en categorie.
- [ ] Resultaat opnieuw openen vanaf Home toont hetzelfde.
- [ ] Deelknop opent het Android-deelvenster.

**Ranglijsten en namen**
- [ ] Titel "Maandklassement", periodebalk, positiekaart met "ptn deze maand".
- [ ] "Top" en "Mijn plek" scrollen correct.
- [ ] Toernooi: scores en punten zichtbaar, rang `#4` leesbaar, "1 ronde" in enkelvoud, lange username afgekapt.
- [ ] Vrienden, toernooileden en spelersprofiel tonen alleen usernames.

**Android-specifiek gedrag**
- [ ] Hardware-terugknop op Daily Result, klassement en toernooi: gaat logisch terug, sluit de app niet onverwacht.
- [ ] Tekst op de grootste systeemgrootte: niets afgebroken op Home, popup en Instellingen.
- [ ] Navigatiebalk overlapt geen knoppen, zowel met gebaren als met drie knoppen.
- [ ] Vliegtuigstand tijdens laden: nette foutmelding, geen crash. Daarna herstelt de app.

**Talen**
- [ ] Wissel in Instellingen door NL, EN, FR, DE en ES en controleer popup, resultaat en klassement. Let op `PUNTUACIÓN` in het Spaans.

Slaagt alles: Android is GO. Noteer bij een fout het toestelmodel, de Android-versie en een schermafbeelding.

## 9. Commando's, in volgorde

Niets hiervan is uitgevoerd.

### 9.1 Commit en push (lokaal)

```bash
cd ~/Documents/ballspot-integration
git status --short
{ git diff --name-only; git ls-files -o --exclude-standard; } | grep -E '(^|/)\.env($|\.[^e])|\.pem$|\.p12$|\.jks$|service-account'   # verwacht: geen output
git add -A
git diff --cached --stat | tail -1
git commit -m "release: public usernames, admin players, competition periods, numeric rankings, played-challenge lock" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git log --oneline -2
git push origin master
```

### 9.2 Backend (server)

```bash
ssh deploy@168.119.248.14
cd /var/www/ballpicker
git rev-parse --short HEAD                # noteer dit als rollbackdoel
git --no-optional-locks status --short    # verwacht: leeg; deploy.sh wist lokale wijzigingen
sed -n 1,4p deploy.sh                     # moet "the entry point operators type" noemen
grep -c '^BALLPICKER_SHARE_FIRST_XP=' backend/.env   # 0 betekent: deelbeloning is 200 XP
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh && echo "backup ok"
./deploy.sh                               # migrate (niets te doen), BadgeSeeder, caches, queue-restart
```

Controle na deployment:

```bash
B=https://ballpicker.vanmalderstudio.be
curl -s $B/api/health
cd /var/www/ballpicker/backend
sudo -u www-data php artisan ballspot:health
sudo -u www-data php artisan ballspot:audit-usernames   # alleen-lezen, toont alleen id's
```

- Open `/admin/players` en zoek een speler.
- Open een dropdown op `/admin/challenges`.
- Open een gespeelde challenge en controleer de vergrendeling.
- Voer de `AboveGoalHeader`-controle uit (sectie 3) en stuur de output door.
- Staat retentie bij Brevo al op 30 dagen? De privacypagina belooft dat (`docs/brevo-email.md` §5). `MAIL_MAILER` blijft ongewijzigd.

### 9.3 Builds (lokaal, na de deployment)

```bash
cd ~/Documents/ballspot-integration/mobile
npx eas whoami
npx eas build:version:get -p ios --profile production      # nu 8
npx eas build:version:set -p ios --profile production      # interactief: hoger dan het hoogste nummer in App Store Connect
npx eas build -p ios --profile production

npx eas build -p android --profile preview                 # APK voor sectie 8
# Pas na een geslaagde Android-check:
npx eas build -p android --profile production              # versionCode telt zelf op
```

### 9.4 Indienen (pas na jouw toestemming)

```bash
cd ~/Documents/ballspot-integration/mobile
npx eas submit -p ios --profile production --latest
npx eas submit -p android --profile production --latest    # zonder serviceaccount: AAB handmatig uploaden in Play Console
```

Controleer vóór het indienen in het builddashboard dat de build de nieuwe commit heeft en niet `4af432f`.
