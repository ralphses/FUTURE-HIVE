import type { Metadata } from "next";
import { SiteFooter } from "@/components/site/site-footer";
import { SiteHeader } from "@/components/site/site-header";
import { FeaturesPageContent } from "@/components/features/features-page-content";

export const metadata: Metadata = {
  title: "Features | Clicks Smart Schools",
  description: "Explore the product areas Clicks Smart Schools is designed to support for Nigerian school teams.",
};

export default function FeaturesPage(): React.ReactElement {
  return (
    <div id="top" className="min-h-screen bg-white">
      <a href="#main-content" className="sr-only fixed left-4 top-4 z-50 rounded-md bg-white px-4 py-3 text-sm font-semibold text-navy-950 focus:not-sr-only">Skip to content</a>
      <SiteHeader currentPath="/features" />
      <main id="main-content">
        <FeaturesPageContent />
      </main>
      <SiteFooter />
    </div>
  );
}
