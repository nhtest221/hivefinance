import { LockKeyhole } from 'lucide-react'

import { Alert, Button, Card, CardContent, Input } from '@/design-system'

export function LoginPage() {
  return (
    <main className="grid min-h-screen place-items-center bg-[var(--color-bg)] p-4">
      <Card className="w-full max-w-md">
        <CardContent className="space-y-5 p-6">
          <div className="space-y-2">
            <div className="flex size-10 items-center justify-center rounded-lg bg-[var(--color-primary)] text-white">
              <LockKeyhole className="size-5" />
            </div>
            <div>
              <h1 className="text-xl font-semibold">Sign in to HiveFinance</h1>
              <p className="mt-1 text-sm text-[var(--color-text-muted)]">Secure workspace for Finance & Accounts.</p>
            </div>
          </div>
          <div className="space-y-3">
            <label className="space-y-1 text-sm font-medium">
              <span>Email</span>
              <Input placeholder="finance@notionhive.com" type="email" />
            </label>
            <label className="space-y-1 text-sm font-medium">
              <span>Password</span>
              <Input placeholder="Enter password" type="password" />
            </label>
            <Button className="w-full">Continue</Button>
          </div>
          <Alert>MFA is required for Owner and Finance Manager roles. This M0.5 screen is static.</Alert>
        </CardContent>
      </Card>
    </main>
  )
}
