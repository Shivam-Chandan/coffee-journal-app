import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ChatgptConnectionControls } from '../src/features/profile/ChatgptConnectionControls'
import { resetCsrfToken } from '../src/api/client'

const authorized = { enabled: true, status: 'authorized' as const, scopes: ['beans:read', 'beans:create'], authorized_at: 1793491200 }
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

async function render() {
  await act(async () => {
    root.render(createElement(QueryClientProvider, { client: cache }, createElement(ChatgptConnectionControls, { uid: 42, initial: authorized })))
  })
}

async function settle(assertion: () => void) {
  await vi.waitFor(async () => {
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
    assertion()
  })
}

describe('ChatGPT authorization controls', () => {
  it('uses the server state rather than an initial authorized snapshot', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ data: { enabled: true, status: 'not_connected' } })))
    await render()
    await settle(() => expect(element.textContent).toContain('choose Connect'))
    expect(element.textContent).not.toContain('ChatGPT is authorized')
    expect(element.querySelector('button')).toBeNull()
  })

  it('does not report a failed status request as disconnected', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ error: { code: 'unavailable', message: 'Unavailable' } }, { status: 503 })))
    await render()
    await settle(() => expect(element.textContent).toContain('Connection status is unavailable'))
    expect(element.textContent).not.toContain('choose Connect')
    expect(element.textContent).not.toContain('ChatGPT is authorized')
  })

  it('disconnects through a CSRF-protected mutation and updates from its result', async () => {
    const fetcher = vi.fn(async (path: string, options?: RequestInit) => {
      if (path === '/session/token') return new Response('csrf-fixture')
      if (options?.method === 'DELETE') return Response.json({ data: { enabled: true, status: 'reconnect_required' } })
      return Response.json({ data: authorized })
    })
    vi.stubGlobal('fetch', fetcher)
    await render()
    await settle(() => expect(element.textContent).toContain('Disconnect ChatGPT'))
    await act(async () => element.querySelector('button')!.click())
    await settle(() => expect(element.textContent).toContain('Reconnect in ChatGPT'))
    const deletion = fetcher.mock.calls.find(([, options]) => options?.method === 'DELETE')
    expect(deletion?.[1]?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-fixture' })
    expect(element.textContent).not.toContain('ChatGPT is authorized')
  })
})
