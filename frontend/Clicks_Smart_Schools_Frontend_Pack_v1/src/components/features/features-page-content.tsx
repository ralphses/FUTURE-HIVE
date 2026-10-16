import {
  ArrowUpRight,
  BarChart3,
  BookOpen,
  CreditCard,
  MessageCircle,
  Settings2,
  UsersRound,
} from "lucide-react";
import Link from "next/link";
import { ButtonLink } from "@/components/ui/button";

type FeatureIcon = typeof BookOpen;

type FeatureItem = {
  title: string;
  description: string;
  icon: FeatureIcon;
  accent: string;
  index: string;
};

const featureItems: FeatureItem[] = [
  { index: "01", title: "Academic Management", description: "Bring academic operations and school information into a clearer working rhythm.", icon: BookOpen, accent: "bg-blue-100 text-electric" },
  { index: "02", title: "Fees and Payments", description: "Give finance workflows a more organised place within the school day.", icon: CreditCard, accent: "bg-amber-100 text-amber-700" },
  { index: "03", title: "Student Management", description: "Keep student records and essential school context easier to find and manage.", icon: UsersRound, accent: "bg-emerald-100 text-brand-dark" },
  { index: "04", title: "Parent Communication", description: "Create a clearer line between school teams, parents and their communities.", icon: MessageCircle, accent: "bg-rose-100 text-rose-700" },
  { index: "05", title: "Reports and Analytics", description: "Turn school information into a steadier foundation for planning and review.", icon: BarChart3, accent: "bg-violet-100 text-violet-700" },
  { index: "06", title: "School Administration", description: "Connect the essential administrative work that keeps a school moving.", icon: Settings2, accent: "bg-sky-100 text-sky-700" },
];

export function FeaturesPageContent(): React.ReactElement {
  return (
    <>
      <section className="overflow-hidden bg-[#f4f5f1]">
        <div className="mx-auto grid max-w-[1320px] gap-12 px-5 py-14 sm:px-8 sm:py-20 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:gap-20 lg:px-10 lg:py-28">
          <div className="flex max-w-xl flex-col items-start gap-7">
            <div className="flex items-center gap-3 text-xs font-bold uppercase tracking-[0.24em] text-brand"><span className="h-px w-9 bg-brand" />Product architecture</div>
            <h1 className="text-[clamp(2.8rem,6vw,5.8rem)] font-semibold leading-[0.94] tracking-[-0.055em] text-navy-950">A calmer operating rhythm for <span className="text-brand">school teams.</span></h1>
            <p className="max-w-lg text-base leading-8 text-muted sm:text-lg">Clicks Smart Schools is designed around the connected work of running a school — from academic operations to everyday administration.</p>
            <div className="flex flex-col gap-3 sm:flex-row"><ButtonLink href="/#get-started">Create a School Account <ArrowUpRight size={17} aria-hidden="true" /></ButtonLink><ButtonLink href="#feature-map" variant="outline">View the product map</ButtonLink></div>
          </div>
          <ProductMap />
        </div>
      </section>

      <section id="feature-map" className="scroll-mt-24 bg-navy-950 px-5 py-16 sm:px-8 sm:py-24 lg:px-10 lg:py-28">
        <div className="mx-auto max-w-[1120px]">
          <div className="grid gap-10 border-b border-white/15 pb-12 lg:grid-cols-[0.7fr_1.3fr] lg:items-end lg:gap-20"><div className="flex items-center gap-3 text-xs font-bold uppercase tracking-[0.24em] text-emerald-300"><span className="h-px w-9 bg-emerald-300" />The product map</div><div className="flex max-w-3xl flex-col gap-4"><h2 className="text-3xl font-semibold leading-tight tracking-tight text-white sm:text-5xl">Everything important has a place.</h2><p className="text-base leading-7 text-slate-300 sm:text-lg">Explore the product areas Clicks Smart Schools is designed to support. Final availability will follow the approved product matrix.</p></div></div>
          <div className="mt-8 grid gap-px overflow-hidden rounded-2xl border border-white/15 bg-white/15 md:grid-cols-2">{featureItems.map((feature) => <FeatureCard key={feature.title} feature={feature} />)}</div>
        </div>
      </section>

      <section className="bg-white px-5 py-16 sm:px-8 sm:py-24 lg:px-10"><div className="mx-auto grid max-w-[1120px] gap-8 rounded-[2rem] border border-slate-200 bg-[#f4f5f1] px-6 py-10 sm:px-10 lg:grid-cols-[1fr_auto] lg:items-end lg:px-14 lg:py-14"><div className="flex max-w-2xl flex-col gap-4"><div className="flex items-center gap-3 text-xs font-bold uppercase tracking-[0.24em] text-brand"><span className="h-px w-9 bg-brand" />A considered next step</div><h2 className="text-3xl font-semibold leading-tight tracking-[-0.03em] text-navy-950 sm:text-5xl">Build a clearer school day, one workflow at a time.</h2><p className="max-w-xl leading-7 text-muted">Create a school account when the approved registration flow is ready for your school.</p></div><ButtonLink href="/#get-started" className="w-full sm:w-auto">Create a School Account <ArrowUpRight size={17} aria-hidden="true" /></ButtonLink></div></section>
    </>
  );
}

