import { ChevronRight } from 'lucide-react'

type Breadcrumb = {
  label: string
  href?: string
}

export function Breadcrumbs({ items }: { items: Breadcrumb[] }) {
  return (
    <nav aria-label="Breadcrumb" className="flex items-center gap-1 text-sm text-[var(--color-text-muted)]">
      {items.map((item, index) => (
        <span className="flex items-center gap-1" key={item.label}>
          {index > 0 ? <ChevronRight className="size-3" /> : null}
          {item.href ? <a href={item.href}>{item.label}</a> : <span>{item.label}</span>}
        </span>
      ))}
    </nav>
  )
}
