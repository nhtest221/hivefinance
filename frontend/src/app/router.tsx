import { createBrowserRouter } from 'react-router'

import { FoundationScreen } from './foundation-screen'

export const router = createBrowserRouter([
  {
    path: '/',
    element: <FoundationScreen />,
  },
])
