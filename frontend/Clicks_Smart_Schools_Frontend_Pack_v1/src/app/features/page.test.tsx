import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import FeaturesPage from "@/app/features/page";

afterEach(cleanup);

describe("PUB-002 features page", () => {
  it("renders the feature hero and all six product areas", () => {
    render(<FeaturesPage />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("A calmer operating rhythm for school teams.");
    expect(screen.getAllByRole("link", { name: /Explore/ })).toHaveLength(6);
    expect(screen.getAllByText("Academic Management")).toHaveLength(2);
    expect(screen.getAllByText("Fees and Payments")).toHaveLength(2);
    expect(screen.getAllByText("Student Management")).toHaveLength(2);
    expect(screen.getAllByText("Parent Communication")).toHaveLength(2);
    expect(screen.getAllByText("Reports and Analytics")).toHaveLength(2);
    expect(screen.getAllByText("School Administration")).toHaveLength(2);
  });

  it("keeps feature availability and commercial claims neutral", () => {
    render(<FeaturesPage />);
    const pageText = document.body.textContent ?? "";

    expect(pageText).not.toMatch(/available|planned|free|credit card|testimonial|schools already|customer logos|certification/i);
    expect(pageText).not.toMatch(/\$|₦|%/);
  });

  it("uses safe anchor destinations for feature CTAs", () => {
    render(<FeaturesPage />);

    expect(screen.getAllByRole("link", { name: /Explore/ }).every((link) => link.getAttribute("href") === "/#get-started")).toBe(true);
    expect(screen.getAllByRole("link", { name: /Create a School Account/ }).every((link) => link.getAttribute("href") === "/#get-started")).toBe(true);
    expect(screen.getByRole("navigation", { name: "Primary navigation" })).toBeInTheDocument();
  });
});
