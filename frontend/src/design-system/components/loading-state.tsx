import { Loader2 } from 'lucide-react'

export function LoadingState({ label = 'Loading' }: { label?: string }) {
  return (
    <div className="flex items-center gap-2 text-sm text-[var(--color-text-muted)]">
      <Loader2 className="size-4 animate-spin" />
      <span>{label}</span>
    </div>
  )
}
