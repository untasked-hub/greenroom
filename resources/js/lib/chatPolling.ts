export function createChatPoller<T>(getUrl: () => string, apply: (data: T) => void) {
  let lastUrl: string | null = null
  let etag: string | null = null
  let inFlight = false

  return async () => {
    // Keep the validator and displayed state in sync across refreshes.
    if (inFlight) return
    inFlight = true

    try {
      const url = getUrl()
      const headers = new Headers({ Accept: 'application/json' })
      if (url === lastUrl && etag) headers.set('If-None-Match', etag)

      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers })
      if (res.status === 304 || !res.ok) return

      const data: T = await res.json()
      if (url !== getUrl()) return

      apply(data)
      lastUrl = url
      etag = res.headers.get('ETag')
    } catch {
      // Keep the current state and retry on the next poll.
    } finally {
      inFlight = false
    }
  }
}
