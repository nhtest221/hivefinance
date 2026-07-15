import * as RadioGroupPrimitive from '@radix-ui/react-radio-group'
import type { ComponentPropsWithoutRef } from 'react'

import { cn } from '@/lib/cn'

export const RadioGroup = RadioGroupPrimitive.Root

export function RadioGroupItem({ className, ...props }: ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>) {
  return (
    <RadioGroupPrimitive.Item
      className={cn('flex size-4 items-center justify-center rounded-full border border-[var(--color-border-strong)] bg-[var(--color-surface)] data-[state=checked]:border-[var(--color-primary)]', className)}
      {...props}
    >
      <RadioGroupPrimitive.Indicator className="size-2 rounded-full bg-[var(--color-primary)]" />
    </RadioGroupPrimitive.Item>
  )
}
