# Acceptance criteria by feature
## Shared public shell
Header/footer links resolve, mobile drawer keyboard accessible, skip-to-content, active nav, consistent CTA, no fake metrics, responsive 360–1440.
## Homepage
Clear product explanation and CTA within first viewport; no unverified customer proof; optimize hero image.
## Features
Every capability marked `Available`, `Planned` or omitted according to confirmed product matrix; no implied GA availability.
## Pricing
No fabricated amounts, free trials, cancellation terms or discount labels. If pricing unconfirmed, use `Request pricing`/`Contact sales` after product-owner approval.
## How it works
Distinguish register, email verification, login and post-login onboarding; do not promise immediate activation.
## About
Correct company attribution; only verified facts/people.
## Contact
Server-validated enquiry, honeypot/rate limiting, success/failure; no exposed mail provider secrets.
## Registration
Step state persists during navigation; field errors focus first invalid; server validation displayed; double submission prevented; passwords never persisted in browser storage; optional step skippable; legal consent required; safe verification resend; auth gate after verification.
## Login/recovery
No email enumeration in reset response; generic bad credential errors; lockouts from actual backend; no client-side access control decisions; secure session handling.
## Help
Category list, search, no results, accessible accordions, verified support contacts; article URLs stable.
## Terms/privacy
Actual version/effective date; semantic heading hierarchy; mobile TOC; legal review sign-off before production.
## 404/maintenance
Correct HTTP semantics, links resolve, no invented ETA; retry action only when meaningful; maintenance status page independent of main outage if possible.
