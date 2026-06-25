# syntax=docker/dockerfile:1.6
#
# Multi-stage build for Next.js standalone output.
# Resulting image runs as a non-root user on port 3000.
#
# Build context: web/

# ─── Stage 1: deps (cached separately from source) ──────────────────────
FROM node:22-alpine AS deps
RUN corepack enable && corepack prepare pnpm@9 --activate
WORKDIR /app
COPY pnpm-lock.yaml pnpm-workspace.yaml* package.json ./
# `--frozen-lockfile` requires the lockfile; `*` allows missing workspace.
RUN pnpm install --frozen-lockfile

# ─── Stage 2: build ──────────────────────────────────────────────────────
FROM node:22-alpine AS builder
RUN corepack enable && corepack prepare pnpm@9 --activate
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
ENV NODE_ENV=production

COPY --from=deps /app/node_modules ./node_modules
COPY . .

RUN pnpm build

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