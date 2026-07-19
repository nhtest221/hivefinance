import { ShieldCheck } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { Link, useNavigate } from 'react-router'

import { Alert, Button, Card, CardContent, Input } from '@/design-system'
import { ApiRequestError, login, verifyMfa } from '@/features/identity/auth-api'

export function LoginPage() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('finance@notionhive.com')
  const [password, setPassword] = useState('')
  const [mfaCode, setMfaCode] = useState('')
  const [challengeId, setChallengeId] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      const result = challengeId === null ? await login(email, password) : await verifyMfa(challengeId, mfaCode)

      if ('mfa_required' in result) {
        setChallengeId(result.mfa_challenge_id)
        return
      }

      sessionStorage.setItem('hivefinance.auth_token', result.token)
      sessionStorage.setItem('hivefinance.permissions', JSON.stringify(result.session.permissions))
      sessionStorage.setItem('hivefinance.roles', JSON.stringify(result.session.roles))
      if (result.session.active_entity !== null) {
        sessionStorage.setItem('hivefinance.entity_id', result.session.active_entity.id)
        sessionStorage.setItem('hivefinance.functional_currency', result.session.active_entity.functional_currency)
      }
      navigate('/')
    } catch (requestError) {
      if (requestError instanceof ApiRequestError) {
        setError(requestError.errorCode === 'account_locked' ? 'Account locked after repeated failed attempts. Ask an Owner or Admin to review access.' : requestError.message)
      } else {
        setError('Unable to reach HiveFinance authentication.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-[var(--color-bg)] p-4">
      <Card className="w-full max-w-md">
        <CardContent className="space-y-5 p-6">
          <div className="space-y-2">
            <div className="flex items-center gap-3">
              <img src="/nh_logo.png" alt="HiveFinance" className="h-7 w-auto object-contain" />
              <div className="flex size-9 items-center justify-center rounded-lg bg-[var(--color-primary)] text-white">
                <ShieldCheck className="size-5" />
              </div>
            </div>
            <div>
              <h1 className="text-xl font-semibold">Sign in to HiveFinance</h1>
              <p className="mt-1 text-sm text-[var(--color-text-muted)]">Secure workspace for Finance & Accounts.</p>
            </div>
          </div>
          <form className="space-y-3" onSubmit={handleSubmit}>
            <label className="space-y-1 text-sm font-medium">
              <span>Email</span>
              <Input autoComplete="email" onChange={(event) => setEmail(event.target.value)} placeholder="finance@notionhive.com" required type="email" value={email} />
            </label>
            <label className="space-y-1 text-sm font-medium">
              <span>Password</span>
              <Input autoComplete="current-password" onChange={(event) => setPassword(event.target.value)} placeholder="Enter password" required type="password" value={password} />
            </label>
            {challengeId !== null ? (
              <label className="space-y-1 text-sm font-medium">
                <span>MFA code</span>
                <Input inputMode="numeric" maxLength={6} onChange={(event) => setMfaCode(event.target.value)} placeholder="000000" required value={mfaCode} />
              </label>
            ) : null}
            {error !== null ? <Alert>{error}</Alert> : null}
            <Button className="w-full" disabled={isSubmitting} type="submit">
              {isSubmitting ? 'Checking access...' : challengeId === null ? 'Continue' : 'Verify MFA'}
            </Button>
          </form>
          <div className="flex items-center justify-between text-sm">
            <Link className="font-medium text-[var(--color-primary)] hover:underline" to="/forgot-password">
              Reset password
            </Link>
            <span className="text-[var(--color-text-muted)]">MFA required for privileged roles</span>
          </div>
        </CardContent>
      </Card>
    </main>
  )
}
