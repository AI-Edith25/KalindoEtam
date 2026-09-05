import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/** Digits-only value in RHF (string-then-convert), formatted with Indonesian thousand separators while editing. */
export function RupiahInput({
  value,
  onChange,
  placeholder = '0',
  disabled,
  className,
  'aria-label': ariaLabel,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  disabled?: boolean
  className?: string
  'aria-label'?: string
}) {
  const display = value ? new Intl.NumberFormat('id-ID').format(Number(value)) : ''
  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">Rp</span>
      <Input
        className={cn('pl-9', className)}
        inputMode="numeric"
        placeholder={placeholder}
        disabled={disabled}
        aria-label={ariaLabel}
        value={display}
        onChange={(event) => onChange(event.target.value.replace(/\D/g, ''))}
      />
    </div>
  )
}
