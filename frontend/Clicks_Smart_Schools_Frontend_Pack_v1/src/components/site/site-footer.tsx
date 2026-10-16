export function SiteFooter(): React.ReactElement {
  return (
    <footer id="footer" className="border-t bg-surface">
      <div className="mx-auto flex max-w-300 flex-col gap-4 px-5 py-8 text-sm text-muted sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-10">
        <div className="flex flex-col gap-1"><p className="font-semibold text-navy-950">Clicks Smart Schools</p><p>Official brand and contact details will be added after verification.</p></div>
        <p>Public website</p>
      </div>
    </footer>
  );
}
