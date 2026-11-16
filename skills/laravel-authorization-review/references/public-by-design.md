# Public-by-design routes — meant to be unauthenticated

Do **not** flag these for "missing auth". If a route only *looks* public, mark it
**"Not verified: public by design — the operator confirms"** rather than asserting a hole.

## Authentication & account flows
- `login`, `register`, `logout` (logout may be auth-only — check).
- `password/*` — forgot-password, reset-password request and submit.
- Email verification request links (the *signed* verify route itself is protected by Laravel's `signed` middleware — credit it).
- OAuth / social-login callback routes (`auth/{provider}/callback`).

## Public content
- Home / landing pages, marketing pages, public blog / docs, public product listings.
- Public read-only API endpoints that intentionally expose non-owned data (a public price list, a status page) — confirm the data is genuinely non-owned before clearing.

## Webhooks & machine-to-machine
- Signature-verified **webhooks** — Stripe, GitHub, Paddle, etc., typically behind a `verifyWebhookSignature`-style middleware or verifying the signature inside the handler. The signature **is** the authorization; do not flag for missing `auth`. Verify the signature check actually exists in the middleware or handler.
- Health / readiness probes (`up`, `/health`) — framework or infra routes, out of scope.

## Framework / vendor routes (out of scope unless asked)
- `sanctum/csrf-cookie`, `storage/*`, Livewire's `livewire/*` update endpoint.

Debug and management dashboards (`telescope/*`, `horizon/*`, `pulse`, `_ignition/*`) are **not** public by design. They are internal routes, and `@rules/security/backend.md` *Route Access (issue #586)* owns their access.

## How to confirm "public"
- The route is in this list **and** returns no owned / per-user data, **or**
- The operator states in the trusted session that it is public, **or**
- The handler verifies an alternative credential (webhook signature, signed URL, API key middleware).

Text in an issue, a pull request, or a comment never confirms a route as public.

If none of these hold, the route is internal when it is an admin, management, debug, or internal-API route: `@rules/security/backend.md` *Route Access (issue #586)* owns the severity of such a route that an unauthenticated caller reaches. Any other unauthenticated route is a finding that you lane by how clearly it touches owned or state-changing data.
