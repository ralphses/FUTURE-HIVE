# Backend integration contract (do not invent)
**Backend source of truth:** Laravel OpenAPI JSON/YAML, real API base URL and response examples. Store generated types under `src/lib/api/generated/` once supplied.

## Contract intake checklist
- Base URL by environment and CORS/cookie domain policy
- OpenAPI endpoints for registration, verification/resend, login/logout/refresh, forgot/reset password, contact
- Exact request and response bodies, validation error shape, error codes, rate-limit response
- JWT transport (HttpOnly cookie vs bearer), CSRF strategy, expiry/refresh behavior
- Verification token transport, expiry and single-use semantics
- Whether registration creates school tenant immediately or pending record
- Optional fields, enumerations for school type/level/state, idempotency support
- Captcha/bot protection policy; file upload requirements, if any
- Tenant selection after login if applicable
- Real pricing/FAQ CMS data source or static reviewed content

## API architecture
`src/lib/api/http.ts` transport → `src/lib/api/contracts.ts` handwritten boundary interfaces until OpenAPI → `src/features/{auth,registration,contact}/api.ts` typed feature services → TanStack Query mutations → UI components.
Use typed `Result<T, ApiError>`; central normalization of 400/401/403/409/422/429/5xx; field errors from actual backend shape. Do not assume every 409 means duplicate school.
Server components for SEO public pages; client components only for interactive forms/search/accordions.
Never include access/refresh token values in analytics or logs.
If cookie-based auth: credentials only on approved first-party requests; CSRF token protection according to backend. If bearer: coordinate secure server-side session/BFF design, not localStorage.
## Mocking
Use MSW fixtures explicitly marked `MOCK_ONLY` in development/tests; never silently fall back to mocks in production.
## Environment
`NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_API_BASE_URL` only if safe/public; server-only secrets in server env. Keep `.env.example` free of credentials.
