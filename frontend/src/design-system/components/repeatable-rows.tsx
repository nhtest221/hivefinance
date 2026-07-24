import { Plus, Trash2 } from 'lucide-react'

import { Button } from './button'
import { Input } from './input'

export type RepeatableRowField<T> = { key: keyof T; label: string; width?: string }

/** Add/remove row editor for command payloads that are flat arrays of small objects
 * (settlement allocations, credit sources, note lines, version references). Every row
 * is string-keyed in local state; callers convert to the typed payload at submit time,
 * since Money/number fields vary in currency and precision per caller. */
export function RepeatableRows<T extends Record<string, string>>({ label, hint, value, onChange, fields, makeEmpty }: {
  label: string
  hint?: string
  value: T[]
  onChange: (rows: T[]) => void
  fields: RepeatableRowField<T>[]
  makeEmpty: () => T
}) {
  function update(index: number, key: keyof T, next: string) {
    onChange(value.map((row, i) => (i === index ? { ...row, [key]: next } : row)))
  }
  function remove(index: number) {
    onChange(value.filter((_, i) => i !== index))
  }

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium text-[var(--color-text)]">{label}</p>
      {hint ? <p className="text-xs text-[var(--color-text-muted)]">{hint}</p> : null}
      {value.length === 0 ? <p className="text-xs text-[var(--color-text-muted)]">None added yet.</p> : null}
      {value.map((row, index) => (
        <div className="flex flex-wrap items-center gap-2" key={index}>
          {fields.map((field) => (
            <Input
              key={String(field.key)}
              placeholder={field.label}
              value={row[field.key]}
              onChange={(event) => { update(index, field.key, event.target.value) }}
              className={field.width ?? 'w-40'}
              required
            />
          ))}
          <Button type="button" variant="ghost" size="sm" onClick={() => { remove(index) }}>
            <Trash2 className="size-4" />
          </Button>
        </div>
      ))}
      <Button type="button" variant="secondary" size="sm" onClick={() => { onChange([...value, makeEmpty()]) }}>
        <Plus className="size-3.5" /> Add row
      </Button>
    </div>
  )
}
