import { type FormEvent, useState } from 'react'
import { Link, useSearchParams } from 'react-router'

import { Alert, Button, Card, CardContent, Input } from '@/design-system'
import { ApiRequestError, resetPassword } from '@/features/identity/auth-api'

export function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const [email, setEmail] = useState(searchParams.get('email') ?? '')
  const [token, setToken] = useState(searchParams.get('token') ?? '')
  const [password, setPassword] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setMessage(null)
    setIsSubmitting(true)

    try {
      await resetPassword(email, token, password)
      setMessage('Password reset complete. You can sign in with the new password.')
    } catch (requestError) {
      setError(requestError instanceof ApiRequestError ? requestError.message : 'Unable to reset this password.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-[var(--color-bg)] p-4">
      <Card className="w-full max-w-md">
        <CardContent className="space-y-5 p-6">
          <div className="space-y-2">
            <img src="/nh_logo.png" alt="HiveFinance" className="h-7 w-auto object-contain" />
            <div>
              <h1 className="text-xl font-semibold">Choose a new password</h1>
              <p className="mt-1 text-sm text-[var(--color-text-muted)]">Use the reset token issued for your HiveFinance account.</p>
            </div>
          </div>
          <form className="space-y-3" onSubmit={handleSubmit}>
            <label className="space-y-1 text-sm font-medium">
              <span>Email</span>
              <Input autoComplete="email" onChange={(event) => setEmail(event.target.value)} required type="email" value={email} />
            </label>
            <label className="space-y-1 text-sm font-medium">
              <span>Reset token</span>
              <Input onChange={(event) => setToken(event.target.value)} required value={token} />
            </label>
            <label className="space-y-1 text-sm font-medium">
              <span>New password</span>
              <Input autoComplete="new-password" minLength={12} onChange={(event) => setPassword(event.target.value)} required type="password" value={password} />
            </label>
            {message !== null ? <Alert>{message}</Alert> : null}
            {error !== null ? <Alert>{error}</Alert> : null}
            <Button className="w-full" disabled={isSubmitting} type="submit">
              {isSubmitting ? 'Saving password...' : 'Reset password'}
            </Button>
          </form>
          <Link className="text-sm font-medium text-[var(--color-primary)] hover:underline" to="/login">
            Back to login
          </Link>
        </CardContent>
      </Card>
    </main>
  )
}
