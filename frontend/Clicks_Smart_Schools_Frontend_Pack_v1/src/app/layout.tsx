import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Clicks Smart Schools | Frontend foundation",
  description: "The Clicks Smart Schools public website foundation.",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body className="font-sans antialiased">{children}</body>
    </html>
  );
}
