# Notery — Deep Codebase Audit

**Scope:** the full application at `notery/` — Laravel 10 / PHP 8.3, notes + chunked uploads + the Portal feature.
**Method:** line-by-line read of controllers, models, routes, middleware, migrations, Blade views, JS clients, commands, config, and deployment files; three independent parallel audits; high-impact claims re-verified against vendored framework/Symfony source and the filesystem.
**Revision audited:** `master` @ `df835b2`.

---

## 1. TL;DR

Notery's only access control is a **4-digit code** (10,000 values), and **nothing rate-limits code lookups**. A parallel audit brute-forced a live note's code from its stored SHA-256 in 4,137 attempts in under a second. The same enumeration lets an attacker join any active Portal as the second participant, read everything shared, impersonate a peer, or close the session. On top of that: chunked uploads have **no server-side size/type caps**, portal content and note attachments are stored **in plaintext**, and two client-side XSS sinks let a malicious participant run script in the other peer's origin.

| Severity | Count | Headline |
|---|---|---|
| Critical | 3 | Code enumeration (confirmed live); code-space exhaustion DoS; `APP_DEBUG=true` + Ignition |
| High | 10 | 2 XSS sinks; unbounded chunked uploads; second-granularity poll cursor; attachments deleted on first view; FS writes inside DB transaction; peer-slot eviction/squatting; missing `.htaccess`; non-self-contained image; plaintext data |
| Medium | 15 | peer_id trust, manifest races, magic-byte gaps, extension control, missing unique index, replayable signed URLs, cleanup, cookie/headers, logging, download 500s, non-transactional assembly, limit disagreement, file sessions, build drift, dead queue |
| Low/Info | 15 | Stats dead data, dead code/assets, weak PRNG, error leakage, client edge cases, accessibility, ops nits |

**Most urgent:** (1) throttle + lengthen the code, (2) verify/disable `APP_DEBUG` and Ignition on the live host, (3) fix the two XSS sinks, (4) enforce upload caps server-side.

---

## 2. How the system fits together

```
Notes:   POST /save (encrypted body) | POST /upload/chunk + /upload/assemble → storage/app/notery/*
         GET /{code} → portal? redirect : decrypt note, count view, delete at limit
         GET /attachments/{id} (signed)

Portal:  POST /portal/create (5/10/30/60 min) → GET /p/{code} (max 2 peers)
         GET /portal/{code}/poll?since= · POST /message · /upload-chunk · /upload-assemble · /close
         GET /portal/{code}/attachment|download/{messageId} (signed)

Cron:    hourly  saves:cleanup      (notes >3h; expired/closed portals)
         daily   saves:clean-chunks (abandoned chunk dirs >24h)
```

**Data at rest**

| Data | Storage | Encrypted? |
|---|---|---|
| Note body (`saves.writeup`) | Postgres `longText` | Yes — `Crypt::encryptString` |
| Note attachments (`save_images.path`) | `storage/app/notery/*` | **No** — `is_encrypted = false` |
| Legacy note blob (`save_images.image`) | Postgres | Yes (legacy path only) |
| Portal text (`portal_messages.content`) | Postgres `text` | **No** |
| Portal files (`portal_messages.image_path`) | `storage/app/notery/*` | **No** |
| Access codes | `sha256(code)` in DB | Unsalted hash of a 4-digit value = reversible |

---

## 3. Critical

### C1 — No rate limiting or lockout on 4-digit code lookups → full enumeration
- **Category:** authz
- **Location:** `routes/web.php:12-51`, `app/Http/Kernel.php:64` (alias exists, never applied), `app/Providers/RouteServiceProvider.php:26-28` (limiter is `api`-only), `SaveController.php:115-244`, `PortalController.php:65-137`
- **Evidence:** every sensitive route is in the unthrottled `web` group; no `throttle:` appears in any route file. A parallel audit queried the live PostgreSQL DB, took the stored hash of the single note, and recovered **code `4137` after 4,137 attempts (<1s)**.
- **Impact:** the 10,000-value space is enumerable in minutes. An attacker can read every note (and, because default `max_views=1`, **destroy** it in the same request), and enumerate `GET /p/{code}` to claim the free peer slot on any live portal — gaining full read, send, upload, close, and attachment rights.
- **Fix:** apply `throttle:` per IP and per code to `/{code}`, `/p/{code}`, and all `/portal/{code}/*` routes; add failed-lookup lockout/backoff; move to a high-entropy secret (≥128-bit) with the 4-digit code retained only as a display key; stop storing a tiny-space secret as bare `sha256`.

