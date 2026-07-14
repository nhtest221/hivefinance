import { useCallback, useState } from 'react'

export function useDisclosure(initialOpen = false) {
  const [open, setOpen] = useState(initialOpen)
  const onOpen = useCallback(() => setOpen(true), [])
  const onClose = useCallback(() => setOpen(false), [])
  const onToggle = useCallback(() => setOpen((value) => !value), [])

  return { open, setOpen, onOpen, onClose, onToggle }
}
