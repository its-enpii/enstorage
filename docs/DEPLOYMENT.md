# Production Deployment — EnStorage WebSocket Auto-Sync

Last updated: 2026-07-01. All code is committed on `main`; this document
covers VPS-side configuration only.

---

## 1. Backend env vars (`backend/.env` on the VPS)

Add to your existing `backend/.env` (don't commit this file):

```bash
# ─── Broadcasting driver ─────────────────────────────────────
BROADCAST_CONNECTION=reverb

# ─── Reverb (WebSocket server) ──────────────────────────────
# Public key — same value goes to NEXT_PUBLIC_REVERB_APP_KEY
# in the web container's build args (see section 3).
REVERB_APP_ID=enstorage
REVERB_APP_KEY=<generate-public-key-below>
# Private secret — DO NOT expose to web/frontend.
REVERB_APP_SECRET=<generate-secret-below>
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

Generate the two values (run on VPS or any Linux box):

```bash
# 32-char public key (also used by frontend)
openssl rand -hex 16

# 64-char private secret
openssl rand -hex 32
```

---

## 2. Reverse proxy — Nginx Proxy Manager

The `reverb` container listens on container port `8080` over the
`web-network` Docker network. Browsers cannot reach Docker containers
directly, so NPM must terminate TLS and forward the WSS upgrade.

### NPM Location Block

In NPM → your domain (`enpii.enpiistudio.com`) → **Advanced** tab →
add a new location:

| Field | Value |
|---|---|
| Location | `/app` |
| Scheme | `http` |
| Forward host | `reverb` |
| Forward port | `8080` |
| Websocket support | ✅ ON |
| Block common exploits | ✅ ON |
| Cache assets | ❌ OFF |

Custom Nginx config (paste into the "Custom Nginx Configuration"
textbox on the location):

```nginx
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_read_timeout 86400;
proxy_send_timeout 86400;
proxy_buffering off;
```

Apply. Done.

### Verify NPM → reverb

On VPS:

```bash
docker network inspect web-network | grep -E "(reverb|Name)"
# Must show reverb in web-network

docker compose logs -f reverb
# Watch for "Connection opened" lines when a browser loads the app
```

---

## 3. Web container build args (host shell env on VPS)

`NEXT_PUBLIC_*` are baked into the JS bundle at build time, so you must
set them in the **shell** before running `docker compose build web`.

```bash
# On the VPS, in the project root:
export NEXT_PUBLIC_REVERB_SCHEME=wss
export NEXT_PUBLIC_REVERB_HOST=enpii.enpiistudio.com
export NEXT_PUBLIC_REVERB_PORT=443
# MUST equal REVERB_APP_KEY from backend/.env
export NEXT_PUBLIC_REVERB_APP_KEY=<paste-the-same-public-key>

# Rebuild + restart
docker compose build web
docker compose up -d web
```

Defaults already exist in `docker-compose.yml` (scheme=wss, host=
enpii.enpiistudio.com, port=443, app_key blank). Override only what
differs from your domain.

---

## 4. Deploy commands (full sequence)

```bash
# 1. Pull latest code
cd /path/to/enstorage
git pull origin main

# 2. Edit backend/.env on the host — add the Reverb vars (section 1)
nano backend/.env

# 3. Set build args + rebuild web image (section 3)
export NEXT_PUBLIC_REVERB_APP_KEY=<value>
docker compose build web
docker compose up -d

# 4. (One time, if you haven't) ensure the external network exists
docker network create web-network

# 5. Verify all containers are up
docker compose ps
# Expect: app, worker, reverb, postgres, redis, nginx, web
```

---

## 5. Post-deploy smoke test

### A. Reverb container reachable via web-network

```bash
docker compose exec nginx wget -qO- http://reverb:8080/
# Should return Pusher's "Welcome to Pusher" or similar handshake
# (or at minimum not timeout / connection refused)
```

### B. Broadcasting auth endpoint

```bash
# Get a test API key for your user
docker compose exec app php artisan tinker --execute='echo \App\Models\ApiKey::first()->key;'

# Test auth
docker compose exec app curl -sS -X POST http://localhost/api/v1/broadcasting/auth \
  -H "Authorization: Bearer <api-key>" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "socket_id=123.456&channel_name=private.client.<your-client-key>.folder.root"
# Should return JSON with `auth: "..."` (Pusher HMAC signature)
```

### C. Browser WSS handshake

1. Open `https://enpii.enpiistudio.com` in Chrome.
2. Open DevTools → **Network** tab → filter by **WS**.
3. Log in, open the files page.
4. You should see a `wss://enpii.enpiistudio.com/app/...` connection
   with status **101 Switching Protocols**.
5. From another browser/Postman, upload a file via API as the same user.
6. The files page should show the new file within ~1 second, **without
   a manual refresh**.

If step 4 shows `404` or `failed to connect`, the NPM location block
is wrong — re-check section 2.

---

## 6. Rollback

If Reverb breaks production:

```bash
# 1. Stop the container
docker compose stop reverb

# 2. Tell the web app to disable WS by removing the build arg
unset NEXT_PUBLIC_REVERB_APP_KEY
docker compose build web
docker compose up -d web

# 3. Broadcasting falls back to: web polling (2s interval) + FCM
#    (mobile). Existing UI continues to work, just slower.
```

---

## Quick reference — file locations on VPS

| File | Purpose |
|---|---|
| `backend/.env` | Reverb secrets + DB/Redis/queue config (gitignored) |
| `docker-compose.yml` (root) | Orchestrates app + web + reverb stack |
| `backend/docker-compose.yml` | Backend services (app, worker, reverb, etc.) |
| `web/Dockerfile` | ARG declarations for NEXT_PUBLIC_REVERB_* |
| NPM location block | WSS upgrade `/app` → `reverb:8080` |
