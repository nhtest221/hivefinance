import * as ToastPrimitive from '@radix-ui/react-toast'
import type { ComponentPropsWithoutRef } from 'react'

import { cn } from '@/lib/cn'

export function ToastProvider(props: ComponentPropsWithoutRef<typeof ToastPrimitive.Provider>) {
  return <ToastPrimitive.Provider swipeDirection="right" {...props} />
}

export function Toast({ className, ...props }: ComponentPropsWithoutRef<typeof ToastPrimitive.Root>) {
  return <ToastPrimitive.Root className={cn('rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-sm shadow-[var(--shadow-raised)]', className)} {...props} />
}

export const ToastTitle = ToastPrimitive.Title
export const ToastDescription = ToastPrimitive.Description

export function Toaster() {
  return (
    <ToastProvider>
      <ToastPrimitive.Viewport className="fixed bottom-4 right-4 z-50 flex w-96 max-w-[calc(100vw-2rem)] flex-col gap-2" />
    </ToastProvider>
  )
}
