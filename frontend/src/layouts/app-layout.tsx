import { Home, Settings } from 'lucide-react'
import type { ReactNode } from 'react'

import { Button, PageHeader, Sidebar, SidebarSection, TopNavigation } from '@/design-system'

export function AppLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen bg-[var(--color-bg)]">
      <Sidebar>
        <div className="flex h-14 items-center border-b border-[var(--color-border)] px-5 text-sm font-semibold">HiveFinance</div>
        <SidebarSection title="Foundation">
          <Button variant="ghost" className="w-full justify-start">
            <Home className="size-4" />
            System
          </Button>
          <Button variant="ghost" className="w-full justify-start">
            <Settings className="size-4" />
            Settings
          </Button>
        </SidebarSection>
      </Sidebar>
      <div className="min-w-0 flex-1">
        <TopNavigation>
          <span className="text-sm font-medium text-[var(--color-text-muted)]">M0 Platform</span>
        </TopNavigation>
        <PageHeader title="Platform Foundation" description="Reusable shell and design system primitives." />
        <main className="p-6">{children}</main>
      </div>
    </div>
  )
}
