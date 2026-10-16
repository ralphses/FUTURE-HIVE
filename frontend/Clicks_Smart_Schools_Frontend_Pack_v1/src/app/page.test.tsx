import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import Home from "@/app/page";

describe("PUB-001 homepage", () => {
  it("renders the homepage composition and all six feature areas", () => {
    render(<Home />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Everything your school team needs, thoughtfully connected.");
    expect(screen.getByText("Academic Management")).toBeInTheDocument();
    expect(screen.getByText("Fees and Payments")).toBeInTheDocument();
    expect(screen.getByText("Student Management")).toBeInTheDocument();
    expect(screen.getByText("Parent Communication")).toBeInTheDocument();
    expect(screen.getByText("Reports and Analytics")).toBeInTheDocument();
    expect(screen.getByText("School Administration")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /create a school account/i }).length).toBeGreaterThan(0);
  });

  it("keeps unverified proof and pricing claims out of the page", () => {
    render(<Home />);
    const pageText = document.body.textContent ?? "";

    expect(pageText).not.toMatch(/free|credit card|testimonial|schools already|customer logos/i);
    expect(pageText).not.toMatch(/\$|₦|%/);
  });

  it("provides the homepage section anchors", () => {
    render(<Home />);

    expect(screen.getAllByRole("link", { name: "Features" }).every((link) => link.getAttribute("href") === "#features")).toBe(true);
    expect(screen.getAllByRole("link", { name: "How it works" }).every((link) => link.getAttribute("href") === "#how-it-works")).toBe(true);
    expect(screen.getAllByRole("link", { name: "Pricing" }).every((link) => link.getAttribute("href") === "#footer")).toBe(true);
  });
});
