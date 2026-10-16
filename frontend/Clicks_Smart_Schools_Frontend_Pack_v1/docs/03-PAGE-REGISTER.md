# Public page and route register
| ID | Route | Primary content | Key states | Gate |
|---|---|---|---|---|
| PUB-001 | `/` | Hero, value proposition, feature highlights, trust/CTA | responsive, skeleton if dynamic | approved design |
| PUB-002 | `/features` | Categorized feature explanations and CTAs | available vs coming-soon labels | approved design |
| PUB-003 | `/pricing` | Plan comparison or contact-sales fallback | prices only if verified | pricing policy BLOCKED |
| PUB-004 | `/how-it-works` | Registration/setup explanation | step list, CTA | approved design |
| PUB-005 | `/about` | Mission, story, parent-company attribution | authentic facts only | approved design |
| PUB-006 | `/contact` | Enquiry form and verified contact routes | errors/success/loading | contact API/details BLOCKED |
| PUB-007A | `/register` | Step 1 school details | field errors/draft | API contract BLOCKED |
| PUB-007B | `/register` | Step 2 primary administrator | password validation | API contract BLOCKED |
| PUB-007C | `/register` | Step 3 optional school context | skip and resume | API contract BLOCKED |
| PUB-007D | `/register` | Step 4 review and submit | consent, duplicate submit | API contract BLOCKED |
| PUB-007E | `/register/check-email` | Submitted and verification instructions | resend cooldown | API contract BLOCKED |
| PUB-007F | `/verify-email` | verified/invalid/expired/already verified | safe retry | API contract BLOCKED |
| PUB-007G | `/register` | Validation/recovery states | retry/timeout/duplicate | API contract BLOCKED |
| PUB-008 | `/login` | Admin sign in | invalid/locked/network | API contract BLOCKED |
| PUB-008 | `/forgot-password` | Reset request | generic confirmation | API contract BLOCKED |
| PUB-008 | `/reset-password` | Set new password | expired/used token | API contract BLOCKED |
| PUB-009 | `/help` and `/help/[slug]` | FAQ search/categories/articles | no results/feedback | content needed |
| PUB-010 | `/terms` | Terms of Service | TOC/search/anchors | legal review BLOCKED |
| PUB-011 | `/privacy` | Privacy Policy | TOC/anchors | legal review BLOCKED |
| PUB-012 | Next `not-found.tsx` | Friendly 404, navigation | missing route | ready |
| PUB-013 | `/maintenance` + Next error handling | scheduled vs unexpected outage | retry/status | actual status source TBD |

Desktop and mobile are **responsive presentations of the same routes**, not separate websites.

## Registration workflow
1. School details: name, school type, Nigeria/state/LGA and contact fields **only if backend schema confirms**.
2. Administrator: identity/contact and password; server assigns primary administrator role.
3. Additional information: primary/secondary multi-select, estimates and preferences; optional unless backend says otherwise.
4. Review: edit each step, hide password, explicit legal acceptance, submit exactly once.
5. Check email: state depends on provider acceptance; resend cooldown and secure email change.
6. Verify: success routes to login; email verification is NOT login.
7. Errors: inline, summary, timeout, safe retry, draft recovery only if implemented.
Do not block two unrelated schools sharing the same display name.
