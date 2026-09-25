# Notery audit remediation tracker

Branch: `fix/security-audit`. Source of findings: `NOTERY_AUDIT.md`.
Legend: [x] done · [~] partial/accepted · [ ] pending

## Critical — done

- [x] **C1** Per-IP rate limiters on every code-addressed route (`48bdb89`).
- [x] **C2** Bounded code generation + cross-namespace allocation (`48bdb89`).
- [x] **C3** Ignition runnable solutions disabled; safe `.env.example` (`991088e`).

## High — done

- [x] **H1** Quote-safe escaping / filename sanitization (`85cddb1`).
- [x] **H2** SVG rejected, raster-only inline, nosniff (`85cddb1`).
- [x] **H3** Server-side byte/chunk/file caps and MIME checks (`85cddb1`).
- [x] **H4** Poll cursor by monotonic message id (`85cddb1`).
- [x] **H5** 15-minute download grace period before purge (`85cddb1`).
- [x] **H6** File IO outside the DB transaction; partial-output cleanup (`85cddb1`).
- [x] **H7** Atomic peer-slot claim + idle eviction (`85cddb1`).
- [x] **H8** `public/.htaccess` committed (`85cddb1`).
- [x] **H9** Self-contained multi-stage Docker image (`e7ee302`).
- [x] **H10** Streaming AES-256-GCM encryption for portal text and all attachments (`c436176`).

## Medium — done

- [x] **M1** Session-only peer identity; `is_mine` instead of peer ids (`caeabfa`).
- [x] **M2** Manifest read-modify-write serialized with a cache lock (`caeabfa`).
- [x] **M3** Assembled-file MIME/magic validation and `total_chunks` consistency (`85cddb1`, `caeabfa`).
- [x] **M4** Stored extensions allowlisted from detected MIME (`85cddb1`).
- [x] **M5** Unique index on `saves.code` + retry on conflict (`caeabfa`).
- [x] **M6** Signed URLs shortened to 5 min; portal downloads require a live participant (`caeabfa`).
- [x] **M7** Cleanup runs every 5 minutes with `withoutOverlapping`; Dockerfile cron (`85cddb1`).
- [x] **M8** Security headers, trusted proxies, production-only TrustHosts (`caeabfa`).
- [x] **M9** 200-message portal cap (`caeabfa`).
- [x] **M10** Download filenames sanitized (`c436176`).
- [x] **M11** Portal assembly transactional, scoped to the portal, chunks deleted after commit (`85cddb1`).
- [x] **M12** Upload ini limits consolidated at 512M/520M (`caeabfa`).
- [x] **M13** Database sessions table + `.env.example` default (`caeabfa`).
- [x] **M14** Empty Vite CSS entry removed; Docker builds assets (`caeabfa`, `e7ee302`).
- [x] **M15** Dead controllers/views and `/api/user` removed; `QUEUE_CONNECTION=sync` (`caeabfa`, `a549b5e`).

## Low / Info — done or accepted

- [x] **L3** ~12 MB unreferenced assets deleted (`a549b5e`).
- [x] **L4** Root `index.php` removed (`a549b5e`).
- [x] **L5** `random_int` for codes; nested function removed (`48bdb89`).
- [x] **L6** Correct 404/410/409 statuses; internal messages no longer echoed (`a549b5e`, `c436176`).
- [x] **L7** Zero-byte files skipped client-side (`a549b5e`).
- [x] **L8** Legacy blob null/corrupt guards (`c436176`).
- [x] **L10** Directory listings disabled; dotfiles denied (`a549b5e`).
- [x] **L11** `since`/`last_id` validated (`85cddb1`).
- [x] **L12** Debug logging and inline placeholder removed (`a549b5e`).
- [x] **L13** Creator peer slot registered at create time (`48bdb89`).
- [x] **L14** Dialog roles and input labels added (`a549b5e`).
- [x] **L15** Undocumented `railway.toml` block removed (`a549b5e`).
- [x] **L16** SVG validation consistent across both note paths (`85cddb1`).
- [~] **L1** Stats counters remain written but unread; the `firstOrCreate` race is mitigated by Laravel's `createOrFirst`. Accepted as informational.
- [~] **L2** Legacy `User` model/factory and Sanctum dependency remain; unused but harmless. Accepted.
- [~] **L9** Migration-window `is_encrypted` default: left as-is because a legacy `Crypt`-on-disk row cannot be safely distinguished from a plaintext row by content sniffing. The window was one week in October 2025.

## Verification

- `./vendor/bin/phpunit` → 21 tests, 67 assertions, all passing.
- `npm run build` → succeeds; committed bundle regenerated.
- `docker build` → succeeds (multi-stage).
- `php artisan route:list` → boots with all middleware registered.
- Existing plaintext attachments and legacy portal text remain readable via fallbacks; only new writes are encrypted, so no data migration is required.

## Deployment notes

1. Run the new migrations (`2026_09_25_000001` … `000005`). `000004` dedupes any duplicate `saves.code` rows before adding the unique index.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_DRIVER=database` on the host (see `.env.example`).
3. Verify the scheduler is actually running (`saves:cleanup` every 5 minutes).
4. Consider tuning the code-lookup rate limit (15/min per IP) if users share a NAT/proxy.
