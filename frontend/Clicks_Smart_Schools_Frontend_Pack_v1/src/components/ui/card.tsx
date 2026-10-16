import type { HTMLAttributes } from "react";
import { cn } from "@/lib/utils";

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>): React.ReactElement {
  return <div className={cn("rounded-[var(--radius-card)] border bg-white shadow-sm", className)} {...props} />;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>): React.ReactElement {
  return <div className={cn("flex flex-col gap-2 p-6", className)} {...props} />;
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>): React.ReactElement {
  return <div className={cn("p-6 pt-0", className)} {...props} />;
}
