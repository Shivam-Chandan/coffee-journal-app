import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getJson, mutate } from '../../api/client'
import { privateKey } from '../../api/queries'
import { chatgptConnectionResponse, type ChatgptConnection } from '../../api/schemas'

const scopeLabels: Record<string, string> = { 'beans:read': 'Read coffees', 'beans:create': 'Create coffees' }

/** Status is a server grant, never a flag set by opening the plugin URL. */
export function ChatgptConnectionControls({ uid, initial }: { uid: number; initial: ChatgptConnection }) {
  const cache = useQueryClient()
  const key = privateKey(uid, 'chatgpt-connection')
  const status = useQuery({
    queryKey: key,
    queryFn: ({ signal }) => getJson('/api/v1/me/connections/chatgpt', chatgptConnectionResponse, signal),
    initialData: { data: initial },
    initialDataUpdatedAt: 0,
    staleTime: 0,
    refetchOnWindowFocus: 'always',
    retry: false,
  })
  const disconnect = useMutation({
    mutationFn: () => mutate('/api/v1/me/connections/chatgpt', chatgptConnectionResponse, { method: 'DELETE' }),
    onSuccess: async (result) => {
      cache.setQueryData(key, result)
      await cache.invalidateQueries({ queryKey: privateKey(uid, 'profile') })
    },
  })
  if (status.isError) {
    return <p role="status">Connection status is unavailable. <button type="button" onClick={() => void status.refetch()}>Try again</button></p>
  }
  const grant = status.data.data
  return (
    <div>
      <p role="status">
        {grant.status === 'authorized'
          ? 'ChatGPT is authorized to access your journal. This does not indicate whether the plugin is currently installed.'
          : grant.status === 'reconnect_required'
            ? 'Reconnect in ChatGPT to authorize journal access again.'
            : 'Open the ChatGPT plugin and choose Connect. Sign in to Drupal and approve the requested permissions.'}
      </p>
      {grant.status === 'authorized' && (
        <>
          <p>Permissions: {grant.scopes?.map((scope) => scopeLabels[scope] ?? scope).join(', ')}</p>
          {grant.authorized_at && <p>Authorized {new Date(grant.authorized_at * 1000).toLocaleDateString()}</p>}
          <button type="button" className="cj-button cj-button--quiet" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>
            {disconnect.isPending ? 'Disconnecting…' : 'Disconnect ChatGPT'}
          </button>
        </>
      )}
      {disconnect.isError && <p className="cj-field__error" role="alert">Could not disconnect ChatGPT. Please try again.</p>}
    </div>
  )
}
