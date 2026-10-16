import type { InputHTMLAttributes } from "react";
import { cn } from "@/lib/utils";

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>): React.ReactElement {
  return (
    <input
      className={cn(
        "min-h-11 w-full rounded-[var(--radius-control)] border bg-white px-3.5 text-sm text-ink shadow-xs placeholder:text-muted focus:border-electric focus:outline-none",
        className,
      )}
      {...props}
    />
  );
}
