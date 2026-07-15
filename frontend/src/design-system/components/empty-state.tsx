import type { ReactNode } from 'react'

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex min-h-48 flex-col items-center justify-center rounded-lg border border-dashed border-[var(--color-border)] bg-[var(--color-surface)] p-6 text-center">
      <h2 className="text-sm font-semibold text-[var(--color-text)]">{title}</h2>
      {description ? <p className="mt-1 max-w-md text-sm text-[var(--color-text-muted)]">{description}</p> : null}
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  )
}
