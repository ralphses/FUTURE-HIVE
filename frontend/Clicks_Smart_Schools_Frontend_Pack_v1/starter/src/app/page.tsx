import Link from "next/link";
export default function Home() {
 return <main id="main" className="mx-auto max-w-6xl px-6 py-20">
  <p className="mb-4 text-sm font-semibold tracking-wide text-emerald-700">CLICKS SMART SCHOOLS</p>
  <h1 className="max-w-3xl text-5xl font-semibold leading-tight tracking-tight text-[#0B1B4D] md:text-7xl">A simpler way to run your school.</h1>
  <p className="mt-6 max-w-xl text-lg text-slate-600">Bring school administration and academic workflows together in one organised experience.</p>
  <div className="mt-8 flex flex-wrap gap-3"><Link href="/register" className="rounded-xl bg-[#008D62] px-6 py-3 font-semibold text-white">Create a School Account</Link><Link href="/features" className="rounded-xl border border-slate-300 px-6 py-3 font-semibold text-[#0B1B4D]">Explore Features</Link></div>
  <p className="mt-12 text-sm text-slate-500">Powered by Clicks Technology Limited.</p>
 </main>;
}
