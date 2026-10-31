import { infiniteQueryOptions, queryOptions } from '@tanstack/react-query'
import { getJson } from './client'
import {
  beanChoicesSchema,
  beanDetailResponse,
  beansSchema,
  brewDetailResponse,
  feedSchema,
  formOptionsSchema,
  profileSchema,
  savedSchema,
  sessionSchema,
} from './schemas'

/** Private query keys start with 'private' so logout can drop them wholesale. */
export const privateKey = (uid: number, ...rest: unknown[]) => ['private', uid, ...rest] as const

export const sessionOptions = queryOptions({
  queryKey: ['session'],
  queryFn: ({ signal }) => getJson('/api/v1/session', sessionSchema, signal),
  staleTime: 0,
  retry: false,
})

export const formOptionsQuery = queryOptions({
  queryKey: ['form-options'],
  queryFn: ({ signal }) => getJson('/api/v1/form-options', formOptionsSchema, signal),
  staleTime: 5 * 60_000,
})

export const feedOptions = infiniteQueryOptions({
  queryKey: ['feed'],
  initialPageParam: null as number | null,
  queryFn: ({ signal, pageParam }) =>
    getJson(`/api/v1/feed${pageParam ? `?before=${pageParam}` : ''}`, feedSchema, signal),
  getNextPageParam: (page) => page.meta.next ?? undefined,
})

export const journalOptions = (uid: number) => infiniteQueryOptions({
  queryKey: privateKey(uid, 'beans'),
  initialPageParam: null as number | null,
  queryFn: ({ signal, pageParam }) =>
    getJson(`/api/v1/beans${pageParam ? `?before=${pageParam}` : ''}`, beansSchema, signal),
  getNextPageParam: (page) => page.meta.next ?? undefined,
  retry: false,
})

export const myBrewsOptions = (uid: number) => infiniteQueryOptions({
  queryKey: privateKey(uid, 'brews'),
  initialPageParam: null as number | null,
  queryFn: ({ signal, pageParam }) =>
    getJson(`/api/v1/me/brews${pageParam ? `?before=${pageParam}` : ''}`, feedSchema, signal),
  getNextPageParam: (page) => page.meta.next ?? undefined,
  retry: false,
})

export const beanQuery = (uid: number, id: string) => queryOptions({
  queryKey: privateKey(uid, 'bean', id),
  queryFn: ({ signal }) => getJson(`/api/v1/beans/${id}`, beanDetailResponse, signal),
  retry: false,
})

export const brewQuery = (uid: number, id: string) => queryOptions({
  queryKey: privateKey(uid, 'brew', id),
  queryFn: ({ signal }) => getJson(`/api/v1/brews/${id}`, brewDetailResponse, signal),
  retry: false,
})

export const beanChoicesQuery = (uid: number) => queryOptions({
  queryKey: privateKey(uid, 'bean-choices'),
  queryFn: ({ signal }) => getJson('/api/v1/bean-choices', beanChoicesSchema, signal),
  retry: false,
})

export const profileQuery = (uid: number) => queryOptions({
  queryKey: privateKey(uid, 'profile'),
  queryFn: ({ signal }) => getJson('/api/v1/me/profile', profileSchema, signal),
  retry: false,
})

export const savedQuery = (uid: number) => queryOptions({
  queryKey: privateKey(uid, 'saved'),
  queryFn: ({ signal }) => getJson('/api/v1/me/saved', savedSchema, signal),
  retry: false,
})
