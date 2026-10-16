import {
  ArrowRight,
  BarChart3,
  BookOpen,
  CheckCircle2,
  ClipboardList,
  CreditCard,
  GraduationCap,
  LogIn,
  MailCheck,
  MessageCircle,
  MessagesSquare,
  Settings2,
  UsersRound,
} from "lucide-react";
import Image from "next/image";
import { ButtonLink } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

type Feature = { title: string; description: string; icon: typeof BookOpen; accent: string };

const features: Feature[] = [
  { title: "Academic Management", description: "A clear place for school teams to organise academic operations and information.", icon: BookOpen, accent: "bg-blue-50 text-electric" },
  { title: "Fees and Payments", description: "Bring school finance workflows into a more organised digital experience.", icon: CreditCard, accent: "bg-amber-50 text-amber-600" },
  { title: "Student Management", description: "Keep student records and day-to-day school information easier to manage.", icon: UsersRound, accent: "bg-brand-soft text-brand" },
  { title: "Parent Communication", description: "Support clearer communication between schools, parents and their communities.", icon: MessageCircle, accent: "bg-rose-50 text-rose-600" },
  { title: "Reports and Analytics", description: "Create a foundation for more informed school planning and review.", icon: BarChart3, accent: "bg-violet-50 text-violet-600" },
  { title: "School Administration", description: "Bring core administrative work into one considered school-management experience.", icon: Settings2, accent: "bg-sky-50 text-sky-600" },
];

const steps = [
  { number: "01", title: "Create a school account", description: "Start the school registration process with the information the approved flow requests.", icon: ClipboardList },
  { number: "02", title: "Verify your email", description: "Complete the separate email-verification step before signing in.", icon: MailCheck },
  { number: "03", title: "Sign in and continue", description: "Log in securely and continue through the school’s approved onboarding experience.", icon: LogIn },
];

const audiences = [
  { title: "School leaders", description: "See the bigger picture and keep priorities moving.", icon: GraduationCap, accent: "bg-blue-50 text-electric" },
  { title: "Administrators", description: "Create a calmer rhythm for everyday coordination.", icon: Settings2, accent: "bg-brand-soft text-brand" },
  { title: "Teachers", description: "Keep academic work and school information within reach.", icon: BookOpen, accent: "bg-amber-50 text-amber-600" },
  { title: "Parents", description: "Stay closer to the updates that matter to school life.", icon: MessagesSquare, accent: "bg-rose-50 text-rose-600" },
];

export function HeroSection(): React.ReactElement {
  return (
    <section className="overflow-hidden border-b bg-linear-to-br from-brand-soft/70 via-white to-white">
      <div className="mx-auto grid max-w-[1320px] gap-10 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-14 lg:px-10 lg:py-7">
        <div className="flex flex-col items-start gap-7">
          <p className="relative pl-0 text-xs font-bold uppercase tracking-[0.22em] text-muted before:absolute before:-bottom-3 before:left-0 before:h-0.5 before:w-8 before:bg-brand">School management, thoughtfully connected</p>
          <h1 className="max-w-3xl text-[clamp(2.8rem,5vw,4.3rem)] font-bold leading-[1.04] tracking-tight text-navy-950">Everything your school team needs, <span className="text-brand">thoughtfully connected.</span></h1>
          <p className="max-w-2xl text-lg leading-8 text-muted">Clicks Smart Schools is a Nigeria-first school-management platform designed to help school teams bring essential work into one considered experience.</p>
          <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
            <ButtonLink href="#get-started">Create a School Account <ArrowRight size={17} aria-hidden="true" /></ButtonLink>
            <ButtonLink href="#features" variant="outline">Explore Features</ButtonLink>
          </div>
          <p className="text-sm text-muted">Product availability and onboarding details will be confirmed before launch.</p>
        </div>
        <MediaPlaceholder />
      </div>
    </section>
  );
}

function MediaPlaceholder(): React.ReactElement {
  return (
    <div className="relative min-h-80 overflow-hidden rounded-3xl border bg-white/75 p-3 shadow-sm sm:min-h-105 lg:shadow-lg">
      <div className="relative h-full min-h-74 overflow-hidden rounded-2xl border border-white/80 bg-white sm:min-h-99">
        <Image src="/images/hero-school-concept.png" alt="Concept photograph of Nigerian schoolchildren learning in a classroom" fill sizes="(max-width: 1023px) 100vw, 45vw" className="object-cover" priority />
        <div className="absolute left-4 top-4 flex items-center gap-2 rounded-xl border border-white/70 bg-white/90 px-3 py-2 text-xs shadow-sm backdrop-blur-sm"><span className="h-2 w-2 rounded-full bg-brand" /><span className="font-medium text-navy-950">A connected school day</span></div>
        <div className="absolute inset-x-4 bottom-4 flex items-center justify-between gap-3 rounded-xl border border-white/70 bg-white/90 px-3 py-2 text-xs shadow-sm backdrop-blur-sm"><span className="font-medium text-navy-950">Hero image concept</span><span className="text-muted">Development asset</span></div>
      </div>
    </div>
  );
}

