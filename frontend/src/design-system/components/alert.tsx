import { AlertCircle } from 'lucide-react'
import type { HTMLAttributes } from 'react'

import { cn } from '@/lib/cn'

export function Alert({ className, children, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn('flex gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-sm shadow-sm', className)} {...props}>
      <AlertCircle className="mt-0.5 size-4 text-[var(--color-info)]" />
      <div>{children}</div>
    </div>
  )
}
