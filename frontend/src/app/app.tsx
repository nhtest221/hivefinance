import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router'

import { Toaster } from '@/design-system/components/toast'
import { queryClient } from '@/services/query-client'
import { router } from './router'

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <Toaster />
    </QueryClientProvider>
  )
}
