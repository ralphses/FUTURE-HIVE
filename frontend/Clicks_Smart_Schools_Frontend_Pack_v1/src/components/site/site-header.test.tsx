import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { SiteHeader } from "@/components/site/site-header";

describe("SiteHeader", () => {
  it("opens and closes the mobile navigation accessibly", () => {
    render(<SiteHeader />);

    const openButton = screen.getByRole("button", { name: "Open navigation menu" });
    expect(openButton).toHaveAttribute("aria-expanded", "false");
    expect(screen.getByRole("link", { name: "Create Account" })).toHaveAttribute("href", "#get-started");

    fireEvent.click(openButton);
    expect(screen.getByRole("button", { name: "Close navigation menu" })).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("navigation", { name: "Mobile navigation" })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Close navigation menu" }));
    expect(screen.getByRole("button", { name: "Open navigation menu" })).toHaveAttribute("aria-expanded", "false");
  });
});
