import { StatusBadge } from '@/components/shared/StatusBadge'

/** Manual renders no badge at all — only an imported document gets a visible marker. */
export function SourceBadge({ source }: { source?: 'manual' | 'import' | null }) {
  return source === 'import' ? <StatusBadge status="import" /> : null
}
