# Testing matrix
## Unit/component
Header/menu, buttons, input labels and error states, password toggle, stepper navigation, legal TOC, FAQ accordion, search empty state, form validation, request-state handling.
## Integration
Registration progression/back/skip, edit review, duplicate submission, server 422 mapped to fields, 409 conflicts without school-name assumptions, resend cooldown, expired verification, password reset generic response, auth session expiration.
## E2E (Playwright)
1. Home → Features → Pricing → Registration.
2. Register across four steps → review → submit → email verification → login (staging API or deterministic mock in CI).
3. Invalid email, weak password, duplicate email, 429, 5xx and timeout.
4. Forgot password → reset token invalid/expired/success.
5. Contact form validation/success/failure.
6. FAQ search/results/no-results, keyboard accordion.
7. Terms/privacy TOC navigation; 404, maintenance.
8. Keyboard-only navigation, axe critical pages, mobile menu.
## Viewports
360x800, 390x844, 768x1024, 1024x768, 1440x900. Chrome, Firefox, WebKit.
## Quality commands
`npm run lint`, `npm run typecheck`, `npm run test`, `npm run test:e2e`, `npm run build`.
## Visual regression
Capture approved screenshots as **visual references**, not exact pixel-perfect targets. Prefer restrained live UI over mockup decorations.
