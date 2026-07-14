import type { ReactNode } from 'react'

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="flex flex-col gap-3 border-b border-[var(--color-border)] bg-[var(--color-surface)] px-6 py-5 md:flex-row md:items-center md:justify-between">
      <div>
        <h1 className="text-xl font-semibold text-[var(--color-text)]">{title}</h1>
        {description ? <p className="mt-1 text-sm text-[var(--color-text-muted)]">{description}</p> : null}
      </div>
      {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
    </div>
  )
}
