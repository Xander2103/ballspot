# BallPicker — beeldprestaties: audit en optimalisatie (2026-11-06)

Werkmap: `~/Documents/ballspot-integration` (master @ 3ca7cec + onderstaande niet-gecommitte wijzigingen).
Niets gecommit, gepusht, gedeployed of als EAS-build gestart. Productie alleen benaderd met
niet-geauthenticeerde GET/HEAD-requests op publieke bestanden.

## 1. Bevestigde oorzaken (top 3)

| # | Oorzaak | Bewijs |
|---|---|---|
| 1 | **Op iOS wordt elke foto 3× parallel gedownload** in de huidige app (Android/Fresco voegt ze samen): `Image.getSize()` in `ImageGuessPicker` + de `<Image>` zelf + nog een `Image.getSize()` in `FullscreenImageViewer`, die ook gemount (en dus actief) is terwijl het fullscreen-venster dicht is. | Proxylog, iOS-simulator, oude app (HEAD): `perfqa-d-h.jpg` 3× 2,39 MB binnen dezelfde milliseconde gestart; reveal idem 3× 1,99 MB; pack: 15 downloads voor 3 stappen. |
| 2 | **Originele uploads worden ongewijzigd geserveerd**: de admin uploadt tot 5 MB / ~4000 px (telefoonfoto's), de app toont ze op een kaart van ~400 pt. Er bestaan geen verkleinde versies. | `ChallengeController::store()` → `store('challenges/hidden')` zonder verwerking; alle API's gaven `asset('storage/' . $path)` van het origineel. Telefoonfoto 4032×3024 = 2,39 MB → weergavekopie 240 KB (10 %). |
| 3 | **Geen bruikbare cache**: RN `<Image>` (iOS NSURLCache / Android Fresco) zonder `Cache-Control`; productie-nginx stuurt voor `/storage/` alleen `ETag`/`Last-Modified`, geen `Cache-Control`/`Expires`. Resultaat opnieuw openen ⇒ reveal opnieuw volledig gedownload (1 van 2 runs: 3× 1,99 MB, geen conditionele request). Geen prefetch: de Daily-foto start pas met downloaden ná het openen van het Daily-scherm. | Proxylog `B-4g`: `result-again` = 3 volledige 200-downloads. `curl -I` prod: geen Cache-Control (zie §2.1). |

Bijkomend (kleiner): de reveal-afbeelding verschijnt pas na de score-response (onvermijdelijk — mag niet vooraf);
een ontbrekend `/storage/`-bestand start op productie PHP/Laravel voor een 404 (`Cache-Control: no-cache, private` op de 404);
productie spreekt HTTP/1.1 (geen HTTP/2). API-latency is géén bottleneck: `/api/daily/today` 40–90 ms lokaal,
`/api/health` ~115 ms TTFB op productie.

Niet de oorzaak (gecontroleerd): signed of wisselende URL's (bestandsnamen zijn stabiel en uniek per upload),
beeldverwerking tijdens API-requests (er was er geen), rerenders (de picker rendert één `<Image>` per URI).

## 2. Metingen

### 2.1 Productie (read-only, 2026-11-06)

| Controle | Resultaat |
|---|---|
| `GET /images/hero-stadium.jpg` (206 KB) | TTFB 0,13 s (nieuwe TLS-verbinding), totaal 0,20–0,23 s |
| Headers statische bestanden | `Content-Type`, `ETag`, `Last-Modified`, `nosniff` — **geen `Cache-Control`, geen `Expires`** |
| `GET /storage/does-not-exist.jpg` | 404 vanuit **Laravel** (`Cache-Control: no-cache, private`) → geen eigen `/storage/`-location |
| Protocol | HTTP/1.1 |
| Werkelijke grootte challenge-foto's op prod | **niet meetbaar zonder serveraccess** → `php artisan ballspot:images-audit` (read-only, zie §7) |

### 2.2 Bestandsgrootte (representatieve testbeelden, GD op PHP 8.5, WebP q82)

| Bron | Origineel | Weergavekopie | Aandeel | Tijd | PSNR vs. lossless verkleining |
|---|---|---|---|---|---|
| Luchtfoto 3840×2160 JPEG | 5,62 MB | 2048×1152, 207 KB | 3,7 % | 279 ms | 38,0 dB |
| Telefoonformaat 4032×3024 JPEG q88 | 2,39 MB | 2048×1536, 240 KB | 10,0 % | 309 ms | 38,9 dB |
| Reeds verkleind 1600×900 JPEG | 386 KB | 1600×900, 137 KB | 35,4 % | 98 ms | — |
| PNG 1608×449 | 1,31 MB | 1608×449, 144 KB | 11,0 % | 72 ms | — |

100 %-uitsnede visueel naast elkaar vergeleken: geen zichtbaar verschil (≥ 38 dB).

### 2.3 Overdrachtstijd per bestand (nginx 1.27, `curl --limit-rate`, excl. ~0,13 s verbindingsopbouw prod)

| Bestand | 50 Mbit | 10 Mbit (4G) | 1,6 Mbit (trage 3G) |
|---|---|---|---|
| 4032×3024 origineel 2,39 MB | 0,39 s | 1,92 s | 11,97 s |
| → kopie 240 KB | 0,04 s | 0,20 s | 1,19 s |
| 3840×2160 origineel 5,62 MB | 0,90 s | 4,46 s | 27,88 s |
| → kopie 207 KB | 0,02 s | 0,12 s | 0,68 s |

### 2.4 In de app (iOS-simulator iPhone 17, iOS 26.5; Android-emulator API 37)

Opstelling (alleen lokaal): QA-backend `127.0.0.1:8093` + MariaDB `ballpicker_e2e`, daarvoor een meet-nginx
(`127.0.0.1:18091`) die `/storage/` statisch serveert zoals productie (ETag/Last-Modified, géén Cache-Control in
"vóór"; `immutable` in "ná"), de snelheid begrenst (gekalibreerd: 1,15 MB/s ≈ 9 Mbit "4G", 0,19 MB/s ≈ 1,6 Mbit
"trage 3G") en elk request met tijd en bytes logt. Maestro zet markeringen in hetzelfde log. Testcontent: Daily +
pack met 3 stappen, elk met een 4032×3024-foto (2,0–2,4 MB) als guess- en reveal-afbeelding. "Vóór" = app-code
van HEAD (export), "ná" = werkboom. App-caches vóór elke run gewist (koude start), tenzij "warm".

**Belangrijk bij het lezen:** nginx' `limit_rate` geldt per verbinding. De drie parallelle downloads van de oude app
kregen hier dus elk de volle snelheid; op een echte (gedeelde) verbinding duren ze samen ~3× zo lang. De
"vóór"-tijden hieronder zijn daarom een **ondergrens**.

**Daily: Home → Daily → Resultaat → Home → Resultaat opnieuw (iOS)**

| | Vóór 4G | Ná 4G | Vóór 3G | Ná 3G |
|---|---|---|---|---|
| Daily-foto zichtbaar na tik op "Play" | foto compleet ~3,0 s na tik (gedeeld: ~7,6 s) | uit memory-cache, **44 ms** na mount (voorgeladen op Home in 0,27 s) | compleet ~16,3 s na tik (gedeeld: ~40 s) | **31 ms** na mount (voorgeladen op Home in 1,28 s) |
| Reveal zichtbaar na score | ~1,9 s (3× 1,99 MB) | **183–196 ms** (1× 243 KB) | ~10,6–10,8 s | **1,22 s** |
| Resultaat opnieuw openen | 3× 1,99 MB opnieuw (run 4G) / uit geheugen (run 3G) | **22–27 ms**, 0 requests | — | **23–26 ms**, 0 requests |
| Afbeeldingsrequests / volume | 9 / **18,26 MB** | 2 / **0,46 MB** | 6 / 12,55 MB | 2 / 0,46 MB |

**Warme herstart (iOS, app opnieuw gestart, diskcache behouden):** 0 afbeeldingsrequests; Daily-foto `cache=disk` 65 ms,
reveal `cache=disk` 45 ms. (Eerste versie downloadde hier nog 2× opnieuw via `Image.prefetch`; opgelost door eerst
`Image.getCachePathAsync` te controleren — zie §3.)

**Pack, 3 opeenvolgende foto's (iOS, 4G)**

| | Vóór | Ná |
|---|---|---|
| Stap 1 zichtbaar | compleet ~2,5 s na start | 310 ms (geen prefetch mogelijk: URL komt pas uit `/start`) |
| Stap 2 en 3 zichtbaar na "Next challenge" | compleet ~2,7 s / ~2,4 s | **16 ms** / **16 ms** (memory-cache; `next_challenge` voorgeladen tijdens het resultaat) |
| Reveal per stap | ~2,1–2,4 s | 264–290 ms |
| Requests / volume | 15 / **29,91 MB** | 6 / **1,35 MB** (incl. 240 KB Daily-prefetch) |

**Android (emulator Medium_Phone_API_37, debug-build, 4G, koude start)**

| | Vóór | Ná |
|---|---|---|
| Daily-foto | 1× 2,39 MB, download 2,20 s ná het openen van het scherm | uit **diskcache 206 ms** na mount (voorgeladen op Home, 0,26 s) |
| Reveal | 1× 1,99 MB, 1,79 s | 1× 243 KB, zichtbaar 434 ms na mount |
| Resultaat opnieuw | geen download (Fresco-cache) | 44 ms, memory-cache |
| Requests / volume | 2 / **4,18 MB** | 2 / **0,46 MB** |

Android (Fresco) voegt de `getSize`- en `<Image>`-aanvragen wél samen: de 3×-download is een **iOS**-probleem.
Op Android was de bottleneck puur bestandsgrootte + geen prefetch. Eerste ná-meting toonde een dubbele
reveal-download (prefetch nog bezig toen het scherm opende — Glide gebruikt voor prefetch een andere cache-sleutel);
opgelost (§3) en opnieuw gemeten: 1×. De Android-runs liepen onder zware systeemlast (load average 130–150 door
andere simulators/processen); absolute schermtijden daar zijn indicatief, aantallen en bytes zijn exact.

**Toernooien:** dezelfde componenten (`ImageGuessPicker`, `FullscreenImageViewer`, `ResultImageSection`) en dezelfde
backend-resolver; geen aparte in-app-meting (vergt een actief toernooi met meerdere spelers). Backend-test bewijst
kopie-URL + afmetingen vóór de gok, reveal-kopie pas erna (`test_tournament_round_serves_guess_copy_before_and_reveal_copy_after`).

Doel "< 1 s op een goede verbinding, onmiddellijk bij cache-hit": gehaald in de metingen (4G: 44 ms na prefetch,
310 ms zonder prefetch, reveal ~0,2 s; cache-hit 16–65 ms). Op trage 3G is een reveal die pas na de gok
bekend mag zijn ~1,2 s — dat is de overdracht van 243 KB en kan niet vooraf zonder het geheim te lekken.

## 3. Geïmplementeerde optimalisaties

**Backend**
- `ImageVariantService` (nieuw): maakt per upload een weergavekopie (lange zijde ≤ 2048 px voor challenges,
  ≤ 1200 px voor pack-covers), WebP q82 (JPEG q84 als GD geen WebP heeft), EXIF-oriëntatie toegepast (eigen parser,
  geen exif-extensie nodig), metadata (EXIF/GPS) verwijderd, nooit vergroot, uniforme schaal (geen crop/vervorming;
  afwijking < 1 px). Is de kopie niet ≥ 15 % kleiner en niet verkleind, dan blijft het origineel in gebruik.
  Origineel wordt nooit aangeraakt. Bestandsnaam = bronnaam + `-w2048-v1` → nieuwe upload = nieuwe URL (cache-invalidatie).
- Automatisch bij upload/vervanging (model-events `created`/`updated` op `Challenge` en `ChallengePack`), nooit
  tijdens speler-requests; faalt stil terug op het origineel (`images.variant_failed` in het log).
- Eén resolver (`hiddenImageUrl()`, `revealImageUrl()`, `hiddenImagePayload()`, `revealImagePayload()`, `coverImageUrl()`)
  in alle API's: Daily today/guess/result, pack start/guess/`next_challenge`/detail, toernooironde + guess/result.
  Nieuwe optionele velden `hidden_image_width/height`, `reveal_image_width/height` (oude apps negeren ze).
  Metadata die niet meer bij het huidige bronbestand hoort wordt genegeerd. Kill switch `BALLPICKER_IMAGE_VARIANTS=false`.
- `image_variants` / `cover_image_variant` zitten in `$hidden` (nooit geserialiseerd — bevat het pad van de reveal-kopie).
- `php artisan ballspot:images-audit` (strikt read-only) en `php artisan ballspot:images-optimize [--dry-run] [--force] [--challenge=ID]`
  (alleen nieuwe bestanden onder `…/display/` + de metadatakolom; werkt ook op gespeelde, vergrendelde challenges,
  raakt `updated_at` niet).
- Een kopie wordt niet verwijderd zolang een andere challenge (of pack) hetzelfde bronbestand gebruikt.
- `ballspot:recover-challenges` negeert `…/display/` (anders zouden kopieën als "verweesde uploads" drafts worden).

**Mobiel (Expo SDK 56, `expo-image` 56.0.13 — zat al in de app en in de bestaande native builds)**
- `ImageGuessPicker` en `FullscreenImageViewer` op `expo-image`: persistente diskcache + memorycache
  (`cachePolicy="memory-disk"`), `priority="high"`, `recyclingKey`, 120 ms cross-dissolve, spinner-placeholder.
- **Beide `Image.getSize`-probes verwijderd**: de verhouding komt uit de API (`*_image_width/height`) en anders uit
  `onLoad` van dezelfde (gecachete) load → één download per foto.
- Tikken en markers pas zodra de foto zichtbaar is op zijn echte verhouding (taps vóór het laden telden vroeger met
  de 4:3-fallback in de fullscreen-viewer).
- Fullscreen decodeert de volledige 2048 px-kopie (`allowDownscaling={false}`) → pinch-zoom blijft scherp.
- Prefetch (`services/imagePrefetch.ts` + pure regels in `utils/imagePrefetch.ts`):
  Home → guess-foto van een nog niet gespeelde Daily; toernooidetail → guess-foto van de open ronde;
  na een gescoorde pack-gok → guess-foto van de volgende stap; na een gescoorde gok → de reveal (alleen iOS,
  zie risico's). Eerst `getCachePathAsync` (staat al op disk ⇒ niets doen), de-duplicatie, nooit blokkerend.
- Pack-covers (`PacksScreen`, `PackDetailScreen`) op `expo-image` met cache.
- Dev-only laadlog `[img] <ms> cache=<none|disk|memory>` in Metro (niet in productie).

**nginx (voorstel, niet toegepast):** `docs/nginx/ballpicker-storage-cache.conf` — `location ^~ /storage/` met
`Cache-Control: public, max-age=31536000, immutable`, `try_files $uri =404` (geen PHP voor ontbrekende bestanden),
alleen GET/HEAD, `nosniff` herhaald. Gevalideerd met `nginx -t` in nginx 1.27 en gemeten (headers, 404, POST → 403).
Optioneel: `listen 443 ssl http2`. **CDN: niet aanbevolen** — na de kopieën is een speelbeurt ~0,5 MB en zit de winst in
cache + grootte, niet in afstand; een CDN zou extra kosten/infrastructuur zijn zonder aangetoond voordeel.

## 4. Veiligheid (reveal/balpositie)

- Pre-guess payloads bevatten alleen `hidden_image_*`; tests controleren dat de ruwe JSON van `/daily/today`,
  `current-round`, pack start/detail en `next_challenge` geen `original`, `reveal`, `image_variants` of de bestandsnaam van
  het origineel/de reveal-kopie bevat.
- De reveal-kopie heeft een naam afgeleid van de (willekeurige) naam van het **origineel**, niet van de guess-foto
  → niet te raden vanuit de guess-URL.
- App: prefetch van een reveal kan alleen met de response van een gescoorde gok als invoer; een jest-test scant alle
  prefetch-aanroepen in `src/` en faalt bij elke andere bron.
- Cache: URL's zijn per bestand uniek en zonder gebruikersgegevens; de cache bevat alleen wat de server die speler
  al gaf. Clubs (privéfoto's via signed routes) zit niet in deze branch en is niet aangeraakt.

## 5. Gewijzigde bestanden

Backend: `app/Services/ImageVariantService.php` (nieuw), `app/Console/Commands/ImagesAudit.php` (nieuw),
`app/Console/Commands/ImagesOptimize.php` (nieuw), `database/migrations/2026_11_06_000001_add_image_variants.php` (nieuw),
`tests/Feature/ImageVariantTest.php` (nieuw), `app/Models/Challenge.php`, `app/Models/ChallengePack.php`,
`app/Http/Controllers/Api/{DailyChallengeController,PackPlayController,ChallengePackController}.php`,
`app/Http/Resources/{LeagueRoundResource,GuessResultResource}.php`, `app/Services/PackPlayService.php`,
`app/Console/Commands/RecoverChallenges.php`, `config/ballspot.php`, `tests/Feature/ChallengeCategoryTest.php`
(gebruikt nu `Storage::fake` — schreef eerder echte bestanden in `storage/app/public`).

Mobiel: `src/services/imagePrefetch.ts` (nieuw), `src/utils/imagePrefetch.ts` (nieuw),
`src/utils/__tests__/imagePrefetch.test.ts` (nieuw), `src/components/{ImageGuessPicker,FullscreenImageViewer,ResultImageSection}.tsx`,
`src/screens/{Home,DailyChallenge,DailyResult,Guess,Result,PackGuess,PackResult,Packs,PackDetail,LeagueDetail}Screen.tsx`,
`src/types/{challenge,daily,guess,pack}.ts`, `src/utils/dailyResultLoad.ts`, `src/utils/__tests__/dailyResultLoad.test.ts`.

Docs: `docs/image-performance-2026-11.md` (dit rapport), `docs/nginx/ballpicker-storage-cache.conf`, `docs/api-contract.md`.

## 6. Testresultaten

| Suite | Resultaat |
|---|---|
| PHPUnit (SQLite in-memory, `vendor/bin/phpunit`, tijdelijke APP_KEY via env — de worktree heeft geen `.env`) | **1318 tests geslaagd**, 1 skipped (was 1300; +18 in `ImageVariantTest`). Eén run onder load ~140 gaf een timeout in `DeployScriptTest` (shellscript-test, los geslaagd; herhaalde volledige runs groen). |
| Jest | **40 suites, 595 tests geslaagd** (was 584; +11: prefetch-regels, reveal-bronscan, verhouding) |
| `tsc --noEmit` | schoon |
| Migratie op MariaDB 11.4 (QA-DB `ballpicker_e2e`) | OK (32 ms) |
| Migratie in wegwerpcontainers `mysql:8.4` en `mariadb:11.4` | migrate → rollback `--step=1` → migrate OK; kolomtype `json` (MySQL) / `longtext` (MariaDB); JSON-roundtrip + URL-resolver OK |
| Oude app (HEAD) tegen de WebP-kopieën | iOS-simulator én Android-emulator: volledige Daily-flow, foto + reveal + markers correct, score consistent met afstand |
| `nginx -t` (1.27) met het voorgestelde blok | OK |

## 7. Release-stappen (door jou uit te voeren, als `deploy` op de server)

```bash
# 0. Back-up van de database
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh && echo "backup ok"

# 1. PHP GD + WebP aanwezig? (CLI en php8.3-fpm gebruiken hetzelfde pakket)
php -r 'echo extension_loaded("gd") ? "gd ok\n" : "GD MISSING\n"; echo function_exists("imagewebp") ? "webp ok\n" : "no webp (JPEG fallback)\n";'
#    alleen als "GD MISSING":  sudo apt install php8.3-gd && sudo systemctl reload php8.3-fpm

# 2. Deploy: git reset naar origin/master, composer, migrate --force, caches, php-fpm + nginx reload
cd /var/www/ballpicker && ./deploy.sh

# 3. Controle
cd /var/www/ballpicker/backend
git log -1 --oneline                                     # = de gepushte commit
sudo -u www-data php artisan migrate:status | grep add_image_variants     # Ran
sudo -u www-data php artisan ballspot:images-audit       # read-only: echte groottes + "GD: yes · WebP encoder: yes"

# 4. Kopieën maken voor bestaande foto's (alleen nieuwe bestanden onder .../display/, herhaalbaar)
sudo -u www-data php artisan ballspot:images-optimize --dry-run
sudo -u www-data php artisan ballspot:images-optimize
sudo -u www-data php artisan ballspot:images-audit       # "Display copy" n/n en "Avg served now"

# 5. Steekproef
f=$(ls storage/app/public/challenges/hidden/display | head -1); echo "$f"
curl -sI "https://ballpicker.vanmalderstudio.be/storage/challenges/hidden/display/$f" | grep -i -E "^HTTP|content-type|content-length|cache-control"

# 6. (aanbevolen) nginx-cacheblok: plak docs/nginx/ballpicker-storage-cache.conf in de 443-serverblock, boven `location /`
sudo nginx -T 2>/dev/null | grep -n -E "# configuration file|server_name|listen 443"   # welk bestand
sudo nginx -t && sudo systemctl reload nginx
curl -sI "https://ballpicker.vanmalderstudio.be/storage/challenges/hidden/display/$f" | grep -i cache-control
#    verwacht: Cache-Control: public, max-age=31536000, immutable
```

7. **Nieuwe mobiele builds** (iOS + Android) voor de app-kant. Geen nieuwe native dependency (`expo-image` zat al in de builds).
   Volgorde maakt niet uit: oude builds werken met de nieuwe backend (gemeten), de nieuwe app met de oude backend.
8. **Rollback zonder deploy:** `BALLPICKER_IMAGE_VARIANTS=false` in `.env`, dan `sudo -u www-data php artisan config:cache`
   en `sudo systemctl reload php8.3-fpm` → overal weer originelen. Kopieën ruimen hoeft niet.
   Kopieën bouwen bij upload uitzetten: `BALLPICKER_IMAGE_VARIANTS_ON_UPLOAD=false`.

## 8. QA-kit en opgeruimde/achtergelaten zaken

Meetkit buiten de repo: `~/Documents/ballspot-image-perf-qa` (`README.md`, `run.sh`, `switch-metro.sh`, Maestro-flows,
proxylogs `proxy/logs/perf.log`, per-run logs `runs/*.log`, screenshots `shots/`).
Achtergelaten in de **lokale QA-omgeving** (nooit productie): QA-DB `ballpicker_e2e` heeft de migratie + 4 challenges
"PerfQA …", pack `perf-pack` en Daily #2 van 2026-11-06; `backend/storage/app/public/challenges/{hidden,original}/perfqa-*.jpg`
+ hun `display/`-kopieën (gitignored). Opruimcommando's staan in de README van de kit.

## 9. Releasecontrole (2026-11-07)

| Controle | Resultaat |
|---|---|
| Geen reveal vóór de gok | Reveal-URL's alleen in `buildGuessResult` (Daily guess/result), `PackPlayController::guess` en `GuessResultResource` (toernooi guess/result) — allemaal vanuit een bestaande gok. Tests op ruwe JSON van alle pre-guess endpoints; app-bronscan op prefetch-aanroepen. |
| Geen verschuiving van coördinaten | Score gebruikt alleen 0–1-ratio's (`ScoreService`), admin-balplaatsing ook (t.o.v. de getoonde afbeelding, EXIF toegepast door de browser). Kopie = uniforme schaal (< 1 px afwijking, test), EXIF-oriëntatie ingebakken (test met kleurvlakken). In-app: afstand en score consistent op iOS en Android. |
| Caching en invalidering | Elke upload krijgt een unieke willekeurige naam (`store()`/`Str::random`), kopieën heten naar hun bron + versie; vervangen = nieuwe URL (test). Metadata die niet bij het huidige bronbestand hoort wordt genegeerd (test). Gedeelde bron: kopie blijft (test). |
| Bestaande builds | Oude app (RN `Image`) toont de WebP-kopieën op iOS en Android (gemeten). iOS-minimum van RN 0.85 = 15.1 (WebP sinds iOS 14). Nieuwe velden optioneel. Nieuwe app werkt ook tegen de oude backend (geen velden → verhouding uit `onLoad`). |
| Migratie / uploads | Additief, nullable, omkeerbaar; getest op MySQL 8.4 + MariaDB 11.4. Originelen worden nooit gewijzigd of verwijderd door de service of de commando's (test: bytes identiek). |
| Notificatie- en Monthly-fixes | Zitten al in HEAD (08a521a/3ca7cec); deze wijziging raakt geen van die bestanden; hun tests zitten in de groene suites. |
| Secrets | Diff + nieuwe bestanden gescand: geen sleutels, wachtwoorden of tokens (alleen test-tokens). |

## 10. Resterende risico's

- PNG/WebP-bronnen met een EXIF-oriëntatietag (zeldzaam) worden niet gedraaid; alleen JPEG-oriëntatie wordt toegepast.
- **Prod-cijfers ontbreken nog**: de winst is gemeten op representatieve testfoto's; de werkelijke verhouding hangt af
  van wat er geüpload is (`ballspot:images-audit` geeft het antwoord). Zijn uploads al klein, dan is de bytes-winst kleiner;
  de 3× → 1× download- en cachewinst geldt hoe dan ook.
- **Android-prefetch**: Glide gebruikt voor prefetch (volle grootte) een andere sleutel dan het scherm (weergavegrootte).
  Gemeten: een prefetch die nog loopt als het scherm opent, wordt dubbel gedownload. Daarom geen reveal-prefetch op
  Android; bij een pack-"Next" binnen ~0,2–1,2 s na het resultaat kan de volgende foto op Android nog 2× komen (alleen bytes, niet trager).
- **Upload duurt ~0,3 s langer** per 12 MP-foto (synchrone kopie). Geheugen: tot ~50 MB voor een 4032×3024-decode;
  de service verhoogt `memory_limit` alleen voor dat request als nodig.
- Fysieke toestellen niet getest (simulator/emulator); Android alleen op de emulator, iOS alleen in de simulator.
- Toernooi-flow niet in de app gemeten (zelfde componenten; backend-test aanwezig).
- Oude app-builds blijven 3× downloaden (bug zit in de app); met kleinere kopieën is dat 3× ~240 KB i.p.v. 3× 2,4 MB.
- WebP: ondersteund door iOS 14+, Android, expo-image en alle huidige browsers; heel oude iOS (< 14) zou het niet tonen
  (Expo SDK 56 vereist al een nieuwere iOS).
