import { useInfiniteQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { feedOptions } from '../../api/queries'
import { State } from '../../components/State'
import { useSession } from '../auth/useAuth'
import { BrewCardItem } from '../brews/BrewCardItem'

export function FeedPage() {
  const query = useInfiniteQuery(feedOptions)
  const { account } = useSession()

  if (query.isPending) return <State title="Loading shared brews…" />
  if (query.isError) return <State title={query.error.message} retry={() => void query.refetch()} />

  const brews = query.data.pages.flatMap((page) => page.data)

  return (
    <div>
      <div className="cj-view-heading">
        <p className="cj-eyebrow">FROM THE COMMUNITY</p>
        <h1>The daily brew<span className="cj-heading-dot">.</span></h1>
        <p className="cj-page-description">A little inspiration for your next cup.</p>
      </div>

      {brews.length === 0 ? (
        <State title="No brews shared yet.">
          <p>Be the first to share how you brew.</p>
          {account
            ? <Link to="/brews/new" search={{ method: '', bean: '' }} className="cj-button cj-button--primary">Share a brew</Link>
            : <Link to="/login" search={{ redirect: '/feed' }} className="cj-button cj-button--primary">Sign in to share</Link>}
        </State>
      ) : (
        <>
          <div className="cj-feed-stack">
            {brews.map((brew) => (
              <BrewCardItem key={brew.id} brew={brew} uid={account?.id ?? 0} canEngage={Boolean(account)} />
            ))}
          </div>
          {query.hasNextPage && (
            <button className="cj-load-more-btn" disabled={query.isFetchingNextPage} onClick={() => void query.fetchNextPage()}>
              {query.isFetchingNextPage ? 'Loading…' : 'Load more'}
            </button>
          )}
        </>
      )}
    </div>
  )
}
