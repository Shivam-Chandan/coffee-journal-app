import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { useState } from 'react'
import { ApiError } from '../../api/client'
import { beanQuery } from '../../api/queries'
import { useDeleteEntry } from '../../api/mutations'
import { FormError } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { Sheet } from '../../components/Sheet'
import { State } from '../../components/State'
import { BrewCardItem } from '../brews/BrewCardItem'
import { useSession } from '../auth/useAuth'
import { ROAST_LABELS } from './labels'
import { BeanForm } from './BeanForm'
import { ProvenanceBadge } from '../../components/ProvenanceBadge'

export function BeanDetailPage() {
  const { beanId } = useParams({ from: '/beans/$beanId' })
  const { account, isPending } = useSession()
  const uid = account?.id ?? 0
  const query = useQuery({ ...beanQuery(uid, beanId), enabled: !isPending })
  const remove = useDeleteEntry(uid, 'beans')
  const navigate = useNavigate()
  const [editing, setEditing] = useState(false)
  const [confirming, setConfirming] = useState(false)

  if (isPending || query.isPending) return <State title="Loading this coffee…" />
  if (query.isError) {
    const error = query.error as ApiError
    if (error.status === 404) {
      return (
        <State title="This coffee is unavailable.">
          It may have been deleted, or it belongs to someone else.
        </State>
      )
    }
    return <State title={error.message} retry={() => void query.refetch()} />
  }

  const bean = query.data.data
  const deleteError = remove.error as ApiError | null

  return (
    <article className="cj-detail">
      <Link to="/my-coffees" className="cj-back"><Icon name="back" />Journal</Link>

      <div className="cj-detail__art" data-roast={bean.roast} aria-hidden="true">
        <Icon name="bean" />
      </div>

      <header className="cj-detail__header">
        <div>
          {(bean.brand || bean.estate) && (
            <p className="cj-detail__eyebrow">{[bean.brand, bean.estate].filter(Boolean).join(' · ')}</p>
          )}
          <h1 className="cj-detail__title">{bean.title}</h1>
        </div>
        {bean.can_edit && (
          <div className="cj-detail__actions">
            <button type="button" className="cj-icon-button" onClick={() => setEditing(true)} aria-label={`Edit ${bean.title}`}>
              <Icon name="edit" />
            </button>
            {bean.can_delete && (
              <button type="button" className="cj-icon-button cj-icon-button--danger" onClick={() => setConfirming(true)} aria-label={`Delete ${bean.title}`}>
                <Icon name="trash" />
              </button>
            )}
          </div>
        )}
      </header>

      <div className="cj-chip-row">
        <ProvenanceBadge provenance={bean.provenance} detail />
        {bean.roast && <span className="cj-chip cj-chip--filled">{ROAST_LABELS[bean.roast] ?? bean.roast}</span>}
        {bean.form_factor && <span className="cj-chip">{bean.form_factor === 'ground' ? 'Ground' : 'Whole bean'}</span>}
        {bean.quantity !== null && <span className="cj-chip">{bean.quantity}{bean.quantity_unit}</span>}
        {bean.purchase_date && <span className="cj-chip">{formatDate(bean.purchase_date)}</span>}
        {bean.finished && <span className="cj-chip">Finished</span>}
        {bean.worth_reordering && <span className="cj-chip">Would reorder</span>}
      </div>

      <section className="cj-detail__section">
        <h2 className="cj-section-heading">Taste profile</h2>
        <dl className="cj-taste-grid">
          <TasteBar label="Overall" value={bean.taste.rating} max={5} />
          <TasteBar label="Bitterness" value={bean.taste.bitterness} max={10} />
          <TasteBar label="Acidity" value={bean.taste.acidity} max={10} />
          <TasteBar label="Note clarity" value={bean.taste.clarity} max={10} />
        </dl>
      </section>

      {bean.notes && (
        <section className="cj-detail__section">
          <h2 className="cj-section-heading">Tasting notes</h2>
          <p className="cj-detail__prose">{bean.notes}</p>
        </section>
      )}

      <section className="cj-detail__section">
        <div className="cj-section-head">
          <h2 className="cj-section-heading">Brews</h2>
          {bean.can_edit && (
            <Link to="/brews/new" search={{ bean: bean.id, method: '' }} className="cj-button cj-button--quiet">
              <Icon name="plus" />Add brew
            </Link>
          )}
        </div>
        {bean.brews?.length ? (
          <div className="cj-feed-stack">
            {bean.brews.map((brew) => <BrewCardItem key={brew.id} brew={brew} uid={uid} canEngage={Boolean(account)} />)}
          </div>
        ) : (
          <p className="cj-empty-inline">No brews logged with this coffee yet.</p>
        )}
      </section>

      {editing && (
        <Sheet title="Edit coffee" onClose={() => setEditing(false)}>
          <BeanForm uid={uid} bean={bean} onDone={() => setEditing(false)} />
        </Sheet>
      )}

      {confirming && (
        <Sheet
          title="Delete this coffee?"
          description="This cannot be undone. Brews using this coffee must be removed first."
          onClose={() => setConfirming(false)}
        >
          {deleteError && <FormError>{deleteError.message}</FormError>}
          <div className="cj-sheet__actions">
            <button
              type="button"
              className="cj-button cj-button--danger"
              disabled={remove.isPending}
              onClick={async () => {
                const result = await remove.mutateAsync(bean.id).catch(() => null)
                if (result) await navigate({ to: '/my-coffees' })
              }}
            >
              {remove.isPending ? 'Deleting…' : 'Delete coffee'}
            </button>
            <button type="button" className="cj-button cj-button--quiet" onClick={() => setConfirming(false)}>Keep it</button>
          </div>
        </Sheet>
      )}
    </article>
  )
}

function TasteBar({ label, value, max }: { label: string; value: number; max: number }) {
  const percent = Math.round((Math.min(value, max) / max) * 100)
  return (
    <div className="cj-taste">
      <dt className="cj-taste__label">{label}</dt>
      <dd className="cj-taste__value">
        <span className="cj-taste__track" aria-hidden="true"><span className="cj-taste__fill" style={{ width: `${percent}%` }} /></span>
        <span className="cj-taste__number">{value}<span>/{max}</span></span>
      </dd>
    </div>
  )
}

function formatDate(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}
