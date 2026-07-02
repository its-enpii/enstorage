#!/usr/bin/env python3
"""
ws-push-check.py — minimal Reverb WS subscriber test.

Verifies whether Reverb pushes events to a private-channel subscriber
in the SAME docker network that the PHP app uses, bypassing the
browser entirely. If events are received here but not in the browser,
the issue is NPM WSS proxy. If not received here, Reverb internal
pub/sub is broken.

Usage (must run from a host that can reach enstorage-reverb:8080):

  python3 tools/ws-push-check.py \
      --token "<Sanctum PAT>" \
      --reverb-host enstorage-reverb \
      --reverb-port 8080 \
      --app-key ce8eb7614a7dce48671a367aa9f54 \
      --app-secret "<REVERB_APP_SECRET from backend/.env>"

What it does:
  1. POST /broadcasting/auth against the API host (configurable,
     defaults to enstorage-nginx) to obtain the Pusher auth signature
     for `private-user-{id}.folder.root`.
  2. Connect WS to ws://enstorage-reverb:8080/app/{APP_KEY}, send
     pusher:subscribe for the channel.
  3. Wait up to 10 seconds for incoming frames. If you trigger an
     upload (in another terminal) during this window, the file-upload
     event should land here.
"""

import argparse
import asyncio
import base64
import hashlib
import hmac
import json
import sys
import urllib.parse
import urllib.request

import websockets  # pip install websockets


def fetch_user_id_from_token(token: str, db: dict) -> str:
    pat_id = token.split("|", 1)[0]
    # Lightweight pgsql query via psql in the host shell.
    import subprocess
    out = subprocess.check_output([
        "docker", "compose", "exec", "-T", "postgres", "psql",
        "-U", db["user"], "-d", db["name"],
        "-tAc", f"SELECT tokenable_id FROM personal_access_tokens WHERE id = {pat_id}",
    ], cwd=".").decode().strip()
    if not out:
        raise SystemExit(f"Token id {pat_id} not found in personal_access_tokens")
    return out


def fetch_latest_file_for_user(user_id: str, db: dict) -> dict:
    import subprocess
    out = subprocess.check_output([
        "docker", "compose", "exec", "-T", "postgres", "psql",
        "-U", db["user"], "-d", db["name"],
        "-tAc",
        f"SELECT id || '|' || name || '|' || COALESCE(folder_id::text,'null') || '|' || mime_type || '|' || size "
        f"FROM files WHERE user_id = '{user_id}' ORDER BY id DESC LIMIT 1",
    ], cwd=".").decode().strip()
    if not out:
        raise SystemExit("no files for this user")
    parts = out.split("|")
    return {"id": parts[0], "name": parts[1], "folder_id": parts[2], "mime_type": parts[3], "size": parts[4]}


def sign_pusher(method: str, path: str, params: dict, secret: str) -> str:
    # Reverb's signing string is METHOD + "\n" + path + "\n" + sorted query
    sorted_params = sorted(params.items())
    qs = "&".join(f"{k}={urllib.parse.quote(str(v), safe='')}" for k, v in sorted_params)
    return hmac.new(secret.encode(), f"{method}\n{path}\n{qs}".encode(), hashlib.sha256).hexdigest()


def broadcast_event(host: str, port: int, app_id: str, app_key: str, app_secret: str,
                    name: str, channel: str, data: str) -> int:
    body = json.dumps({"name": name, "channel": channel, "data": data}, separators=(",", ":"))
    md5 = hashlib.md5(body.encode()).hexdigest()
    now = str(int(__import__("time").time()))
    params = {
        "auth_key": app_key,
        "auth_timestamp": now,
        "auth_version": "1.0",
        "body_md5": md5,
    }
    sig = sign_pusher("POST", f"/apps/{app_id}/events", params, app_secret)
    params["auth_signature"] = sig
    qs = urllib.parse.urlencode(params)
    url = f"http://{host}:{port}/apps/{app_id}/events?{qs}"
    req = urllib.request.Request(url, data=body.encode(), headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status
    except urllib.error.HTTPError as e:
        return e.code


async def main(args):
    db = {"user": args.db_user, "name": args.db_name}
    user_id = fetch_user_id_from_user(args.user, db) if args.user else fetch_user_id_from_token(args.token, db)
    channel = f"private-user-{user_id}.folder.root"

    # 1. channel auth via API
    auth_body = urllib.parse.urlencode({
        "socket_id": "123456.789",
        "channel_name": channel,
    }).encode()
    auth_url = args.api_base.rstrip("/") + "/broadcasting/auth"
    req = urllib.request.Request(auth_url, data=auth_body, headers={
        "Authorization": f"Bearer {args.token}",
        "Content-Type": "application/x-www-form-urlencoded",
    })
    with urllib.request.urlopen(req, timeout=5) as resp:
        if resp.status != 200:
            raise SystemExit(f"auth failed HTTP {resp.status}")
        auth = json.loads(resp.read())["auth"]
    print(f"[check] subscribed channel = {channel}")
    print(f"[check] auth = {auth[:24]}...")

    # 2. open WS
    ws_url = f"ws://{args.reverb_host}:{args.reverb_port}/app/{args.app_key}"
    print(f"[check] ws connect {ws_url}")
    async with websockets.connect(ws_url) as ws:
        sub = json.dumps({"event": "pusher:subscribe", "data": {"auth": auth, "channel": channel}})
        await ws.send(sub)
        print("[check] sent subscribe")

        async def listener():
            async for raw in ws:
                msg = json.loads(raw)
                ev = msg.get("event", "")
                print(f"[check] <<< {ev} channel={msg.get('channel', '')} data={msg.get('data', '')[:200]}")
                if ev.startswith("App\\Events\\") or "App\\\\Events\\\\" in ev:
                    print(f"[check] *** DOMAIN EVENT RECEIVED ***")
                    return True
            return False

        # 3. Fire a synthetic broadcast after a short delay.
        latest = fetch_latest_file_for_user(user_id, db)
        payload = {
            "file_id": latest["id"], "name": latest["name"],
            "folder_id": latest["folder_id"], "mime_type": latest["mime_type"],
            "size": int(latest["size"]),
        }
        data_str = json.dumps(payload)
        status = broadcast_event(
            args.reverb_host, args.reverb_port, args.app_id, args.app_key, args.app_secret,
            "App\\Events\\FileUploadedBroadcast", channel, data_str,
        )
        print(f"[check] fired synthetic event → Reverb HTTP {status}")

        # 4. Listen for 8s. Return whether we saw a domain event.
        try:
            await asyncio.wait_for(listener(), timeout=8.0)
            print("[check] PASS — domain event arrived")
            sys.exit(0)
        except asyncio.TimeoutError:
            print("[check] TIMEOUT — no App\\Events frame in 8s")
            sys.exit(1)


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--token", required=True)
    ap.add_argument("--user", default=None,
                    help="user_id (skip token lookup)")
    ap.add_argument("--api-base", default="http://enstorage-nginx")
    ap.add_argument("--reverb-host", default="enstorage-reverb")
    ap.add_argument("--reverb-port", type=int, default=8080)
    ap.add_argument("--app-id", default="enstorage")
    ap.add_argument("--app-key", required=True)
    ap.add_argument("--app-secret", required=True)
    ap.add_argument("--db-user", default="enstorage")
    ap.add_argument("--db-name", default="enstorage")
    args = ap.parse_args()
    asyncio.run(main(args))