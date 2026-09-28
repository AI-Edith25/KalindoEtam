import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/**
 * Digits-only value in RHF (string-then-convert), formatted with Indonesian thousand separators.
 * `decimals` (default 0, unchanged for every existing caller) opts a field into fractional input
 * — e.g. Purchase/Sales Order Unit Price and Payment Voucher/Official Receipt Amount, which the
 * backend already stores as decimal(x,2).
 *
 * Calculator-tape entry when decimals > 0: the user only ever types digits (no "." key), and the
 * last `decimals` digits typed are always the fraction — same convention as a POS/ATM keypad, so
 * "132739" reads as Rp 1.327,39. Every keystroke re-derives the full digit string from the
 * formatted display (stripping punctuation) rather than tracking a separate raw buffer, which
 * gives Backspace its natural "drop the last digit and reshift" behavior for free.
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
  const formatted = value
    ? new Intl.NumberFormat('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
    : ''

  const handleChange = (raw: string) => {
    const digits = raw.replace(/\D/g, '')
    if (digits === '') {
      onChange('')
      return
    }

    if (decimals <= 0) {
      onChange(digits)
      return
    }

    // The stored value always uses "." (never Indonesian ","), since it round-trips through
    // Number() elsewhere (schema validation, toPayload()).
    onChange((parseInt(digits, 10) / 10 ** decimals).toFixed(decimals))
  }

  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">Rp</span>
      <Input
        className={cn('pl-9', className)}
        inputMode="numeric"
        placeholder={placeholder}
        disabled={disabled}
        aria-label={ariaLabel}
        value={formatted}
        onChange={(event) => handleChange(event.target.value)}
      />
    </div>
  )
}
