import { AppLayout } from '@/layouts/app-layout'

export function FoundationScreen() {
  return (
    <AppLayout>
      <div className="space-y-6">
        <div>
          <p className="text-sm font-medium text-[var(--color-text-muted)]">Milestone M0</p>
          <h1 className="text-2xl font-semibold tracking-normal text-[var(--color-text)]">Design system foundation</h1>
        </div>
      </div>
    </AppLayout>
  )
}
