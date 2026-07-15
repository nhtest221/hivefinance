import { Command, Search } from 'lucide-react'
import type { ReactNode } from 'react'
import { NavLink } from 'react-router'

import { Badge, Button, Input, Select, SelectContent, SelectItem, SelectTrigger, SelectValue, Sidebar, SidebarSection, TopNavigation } from '@/design-system'
import { navigationGroups } from '@/app/navigation'
import { cn } from '@/lib/cn'

export function AppLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen bg-[var(--color-bg)]">
      <Sidebar>
        <div className="flex h-14 items-center justify-between border-b border-[var(--color-border)] px-5">
          <img src="/nh_logo.png" alt="HiveFinance" className="h-6 w-auto object-contain" />
          <Badge variant="info">BD</Badge>
        </div>
        {navigationGroups.map((group) => (
          <SidebarSection key={group.label} title={group.label}>
            {group.items.map((item) => {
              const Icon = item.icon

              return (
                <NavLink
                  className={({ isActive }) =>
                    cn(
                      'flex h-8 items-center justify-between rounded-md px-2 text-sm font-medium text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text)]',
                      isActive && 'bg-[var(--color-surface-subtle)] text-[var(--color-text)]',
                    )
                  }
                  key={item.path}
                  to={item.path}
                >
                  <span className="flex min-w-0 items-center gap-2">
                    <Icon className="size-4 shrink-0" />
                    <span className="truncate">{item.label}</span>
                  </span>
                  <span className="hidden text-[10px] text-[var(--color-text-subtle)] xl:inline">{item.shortcut}</span>
                </NavLink>
              )
            })}
          </SidebarSection>
        ))}
      </Sidebar>
      <div className="min-w-0 flex-1">
        <TopNavigation>
          <div className="flex min-w-0 flex-1 items-center gap-3">
            <div className="relative hidden w-full max-w-md md:block">
              <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-text-subtle)]" />
              <Input className="h-8 pl-9" placeholder="Search documents, accounts, reports" />
            </div>
            <Badge>FY26-P01</Badge>
          </div>
          <div className="flex items-center gap-2">
            <Select defaultValue="notionhive-bd">
              <SelectTrigger className="h-8 w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="notionhive-bd">Notionhive Bangladesh</SelectItem>
                <SelectItem value="notionhive-ca">Notionhive Canada</SelectItem>
              </SelectContent>
            </Select>
            <Button variant="secondary" size="sm">
              <Command className="size-4" />
              <span className="hidden sm:inline">Shortcuts</span>
            </Button>
          </div>
        </TopNavigation>
        <main>{children}</main>
      </div>
    </div>
  )
}
