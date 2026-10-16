# Phased implementation plan for Codex
## Phase 0 — Discovery and source validation
Review all screenshots, inventory assets, request API contract, verify brand logo, legal/pricing/contact data. Produce gap report. Do not silently make decisions.
## Phase 1 — Scaffold and design system
Next.js TypeScript strict, Tailwind, shadcn/ui, lint, tests, env example; tokens; typography; header/footer; responsive layout; component gallery.
## Phase 2 — Marketing pages
Implement PUB-001 Home, 002 Features, 004 How It Works, 005 About, 003 Pricing (verified/placeholder policy), 006 Contact UI. SEO and responsive QA.
## Phase 3 — Legal/help/error
Implement PUB-009 Help, 010 Terms, 011 Privacy (approved copy gate), 012 404, 013 Maintenance. Search and accessibility.
## Phase 4 — Registration
Implement 4-step flow, draft-in-memory state, review, field validation, submission, verification result and all failure states. Integrate only against real contract.
## Phase 5 — Authentication
Login, password recovery, reset and errors; secure token/session strategy aligned with backend; no assumed Google SSO.
## Phase 6 — Hardening and release
E2E full flows, axe, performance budgets, SEO, analytics consent, security headers, staging, UAT, production readiness.
## Required output each slice
Files changed, screenshot before/after, responsive checks, tests run, contract assumptions, unresolved blockers, tracker update.
## Public-phase exit gate
All PUB-001–013 built; desktop/mobile QA pass; registration/login end-to-end with real staging API; legal/pricing/contact signed off; no fictional data; build/tests/accessibility/SEO/security gates passed.
