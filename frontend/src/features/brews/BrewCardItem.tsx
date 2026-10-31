import { Link } from '@tanstack/react-router'
import { useEngagement } from '../../api/mutations'
import type { BrewCard } from '../../api/schemas'
import { Icon } from '../../components/Icon'
import { relativeAge } from '../beans/labels'

/** One brew in the feed or a brew list, with optimistic engagement actions. */
export function BrewCardItem({ brew, uid, canEngage }: { brew: BrewCard; uid: number; canEngage: boolean }) {
  const like = useEngagement(uid, 'like')
  const bookmark = useEngagement(uid, 'bookmark')

  return (
    <article className="cj-feed-card">
      <div className="cj-feed-card__header">
        <span className="cj-avatar" aria-hidden="true">{brew.author.initial}</span>
        <span className="cj-feed-card__byline">
          <span className="cj-feed-card__author">{brew.author.is_me ? 'You' : brew.author.name}</span>
          <span className="cj-feed-card__time">{relativeAge(brew.created)}</span>
        </span>
        {!brew.is_public && <span className="cj-chip"><Icon name="lock" />Private</span>}
      </div>

      <Link to="/brews/$brewId" params={{ brewId: brew.id }} className="cj-feed-card__media" aria-label={brew.title}>
        {brew.photo ? (
          <img
            className="cj-feed-card__photo"
            src={brew.photo.url}
            width={brew.photo.width ?? undefined}
            height={brew.photo.height ?? undefined}
            alt=""
            loading="lazy"
          />
        ) : (
          <span className="cj-feed-card__hero" aria-hidden="true"><Icon name="coffee" /></span>
        )}
      </Link>

      <div className="cj-feed-card__body">
        <h3 className="cj-feed-card__title">
          <Link to="/brews/$brewId" params={{ brewId: brew.id }}>{brew.title}</Link>
        </h3>
        {brew.caption && <p className="cj-feed-card__caption">{brew.caption}</p>}

        <div className="cj-feed-card__footer">
          <div className="cj-feed-card__chips">
            {brew.method && <span className="cj-chip cj-chip--filled">{brew.method}</span>}
            {brew.ratio && <span className="cj-chip">1:{brew.ratio}</span>}
          </div>
          {canEngage && (
            <div className="cj-engage">
              <button
                type="button"
                className="cj-engage__button"
                aria-pressed={brew.engagement.liked}
                onClick={() => like.mutate({ id: brew.id, desired: !brew.engagement.liked })}
              >
                <Icon name="heart" className={brew.engagement.liked ? 'is-filled' : undefined} />
                <span>{brew.engagement.likes}</span>
                <span className="sr-only">{brew.engagement.liked ? 'Remove like' : 'Like this brew'}</span>
              </button>
              <button
                type="button"
                className="cj-engage__button"
                aria-pressed={brew.engagement.bookmarked}
                onClick={() => bookmark.mutate({ id: brew.id, desired: !brew.engagement.bookmarked })}
              >
                <Icon name="bookmark" className={brew.engagement.bookmarked ? 'is-filled' : undefined} />
                <span className="sr-only">{brew.engagement.bookmarked ? 'Remove from saved' : 'Save this brew'}</span>
              </button>
            </div>
          )}
        </div>
      </div>
    </article>
  )
}
