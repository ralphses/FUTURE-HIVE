import type { Metadata } from "next";
import { SiteFooter } from "@/components/site/site-footer";
import { SiteHeader } from "@/components/site/site-header";
import { AudienceSection, FeatureHighlights, HeroSection, HowItWorksSection, ValueSection } from "@/components/home/homepage-sections";

export const metadata: Metadata = {
  title: "Clicks Smart Schools | School management, thoughtfully connected",
  description: "Clicks Smart Schools is a Nigeria-first school-management platform for school teams.",
};

export default function Home(): React.ReactElement {
  return (
    <div id="top" className="min-h-screen bg-white">
      <a href="#main-content" className="sr-only fixed left-4 top-4 z-50 rounded-md bg-white px-4 py-3 text-sm font-semibold text-navy-950 focus:not-sr-only">Skip to content</a>
      <SiteHeader />
      <main id="main-content"><HeroSection /><FeatureHighlights /><ValueSection /><AudienceSection /><HowItWorksSection /></main>
      <SiteFooter />
    </div>
  );
}
