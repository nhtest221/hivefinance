import { type FormEvent, useState } from 'react'
import { Link } from 'react-router'

import { Alert, Button, Card, CardContent, Input } from '@/design-system'
import { ApiRequestError, requestPasswordReset } from '@/features/identity/auth-api'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setMessage(null)
    setIsSubmitting(true)

    try {
      await requestPasswordReset(email)
      setMessage('If the account exists, a reset link has been issued.')
    } catch (requestError) {
      setError(requestError instanceof ApiRequestError ? requestError.message : 'Unable to request a password reset.')
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
              <h1 className="text-xl font-semibold">Reset password</h1>
              <p className="mt-1 text-sm text-[var(--color-text-muted)]">Request a reset link for an active HiveFinance account.</p>
            </div>
          </div>
          <form className="space-y-3" onSubmit={handleSubmit}>
            <label className="space-y-1 text-sm font-medium">
              <span>Email</span>
              <Input autoComplete="email" onChange={(event) => setEmail(event.target.value)} placeholder="finance@notionhive.com" required type="email" value={email} />
            </label>
            {message !== null ? <Alert>{message}</Alert> : null}
            {error !== null ? <Alert>{error}</Alert> : null}
            <Button className="w-full" disabled={isSubmitting} type="submit">
              {isSubmitting ? 'Issuing reset...' : 'Send reset link'}
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
