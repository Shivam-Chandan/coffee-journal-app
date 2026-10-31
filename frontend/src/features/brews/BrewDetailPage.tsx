import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { useState } from 'react'
import { ApiError } from '../../api/client'
import { brewQuery } from '../../api/queries'
import { useDeleteEntry, useEngagement, useSetVisibility } from '../../api/mutations'
import { FormError, TextField, ToggleField } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { Sheet } from '../../components/Sheet'
import { State } from '../../components/State'
import { relativeAge } from '../beans/labels'
import { useSession } from '../auth/useAuth'
import { BrewForm } from './BrewForm'

export function BrewDetailPage() {
  const { brewId } = useParams({ from: '/brews/$brewId' })
  const { account, isPending } = useSession()
  const uid = account?.id ?? 0
  const query = useQuery({ ...brewQuery(uid, brewId), enabled: !isPending })
  const remove = useDeleteEntry(uid, 'brews')
  const like = useEngagement(uid, 'like')
  const bookmark = useEngagement(uid, 'bookmark')
  const navigate = useNavigate()
  const [editing, setEditing] = useState(false)
  const [sharing, setSharing] = useState(false)
  const [confirming, setConfirming] = useState(false)

  if (isPending || query.isPending) return <State title="Loading this brew…" />
  if (query.isError) {
    const error = query.error as ApiError
    if (error.status === 404) {
      return <State title="This brew is unavailable.">It may be private or already deleted.</State>
    }
    return <State title={error.message} retry={() => void query.refetch()} />
  }

  const brew = query.data.data
  const deleteError = remove.error as ApiError | null

  return (
    <article className="cj-detail">
      <Link to="/feed" className="cj-back"><Icon name="back" />Feed</Link>

      <div className="cj-detail__art" aria-hidden="true">
        {brew.photo
          ? <img className="cj-detail__photo" src={brew.photo.url} alt="" width={brew.photo.width ?? undefined} height={brew.photo.height ?? undefined} />
          : <Icon name="coffee" />}
      </div>

      <header className="cj-detail__header">
        <div>
          <p className="cj-detail__eyebrow">
            {brew.author.is_me ? 'Your brew' : brew.author.name} · {relativeAge(brew.created)}
          </p>
          <h1 className="cj-detail__title">{brew.title}</h1>
        </div>
        {brew.can_edit && (
          <div className="cj-detail__actions">
            <button type="button" className="cj-icon-button" onClick={() => setEditing(true)} aria-label={`Edit ${brew.title}`}>
              <Icon name="edit" />
            </button>
            <button type="button" className="cj-icon-button" onClick={() => setSharing(true)} aria-label="Sharing options">
              <Icon name="share" />
            </button>
            {brew.can_delete && (
              <button type="button" className="cj-icon-button cj-icon-button--danger" onClick={() => setConfirming(true)} aria-label={`Delete ${brew.title}`}>
                <Icon name="trash" />
              </button>
            )}
          </div>
        )}
      </header>

      <div className="cj-chip-row">
        {brew.method && <span className="cj-chip cj-chip--filled">{brew.method}</span>}
        {brew.ratio && <span className="cj-chip">1:{brew.ratio}</span>}
        <span className="cj-chip">
          <Icon name={brew.is_public ? 'globe' : 'lock'} />
          {brew.is_public ? 'Shared' : 'Private'}
        </span>
      </div>

      {brew.caption && <p className="cj-detail__prose">{brew.caption}</p>}

      <section className="cj-detail__section">
        <h2 className="cj-section-heading">Recipe</h2>
        <dl className="cj-spec-grid">
          <Spec icon="scale" label="Coffee" value={brew.coffee_weight === null ? null : `${brew.coffee_weight} g`} />
          <Spec icon="droplet" label="Water" value={brew.water_weight === null ? null : `${brew.water_weight} g`} />
          <Spec icon="coffee" label="Temp" value={brew.water_temp === null ? null : `${brew.water_temp} °C`} />
          <Spec icon="timer" label="Time" value={brew.brew_time || null} />
          <Spec icon="bean" label="Grind" value={brew.grind || null} />
        </dl>
      </section>

      {brew.bean && (
        <section className="cj-detail__section">
          <h2 className="cj-section-heading">Coffee used</h2>
          <Link to="/beans/$beanId" params={{ beanId: brew.bean.id }} className="cj-link-row">
            <span className="cj-link-row__art" aria-hidden="true"><Icon name="bean" /></span>
            <span className="cj-link-row__text">{brew.bean.title}</span>
            <Icon name="arrow" />
          </Link>
        </section>
      )}

      {brew.notes && (
        <section className="cj-detail__section">
          <h2 className="cj-section-heading">Notes</h2>
          <p className="cj-detail__prose">{brew.notes}</p>
        </section>
      )}

      {account && (
        <div className="cj-detail__engage">
          <button
            type="button"
            className="cj-button cj-button--quiet"
            aria-pressed={brew.engagement.liked}
            onClick={() => like.mutate({ id: brew.id, desired: !brew.engagement.liked })}
          >
            <Icon name="heart" className={brew.engagement.liked ? 'is-filled' : undefined} />
            {brew.engagement.likes} {brew.engagement.likes === 1 ? 'like' : 'likes'}
          </button>
          <button
            type="button"
            className="cj-button cj-button--quiet"
            aria-pressed={brew.engagement.bookmarked}
            onClick={() => bookmark.mutate({ id: brew.id, desired: !brew.engagement.bookmarked })}
          >
            <Icon name="bookmark" className={brew.engagement.bookmarked ? 'is-filled' : undefined} />
            {brew.engagement.bookmarked ? 'Saved' : 'Save'}
          </button>
        </div>
      )}

      {editing && (
        <Sheet title="Edit brew" onClose={() => setEditing(false)}>
          <BrewForm uid={uid} brew={brew} onDone={() => setEditing(false)} />
        </Sheet>
      )}

      {sharing && <ShareSheet uid={uid} brew={brew} onClose={() => setSharing(false)} />}

      {confirming && (
        <Sheet title="Delete this brew?" description="This cannot be undone." onClose={() => setConfirming(false)}>
          {deleteError && <FormError>{deleteError.message}</FormError>}
          <div className="cj-sheet__actions">
            <button
              type="button"
              className="cj-button cj-button--danger"
              disabled={remove.isPending}
              onClick={async () => {
                const result = await remove.mutateAsync(brew.id).catch(() => null)
                if (result) await navigate({ to: '/my-coffees' })
              }}
            >
              {remove.isPending ? 'Deleting…' : 'Delete brew'}
            </button>
            <button type="button" className="cj-button cj-button--quiet" onClick={() => setConfirming(false)}>Keep it</button>
          </div>
        </Sheet>
      )}
    </article>
  )
}

