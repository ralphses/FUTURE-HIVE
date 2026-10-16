import type { AnchorHTMLAttributes, ButtonHTMLAttributes } from "react";
import { cn } from "@/lib/utils";

type ButtonVariant = "primary" | "outline" | "ghost";

export function buttonStyles(variant: ButtonVariant = "primary", className?: string): string {
  return cn(
    "inline-flex min-h-11 items-center justify-center gap-2 rounded-[var(--radius-control)] px-5 text-sm font-semibold transition-colors duration-150 disabled:pointer-events-none disabled:opacity-50",
    variant === "primary" && "bg-brand text-white shadow-sm hover:bg-brand-dark",
    variant === "outline" && "border bg-white text-navy-950 hover:bg-surface",
    variant === "ghost" && "text-navy-950 hover:bg-brand-soft",
    className,
  );
}

export function Button({
  className,
  variant = "primary",
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }): React.ReactElement {
  return (
    <button
      className={buttonStyles(variant, className)}
      {...props}
    />
  );
}

export function ButtonLink({
  className,
  variant = "primary",
  ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & { variant?: ButtonVariant }): React.ReactElement {
  return <a className={buttonStyles(variant, className)} {...props} />;
}
