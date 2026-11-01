import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AssistantConnectionControls } from '../src/features/profile/AssistantConnectionControls'
import { resetCsrfToken } from '../src/api/client'

const bothAuthorized = {
  enabled: true,
  providers: [
    { provider: 'chatgpt' as const, status: 'authorized' as const, scopes: ['beans:read', 'beans:create'], authorized_at: 1793491200 },
    { provider: 'claude' as const, status: 'authorized' as const, scopes: ['beans:read'], authorized_at: 1793491200 },
  ],
}
let root: Root
let element: HTMLDivElement
let cache: QueryClient

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  element = document.createElement('div')
  document.body.append(element)
  root = createRoot(element)
  cache = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  resetCsrfToken()
})
afterEach(async () => {
  await act(async () => root.unmount())
  cache.clear()
  element.remove()
  vi.unstubAllGlobals()
})

async function render(initial = bothAuthorized) {
  await act(async () => {
    root.render(createElement(QueryClientProvider, { client: cache }, createElement(AssistantConnectionControls, { uid: 42, initial })))
  })
}

async function settle(assertion: () => void) {
  await vi.waitFor(async () => {
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
    assertion()
  })
}

describe('assistant authorization controls', () => {
  it('uses the server state rather than an initial authorized snapshot', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({
      data: { enabled: true, providers: [{ provider: 'claude', status: 'not_connected' }] },
    })))
    await render()
    await settle(() => expect(element.textContent).toContain('not connected'))
    expect(element.textContent).not.toContain('authorized to read and add coffees')
    expect(element.querySelector('button')).toBeNull()
  })

  it('does not report a failed status request as disconnected', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ error: { code: 'unavailable', message: 'Unavailable' } }, { status: 503 })))
    await render()
    await settle(() => expect(element.textContent).toContain('Connection status is unavailable'))
    expect(element.textContent).not.toContain('not connected')
    expect(element.textContent).not.toContain('authorized to read and add coffees')
  })

  it('disconnects only the chosen assistant through a CSRF-protected mutation', async () => {
    const fetcher = vi.fn(async (path: string, options?: RequestInit) => {
      if (path === '/session/token') return new Response('csrf-fixture')
      if (options?.method === 'DELETE') {
        return Response.json({
          data: {
            enabled: true,
            providers: [
              { provider: 'chatgpt', status: 'authorized', scopes: ['beans:read', 'beans:create'], authorized_at: 1793491200 },
              { provider: 'claude', status: 'reconnect_required' },
            ],
          },
        })
      }
      return Response.json({ data: bothAuthorized })
    })
    vi.stubGlobal('fetch', fetcher)
    await render()
    await settle(() => expect(element.textContent).toContain('Disconnect Claude'))
    const claudeButton = [...element.querySelectorAll('button')].find((button) => button.textContent === 'Disconnect Claude')!
    await act(async () => claudeButton.click())
    await settle(() => expect(element.textContent).toContain('reconnect from its connector settings'))
    const deletion = fetcher.mock.calls.find(([, options]) => options?.method === 'DELETE')
    expect(deletion?.[0]).toBe('/api/v1/me/connections/claude')
    expect(deletion?.[1]?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-fixture' })
    // ChatGPT must stay connected when only Claude is revoked.
    expect(element.textContent).toContain('Disconnect ChatGPT')
  })
})
