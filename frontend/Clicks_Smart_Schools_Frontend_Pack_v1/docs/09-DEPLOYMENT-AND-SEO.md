# Deployment and operational handoff
Recommended hosting: Vercel or an equivalent Node-capable host; confirm commercial and infrastructure requirements first.
Environments: local, staging, production with independent API URLs and domain/cookie configuration.
CI: install locked deps → lint → typecheck → unit tests → Playwright → build → deploy staging → smoke test.
Release checklist: DNS/TLS, env vars, allowed origins, cookies/CSRF, redirect URLs, email links, sitemap/robots, error reporting with PII scrub, CSP, cache policy, rollback, legal approval, accessibility and performance.
SEO: title/description per public page; canonical; OG image; sitemap index; schema.org only if verified; avoid indexing reset/verify routes; proper 404 status.
Operational readiness: maintenance page must be servable independently of broken API; do not show invented ETA.
