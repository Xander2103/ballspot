# Release: Brevo email, privacy text, landing and download pages

Pushing and deploying are done by hand; nothing here runs automatically.

## What goes out

Production runs `10e020b`. `deploy.sh` runs `git reset --hard origin/master`, so
**everything between that commit and `origin/master` goes live**, not just the
three new commits.

Backend commits already on `origin/master` that go live with this release:

| Commit | Effect |
|---|---|
| `8f323a4` | Adds `next_daily` to `GET /daily/today` (additive; old apps ignore it) |
| `950d518` | First-share reward goes from 25 to 200 XP, unless `BALLPICKER_SHARE_FIRST_XP` is set in `.env`. Accounts that already claimed are not awarded again |

The other commits in `10e020b..b7e8a17` touch only `mobile/`, docs, `.easignore`
and a `.DS_Store`, none of which the server executes. In that range
`backend/database`, `backend/composer.json`, `backend/composer.lock` and
`backend/scripts` are unchanged, so `migrate` has nothing to run, `composer install`
changes nothing, and the deploy script that starts the deploy is the one it deploys.

Held back from this release: `mobile/src/utils/share.ts` and its test, because
they link to `/download`, which must be live first; and `mobile/eas.json`, the
owner's own edit.

## 1. Read-only check on the server

```bash
ssh deploy@168.119.248.14
cd /var/www/ballpicker
git --no-optional-locks status --short   # any modified file would be discarded by the deploy
sed -n 1,4p deploy.sh                    # must say "the entry point operators type"
grep -c '^BALLPICKER_SHARE_FIRST_XP=' backend/.env   # 0 means the reward becomes 200 XP
```

Stop if `status` lists modified files, or if `deploy.sh` is not the wrapper
(runbook §5.2): the old script runs artisan as `deploy`, which caused the
2026-10-15 outage.

## 2. Push (local)

The release is three commits on `master`: the Brevo transport, the privacy text,
and the landing and download pages.

```bash
git status --short                 # expected: only the three mobile/ files
git log --oneline origin/master..  # expected: exactly these three commits
git diff origin/master --name-only | grep -E '(^|/)\.env($|\.[^e])'   # expected: no output
git push origin master
```

## 3. Deploy, test, switch

1. Complete the prerequisites in `docs/brevo-email.md` §5.
2. `cd /var/www/ballpicker && ./deploy.sh`. Expected: exit 0, about 1–2 minutes of
   maintenance. SMTP stays active.
3. Check the public pages:

   ```bash
   B=https://ballpicker.vanmalderstudio.be
   curl -s $B/ | grep -c 'og:image'                        # 1 or more
   curl -sI $B/ | grep -ci set-cookie                      # 0
   curl -sI -A 'iPhone' $B/download | grep -i '^location'  # apps.apple.com
   curl -sI -A 'Android' $B/download | grep -i '^location' # play.google.com
   curl -s $B/download | grep -o 'property="og:url" content="[^"]*"'  # your https address
   curl -sI $B/images/og-image.jpg | head -1               # 200
   curl -s $B/privacy | grep -c 'Sendinblue SAS'           # 1
   curl -s $B/api/health
   ```

4. `docs/brevo-email.md` §5 steps 1–4: key, one test email, switch, real flows.

## 4. Rollback

| What | How |
|---|---|
| Email only | `docs/brevo-email.md` §6. No deploy needed |
| Code | First `MAIL_MAILER=smtp` and `config:cache`, then `./deploy.sh --ref=10e020b` |
| Privacy text only, if you stay on SMTP | `git revert` the privacy commit, push, `./deploy.sh` |

## 5. Afterwards

Commit the `share.ts` change, then build the app. Old builds keep sharing `/`,
which now shows the store buttons.
