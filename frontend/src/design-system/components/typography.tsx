import type { HTMLAttributes } from 'react'

import { cn } from '@/lib/cn'

export function DisplayText({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return <h1 className={cn('text-3xl font-semibold tracking-normal text-[var(--color-text)]', className)} {...props} />
}

export function TitleText({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return <h2 className={cn('text-xl font-semibold tracking-normal text-[var(--color-text)]', className)} {...props} />
}

export function BodyText({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn('text-sm leading-6 text-[var(--color-text)]', className)} {...props} />
}

export function CaptionText({ className, ...props }: HTMLAttributes<HTMLSpanElement>) {
  return <span className={cn('text-xs font-medium text-[var(--color-text-muted)]', className)} {...props} />
}
