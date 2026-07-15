import * as TabsPrimitive from '@radix-ui/react-tabs'
import type { ComponentPropsWithoutRef } from 'react'

import { cn } from '@/lib/cn'

export const Tabs = TabsPrimitive.Root

export function TabsList({ className, ...props }: ComponentPropsWithoutRef<typeof TabsPrimitive.List>) {
  return <TabsPrimitive.List className={cn('inline-flex rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-1', className)} {...props} />
}

export function TabsTrigger({ className, ...props }: ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger>) {
  return <TabsPrimitive.Trigger className={cn('rounded px-3 py-1.5 text-sm font-medium text-[var(--color-text-muted)] data-[state=active]:bg-[var(--color-surface-subtle)] data-[state=active]:text-[var(--color-text)]', className)} {...props} />
}

export const TabsContent = TabsPrimitive.Content
