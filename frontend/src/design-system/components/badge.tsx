import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'react'

import { cn } from '@/lib/cn'

const badgeVariants = cva('inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium', {
  variants: {
    variant: {
      neutral: 'bg-[var(--color-surface-subtle)] text-[var(--color-text-muted)]',
      success: 'bg-emerald-50 text-[var(--color-success)]',
      warning: 'bg-amber-50 text-[var(--color-warning)]',
      danger: 'bg-red-50 text-[var(--color-danger)]',
      info: 'bg-[var(--color-primary-soft)] text-[var(--color-info)]',
    },
  },
  defaultVariants: {
    variant: 'neutral',
  },
})

type BadgeProps = HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>

export function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />
}