export function AudienceSection(): React.ReactElement {
  return (
    <section className="border-y bg-linear-to-br from-surface via-white to-brand-soft/40 px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
      <div className="mx-auto grid max-w-300 gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-center lg:gap-16">
        <SectionIntro eyebrow="For the whole school community" title="One rhythm, shared by the people who keep school moving." description="The direction brings school leaders, administrators, teachers and parents into a clearer shared experience." />
        <div className="grid gap-3 sm:grid-cols-2">
          {audiences.map(({ title, description, icon: Icon, accent }) => <Card key={title} className="bg-white/90"><CardContent className="flex items-start gap-4 p-5"><span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${accent}`}><Icon size={20} aria-hidden="true" /></span><div className="flex flex-col gap-1"><h3 className="font-semibold text-navy-950">{title}</h3><p className="text-sm leading-6 text-muted">{description}</p></div></CardContent></Card>)}
        </div>
      </div>
    </section>
  );
}

export function FeatureHighlights(): React.ReactElement {
  return (
    <section id="features" className="scroll-mt-24 bg-white px-5 py-10 sm:px-8 lg:px-10 lg:py-5">
      <div className="mx-auto flex max-w-[1320px] flex-col gap-6">
        <SectionIntro eyebrow="Product areas" title="The work of running a school, brought closer together." description="Explore the product areas Clicks Smart Schools is designed to support. Final availability labels will follow the approved product matrix." />
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {features.map(({ title, description, icon: Icon, accent }) => (
            <Card key={title} className="group rounded-2xl transition-shadow duration-150 hover:shadow-md"><CardContent className="flex min-h-48 h-full flex-col gap-4 pt-5"><span className={`flex h-12 w-12 items-center justify-center rounded-2xl ${accent}`}><Icon size={23} aria-hidden="true" /></span><div className="flex flex-1 flex-col gap-2"><h3 className="text-lg font-semibold text-navy-950">{title}</h3><p className="text-sm leading-6 text-muted">{description}</p></div><a href="#get-started" className="inline-flex min-h-11 items-center gap-2 self-start text-sm font-semibold text-navy-950 transition-colors hover:text-brand">Explore this area <ArrowRight size={16} aria-hidden="true" /></a></CardContent></Card>
          ))}
        </div>
      </div>
    </section>
  );
}

export function HowItWorksSection(): React.ReactElement {
  return (
    <section id="how-it-works" className="scroll-mt-24 border-y bg-surface px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
      <div className="mx-auto flex max-w-300 flex-col gap-10"><SectionIntro eyebrow="A clear next step" title="From registration to your school’s next chapter." description="The public journey keeps registration, verification, sign-in and post-login onboarding distinct." /><div className="grid gap-5 lg:grid-cols-3">{steps.map(({ number, title, description, icon: Icon }) => <Card key={number} className="bg-white"><CardContent className="flex flex-col gap-5 pt-6"><div className="flex items-center justify-between"><span className="text-sm font-bold tracking-[0.18em] text-brand">{number}</span><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-soft text-brand"><Icon size={19} aria-hidden="true" /></span></div><h3 className="text-lg font-semibold text-navy-950">{title}</h3><p className="text-sm leading-6 text-muted">{description}</p></CardContent></Card>)}</div></div>
    </section>
  );
}

export function ValueSection(): React.ReactElement {
  return (
    <section id="value" className="scroll-mt-24 px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
      <div className="mx-auto grid max-w-[1320px] gap-8 rounded-3xl bg-brand-soft px-6 py-8 sm:px-10 lg:grid-cols-[1fr_auto] lg:items-center lg:px-16 lg:py-9"><div className="flex max-w-2xl flex-col gap-3"><p className="text-xs font-bold uppercase tracking-[0.22em] text-muted">Ready to get started?</p><h2 className="text-3xl font-bold tracking-tight text-navy-950 sm:text-4xl">Make the next school day clearer.</h2><p className="leading-7 text-muted">Create a school account when the approved registration flow is ready for your school.</p><div className="flex flex-col gap-3 pt-2 text-sm text-navy-900 sm:flex-row sm:gap-6"><span className="inline-flex items-center gap-2"><CheckCircle2 size={17} className="text-brand" aria-hidden="true" /> Nigeria-first product direction</span><span className="inline-flex items-center gap-2"><CheckCircle2 size={17} className="text-brand" aria-hidden="true" /> Designed for school teams</span></div></div><ButtonLink href="#footer" className="w-full sm:w-auto" id="get-started">Create a School Account <ArrowRight size={17} aria-hidden="true" /></ButtonLink></div>
    </section>
  );
}

function SectionIntro({ eyebrow, title, description }: { eyebrow: string; title: string; description: string }): React.ReactElement {
  return <div className="flex max-w-2xl flex-col gap-3"><p className="text-xs font-bold uppercase tracking-[0.22em] text-brand">{eyebrow}</p><h2 className="text-3xl font-bold tracking-tight text-navy-950 sm:text-4xl">{title}</h2><p className="leading-7 text-muted">{description}</p></div>;
}
