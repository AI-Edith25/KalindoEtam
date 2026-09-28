import { apiClient } from '@/shared/services/apiClient'

const SESSION_FLAG = 'browser-diagnostics-sent'

/**
 * Confirms/refutes the "old Chrome can't parse oklch()/color-mix()" hypothesis for a device we
 * can't access, without asking the user to open devtools — fires once per tab session, fire-and-
 * forget, no PII (feature-support booleans + UA + DPR + computed font only). See
 * BrowserDiagnosticsController on the backend for where this lands (storage/logs/laravel.log).
 */
export function reportBrowserDiagnostics(): void {
  if (typeof window === 'undefined' || sessionStorage.getItem(SESSION_FLAG)) return
  sessionStorage.setItem(SESSION_FLAG, '1')

  apiClient
    .post('/diagnostics/browser-support', {
      user_agent: navigator.userAgent,
      supports_oklch: CSS.supports('color', 'oklch(50% 0.1 200)'),
      supports_color_mix: CSS.supports('color', 'color-mix(in srgb, red, blue)'),
      device_pixel_ratio: window.devicePixelRatio,
      body_font_family: getComputedStyle(document.body).fontFamily,
      page: window.location.pathname,
    })
    .catch(() => {
      // Best-effort diagnostic only — never surface this to the user.
    })
}
