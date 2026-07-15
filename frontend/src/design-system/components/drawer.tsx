import * as DialogPrimitive from '@radix-ui/react-dialog'
import type { ComponentPropsWithoutRef } from 'react'

import { cn } from '@/lib/cn'

export const Drawer = DialogPrimitive.Root
export const DrawerTrigger = DialogPrimitive.Trigger

export function DrawerContent({ className, ...props }: ComponentPropsWithoutRef<typeof DialogPrimitive.Content>) {
  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-40 bg-black/30" />
      <DialogPrimitive.Content
        className={cn('fixed bottom-0 right-0 top-0 z-50 w-[min(28rem,100vw)] border-l border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-raised)]', className)}
        {...props}
      />
    </DialogPrimitive.Portal>
  )
}
