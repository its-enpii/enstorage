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