# Product and public frontend scope
Clicks Smart Schools is a school-management platform designed primarily for Nigerian primary and secondary schools, built by Clicks Technology Limited. Public messaging may describe administration, student records, staff, attendance, assessments, exams/results, fees, parent communication, and future capabilities, **only when product availability is confirmed**.

## Audience
School proprietors, principals, administrators, bursars, teachers, parents (secondary audience) and prospective school customers. Primary public CTA: **Create a School Account**; secondary: **Explore Features** or **Contact Us**.

## Public scope
PUB-001 Homepage; PUB-002 Features; PUB-003 Pricing; PUB-004 How It Works; PUB-005 About Us; PUB-006 Contact Us; PUB-007 four-step school registration + verification + validation; PUB-008 administrator login and password recovery; PUB-009 FAQ/Help Centre; PUB-010 Terms; PUB-011 Privacy; PUB-012 404; PUB-013 maintenance/outage.

## Non-goals for this pack
Authenticated dashboard, onboarding wizard, academic operations, student/parent portals, payment integrations, AI features, real-time messaging, marketing campaigns, mobile native apps.

## Known architecture constraints
Backend: Laravel 13, PostgreSQL, shared schema with strict school tenant isolation, JWT access/refresh (exact transport TBD), public UUIDv7 + internal BIGINT. These are backend decisions, **not a license to assume API shapes**.

## Approval model
The UI screens are approved by default unless user requests changes. Visual approval is not API/legal/business-policy approval.
