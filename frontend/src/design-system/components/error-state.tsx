import { AlertTriangle } from 'lucide-react'

export function ErrorState({ title = 'Something went wrong', description }: { title?: string; description?: string }) {
  return (
    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-[var(--color-danger)]">
      <div className="flex items-center gap-2 font-semibold">
        <AlertTriangle className="size-4" />
        {title}
      </div>
      {description ? <p className="mt-1 text-red-700">{description}</p> : null}
    </div>
  )
}
