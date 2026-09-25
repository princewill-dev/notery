# Notery audit remediation tracker

Branch: `fix/security-audit`. Source of findings: `NOTERY_AUDIT.md`.

Legend: [x] done · [~] partial · [ ] pending

## Critical — done

- [x] **C1** Rate-limit code lookups. Per-IP limiters (`code-lookup`, `code-create`, `portal-poll`, `portal-action`, `upload`) applied to every code-addressed route. `app/Providers/RouteServiceProvider.php`, `routes/web.php`. Commit `48bdb89`.
- [x] **C2** Bound unique-code generation to 50 attempts with a clean 503/validation error, and allocate across both `saves` and `portal_sessions`. `SaveController`, `UploadController`, `PortalController`. Commit `48bdb89`.
- [x] **C3** Disable Ignition runnable solutions via `config/ignition.php` (closes `/_ignition/execute-solution` even with `APP_DEBUG=true` + `APP_ENV=local`); add safe `.env.example`. Commit `991088e`.

## High — done

- [x] **H1** Quote-safe `escapeHtml` in `portal.js`/`uploader.js` (filename can no longer break out of `alt="..."`); server-side filename sanitization. Commit `85cddb1`.
- [x] **H2** Reject SVG on both portal paths, serve only raster allowlist inline with `nosniff`, remove SVG from the direct note image rule. Commit `85cddb1`.
- [x] **H3** Per-type byte and chunk caps, max file count, conflicting `total_chunks` rejection, and MIME/declared-type checks in both chunked flows. `UploadController`, `PortalController`, `UploadValidationException`. Commit `85cddb1`.
- [x] **H4** Portal poll cursor switched to monotonic message id; client dedupes by id and stops overlapping polls. Commit `85cddb1`.
- [x] **H5** Note/attachment purge deferred by a 15-minute download grace period (`saves.delete_after`); cleanup handles it and runs every 5 minutes. Commit `85cddb1`.
- [x] **H6** Note assembly does file IO outside the DB transaction, unlinks partial outputs, and deletes chunks/increments stats only after commit. Commit `85cddb1`.
- [x] **H7** Peer slots claimed inside a locked transaction; idle peers evicted via `portal_sessions.peer_seen_at`. Commit `85cddb1`.
- [x] **H8** `public/.htaccess` committed and un-ignored. Commit `85cddb1`.
- [x] **H9** Self-contained multi-stage Docker image (npm build, `composer install --no-dev`, no bind-mount dependency) + `.dockerignore`; image builds successfully. Commit `e7ee302`.
- [x] **H10** Streaming AES-256-GCM file encryption (`App\Support\EncryptedFile`) for note and portal attachments; portal text encrypted; remaining code/peer values removed from logs. Commit `c436176`.

## Verification

- `./vendor/bin/phpunit` → 18 tests, 61 assertions, all passing (note flow, portal flow, upload caps, rate limits, encryption round-trip/tamper).
- `npm run build` → succeeds; committed bundle regenerated.
- `docker build` → succeeds (multi-stage). Note: older unencrypted `save_images` rows remain readable because the download path falls back to the legacy decrypt; no data migration is required.

## Medium — pending

- [ ] **M1** Derive `peer_id` strictly from the session; keep ids out of URLs/logs.
- [ ] **M2** Lock manifest read-modify-write (or move chunk state to a row).
- [ ] **M3** Validate magic bytes on the assembled file (done for portal) and require `total_chunks` consistency (done both).
- [ ] **M4** Allowlist stored extensions (done) — verify no remaining client-extension paths.
- [ ] **M5** Add a unique index on `saves.code` (allocation now cross-checks; index still missing).
- [ ] **M6** Bind/shorten/single-use signed URLs; check portal status on download.
- [ ] **M7** Cleanup now runs every 5 min with `withoutOverlapping`; still needs a supervised scheduler in the deployment.
- [ ] **M8** `SESSION_SECURE_COOKIE` in `.env.example`; still need trusted proxies, `TrustHosts`, security headers.
- [ ] **M9** Cap messages per portal; `LOG_CHANNEL=daily` no-op (`stack` hardcoded to `single`).
- [ ] **M10** Sanitize download filenames (done); verify all download paths.
- [ ] **M11** Portal assembly is now transactional and scoped; chunk deletion unconditional case reviewed.
- [ ] **M12** Consolidate conflicting upload ini limits.
- [ ] **M13** Shared session store for multi-instance deploys.
- [ ] **M14** Build assets in CI (Docker now builds them); unify the CSS pipeline.
- [ ] **M15** `QUEUE_CONNECTION=sync` in `.env.example`; delete dead queue/api/auth surface.

## Low / Info — pending

- [ ] **L1–L16** Stats dead data, dead code/assets, root `index.php`, weak PRNG (fixed), error leakage/status codes, client edge cases, accessibility, railway.toml, SVG inconsistency, logging.
