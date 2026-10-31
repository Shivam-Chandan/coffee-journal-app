import type { BeanCard } from '../api/schemas'
import { Icon } from './Icon'

/** Creation origin remains visible after later human edits. */
export function ProvenanceBadge({ provenance, detail = false }: {
  provenance: BeanCard['provenance']
  detail?: boolean
}) {
  if (provenance?.channel !== 'ai_connector') return null
  const provider = ({ chatgpt: 'ChatGPT', claude: 'Claude', gemini: 'Gemini' } as Record<string, string>)[provenance.provider ?? '']
  const label = provider ? `Added via ${provider}` : 'Added via AI'
  const date = new Date(provenance.created_at * 1000)
  return (
    <span className="cj-chip cj-provenance" title={label}>
      <Icon name="sparkles" width={14} height={14} />
      {detail ? label : provider ? `AI · ${provider}` : 'Added via AI'}
      {detail && <> · <time dateTime={date.toISOString()}>{date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}</time></>}
    </span>
  )
}
