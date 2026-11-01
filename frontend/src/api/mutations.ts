import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { mutate, newIdempotencyKey } from '../api/client'
import { privateKey } from '../api/queries'
import {
  beanDetailResponse,
  brewDetailResponse,
  deletedResponse,
  engagementResponse,
  type BeanDetail,
  type BrewDetail,
} from '../api/schemas'

/** Invalidates every list and detail view a write could have changed. */
function useRefresh(uid: number) {
  const queryClient = useQueryClient()
  return async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: privateKey(uid) }),
      queryClient.invalidateQueries({ queryKey: ['feed'] }),
    ])
  }
}

export function useSaveBean(uid: number, existing?: BeanDetail) {
  const refresh = useRefresh(uid)
  // One key per mounted form, so a retried submit cannot create duplicates.
  const [idempotencyKey] = useState(newIdempotencyKey)
  return useMutation({
    mutationFn: (values: Record<string, unknown>) =>
      existing
        ? mutate(`/api/v1/beans/${existing.id}`, beanDetailResponse, {
            method: 'PATCH',
            body: { ...values, version: existing.version },
          })
        : mutate('/api/v1/beans', beanDetailResponse, { method: 'POST', body: values, idempotencyKey }),
    onSuccess: refresh,
  })
}

export function useSaveBrew(uid: number, existing?: BrewDetail) {
  const refresh = useRefresh(uid)
  const [idempotencyKey] = useState(newIdempotencyKey)
  return useMutation({
    mutationFn: (values: Record<string, unknown>) =>
      existing
        ? mutate(`/api/v1/brews/${existing.id}`, brewDetailResponse, {
            method: 'PATCH',
            body: { ...values, version: existing.version },
          })
        : mutate('/api/v1/brews', brewDetailResponse, { method: 'POST', body: values, idempotencyKey }),
    onSuccess: refresh,
  })
}

export function useAddOrder(uid: number) {
  const refresh = useRefresh(uid)
  const [idempotencyKey] = useState(newIdempotencyKey)
  return useMutation({
    mutationFn: ({ beanId, values }: { beanId: string; values: Record<string, unknown> }) =>
      mutate(`/api/v1/beans/${beanId}/orders`, beanDetailResponse, { method: 'POST', body: values, idempotencyKey }),
    onSuccess: refresh,
  })
}

export function useCompleteOrder(uid: number) {
  const refresh = useRefresh(uid)
  const [submissionKey] = useState(newIdempotencyKey)
  return useMutation({
    mutationFn: (id: string) => mutate(`/api/v1/orders/${id}/complete`, beanDetailResponse, {
      method: 'POST', body: {}, idempotencyKey: `${id}-${submissionKey.slice(0, 20)}`,
    }),
    onSuccess: refresh,
  })
}

export function useDeleteEntry(uid: number, kind: 'beans' | 'brews') {
  const refresh = useRefresh(uid)
  return useMutation({
    mutationFn: (id: string) =>
      mutate(`/api/v1/${kind}/${id}`, deletedResponse, { method: 'DELETE' }),
    onSuccess: refresh,
  })
}

export function useSetVisibility(uid: number) {
  const refresh = useRefresh(uid)
  return useMutation({
    mutationFn: (input: { id: string; is_public: boolean; caption?: string }) =>
      mutate(`/api/v1/brews/${input.id}/visibility`, brewDetailResponse, {
        method: 'PUT',
        body: { is_public: input.is_public, ...(input.caption === undefined ? {} : { caption: input.caption }) },
      }),
    onSuccess: refresh,
  })
}

/**
 * Applies a desired engagement state optimistically.
 *
 * PUT/DELETE express the target state, so a retry is safe and a failure rolls
 * back to the server's last known value.
 */
export function useEngagement(uid: number, kind: 'like' | 'bookmark') {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { id: string; desired: boolean }) =>
      mutate(`/api/v1/content/${input.id}/${kind}`, engagementResponse, {
        method: input.desired ? 'PUT' : 'DELETE',
      }),
    onMutate: async (input) => {
      await queryClient.cancelQueries({ queryKey: ['feed'] })
      const snapshot = queryClient.getQueriesData({ queryKey: ['feed'] })
      const patch = (item: { id: string; engagement: { likes: number; liked: boolean; bookmarked: boolean } }) => {
        if (item.id !== input.id) return item
        const engagement = { ...item.engagement }
        if (kind === 'like') {
          if (engagement.liked !== input.desired) {
            engagement.likes = Math.max(0, engagement.likes + (input.desired ? 1 : -1))
          }
          engagement.liked = input.desired
        } else {
          engagement.bookmarked = input.desired
        }
        return { ...item, engagement }
      }
      queryClient.setQueriesData({ queryKey: ['feed'] }, (current: unknown) => {
        if (!current || typeof current !== 'object' || !('pages' in current)) return current
        const data = current as { pages: { data: unknown[] }[] }
        return { ...data, pages: data.pages.map((page) => ({ ...page, data: page.data.map((item) => patch(item as never)) })) }
      })
      return { snapshot }
    },
    onError: (_error, _input, context) => {
      context?.snapshot.forEach(([key, value]) => queryClient.setQueryData(key, value))
    },
    onSettled: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['feed'] }),
        queryClient.invalidateQueries({ queryKey: privateKey(uid) }),
      ])
    },
  })
}
