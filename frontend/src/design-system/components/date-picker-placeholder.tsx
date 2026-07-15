import { Calendar } from 'lucide-react'

import { Button } from './button'

export function DatePickerPlaceholder() {
  return (
    <Button type="button" variant="secondary" className="w-full justify-start text-[var(--color-text-muted)]">
      <Calendar className="size-4" />
      Select date
    </Button>
  )
}
