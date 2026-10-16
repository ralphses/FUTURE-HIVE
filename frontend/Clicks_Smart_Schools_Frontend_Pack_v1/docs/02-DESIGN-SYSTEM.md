# Visual system and component specification
## Principles
Minimal, premium, trustworthy, distinctly SaaS; no dense collages in real pages. Use editorial whitespace, precise alignment, modest gradients, soft borders, subtle hover, readable forms and purposeful illustration.
## Design tokens (proposed starting values; calibrate against approved Figma)
- Navy `#0B1B4D` headings, near-black `#17243B` body
- Electric blue `#1264F4` brand emphasis/links
- Green `#16B33E` accent; interactive primary buttons may use accessible darker green `#008D62`
- Background `#FFFFFF`; surface `#F8FAFC`; border `#E5EAF2`; muted `#64748B`
- Error `#B42318`, success `#067647`, warning `#B54708`
- Font: licensed/open-source **Inter** or **Manrope**, with system fallback
- Radius: inputs 10px, cards 16px, buttons 10px
- Content max-width 1200px; long-form reading width 720–760px
- Spacing scale: 4/8/12/16/24/32/48/64/96px
- Buttons min-height 44px; form controls 44–48px; mobile gutters 20–24px
- Breakpoints: mobile <640, tablet 640–1023, desktop >=1024
## Shared components
SiteHeader, MobileNavDrawer, SiteFooter, Container, SectionHeading, Button, TextField, PasswordField, SelectField, CheckboxField, Stepper, FormErrorSummary, Alert, LoadingButton, EmptyState, SearchField, FAQAccordion, SupportCTA, Breadcrumbs, LegalToc, LegalSection, StatusNotice, ErrorPage, SEO metadata, CookiePreferences (only if needed).
## Layout
Desktop navigation: Home, Features, Pricing, How It Works, About, Contact, Login, Create a School Account. Mobile: menu drawer, same routes, clear primary CTA.
## Forms
Required indicator + label; validation after blur/submit; preserve inputs; errors announced and linked by aria-describedby; no red-only meaning; passwords hidden by default with toggle; no preselected marketing opt-in.
## Motion
120–200ms subtle transitions, no autoplay/carousel needed, respect prefers-reduced-motion.
## Images
Use selective licensed, authentic school imagery with Nigerian context; avoid synthetic product screenshots presented as actual product features. Never use reference collages as hero imagery.
## Responsive QA
360, 390, 768, 1024, 1440px; no horizontal scroll; forms single column on mobile; legal TOC collapses; sticky nav never blocks content.
