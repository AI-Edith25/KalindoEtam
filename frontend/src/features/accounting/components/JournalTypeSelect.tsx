import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { JOURNAL_LIST_TYPE_OPTIONS, type JournalListType } from '../lib/journalListType'

interface JournalTypeSelectProps {
  value: JournalListType
  onChange: (value: JournalListType) => void
}

/** The leading filter on Journal List — same Select style as every other filter here (see CashBookFiltersBar's Status field). */
export function JournalTypeSelect({ value, onChange }: JournalTypeSelectProps) {
  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-xs text-muted-foreground">Journal Type</span>
      <Select value={value} onValueChange={(next) => onChange(next as JournalListType)}>
        <SelectTrigger className="w-56">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {JOURNAL_LIST_TYPE_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}
