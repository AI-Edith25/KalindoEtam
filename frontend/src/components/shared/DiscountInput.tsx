import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { RupiahInput } from '@/components/shared/RupiahInput'

interface DiscountInputProps {
  type: string
  value: string
  onTypeChange: (type: string) => void
  onValueChange: (value: string) => void
  disabled?: boolean
}

/**
 * Compact type+value pair for a line's own discount — Rp (RupiahInput) or % (plain 0–100 input),
 * same two-field shape the old header-level Discount Type/Amount/Percentage fields used, just
 * narrow enough to drop into a table cell. Purely controlled — callers own the two underlying
 * form fields (discount_type/discount_value), whether react-hook-form or plain useState.
 */
export function DiscountInput({ type, value, onTypeChange, onValueChange, disabled }: DiscountInputProps) {
  return (
    <div className="flex items-center gap-1">
      <Select value={type || 'amount'} onValueChange={onTypeChange} disabled={disabled}>
        <SelectTrigger className="w-14 shrink-0 px-2" aria-label="Discount type">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="amount">Rp</SelectItem>
          <SelectItem value="percentage">%</SelectItem>
        </SelectContent>
      </Select>
      {type === 'percentage' ? (
        <Input
          type="number"
          min="0"
          max="100"
          step="0.01"
          className="w-20"
          value={value}
          onChange={(event) => onValueChange(event.target.value)}
          disabled={disabled}
          placeholder="0"
          aria-label="Discount value"
        />
      ) : (
        <RupiahInput value={value} onChange={onValueChange} disabled={disabled} decimals={2} aria-label="Discount value" />
      )}
    </div>
  )
}
