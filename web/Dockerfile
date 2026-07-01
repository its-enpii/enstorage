# syntax=docker/dockerfile:1.6
#
# Multi-stage build for Next.js standalone output.
# Resulting image runs as a non-root user on port 3000.
#
# Build context: web/  (must contain package.json + package-lock.json)

# ─── Stage 1: deps (cached separately from source) ──────────────────────
FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
# `npm ci` requires package-lock.json (which we have) and produces a
# reproducible install — equivalent to pnpm's --frozen-lockfile.
RUN npm ci

# ─── Stage 2: build ──────────────────────────────────────────────────────
FROM node:22-alpine AS builder
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
ENV NODE_ENV=production

# NEXT_PUBLIC_* are baked into the JS bundle at build time. They must
# be declared as ARGs and re-exported as ENVs in the builder stage,
# otherwise `process.env.NEXT_PUBLIC_API_BASE` stays undefined in the
# generated code and the client falls back to `localhost:8080`.
ARG NEXT_PUBLIC_API_BASE
ENV NEXT_PUBLIC_API_BASE=${NEXT_PUBLIC_API_BASE}
ARG NEXT_PUBLIC_GOOGLE_CLIENT_ID
ENV NEXT_PUBLIC_GOOGLE_CLIENT_ID=${NEXT_PUBLIC_GOOGLE_CLIENT_ID}
# Reverb WS endpoint — public DNS/port that the browser opens the WS
# connection to. Prod: wss://<domain>/ws (NPM terminates TLS, forwards
# to reverb:8080 over web-network). The host/port can be left empty to
# fall back to the API origin (then NPM must upgrade /api/v1/ws or
# similar — adjust REVERSE_PROXY_LOCATION to match).
ARG NEXT_PUBLIC_REVERB_SCHEME
ENV NEXT_PUBLIC_REVERB_SCHEME=${NEXT_PUBLIC_REVERB_SCHEME}
ARG NEXT_PUBLIC_REVERB_HOST
ENV NEXT_PUBLIC_REVERB_HOST=${NEXT_PUBLIC_REVERB_HOST}
ARG NEXT_PUBLIC_REVERB_PORT
ENV NEXT_PUBLIC_REVERB_PORT=${NEXT_PUBLIC_REVERB_PORT}
ARG NEXT_PUBLIC_REVERB_APP_KEY
ENV NEXT_PUBLIC_REVERB_APP_KEY=${NEXT_PUBLIC_REVERB_APP_KEY}

COPY --from=deps /app/node_modules ./node_modules
COPY . .

RUN npm run build

# ─── Stage 3: runner (minimal runtime image) ────────────────────────────
FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

# Non-root user for runtime.
RUN addgroup --system --gid 1001 nodejs \
 && adduser  --system --uid 1001 nextjs

# Standalone output already bundles only what's needed at runtime.
# We additionally copy public/ and .next/static for assets served by Next.
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static    ./.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public           ./public

USER nextjs
EXPOSE 3000

CMD ["node", "server.js"]