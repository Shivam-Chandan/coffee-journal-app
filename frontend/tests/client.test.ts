import { afterEach, expect, it, vi } from 'vitest'
import { getJson } from '../src/api/client'
import { sessionSchema } from '../src/api/schemas'

afterEach(() => vi.unstubAllGlobals())

it('rejects HTML fallback rather than treating a broken API route as anonymous', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<!doctype html>', { headers: { 'Content-Type': 'text/html' } })))
  await expect(getJson('/api/v1/session', sessionSchema)).rejects.toThrow('unexpected response')
})

it('does not turn server failures into signed-out state', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 503 })))
  await expect(getJson('/api/v1/session', sessionSchema)).rejects.toMatchObject({ status: 503 })
})

it('validates the authenticated account contract', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ data: { authenticated: true, user: null } })))
  await expect(getJson('/api/v1/session', sessionSchema)).rejects.toThrow('does not match')
})

it('passes cancellation and same-origin credentials without persisting API responses', async () => {
  const request = vi.fn().mockResolvedValue(Response.json({ data: { authenticated: false, user: null } }))
  vi.stubGlobal('fetch', request)
  const signal = new AbortController().signal
  await expect(getJson('/api/v1/session', sessionSchema, signal)).resolves.toEqual({ data: { authenticated: false, user: null } })
  expect(request).toHaveBeenCalledWith('/api/v1/session', expect.objectContaining({ signal, credentials: 'same-origin', cache: 'no-store' }))
})