### C2 — 4-digit space exhaustion + unbounded creation → non-terminating generator
- **Category:** dos
- **Location:** `PortalController.php:23-63`, `:769-783`; `SaveController.php:42-46`
- **Evidence:** generation spins with no iteration cap (`do { … } while (PortalSession::where('code',$hash)->exists());`), and `POST /portal/create` is unauthenticated beyond the CSRF token (obtainable from any `GET /`).
- **Impact:** ~10,000 cheap creates fill the namespace for up to 60 minutes; every later `create` then loops indefinitely on DB queries (query time is exempt from `max_execution_time`), pinning PHP workers and blocking legitimate portal creation. The same applies to `POST /save` once 10k note codes exist. Expired rows are only reaped hourly, so the attack is sustainable.
- **Fix:** rate-limit creation, bound the retry loop (e.g. 20 tries → 503), widen the code, and purge/deny when saturated.

### C3 — `APP_DEBUG=true` + Ignition debug routes paired with the live domain
- **Category:** config/security
- **Location:** `.env:2-5` (`APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=https://notery.cc`); `vendor/spatie/laravel-ignition/src/ignition-routes.php:16`
- **Evidence:** `spatie/laravel-ignition` is installed and auto-discovered; it registers `_ignition/execute-solution`, `_ignition/health-check`, and `_ignition/update-config`. `.env` is gitignored, so this is a **deployment-state** risk, but the file in the repo points at the production host.
- **Impact:** *if this `.env` is what the deployed host runs*, any error renders stack traces (paths, SQL, env), and the Ignition solution endpoint is a known remote-code-execution class of surface; a leaked `APP_KEY` additionally lets an attacker forge the signed attachment URLs.
- **Fix:** confirm the production env; set `APP_ENV=production`, `APP_DEBUG=false`; deploy with `composer install --no-dev` so Ignition is absent; rotate `APP_KEY` and DB credentials if the dev `.env` was ever used in production.

---

## 4. High

### H1 — DOM XSS: `escapeHtml()` does not escape quotes, and attacker filenames land in an `alt="…"` attribute
- **Category:** XSS
- **Location:** `resources/js/portal.js:411-417` (sink), `:640-644` (`escapeHtml`); sources `PortalController.php:337, 557-559, 616-619`; validation only `string|max:1024` at `:389, :519`
- **Evidence:**
  ```js
  + '<img src="' + this.escapeHtml(msg.view_url) + '" alt="' + this.escapeHtml(fileName) + '" class="portal-card-image" loading="lazy" />'
  escapeHtml(str) { const div = document.createElement('div'); div.appendChild(document.createTextNode(str)); return div.innerHTML; }
  ```
  HTML text-node serialization escapes only `&`, `<`, `>`, `\u00A0` — **not** `"`. So `escapeHtml('x" onload="alert(1)')` emits the raw quote and breaks out of the attribute.
