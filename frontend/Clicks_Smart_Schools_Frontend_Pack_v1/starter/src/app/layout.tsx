import type { Metadata } from "next";
import "./globals.css";
export const metadata: Metadata = {
  title: { default: "Clicks Smart Schools", template: "%s | Clicks Smart Schools" },
  description: "A simpler way to manage your school.",
};
export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body><a className="skip-link" href="#main">Skip to content</a>{children}</body></html>;
}
