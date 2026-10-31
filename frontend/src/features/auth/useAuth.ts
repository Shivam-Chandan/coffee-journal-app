import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRouter } from '@tanstack/react-router'
import { z } from 'zod'
import { mutate, resetCsrfToken } from '../../api/client'
import { sessionOptions } from '../../api/queries'
import { messageResponse, sessionSchema } from '../../api/schemas'
import { clearDrafts } from '../../lib/drafts'

const logoutSchema = z.object({ data: z.object({ authenticated: z.literal(false) }) })

/** Reads the current session; never treats a failed request as signed out. */
export function useSession() {
  const query = useQuery(sessionOptions)
  const state = query.data?.data
  return {
    ...query,
    account: state?.authenticated ? state.user : null,
    isAnonymous: state?.authenticated === false,
  }
}

export function useLogin() {
  const queryClient = useQueryClient()
  const router = useRouter()
  return useMutation({
    mutationFn: (input: { identifier: string; password: string }) =>
      mutate('/api/v1/auth/login', sessionSchema, { method: 'POST', body: input }),
    onSuccess: async (result) => {
      // The session rotated, so the old CSRF token and any cached private data
      // from a previous account must be discarded before rendering.
      resetCsrfToken()
      await queryClient.cancelQueries({ queryKey: ['private'] })
      queryClient.removeQueries({ queryKey: ['private'] })
      queryClient.setQueryData(sessionOptions.queryKey, result)
      await queryClient.invalidateQueries({ queryKey: ['feed'] })
      await router.invalidate()
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  const router = useRouter()
  return useMutation({
    mutationFn: () => mutate('/api/v1/auth/logout', logoutSchema, { method: 'POST' }),
    onSuccess: async () => {
      resetCsrfToken()
      await queryClient.cancelQueries({ queryKey: ['private'] })
      queryClient.removeQueries({ queryKey: ['private'] })
      await clearDrafts()
      queryClient.setQueryData(sessionOptions.queryKey, { data: { authenticated: false, user: null } })
      await queryClient.invalidateQueries({ queryKey: ['feed'] })
      await router.invalidate()
    },
  })
}

export function usePasswordReset() {
  return useMutation({
    mutationFn: (input: { identifier: string }) =>
      mutate('/api/v1/auth/password-reset', messageResponse, { method: 'POST', body: input }),
  })
}