- **Impact:** a malicious participant uploads a file whose `original_name` is `x" onload="alert(document.domain).png`; the victim's file card renders an injected `onload` that fires when the valid image loads — script execution in the notery origin (read `window.__portalConfig.peerId`, impersonate, exfiltrate the conversation). With C1 this is remotely reachable against arbitrary portals.
- **Fix:** context-correct escaping (escape `"`, `'`, `` ` ``, `&`, `<`, `>`), preferably build attributes via `setAttribute`/`textContent`; validate `original_name` server-side.

### H2 — Stored XSS via inline-served SVG
- **Category:** upload/XSS
- **Location:** `PortalController.php:287-298` (`image` rule), `:300-329` (`type=file` has no type check), `:671-680` (`inline` disposition); `resources/js/portal.js:422` ("View full")
- **Evidence:** Laravel's `image` rule accepts `svg` (`vendor/.../ValidatesAttributes.php:1315`), and non-image serving trusts the stored MIME:
  ```php
  $isImage = $message->image_mime && str_starts_with($message->image_mime, 'image/');
  $disposition = $isImage ? 'inline' : 'attachment';
  ```
- **Impact:** a participant uploads an SVG (via `type=image`, or `type=file` + `attachment_type=image`); the peer clicking **View full** navigates to a signed same-origin URL served `inline` as `image/svg+xml`, executing embedded JavaScript in the notery origin.
- **Fix:** reject SVG; always serve user content as `attachment` (or from a separate cookieless origin with `CSP: sandbox` + `X-Content-Type-Options: nosniff`); add magic-byte validation to the non-chunked path.

### H3 — Chunked uploads bypass all server-side size, count, and minimum bounds
- **Category:** upload/DoS
- **Location:** `UploadController.php:197-370`, `PortalController.php:17, 384-392, 411-418, 496-648`
- **Evidence:** `total_chunks`, `file_index`, and `files.*.index` have `min:0/1` but **no `max`**; `chunk` has no minimum; only per-chunk `MAX_CHUNK_SIZE` (6 MB) is checked; `assemble()` computes `totalSize` but never compares it to a limit; magic bytes are checked only on chunk 0.
- **Impact:** direct API calls ignore every advertised limit. A participant can fill `storage/app` without bound, create millions of 1-byte chunk inodes (`total_chunks` up to 10^7), create unlimited files via unbounded `file_index`, and force O(n²) CPU/IO because each chunk rewrites the whole manifest JSON.
- **Fix:** enforce per-upload byte caps, max chunk/file counts derived from `attachment_type`, sequential chunks or per-request size bounds, and re-validate the assembled file; add per-IP/per-portal quotas and throttle the routes.

### H4 — Poll cursor has second granularity and filters with a strict `>`, silently dropping messages
- **Category:** correctness
- **Location:** `PortalController.php:194-200`; migration `2026_07_19_000002` line 20; `resources/js/portal.js:362-365`
- **Evidence:** `$table->timestamp('created_at')` uses `Blueprint::timestamp($column, $precision = 0)` (`vendor/.../Blueprint.php:1209`) → Postgres `timestamp(0)`. Every `created_at` is second-aligned, and the client advances its cursor to the newest delivered value; the server then filters `where('created_at', '>', $since)`.
- **Impact:** any message inserted in the same wall-clock second as the newest already-delivered one is **never returned** — permanently lost from the feed. This hits consecutive sends and text+file within a second, which is common.
- **Fix:** cursor by monotonic `id` (`where('id','>',$lastId)`) with client de-duplication, or `timestamp(6)` plus id tie-breaking.

### H5 — Attachments are deleted in the same request that first displays them
- **Category:** correctness/lifecycle
- **Location:** `SaveController.php:85` (default `max_views=1`), `:188-222` (URLs minted, view rendered), `:225-235` (files/rows deleted)
- **Evidence:** the `show` view is built, then on the final view the rows and files are deleted before the response is sent. The rendered HTML contains signed download links to rows that no longer exist.
- **Impact:** with the default `max_views=1`, every attachment download 404s — attachments on default notes are effectively undownloadable. Only notes with `max_views>1` allow downloading on an earlier view.
- **Fix:** do not delete in the request that serves the note; delete after a grace period, asynchronously, or embed the bytes in the final response.

### H6 — Filesystem writes/deletes inside a DB transaction → orphan files and irrecoverable lost uploads
- **Category:** lifecycle/race
- **Location:** `UploadController.php:244` (transaction), `:277-326` (file writes), `:357-361` (`deleteDirectory`, then Stats)
- **Evidence:** assembly writes output files and deletes the chunk directory **inside** `DB::transaction`. A later failure (missing chunk for file 2, DB/Stats error) rolls back the DB but leaves already-written files in `storage/app/notery`, which `saves:cleanup` never sweeps because it only walks `Save` rows. The live tree already shows two orphan `.jpg` files with no DB rows and one DB row whose `.zip` is missing from disk.
- **Impact:** unbounded orphaned storage (compounding H3), and a failed post-delete step loses the chunks while the DB rolls back, so the upload cannot be retried.
- **Fix:** assemble to a temp path and rename after commit; move chunk deletion and stats to `DB::afterCommit`; unlink partial outputs on failure; add a reconciler that diffs `storage/app/notery` against `save_images.path`.

### H7 — Peer-slot check-then-write loses updates, evicting peers; slots are never released
- **Category:** race/authz
- **Location:** `PortalController.php:96-129`
- **Evidence:** `peer_ids` is an Eloquent `array` cast (`PortalSession.php:11-14`), so `$session->save()` overwrites the whole JSON column. Two concurrent `show()` calls can both read `count<2`; the later save drops the other's UUID. Nothing removes a peer on disconnect or session end.
- **Impact:** (a) a user already shown a `peer_id` that was then overwritten gets `403 Not a participant` on every later poll/send for the portal's whole life; (b) because slots never free, two cookie-less requests (or a scanner) can occupy both slots and permanently lock out the intended partner for the full 5–60 minute window. (Correcting an earlier hypothesis: the cap cannot be exceeded to 3 — the failure mode is eviction/squatting, not escalation.)
- **Fix:** claim slots atomically (conditional `UPDATE … WHERE json_array_length(peer_ids) < 2` under a row lock, or a `portal_peers` table with a unique constraint), expire idle peers, and support rejoin via a per-peer secret.

### H8 — `public/.htaccess` is gitignored and absent → clean URLs 404 on any fresh deploy
- **Category:** deploy
- **Location:** `.gitignore:19` (`.htaccess`); `public/.htaccess` does not exist; `Dockerfile:18,20-24` only enables `AllowOverride`/`mod_rewrite`
- **Evidence:** `git ls-files` and `find` confirm no `.htaccess` ships outside `vendor/`. A fresh clone/image build therefore has no front-controller rewrite. The live site only works if someone hand-created the file on the host.
- **Impact:** on a clean checkout, CI build, or new host, every clean URL (`/{code}`, `/p/{code}`, `/portal/...`, `/attachments/...`) 404s; only `/` and real files resolve.
- **Fix:** commit `public/.htaccess` with the standard Laravel rewrite block (or move the rewrite into the Apache vhost in the Dockerfile) and remove `.htaccess` from `.gitignore`.

### H9 — Docker image is not self-contained
- **Category:** deploy
- **Location:** `Dockerfile:1-44`
- **Evidence:** the image never runs `composer install` or `npm ci && npm run build`, ships no `vendor/` or `public/build`, and has no `.dockerignore`. It only works because `docker-compose.yaml:6-7` bind-mounts the entire repo over `/var/www/html`.
- **Impact:** `docker build` + `docker run` alone cannot boot (no autoloader, no Vite manifest → hard 500 on every page). Any real container platform or teammate's clean clone fails.
- **Fix:** copy `composer.json`/`composer.lock` and run `composer install --no-dev --optimize-autoloader`; copy the app; run `npm ci && npm run build`; cache config/routes/views; add a `.dockerignore`.

### H10 — Sensitive data at rest is plaintext, and codes are logged in the clear
- **Category:** privacy/crypto
- **Location:** `PortalController.php:273-278` (text), `:349-360`/`:616-624` (files), `:39` (unsalted `sha256`), `:54` (`Log::info('Portal created', ['code' => $code …])`); `SaveController.php:100`; `UploadController.php:353`
- **Evidence:** portal content is inserted verbatim and files are written raw; note attachments set `is_encrypted => false` even though the decrypt path and column exist; plaintext portal codes are written to logs, defeating the hash-only design.
- **Impact:** DB/backup/replica access exposes every portal conversation and reverses every code offline; storage access exposes message files and note attachments. The daily logs hold plaintext codes and peer ids (`portal.js` also puts `peer_id` in query strings, landing in access logs/history).
- **Fix:** encrypt portal content and all attachment bytes (reuse the `is_encrypted` decrypt path), redact codes/peer ids from logs, and stop putting peer ids in URLs.

---

## 5. Medium

| ID | Finding | Location | Impact | Fix |
|---|---|---|---|---|
| M1 | `peer_id` accepted from query/body, not bound to session | `PortalController.php:762-767`; logs at `:160-167`; `portal.js` poll URL | Possessing a peer UUID (no cookie) authenticates as that peer: send as them, download, assemble, close. IDs leak via URLs/logs. | Derive identity strictly from the session or a signed per-peer token; keep IDs out of URLs/logs |
| M2 | Manifest read-modify-write race + shared `manifest.tmp` | `UploadController.php:141-165,417-430`; `PortalController.php:450-474,801-810` | Concurrent chunks clobber `received_chunks`; a chunk that uploaded is reported missing and assembly fails 400 | Lock per upload (`Cache::lock`), unique temp names, or DB-backed chunk state |
| M3 | Magic bytes checked only on chunk 0; `total_chunks` trusted; no MIME cross-check | `PortalController.php:427-436,461-472,875-894`; `UploadController.php` same | Polyglot payloads behind a valid header; truncated/mis-sized files assembled as "success" | Re-validate the assembled file (magic + `finfo`); require `total_chunks` to match (409); verify size/MIME |
| M4 | Attacker-controlled stored extension; `fallbackExtension()` returns MIME types | `UploadController.php:260-262,504-513`; `PortalController.php:566-568,864-873` | `.php`/`.html` files can be stored; no-extension files become `notery/<uuid>.image/jpeg`, creating stray directories. Not web-served today; RCE if the docroot/symlink changes | Allowlist extensions per `attachment_type`; return real extensions from the fallback |
| M5 | `saves.code` has no unique index; note/portal namespaces shadow each other | migration `2023_05_29_034241:16`; `SaveController.php:124-136` | Concurrent saves can create duplicate codes and orphan a note; a portal can shadow a note's code so `/{code}` redirects away. Every lookup is a seq scan, amplifying C1 | Add a unique index and retry on violation; enforce global uniqueness across both tables |
| M6 | Signed URLs are replayable bearer tokens, not bound to peer/state | `PortalController.php:217-228,650-709`; `SaveController.php:189` | Any leaked/history-cached link downloads the file for 10 minutes, by anyone, repeatedly — including after close/expiry; the path discloses the code | Bind the signature to the peer/session, check `status`/`expires_at`, shorten TTL or make single-use |
| M7 | Cleanup is age-only, ignores remaining views, and is not running here | `CleanupOldSaves.php:31-52`; `Kernel.php:15-19` | A `max_views=100` note is deleted after 3h with views unused; live evidence shows a June note and 14 portal sessions still present → the scheduler is down, retaining sensitive data indefinitely. No `withoutOverlapping()` | Supervise/health-check the scheduler; document or align TTL semantics; add `withoutOverlapping()` |
| M8 | Session cookie not force-secure; no trusted proxies; `TrustHosts` off; no security headers | `config/session.php:171`; `TrustProxies.php:15`; `Kernel.php:17` | Behind a TLS-terminating LB `isSecure()` is false, so cookies may lack `Secure`; Host header unconstrained; no CSP/HSTS/`X-Frame-Options`/`nosniff` | Set `SESSION_SECURE_COOKIE=true`, declare proxies, enable `TrustHosts`, add baseline headers |
| M9 | Unbounded messages + per-poll INFO logging + log channel misconfig | `PortalController.php:139-246`; `config/logging.php:44-47` | Poll returns full history and logs 2–3 lines every 1.5s; the `stack` channel is hardcoded to `single`, so `LOG_CHANNEL=daily` is a no-op and `laravel.log` grows unbounded (a single day already reached 798 KB with repeated errors) | Cap messages, log polls at debug, point `stack` at `daily` |
| M10 | Crafted `file_name` with `/` makes Download throw an uncaught 500 | `PortalController.php:704-708` | A peer uploading `original_name = a/b.txt` makes the victim's Download 500 (debug page when `APP_DEBUG=true`) | Sanitize `file_name` on ingestion and/or pass a generated safe name |
| M11 | Portal assembly is non-transactional, deletes chunks unconditionally, `upload_id` not scoped | `PortalController.php:496-648,630` | Concurrent/duplicate assemble creates duplicate message cards + orphan files; failures strand output; any participant knowing an upload UUID can destroy its chunks (UUID-gated) | Wrap in a transaction with a session lock; delete only assembled chunks in `finally`; reject mismatched `portal_code`/`peer_id` |
| M12 | Upload limits disagree across four layers | `public/.user.ini:1-2`; `php/conf.d/uploads.ini:1-2`; `home.blade.php:35-38`; `uploader.js:14-19`; `SaveController.php:58-69` | The two ini files contradict (100M/120M vs 500M/500M) and which wins depends on SAPI (mod_php ignores `.user.ini`; FPM honors it), so advertised 200–500 MB uploads can fail opaquely; `post_max_size=500M` equals the max ZIP size, so a max-size multipart body can be dropped | Consolidate to one ini ≥ the largest advertised limit; align UI, client, and validation |
| M13 | File session driver + full-repo bind mount break multi-instance deploys | `config/session.php:21`; `docker-compose.yaml:6-7` | Portal identity lives in the session; with >1 replica a returning peer is treated as new and hits "portal is full"; sessions vanish on redeploy; bind-mounting the repo lets env/logs/cache drift into the developer tree | Use a shared session store (database/redis) or sticky sessions; mount only `storage/` |
| M14 | Build pipeline: committed artifacts can drift, CSS is uncacheable, `@vite` has no fallback | `public/build/*`; `vite.config.js:7`; `layout.blade.php:13-14` | Edit JS, forget `npm run build`, and production serves stale JS; 21 KB of hand-maintained CSS gets no hash/cache headers; a missing manifest hard-500s every page; Vite builds an empty CSS entry | Build in CI/image; import the CSS through Vite or cache-bust it; drop the empty entry |
| M15 | Queue configured with no worker; large dead surface | `.env` `QUEUE_CONNECTION=database`; `Dockerfile`; `jobs` migration; `routes/api.php:17`; `routes/channels.php`; `User` model/factory; Sanctum/Pusher | The first `dispatch()` silently accumulates rows in `jobs` forever; the api/channels/User stack has no users table and is unusable dead weight | Set `QUEUE_CONNECTION=sync` or add a supervised worker; delete unused package/config/code |

---

## 6. Low / Info

| ID | Finding | Location |
|---|---|---|
| L1 | `Stats` counters are written but never read; the `firstOrCreate` race is actually mitigated by Laravel 10's `createOrFirst` | `Stats.php:15-38` |
| L2 | Dead code: `PageController`, `externalController`, `find`/`success`/`code` views (broken `/find` form target, hardcoded fake code `8876566`) | various |
| L3 | 12 MB of unreferenced legacy theme assets tracked under `public/assets/` | `public/assets/` |
| L4 | Duplicate root `index.php` skips the maintenance-mode check; risk only if docroot is misconfigured (would expose `.env`/`storage`) | `index.php` |
| L5 | `rand()` (non-CSPRNG) used for codes; nested `function generateRandomString` inside a method | `SaveController.php:31-39` |
| L6 | Internal error detail leaked (`'Download failed: '.$e->getMessage()`); missing notes return HTTP 200, making enumeration responses distinguishable | `AttachmentController.php:101-108`; `SaveController.php:149-243` |
| L7 | Zero-byte file yields `totalChunks=0`, breaking the whole batch; cancel never notifies the server, orphaning chunks up to 24h | `uploader.js:117-143,356-362` |
| L8 | Legacy base64 fallback silently serves 0 bytes on decode failure; `Crypt::decryptString(null)` raises an uncaught `TypeError` | `AttachmentController.php:91-100` |
| L9 | `is_encrypted` default `true` added 2025-10-17 may mislabel plaintext disk rows created 2025-10-04→10-17 → decrypt 500 | migration `2025_10_17_130000:15` |
| L10 | Apache `Options Indexes` enables directory listings; `^\.ht` deny does not cover `.user.ini`, which is likely served | `Dockerfile:20-24` |
| L11 | `poll` 500s on `since[]=…` (array → `TypeError`); non-numeric `since` parses to 0 and replays full history | `PortalController.php:194-200` |
| L12 | Client: concurrent polls duplicate messages (no `id` dedupe); polling never stops on a missing portal; `console.log` debug + inline "JS loaded" placeholder overwrites the real status text | `portal.js:301-353`; `portal.blade.php:98-104` |
| L13 | `create()` returns a `peer_id` it never registers, so any client trusting it gets 403 | `PortalController.php:41-52` |
| L14 | Inline Blade scripts duplicate the bundled JS; clipboard logic copied 5×; modals lack `role="dialog"`/focus trap; code inputs unlabeled; progress bars lack ARIA | `home.blade.php`, `show.blade.php`, `portal.blade.php` |
| L15 | `railway.toml` uses an undocumented `[nixpacks.env]` schema; build env may be ignored | `railway.toml` |
| L16 | SVG is accepted on the direct `/save` path but rejected by the chunked magic-byte check — inconsistent enforcement | `SaveController.php:58-69` vs `UploadController.php:118-133` |

---

## 7. Non-findings (verified safe)

- **CSRF:** `VerifyCsrfToken` has no exclusions; all POSTs are in the `web` group and the JS clients send the token.
- **View-limit locking:** on the configured PostgreSQL, `DB::transaction` + `lockForUpdate()` + in-transaction `increment()` correctly serialise concurrent retrievals; a second request blocks, re-reads the deleted row as `null`, and gets "Invalid code". (Caveat: on SQLite `lockForUpdate` is a no-op and the race would return.)
- **Download authorization:** signed middleware is active on note and portal download routes; portal message lookups are scoped by `portal_session_id`, so no cross-portal IDOR. Signature forgery requires `APP_KEY`.
- **SQL injection:** all queries use bound Eloquent/query-builder calls; no raw interpolated SQL.
- **Text XSS:** Blade `{{ }}` escaping and `escapeHtml` cover text content, labels, and filenames in text contexts; only the attribute sink H1 and inline-SVG H2 are exploitable.
- **Chunk path traversal:** `upload_id` is UUID-regex-pinned and indices are cast to integers; `pathinfo(PATHINFO_EXTENSION)` never returns separators. Output stays on the private `local` disk.
- **Validation bypass:** `nullable|required_with:attachment` still enforces `RequiredWith` because it is implicit; `image`/`mimes` use content-based `guessExtension()`.
- **Storage exposure today:** DocumentRoot is `/public` and there is no `public/storage` symlink, so `storage/app/notery` is not directly web-served.
- **`deleteExpiredSessions` OR precedence:** no bug today (no other conjuncts); fragile if a `where` is added later.
- **Stats race:** mitigated by `createOrFirst`; increments are atomic SQL.
- **Secrets:** `.env` is not tracked; `storage/app/*` is gitignored.
- **Route resolution:** all code routes are constrained `[0-9]{4}`, so `/{code}` cannot shadow `/p/...`.

---

## 8. Verification notes (claims tested and corrections)

- **Confirmed live:** a stored note hash was reversed to code `4137` in 4,137 attempts; `pg_indexes` shows no unique index on `saves.code`; a June-created note and 14 portal sessions persist, proving the scheduled cleanup is not running in that environment.
- **Confirmed against vendor source:** `ValidatesAttributes.php:1315` includes `svg` in the `image` rule; `Blueprint.php:1209` defaults `timestamp` precision to 0; `HeaderUtils.php:187` throws for `/` and `\` in disposition filenames; `ignition-routes.php:16` registers `execute-solution`.
- **Confirmed against the tree:** `public/.htaccess` is absent and `.gitignore:19` ignores it; the installed bundle currently matches `portal.js`.
- **Refuted claim:** an audit asserted that `home.blade.php:253-260` throws `TypeError` on every Escape keypress because `portalCreated`/`portalCreate` are declared later with `var`. This is a **false positive**: the IIFE runs synchronously to completion before any keydown event can be dispatched, so both variables are assigned by the time the handler runs. It remains fragile ordering (L14-adjacent), not a runtime bug.
- **Corrected claim:** the peer-slot race cannot admit a third peer; because `save()` overwrites the JSON column, the failure mode is losing one peer's UUID (eviction), plus slot squatting — not exceeding the cap.
- **Mechanism correction:** `public/.user.ini` is ignored under `php:8.3-apache` (mod_php), so the effective Docker limit is `php/conf.d/uploads.ini` (500M/500M). The cross-layer disagreement remains a real configuration smell (M12), but the override direction depends on the SAPI.

---

## 9. Prioritized remediation

**Now (hours):**
1. Throttle + lockout on every code-addressed route; widen the code — closes C1, mitigates C2.
2. Confirm/disable `APP_DEBUG` and remove Ignition from production — closes C3.
3. Fix H1 and H2 (escape attributes; reject SVG; never serve user content inline).
4. Bound chunk counts, file counts, and total bytes server-side — closes H3.
5. Commit `public/.htaccess` — closes H8.

**Next (days):**
6. Encrypt portal content and all attachment files; redact codes from logs — closes H10.
7. Move the poll cursor to monotonic ids — closes H4.
8. Stop deleting attachments in the serving request; move chunk deletion/stats to after-commit — closes H5, H6.
9. Atomic peer-slot claim + idle expiry — closes H7.
10. Add a unique index; enforce a single code namespace — closes M5.
11. Bind/shorten/single-use signed URLs — closes M6.
12. Shared session store; supervise the scheduler; reconcile `.user.ini` — closes M7, M12, M13.

**Later (cleanup):**
13. Make the image self-contained (H9). Build assets in CI (M14). Add real feature tests. Delete dead code/assets (L2, L3, L15). Add security headers and hardening (M8, L10).
