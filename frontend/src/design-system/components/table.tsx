import type { HTMLAttributes, ThHTMLAttributes, TdHTMLAttributes } from 'react'

import { cn } from '@/lib/cn'

// Wrapping in its own horizontal-scroll container keeps a wide table (many columns, or
// narrow viewports) from forcing the whole page — including the sidebar and top nav —
// to scroll sideways. Every page composing <Table> gets this for free.
export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
  return (
    <div className="overflow-x-auto">
      <table className={cn('w-full border-collapse text-left text-sm', className)} {...props} />
    </div>
  )
}

export function TableHeader({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={cn('border-b border-[var(--color-border)] bg-[var(--color-surface-subtle)] text-xs font-medium uppercase text-[var(--color-text-muted)]', className)} {...props} />
}

export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
  return <tr className={cn('border-b border-[var(--color-border)] last:border-0', className)} {...props} />
}

export function TableHead({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return <th className={cn('px-3 py-2 font-medium', className)} {...props} />
}

export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn('px-3 py-2 text-[var(--color-text)]', className)} {...props} />
}
