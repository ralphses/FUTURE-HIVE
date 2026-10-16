"use client";

import { Menu, Search, X } from "lucide-react";
import Image from "next/image";
import { useState } from "react";
import { Button, ButtonLink } from "@/components/ui/button";

const navItems = [
  { label: "Home", path: "/", homepageHref: "#top" },
  { label: "Features", path: "/features", homepageHref: "#features" },
  { label: "How it works", path: "/how-it-works", homepageHref: "#how-it-works" },
  { label: "Pricing", path: "/pricing", homepageHref: "#footer" },
  { label: "Resources", path: "/resources", homepageHref: "#footer" },
  { label: "About", path: "/about", homepageHref: "#value" },
];

export function SiteHeader({ currentPath = "/" }: { currentPath?: string }): React.ReactElement {
  const [isOpen, setIsOpen] = useState(false);
  const isHomepage = currentPath === "/";
  const accountHref = isHomepage ? "#get-started" : "/#get-started";
  const getHref = (path: string, homepageHref: string): string => {
    if (isHomepage) {
      return homepageHref;
    }

    if (path === "/") {
      return "/";
    }

    if (path === "/features") {
      return path === currentPath ? "#top" : "/features";
    }

    return `/${homepageHref}`;
  };

  return (
    <header className="sticky top-0 z-20 border-b bg-white/95 backdrop-blur">
      <div className="mx-auto flex min-h-18 max-w-[1320px] items-center justify-between gap-6 px-5 sm:px-8 lg:px-10">
        <a href={isHomepage ? "#top" : "/"} className="flex items-center gap-2.5" aria-label="Clicks Smart Schools home">
          <Image src="/brand/logo-concept.svg" alt="Clicks Smart Schools" width={160} height={40} className="h-9 w-auto sm:h-11" priority />
        </a>

        <nav className="hidden items-center gap-6 lg:flex" aria-label="Primary navigation">
          {navItems.map((item) => (
            <a key={item.label} href={getHref(item.path, item.homepageHref)} aria-current={item.path === currentPath ? "page" : undefined} className="text-sm font-medium text-navy-950 transition-colors hover:text-brand">
              {item.label}
            </a>
          ))}
        </nav>

        <div className="hidden items-center gap-3 sm:flex">
          <button type="button" className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-[var(--radius-control)] text-navy-950 hover:bg-surface" aria-label="Search"><Search size={20} aria-hidden="true" /></button>
          <Button variant="outline" className="px-4">Login</Button>
          <Button className="px-4">Create a School Account</Button>
        </div>

        <div className="flex items-center gap-2 lg:hidden">
          <ButtonLink href={accountHref} className="min-h-10 px-3 text-xs sm:px-4 sm:text-sm">Create Account</ButtonLink>
          <button
          type="button"
          className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-[var(--radius-control)] text-navy-950 hover:bg-surface"
          aria-label={isOpen ? "Close navigation menu" : "Open navigation menu"}
          aria-expanded={isOpen}
          onClick={() => setIsOpen((open) => !open)}
        >
          {isOpen ? <X aria-hidden="true" /> : <Menu aria-hidden="true" />}
          </button>
        </div>
      </div>

      {isOpen ? (
        <div className="border-t bg-white px-5 py-4 lg:hidden">
          <nav className="mx-auto flex max-w-300 flex-col gap-1" aria-label="Mobile navigation">
            {navItems.map((item) => (
              <a key={item.label} href={getHref(item.path, item.homepageHref)} aria-current={item.path === currentPath ? "page" : undefined} className="rounded-lg px-3 py-3 text-sm font-medium text-navy-950 hover:bg-surface" onClick={() => setIsOpen(false)}>
                {item.label}
              </a>
            ))}
            <div className="mt-3 grid gap-2 border-t pt-4 sm:hidden">
              <Button variant="outline">Login</Button>
              <Button>Create a School Account</Button>
            </div>
          </nav>
        </div>
      ) : null}
    </header>
  );
}

