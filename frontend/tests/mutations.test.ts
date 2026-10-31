import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, mutate, newIdempotencyKey, resetCsrfToken } from '../src/api/client'
import { z } from 'zod'

const ok = z.object({ data: z.object({ id: z.string() }) })

afterEach(() => {
  vi.unstubAllGlobals()
  resetCsrfToken()
})

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('mutations', () => {
  it('sends the session CSRF token and an idempotency key on creates', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response('token-abc'))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 'x' } }))
    vi.stubGlobal('fetch', fetchMock)

    const key = newIdempotencyKey()
    await mutate('/api/v1/beans', ok, { method: 'POST', body: { title: 'A' }, idempotencyKey: key })

    const [, init] = fetchMock.mock.calls[1]
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('same-origin')
    expect(init.headers['X-CSRF-Token']).toBe('token-abc')
    expect(init.headers['Idempotency-Key']).toBe(key)
  })

  it('retries once with a fresh token when the old token is rejected', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response('stale-token'))
      .mockResolvedValueOnce(new Response('<html>denied</html>', { status: 403, headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response('fresh-token'))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 'x' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(mutate('/api/v1/beans', ok, { method: 'POST', body: {} })).resolves.toEqual({ data: { id: 'x' } })
    expect(fetchMock.mock.calls[3][1].headers['X-CSRF-Token']).toBe('fresh-token')
  })

  it('does not retry a genuine access denial', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response('token'))
      .mockResolvedValueOnce(jsonResponse({ error: { code: 'forbidden', message: 'You cannot edit this entry.' } }, 403))
    vi.stubGlobal('fetch', fetchMock)

    await expect(mutate('/api/v1/beans/x', ok, { method: 'PATCH', body: {} }))
      .rejects.toMatchObject({ status: 403, code: 'forbidden' })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('surfaces per-field validation messages', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response('token'))
      .mockResolvedValueOnce(jsonResponse({
        error: { code: 'validation_failed', message: 'Please review.', fields: { quantity: 'Enter a number.' } },
      }, 422)))

    const error = await mutate('/api/v1/beans', ok, { method: 'POST', body: {} }).catch((caught: ApiError) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).fields.quantity).toBe('Enter a number.')
  })

  it('reports a stale edit as a conflict rather than overwriting', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response('token'))
      .mockResolvedValueOnce(jsonResponse({ error: { code: 'conflict', message: 'This entry changed.' } }, 409)))

    await expect(mutate('/api/v1/beans/x', ok, { method: 'PATCH', body: { version: 1 } }))
      .rejects.toMatchObject({ status: 409, code: 'conflict' })
  })

  it('refuses paths outside the API namespace', async () => {
    await expect(mutate('//evil.example/api/v1/beans', ok, { method: 'POST' })).rejects.toThrow('same-origin')
  })
})
