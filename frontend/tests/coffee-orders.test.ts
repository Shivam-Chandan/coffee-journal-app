import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { BeanForm } from '../src/features/beans/BeanForm'
import { formOptionsQuery } from '../src/api/queries'
import { resetCsrfToken } from '../src/api/client'

vi.mock('@tanstack/react-router', () => ({ useNavigate: () => vi.fn() }))

let root: Root
let element: HTMLDivElement
let cache: QueryClient
const id = '00000000-0000-4000-8000-000000000001'

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  localStorage.clear()
  resetCsrfToken()
  element = document.createElement('div')
  document.body.append(element)
  root = createRoot(element)
  cache = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  cache.setQueryData(formOptionsQuery.queryKey, { data: { coffee_bean: {}, brew_recipe: {}, brew_methods: [] } })
})
afterEach(async () => {
  await act(async () => root.unmount())
  cache.clear()
  element.remove()
  localStorage.clear()
  vi.unstubAllGlobals()
})

it('selects an existing coffee with the keyboard and submits only purchase details', async () => {
  const done = vi.fn()
  const fetcher = vi.fn(async (path: string, options?: RequestInit) => {
    if (path === '/session/token') return new Response('csrf-test')
    if (options?.method === 'POST') return Response.json({ data: {
      id, nid: 1, title: 'Mullayangiri', brand: 'Toffee Coffee Roaster', roast: 'medium_dark', rating: 4,
      finished: false, version: 1, can_edit: true, can_delete: true, estate: '', form_factor: 'whole_bean',
      quantity: 250, quantity_unit: 'g', purchase_date: '2026-11-01', notes: '', worth_reordering: false,
      taste: { rating: 4, bitterness: 5, acidity: 5, clarity: 5 }, engagement: { likes: 0, liked: false, bookmarked: false },
    } })
    return Response.json({ data: [{ id, title: 'Mullayangiri', brand: 'Toffee Coffee Roaster', roast: 'medium_dark' }] })
  })
  vi.stubGlobal('fetch', fetcher)
  await act(async () => root.render(createElement(QueryClientProvider, { client: cache }, createElement(BeanForm, { uid: 42, onDone: done }))))
  await vi.waitFor(async () => {
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
    expect(element.querySelector('[role="option"]')).not.toBeNull()
  })
  const input = element.querySelector<HTMLInputElement>('[role="combobox"]')!
  await act(async () => input.dispatchEvent(new FocusEvent('focusin', { bubbles: true })))
  await act(async () => input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true })))
  await act(async () => input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })))
  expect(element.textContent).toContain('The coffee’s rating and notes stay the same')
  expect(element.querySelector('[type="range"]')).toBeNull()
  expect(element.querySelector('[role="combobox"]')).toBeNull()
  await act(async () => element.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
  await vi.waitFor(async () => {
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
    expect(done).toHaveBeenCalledWith(id)
  })
  const write = fetcher.mock.calls.find(([, options]) => options?.method === 'POST')!
  expect(write[0]).toBe(`/api/v1/beans/${id}/orders`)
  expect(Object.keys(JSON.parse(write[1]!.body as string)).sort()).toEqual(['finished', 'form_factor', 'purchase_date', 'quantity', 'quantity_unit'])
  expect(write[1]!.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-test' })
})
