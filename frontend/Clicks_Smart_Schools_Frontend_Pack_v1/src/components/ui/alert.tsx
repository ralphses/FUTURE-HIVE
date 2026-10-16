import type { HTMLAttributes } from "react";
import { cn } from "@/lib/utils";

export function Alert({ className, ...props }: HTMLAttributes<HTMLDivElement>): React.ReactElement {
  return (
    <div
      role="status"
      className={cn("rounded-[var(--radius-control)] border border-electric/20 bg-electric/5 p-4 text-sm text-navy-900", className)}
      {...props}
    />
  );
}
