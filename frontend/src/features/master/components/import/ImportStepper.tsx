import { cn } from '@/lib/utils'

const STEPS = ['Upload', 'Mapping', 'Resolve Relations', 'Preview', 'Commit']

export function ImportStepper({ current }: { current: number }) {
  return (
    <ol className="flex flex-wrap items-center gap-2 text-sm">
      {STEPS.map((label, index) => (
        <li key={label} className="flex items-center gap-2">
          <span
            className={cn(
              'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
              index === current
                ? 'border-primary bg-primary text-primary-foreground'
                : index < current
                  ? 'border-primary text-primary'
                  : 'border-border text-muted-foreground',
            )}
          >
            {index + 1}
          </span>
          <span className={index === current ? 'font-medium' : 'text-muted-foreground'}>{label}</span>
          {index < STEPS.length - 1 && <span className="mx-1 h-px w-6 bg-border" />}
        </li>
      ))}
    </ol>
  )
}
