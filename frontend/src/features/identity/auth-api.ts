type ApiErrorBody = {
  error_code?: string
  message?: string
  details?: Record<string, unknown>
}

export type AuthSession = {
  user: {
    id: string
    name: string
    email: string
    status: string
    mfa_required: boolean
    mfa_enabled: boolean
  }
  active_entity: null | {
    id: string
    legal_name: string
    functional_currency: string
  }
  roles: string[]
  permissions: string[]
}

export type LoginResult =
  | {
      mfa_required: true
      mfa_challenge_id: string
    }
  | {
      token_type: 'Bearer'
      token: string
      session: AuthSession
    }

export class ApiRequestError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errorCode?: string,
  ) {
    super(message)
  }
}

async function requestJson<T>(path: string, options: RequestInit): Promise<T> {
  const response = await fetch(path, {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...options.headers,
    },
    ...options,
  })
  const data = (await response.json().catch(() => ({}))) as ApiErrorBody | T

  if (!response.ok) {
    const body = data as ApiErrorBody
    throw new ApiRequestError(body.message ?? 'Request failed', response.status, body.error_code)
  }

  return data as T
}

export function login(email: string, password: string) {
  return requestJson<LoginResult>('/v1/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export function verifyMfa(mfaChallengeId: string, code: string) {
  return requestJson<Extract<LoginResult, { token: string }>>('/v1/auth/mfa', {
    method: 'POST',
    body: JSON.stringify({ mfa_challenge_id: mfaChallengeId, code }),
  })
}

export function requestPasswordReset(email: string) {
  return requestJson<{ status: string }>('/v1/auth/password/forgot', {
    method: 'POST',
    body: JSON.stringify({ email }),
  })
}

export function resetPassword(email: string, token: string, password: string) {
  return requestJson<{ status: string }>('/v1/auth/password/reset', {
    method: 'POST',
    body: JSON.stringify({ email, token, password }),
  })
}
