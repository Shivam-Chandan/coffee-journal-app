import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getJson, mutate } from '../../api/client'
import { privateKey } from '../../api/queries'
import { assistantConnectionsResponse, type AssistantConnections, type AssistantGrant } from '../../api/schemas'

const scopeLabels: Record<string, string> = { 'beans:read': 'Read coffees', 'beans:create': 'Create coffees' }
const providerLabels: Record<AssistantGrant['provider'], string> = { chatgpt: 'ChatGPT', claude: 'Claude' }

/** Status comes from server grants, never from opening an assistant's page. */
export function AssistantConnectionControls({ uid, initial }: { uid: number; initial: AssistantConnections }) {
  const cache = useQueryClient()
  const key = privateKey(uid, 'assistant-connections')
  const status = useQuery({
    queryKey: key,
    queryFn: ({ signal }) => getJson('/api/v1/me/connections', assistantConnectionsResponse, signal),
    initialData: { data: initial },
    initialDataUpdatedAt: 0,
    staleTime: 0,
    refetchOnWindowFocus: 'always',
    retry: false,
  })
  const disconnect = useMutation({
    mutationFn: (provider: AssistantGrant['provider']) =>
      mutate(`/api/v1/me/connections/${provider}`, assistantConnectionsResponse, { method: 'DELETE' }),
    onSuccess: async (result) => {
      cache.setQueryData(key, result)
      await cache.invalidateQueries({ queryKey: privateKey(uid, 'profile') })
    },
  })

  if (status.isError) {
    return (
      <p role="status">
        Connection status is unavailable.{' '}
        <button type="button" onClick={() => void status.refetch()}>Try again</button>
      </p>
    )
  }

  return (
    <ul className="cj-assistant-list">
      {status.data.data.providers.map((grant) => (
        <li key={grant.provider} className="cj-assistant">
          <p role="status">
            <strong>{providerLabels[grant.provider]}</strong>{' — '}
            {grant.status === 'authorized'
              ? 'authorized to read and add coffees. This does not show whether it is still installed there.'
              : grant.status === 'reconnect_required'
                ? 'reconnect from its connector settings to authorize again.'
                : 'not connected. Start the connection from its connector settings, then approve access here.'}
          </p>
          {grant.status === 'authorized' && (
            <>
              <p>Permissions: {grant.scopes?.map((scope) => scopeLabels[scope] ?? scope).join(', ')}</p>
              {grant.authorized_at && <p>Authorized {new Date(grant.authorized_at * 1000).toLocaleDateString()}</p>}
              <button
                type="button"
                className="cj-button cj-button--quiet"
                disabled={disconnect.isPending}
                onClick={() => disconnect.mutate(grant.provider)}
              >
                {disconnect.isPending && disconnect.variables === grant.provider
                  ? 'Disconnecting…'
                  : `Disconnect ${providerLabels[grant.provider]}`}
              </button>
            </>
          )}
        </li>
      ))}
      {disconnect.isError && <li className="cj-field__error" role="alert">Could not disconnect. Please try again.</li>}
    </ul>
  )
}