function ShareSheet({
  uid,
  brew,
  onClose,
}: {
  uid: number
  brew: { id: string; is_public: boolean; caption: string; title: string }
  onClose: () => void
}) {
  const visibility = useSetVisibility(uid)
  const [isPublic, setIsPublic] = useState(brew.is_public)
  const [caption, setCaption] = useState(brew.caption)
  const error = visibility.error as ApiError | null

  return (
    <Sheet
      title="Share this brew"
      description="Shared brews appear in the community feed. Your coffee details stay private."
      onClose={onClose}
    >
      {error && <FormError>{error.message}</FormError>}
      <ToggleField
        label="Share to the feed"
        description={isPublic ? 'Visible to everyone.' : 'Only you can see this brew.'}
        value={isPublic}
        onChange={setIsPublic}
      />
      {isPublic && (
        <TextField label="Caption" value={caption} onChange={setCaption} multiline placeholder="What made this cup work?" />
      )}
      <p className="cj-field__hint">
        Making a brew private hides it here immediately. It cannot remove copies
        someone already downloaded or shared elsewhere.
      </p>
      <div className="cj-sheet__actions">
        <button
          type="button"
          className="cj-button cj-button--primary"
          disabled={visibility.isPending}
          onClick={async () => {
            const result = await visibility
              .mutateAsync({ id: brew.id, is_public: isPublic, caption })
              .catch(() => null)
            if (result) onClose()
          }}
        >
          {visibility.isPending ? 'Saving…' : 'Save sharing'}
        </button>
        <button type="button" className="cj-button cj-button--quiet" onClick={onClose}>Cancel</button>
      </div>
    </Sheet>
  )
}

function Spec({ icon, label, value }: { icon: 'scale' | 'droplet' | 'coffee' | 'timer' | 'bean'; label: string; value: string | null }) {
  if (!value) return null
  return (
    <div className="cj-spec">
      <dt className="cj-spec__label"><Icon name={icon} />{label}</dt>
      <dd className="cj-spec__value">{value}</dd>
    </div>
  )
}