function ProductMap(): React.ReactElement {
  return <div className="relative min-h-[390px] overflow-hidden rounded-[2rem] bg-navy-950 p-4 shadow-2xl shadow-navy-950/15 sm:min-h-[500px] sm:p-6"><div className="absolute -right-20 -top-24 h-72 w-72 rounded-full bg-brand/20 blur-3xl" /><div className="relative flex h-full min-h-[358px] flex-col rounded-[1.4rem] border border-white/15 bg-white/[0.06] p-5 sm:min-h-[452px] sm:p-7"><div className="flex items-center justify-between border-b border-white/15 pb-5"><div><p className="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300">Clicks Smart Schools</p><p className="mt-2 text-sm text-slate-300">A connected product map</p></div><span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand text-white"><ArrowUpRight size={17} aria-hidden="true" /></span></div><div className="grid flex-1 grid-cols-2 gap-3 py-5 sm:grid-cols-3 sm:gap-4">{featureItems.map(({ title, icon: Icon, accent }) => <div key={title} className="flex min-h-28 flex-col justify-between rounded-2xl border border-white/10 bg-white/[0.08] p-4"><span className={`flex h-9 w-9 items-center justify-center rounded-xl ${accent}`}><Icon size={18} aria-hidden="true" /></span><span className="text-sm font-medium leading-5 text-white">{title}</span></div>)}</div><div className="flex items-center justify-between border-t border-white/15 pt-5 text-xs text-slate-400"><span>Development concept</span><span className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-emerald-300" />Product areas</span></div></div></div>;
}

function FeatureCard({ feature }: { feature: FeatureItem }): React.ReactElement {
  const Icon = feature.icon;

  return <Link href="/#get-started" className="group flex min-h-64 flex-col justify-between bg-navy-900 p-6 transition-colors duration-150 hover:bg-[#19326f] focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300 focus-visible:ring-inset sm:p-8"><div className="flex items-start justify-between gap-5"><span className={`flex h-12 w-12 items-center justify-center rounded-2xl ${feature.accent}`}><Icon size={22} aria-hidden="true" /></span><span className="font-mono text-xs text-slate-500">{feature.index}</span></div><div className="mt-12 flex flex-col gap-3"><span className="text-xl font-semibold tracking-tight text-white">{feature.title}</span><span className="max-w-sm text-sm leading-6 text-slate-300">{feature.description}</span><span className="mt-2 inline-flex items-center gap-2 text-sm font-semibold text-emerald-300">Explore area <ArrowUpRight size={16} aria-hidden="true" className="transition-transform duration-150 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" /></span></div><span className="sr-only">Explore {feature.title}</span></Link>;
}
