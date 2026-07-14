import { Filter, Search } from 'lucide-react'
import type { ReactNode } from 'react'

import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  EmptyState,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Skeleton,
  Table,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system'
import { AppLayout } from '@/layouts/app-layout'

type ModulePageProps = {
  title: string
  description: string
  badge?: string
  columns: string[]
  rows: string[][]
  summary?: Array<{ label: string; value: string; meta?: string }>
  aside?: ReactNode
}

export function ModulePage({ title, description, badge, columns, rows, summary, aside }: ModulePageProps) {
  return (
    <AppLayout>
      <PageHeader
        title={title}
        description={description}
        actions={
          <>
            {badge ? <Badge variant="info">{badge}</Badge> : null}
            <Button variant="secondary">
              <Filter className="size-4" />
              View options
            </Button>
          </>
        }
      />
      <div className="space-y-4 p-4 lg:p-6">
        {summary ? (
          <div className="grid gap-3 md:grid-cols-3">
            {summary.map((item) => (
              <Card key={item.label}>
                <CardContent>
                  <p className="text-xs font-medium uppercase text-[var(--color-text-subtle)]">{item.label}</p>
                  <p className="mt-2 text-xl font-semibold">{item.value}</p>
                  {item.meta ? <p className="mt-1 text-sm text-[var(--color-text-muted)]">{item.meta}</p> : null}
                </CardContent>
              </Card>
            ))}
          </div>
        ) : null}

        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
          <Card className="overflow-hidden">
            <CardHeader className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div className="relative w-full md:max-w-sm">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-text-subtle)]" />
                <Input className="pl-9" placeholder={`Search ${title.toLowerCase()}`} />
              </div>
              <div className="flex gap-2">
                <Select defaultValue="all">
                  <SelectTrigger className="w-36">
                    <SelectValue placeholder="Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All status</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="review">Review</SelectItem>
                  </SelectContent>
                </Select>
                <Button variant="secondary">Export</Button>
              </div>
            </CardHeader>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    {columns.map((column) => (
                      <TableHead key={column}>{column}</TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <tbody>
                  {rows.map((row) => (
                    <TableRow key={row.join('-')}>
                      {row.map((cell, index) => (
                        <TableCell className={index >= row.length - 2 ? 'text-right tabular-nums' : undefined} key={`${cell}-${index}`}>
                          {renderCell(cell)}
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </tbody>
              </Table>
            </div>
          </Card>

          {aside ?? (
            <Card>
              <CardHeader>
                <h2 className="text-sm font-semibold">Detail preview</h2>
              </CardHeader>
              <CardContent className="space-y-3">
                <Skeleton className="h-4 w-2/3" />
                <Skeleton className="h-20 w-full" />
                <EmptyState title="Select a row" description="A read-only detail drawer will keep list context visible." />
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  )
}

function renderCell(value: string) {
  const lower = value.toLowerCase()

  if (['active', 'posted', 'effective', 'reconciled', 'paid', 'healthy', 'matched'].some((token) => lower.includes(token))) {
    return <Badge variant="success">{value}</Badge>
  }

  if (['draft', 'review', 'unreconciled', 'overdue', 'awaiting', 'partially'].some((token) => lower.includes(token))) {
    return <Badge variant="warning">{value}</Badge>
  }

  if (['reversed', 'void', 'failed'].some((token) => lower.includes(token))) {
    return <Badge variant="danger">{value}</Badge>
  }

  return value
}
