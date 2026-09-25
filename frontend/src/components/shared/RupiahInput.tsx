import { useState } from 'react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/**
 * Digits-only value in RHF (string-then-convert), formatted with Indonesian thousand separators
 * while editing. `decimals` (default 0, unchanged for every existing caller) opts a field into
 * fractional input — e.g. Purchase/Sales Order Unit Price, which the backend already stores as
 * decimal(x,2). While focused, shows the raw digits+dot the user is typing (reformatting a money
 * input on every keystroke fights decimal entry — the trailing "." a user just typed would get
 * silently dropped by Intl.NumberFormat before they can type the fraction); on blur, shows the
 * grouped/rounded display like every other Rupiah field.
 */
export function RupiahInput({
  value,
  onChange,
  placeholder = '0',
  disabled,
  className,
  decimals = 0,
  'aria-label': ariaLabel,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  disabled?: boolean
  className?: string
  /** Max fractional digits allowed; 0 (default) keeps the original whole-Rupiah-only behavior. */
  decimals?: number
  'aria-label'?: string
}) {
  const [isFocused, setIsFocused] = useState(false)

  const formatted = value
    ? new Intl.NumberFormat('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
    : ''

  const handleChange = (raw: string) => {
    if (decimals <= 0) {
      onChange(raw.replace(/\D/g, ''))
      return
    }

    // Digits + at most one dot, fraction capped at `decimals` — the underlying stored value
    // always uses "." (never Indonesian ","), since it round-trips through Number() elsewhere
    // (schema validation, toPayload()).
    let cleaned = raw.replace(/[^0-9.]/g, '')
    const firstDot = cleaned.indexOf('.')
    if (firstDot !== -1) {
      const intPart = cleaned.slice(0, firstDot)
      const fracPart = cleaned.slice(firstDot + 1).replace(/\./g, '').slice(0, decimals)
      cleaned = `${intPart}.${fracPart}`
    }
    onChange(cleaned)
  }

  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">Rp</span>
      <Input
        className={cn('pl-9', className)}
        inputMode={decimals > 0 ? 'decimal' : 'numeric'}
        placeholder={placeholder}
        disabled={disabled}
        aria-label={ariaLabel}
        value={decimals > 0 && isFocused ? value : formatted}
        onFocus={() => setIsFocused(true)}
        onBlur={() => setIsFocused(false)}
        onChange={(event) => handleChange(event.target.value)}
      />
    </div>
  )
}
