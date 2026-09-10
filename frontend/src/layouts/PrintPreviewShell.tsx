import { Outlet } from 'react-router-dom'

/**
 * Shared canvas behind every print-preview route (see the flat print-route
 * block in router.tsx) — a solid dark backdrop so the white "paper" reads as
 * one clean sheet, the way a native PDF viewer separates page from
 * background, instead of blending into the app's own light background.
 * print:contents removes this wrapper from the box model when the page is
 * actually printed, so it never affects the document's own @page margins.
 */
export function PrintPreviewShell() {
  return (
    <div className="min-h-screen bg-[#525659] px-4 py-8 print:contents">
      <Outlet />
    </div>
  )
}
