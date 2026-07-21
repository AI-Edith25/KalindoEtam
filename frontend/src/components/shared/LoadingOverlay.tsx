import { Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * Absolutely positioned over a `relative` parent. Wrap the section you
 * want to dim/block while it's loading:
 *
 *   <div className="relative">
 *     ...content...
 *     {isLoading && <LoadingOverlay />}
 *   </div>
 */
export function LoadingOverlay({ label, className }: { label?: string; className?: string }) {
  return (
    <div
      className={cn(
        'absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-background/70 backdrop-blur-[1px]',
        className,
      )}
    >
      <Loader2 className="size-6 animate-spin text-muted-foreground" />
      {label && <p className="text-sm text-muted-foreground">{label}</p>}
    </div>
  )
}
