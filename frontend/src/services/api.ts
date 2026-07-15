export async function healthCheck() {
  const response = await fetch('/v1/health')

  if (!response.ok) {
    throw new Error('Health check failed')
  }

  return response.json() as Promise<{ status: string; service: string }>
}
