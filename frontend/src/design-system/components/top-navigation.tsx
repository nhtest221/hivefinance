import type { ReactNode } from 'react'

export function TopNavigation({ children }: { children?: ReactNode }) {
  return <header className="flex h-14 items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-surface)] px-4">{children}</header>
}
