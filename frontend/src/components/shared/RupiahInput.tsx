import { Input } from '@/components/ui/input'

/** Digits-only value in RHF (string-then-convert), formatted with Indonesian thousand separators while editing. */
export function RupiahInput({
  value,
  onChange,
  placeholder = '0',
  disabled,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  disabled?: boolean
}) {
  const display = value ? new Intl.NumberFormat('id-ID').format(Number(value)) : ''
  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">Rp</span>
      <Input
        className="pl-9"
        inputMode="numeric"
        placeholder={placeholder}
        disabled={disabled}
        value={display}
        onChange={(event) => onChange(event.target.value.replace(/\D/g, ''))}
      />
    </div>
  )
}
