import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { getJson, mutate } from '../../api/client'
import { privateKey } from '../../api/queries'
import { assistantConnectionsResponse, type AssistantConnections, type AssistantGrant } from '../../api/schemas'
import { Icon } from '../../components/Icon'

const scopeLabels: Record<string, string> = { 'beans:read': 'Read coffees', 'beans:create': 'Create coffees' }
const providerLabels: Record<AssistantGrant['provider'], string> = { chatgpt: 'ChatGPT', claude: 'Claude' }

export interface AssistantLaunchUrls {
  chatgpt?: string
  claude?: string
}

/** Status comes from server grants, never from opening an assistant's page. */
export function AssistantConnectionControls({ uid, initial, launchUrls }: {
  uid: number
  initial: AssistantConnections
  launchUrls: AssistantLaunchUrls
}) {
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

  const { providers, mcp_url: mcpUrl } = status.data.data
  return (
    <ul className="cj-assistant-list">
      {providers.map((grant) => (
        <li key={grant.provider} className="cj-assistant">
          <p role="status">
            <strong>{providerLabels[grant.provider]}</strong>{' — '}
            {grant.status === 'authorized'
              ? 'authorized to read and add coffees. This does not show whether it is still installed there.'
              : grant.status === 'reconnect_required'
                ? 'reconnect from its connector settings to authorize again.'
                : 'not connected. Start the connection in the assistant, then approve access here.'}
          </p>
          {grant.status === 'authorized' ? (
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
          ) : (
            launchUrls[grant.provider] && (
              <a
                href={launchUrls[grant.provider]}
                target="_blank"
                rel="noopener noreferrer"
                className="cj-button cj-button--primary"
              >
                <Icon name="link" />Connect {providerLabels[grant.provider]}
                <span className="sr-only"> (opens in a new tab)</span>
              </a>
            )
          )}
          {grant.provider === 'claude' && grant.status !== 'authorized' && mcpUrl && (
            <CopyableUrl url={mcpUrl} />
          )}
        </li>
      ))}
      {disconnect.isError && <li className="cj-field__error" role="alert">Could not disconnect. Please try again.</li>}
    </ul>
  )
}

/** Claude's dialog asks for the server URL, so make it easy to paste. */
function CopyableUrl({ url }: { url: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <div className="cj-assistant__url">
      <p>Add this server URL in Claude, choose Sign in now, then approve access:</p>
      <code>{url}</code>
      <button
        type="button"
        className="cj-button cj-button--quiet"
        onClick={async () => {
          try {
            await navigator.clipboard.writeText(url)
            setCopied(true)
          }
          catch {
            setCopied(false)
          }
        }}
      >
        {copied ? 'Copied' : 'Copy URL'}
      </button>
    </div>
  )
}
