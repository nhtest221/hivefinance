import type { ReactNode } from 'react'

import { cn } from '@/lib/cn'

export function Sidebar({ children, className }: { children: ReactNode; className?: string }) {
  return <aside className={cn('hidden w-64 shrink-0 border-r border-[var(--color-border)] bg-[var(--color-surface)] lg:block', className)}>{children}</aside>
}

export function SidebarSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-2 px-3 py-4">
      <h2 className="px-2 text-xs font-semibold uppercase text-[var(--color-text-subtle)]">{title}</h2>
      <div className="space-y-1">{children}</div>
    </section>
  )
}
