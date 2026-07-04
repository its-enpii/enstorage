/** @type {import('next').NextConfig} */
const nextConfig = {
  // Disabled: StrictMode double-invokes effects in dev, causing every
  // data fetch to fire twice. Adds noise to network tab and stresses
  // rate-limited APIs without surfacing real bugs in this codebase.
  reactStrictMode: false,
  // Standalone output produces a self-contained server.js + minimal
  // node_modules — required for the production Docker image.
  output: 'standalone',
  // API requests go directly from the browser to the backend via
  // NEXT_PUBLIC_API_BASE_URL. No server-side rewrite is needed (and
  // `localhost:8080` would not resolve inside the Next.js container
  // anyway — backend lives in a sibling container).
  //
  // Share-token blob passthrough — `/s/:token/view` is rewritten
  // server-side to the backend's `/api/v1/s/:token` endpoint, so
  // external consumers (Evolution API / WhatsApp bot / link previews)
  // fetch the raw file bytes with the correct Content-Type instead
  // of receiving the HTML share page. Without this, Evolution API
  // errors with "Input buffer contains unsupported image format"
  // when forwarding the shared link as image/video/etc. A rewrite
  // (not redirect) keeps the public origin in the address bar so
  // the backend's internal container hostname doesn't leak.
  async rewrites() {
    const apiBase =
      process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:8080/api/v1';
    return [
      { source: '/s/:token/view', destination: `${apiBase}/s/:token` },
    ];
  },
};

export default nextConfig;
