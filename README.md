# ProofVault (WitnessVault)
# Tamper-Evident Emergency Evidence Capture System
A secure field tool for investigators to record and preserve evidence in a way that can't be quietly altered after the fact.

# Access & security

An investigator authenticates by unlocking a secure "vault" with a master keycode, gating who can capture or view evidence.
# Capture

Once unlocked, they stream video, audio, and photos directly into the vault.
Every new piece of media is written to an append-only log nothing can be edited or deleted, only added.
# Tamper evidence (the core guarantee)

Each entry is fingerprinted with SHA-256 and cryptographically linked to the previous entry, forming a hash chain. If any file or record is changed after capture, the chain breaks and the tampering becomes provable.
# Context & intelligence

Captures are tagged with GPS coordinates, building a location trail of where evidence was collected and when.
An AI risk-scoring layer flags each session or item by threat/severity level to help prioritize review.
# Review & handoff

Investigators can replay a full session — media, timestamps, GPS path, and risk scores together.
The system generates chain-of-custody exports: verifiable reports proving who captured what, when, where, and that the evidence is intact — suitable for legal/audit use.

Powered by **Laravel 12**, **React 19**, **Vite 7**, and **Tailwind CSS 4**.

---

## Table of contents

1. [What it does](#what-it-does)
2. [Stack](#stack)
3. [Requirements](#requirements)
4. [Local setup](#local-setup)
5. [Running the app](#running-the-app)
6. [Authentication](#authentication)
7. [Core concepts](#core-concepts)
8. [API reference](#api-reference)
9. [Frontend](#frontend)
10. [Background jobs and integrations](#background-jobs-and-integrations)
11. [Database](#database)
12. [Environment variables](#environment-variables)
13. [Artisan commands](#artisan-commands)
14. [Testing](#testing)
15. [Docker / Render deployment](#docker--render-deployment)
16. [Project layout](#project-layout)
17. [Known local gotchas](#known-local-gotchas)

---

## What it does

ProofVault keeps media evidence safe so that it cannot be silently modified once it has been captured.

- **Capture** — the browser MediaRecorder streams video or audio in roughly 3-second chunks, and also supports one-shot photos and file uploads
- **Hash chain** — every chunk receives both `SHA-256(bytes)` and `SHA-256(previous_cumulative + chunk_hash)`, beginning from a 64-zero genesis hash
- **WORM policy** — any PUT / PATCH / DELETE on evidence routes returns 403, and finalized sessions refuse new chunks
- **GPS** — lat/lng/accuracy are recorded on each chunk, and a Leaflet map renders the trail
- **AI risk** — Gemini (with a local heuristic fallback) scores weapon / violence / acoustic distress
- **Alerts** — a high-risk verdict can fan out to Telegram, ElevenLabs voice notes, and SMS (Africa's Talking or Twilio)
- **Exports** — a forensic PDF (DomPDF) plus a ZIP package (`report.pdf`, `ledger.json`, `hashes.txt`, and an optional stitched MP4)
- **Cover UI** — the login screen is disguised as a “Fruit Ninja Dojo”, and once unlocked it turns into the ProofVault control room.

Both public routes — `/` and `/vault` — render the same vault SPA.

---

## Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.2+, Laravel 12, Sanctum |
| Frontend | React 19, Vite, Tailwind 4, Leaflet |
| Local DB | SQLite (`database/database.sqlite`) |
| Production DB | MySQL (see `render.yaml`) |
| Queues / cache (prod) | Redis |
| Evidence storage | Local disk, Cloudflare R2, or S3 |
| PDF | barryvdh/laravel-dompdf |
| Optional AI / alerts | Gemini, Telegram, ElevenLabs, Africa's Talking / Twilio |

---

## Requirements

- PHP 8.2+ together with `pdo_sqlite` (local) or `pdo_mysql` (prod)
- Composer 2
- Node.js 18+ and npm
- Recommended PHP extensions: `zip`, `intl`, and `gd` (enable them in `php.ini` on XAMPP if they are missing)
- Optional: Redis, ffmpeg (for frame extraction and MP4 stitching), and API keys for Gemini / Telegram / ElevenLabs / SMS

---

## Local setup

From the project root:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File database\database.sqlite -Force
```

Edit `.env` and set:

```env
DB_CONNECTION=sqlite
EVIDENCE_DISK=local
```

`EVIDENCE_DISK=r2` without R2 credentials will throw on every upload (`r2` disk has `'throw' => true`).

Then:

```powershell
php artisan migrate
php artisan vault:set-master-key Kenya123
npm install
npm run build
```

Default investigator after `vault:set-master-key`:

- Email: `admin@witnessvault.test`
- Master keycode: whatever you passed (example above: `Kenya123`)

---

## Running the app

**Two terminals (recommended on Windows):**

```powershell
php artisan serve
```

```powershell
npm run dev
```

Open http://localhost:8000 and unlock with the master keycode.

If you already ran `npm run build`, Vite is optional; `php artisan serve` alone serves compiled assets from `public/build`.

**Do not use `composer run dev` on Windows.** That script starts `php artisan pail`, which needs `pcntl`/`posix` and will kill the whole concurrently group.

**Optional Redis worker** (threat analysis / Telegram / voice):

```powershell
# .env: REDIS_CLIENT=predis  (no phpredis needed if predis is installed)
php artisan queue:work redis --queue=threat-analysis,default
```

Threat jobs hardcode `->onConnection('redis')`. Without Redis they fail quietly (dispatch is wrapped in try/catch); uploads still succeed.

---

## Authentication

### Master keycode (primary UI flow)

| Method | Path | Body |
|--------|------|------|
| POST | `/api/v1/auth/unlock` | `{ "keycode": "..." }` |
| POST | `/api/v1/auth/register` | `{ "name": "...", "keycode": "..." }` |

Returns Sanctum bearer token + user. Token is stored in `sessionStorage` as `vault_token`.

Register creates a user with a synthetic `@proofvault.local` email and a bcrypt `master_key_hash`. Keycodes must be unique across users.

### Email / password (API)

| Method | Path | Body |
|--------|------|------|
| POST | `/api/v1/auth/login` | `{ "email", "password" }` |

### CLI helpers

```powershell
php artisan vault:set-master-key Kenya123
php artisan vault:create-user --email=you@example.com --password=Secret123!
```

Dashboard auto-locks after 60 seconds of inactivity (timer paused while recording).

---

## Core concepts

### Evidence session

UUID primary key. Status: `active` | `finalized` | `interrupted`. Risk: `unassessed` | `low` | `medium` | `high`.

Human ID: `PV-YYYYMMDD-XXXX` via `EvidenceSession::evidenceId()` (Africa/Nairobi date).

### Hash chain

```
GENESIS = 64 zero hex chars
chunk_hash       = SHA-256(raw bytes)
cumulative_hash  = SHA-256(previous_cumulative + chunk_hash)
session.chain_hash = tip of the chain
```

Ingest streams `php://input` in 8KB buffers so large clips are not held fully in memory (`EvidenceHashingService`).

### WORM

- Append only while `status === active`
- Finalize seals the session
- Any PUT/PATCH/DELETE under `/api/v1/evidence/*` returns 403 with a WORM message

### Public ingest rate limit

`POST /api/v1/evidence/session/start` is limited to **4 requests per day per IP** (`emergency-ingest`). Authenticated `POST /api/v1/evidence/session` is not throttled that way.

---

## API reference

Base: `/api/v1`

### Auth

- `POST /auth/login`
- `POST /auth/unlock`
- `POST /auth/register`

### Evidence (public ingest)

- `POST /evidence/session/start` — start session (throttled)
- `POST /evidence/{sessionId}/chunk` — raw body + headers:
  - `Content-Type`, optional `X-Chunk-Ext`
  - `X-Captured-At`, `X-Geo-Lat`, `X-Geo-Lng`, `X-Geo-Accuracy`
- `POST /evidence/{sessionId}/finalize`

### Evidence (Sanctum)

- `GET /evidence` — paginated list + stats
- `POST /evidence/mock` — fake high-risk session for UI demos
- `POST /evidence/session` — authenticated start
- `POST /evidence/{sessionId}/upload-file` — multipart file (max 50MB); use `sessionId=new` to create a session
- `POST /evidence/{sessionId}/override-risk` — `{ "risk_level": "high|medium|low", "reason?" }`
- `GET /evidence/{sessionId}` — metadata + chunks + signed/media URLs
- `GET /evidence/{sessionId}/chunks/{sequence}/media` — authenticated stream
- `GET /evidence/{sessionId}/playback` — playback manifest
- `GET /evidence/{sessionId}/verify` — integrity verdict (`VERIFIED` or `TAMPER_DETECTED` + 422)
- `GET /evidence/{sessionId}/report` — PDF download
- `GET /evidence/{sessionId}/export-package` — ZIP download

Send `Authorization: Bearer {token}` on Sanctum routes.

---

## Frontend

Entry: `resources/js/witnessvault/main.jsx` → `ProofVaultApp` → `EvidenceDashboard`.

| Module | Role |
|--------|------|
| `ProofVaultApp.jsx` | Cover / unlock / register keyboard UI |
| `EvidenceDashboard.jsx` | Sessions, capture, verify, export, risk override |
| `useEvidenceCapture.js` | MediaRecorder, GPS, offline queue drain |
| `offlineQueue.js` | IndexedDB queue when offline / upload fails |
| `api.js` | Fetch helpers |
| `sha256.js` | Client-side chain verify |
| `GpsTimelineMap.jsx` | Leaflet OpenStreetMap trail |

Capture modes: video stream, audio-only, snapshot photo, file upload. “Simulate Phone Seizure” tears down the client without finalize so server-held chunks remain.

---

## Background jobs and integrations

All threat jobs use Redis queue `threat-analysis`.

| Job | Purpose |
|-----|---------|
| `ProcessEvidenceChunkThreatJob` | Gemini or heuristic scoring; escalate session risk; chain Telegram + voice on **high** |
| `BroadcastTelegramAlertJob` | MarkdownV2 alert to `TELEGRAM_ALERT_CHANNELS` |
| `DispatchVoiceBriefingJob` | ElevenLabs MP3 → Telegram voice note |
| `DispatchSmsAlertJob` | SMS with GPS + one-time access code (service exists; not currently chained from the threat job) |

**Gemini** (`GEMINI_API_KEY`): vision on image or ffmpeg-extracted video frame. Without key/ffmpeg, heuristic scores are derived from the chunk hash.

**SMS**: `SMS_DRIVER=africastalking` (default) or `twilio`; recipients in `SMS_EMERGENCY_RECIPIENTS` (comma-separated).

**Evidence disk**: production Render blueprint uses S3; local should use `local`. Briefings currently write via `Storage::disk('s3')` in ElevenLabs/Telegram voice helpers — configure AWS/S3 vars if you need that path.

---

## Database

**Local:** SQLite file `database/database.sqlite`.

**Production:** MySQL (`DB_CONNECTION=mysql` in `render.yaml`).

### App tables

- `users` — includes nullable email, `master_key_hash`
- `personal_access_tokens` — Sanctum
- `evidence_sessions`
- `evidence_chunks` — unique `(session_id, sequence_number)`, GPS, hashes, `ai_threat_indicators` JSON
- `audit_logs` — `session.started`, `chunk.ingested`, `report.generated`, etc.
- Plus Laravel cache / jobs / sessions tables as migrated

---

## Environment variables

Copy `.env.example` to `.env` and fill in values there. Do not commit secrets. For local development, set `DB_CONNECTION=sqlite` and `EVIDENCE_DISK=local`.

---

## Artisan commands

| Command | Description |
|---------|-------------|
| `php artisan migrate` | Run migrations |
| `php artisan vault:set-master-key {key}` | Set investigator master key (default arg `Kenya123`) |
| `php artisan vault:create-user` | Create/update user + print Sanctum token |
| `php artisan serve` | Dev HTTP server |
| `php artisan queue:work redis --queue=threat-analysis,default` | Process alert/AI jobs |
| `php artisan test` | Run PHPUnit |

---

## Testing

```powershell
php artisan test
```

Feature coverage in `tests/Feature/ProofVaultSecurityTest.php`:

- Intact hash chain → `VERIFIED`
- Corrupted intermediate chunk → `TAMPER_DETECTED` (422)
- PUT/PATCH/DELETE → 403 WORM

PHPUnit forces SQLite in-memory, `EVIDENCE_DISK=local`, `QUEUE_CONNECTION=sync`.

---

## Docker / Render deployment

- `Dockerfile` — PHP 8.3-FPM Alpine, Nginx, Supervisor, Composer vendor stage
- `docker/entrypoint.sh` — config/route/view cache + `migrate --force`
- `docker/nginx.conf` — port 8080, `client_max_body_size 100M`
- `render.yaml` — web service, Redis worker (`queue:work redis --queue=threat-analysis,default`), Redis instance, MySQL + S3 env wired via dashboard secrets

Health check: `GET /up`.

---

## Project layout

```
app/
  Console/Commands/     vault:set-master-key, vault:create-user
  Http/Controllers/Api/V1/   Auth, MasterKey, EvidenceSession, EvidenceChunk
  Jobs/                 threat analysis + Telegram / voice / SMS
  Models/               User, EvidenceSession, EvidenceChunk, AuditLog
  Services/             hashing, forensic PDF/ZIP, Gemini, Telegram, SMS, ElevenLabs, media
database/migrations/
resources/
  js/witnessvault/      React SPA
  views/vault.blade.php
  views/reports/forensic.blade.php
routes/api.php
routes/web.php
docker/
render.yaml
```

---

## Known local gotchas

1. Set `EVIDENCE_DISK=local`, otherwise uploads will fail against an empty R2 config.
2. Avoid `composer run dev` on Windows (Pail / pcntl).
3. Enable `extension=zip` so ZIP exports (`ZipArchive`) work.
4. Enable `extension=intl` if `php artisan db:show` complains.
5. Threat / Telegram / voice features require Redis plus keys; capture still works without them.
6. The public `session/start` route is capped at 4/day/IP, so use the authenticated session start from the UI.
7. Mock sessions create ledger rows with no binary files — the player will report that no stream is stored.

---

## License

MIT (Laravel skeleton base). Application code in this repository follows the same terms unless otherwise noted.
