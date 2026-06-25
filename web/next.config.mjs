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
};

export default nextConfig;
