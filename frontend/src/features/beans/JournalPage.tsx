import { useInfiniteQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { journalOptions } from '../../api/queries'
import { Icon } from '../../components/Icon'
import { State } from '../../components/State'
import { useSession } from '../auth/useAuth'
import { BeanTile } from './BeanTile'

export function JournalPage() {
  const { account, isPending, isError, error, refetch } = useSession()
  if (isPending) return <State title="Loading your journal…" />
  if (isError) return <State title={(error as Error).message} retry={() => void refetch()} />
  if (!account) {
    return (
      <State title="Sign in to see your journal.">
        <p>Your coffees stay private to your account.</p>
        <Link to="/login" search={{ redirect: '/my-coffees' }} className="cj-button cj-button--primary">Sign in</Link>
      </State>
    )
  }
  return <Journal uid={account.id} />
}

function Journal({ uid }: { uid: number }) {
  const query = useInfiniteQuery(journalOptions(uid))
  if (query.isPending) return <State title="Loading your coffees…" />
  if (query.isError) return <State title={query.error.message} retry={() => void query.refetch()} />

  const beans = query.data.pages.flatMap((page) => page.data)

  return (
    <div>
      <div className="cj-view-heading">
        <p className="cj-eyebrow">YOUR DAILY RITUAL</p>
        <h1>My journal<span className="cj-heading-dot">.</span></h1>
        <p className="cj-page-description">Good coffee. Worth remembering.</p>
      </div>

      {beans.length === 0 ? (
        <State title="No coffees yet.">
          <p>Log the first bag you are drinking to start your journal.</p>
          <Link to="/beans/new" className="cj-button cj-button--primary"><Icon name="plus" />Log a coffee</Link>
        </State>
      ) : (
        <>
          <div className="cj-controls-row">
            <span className="cj-section-label">Your collection</span>
            <span className="cj-count">
              {beans.length}{query.hasNextPage ? ' loaded' : ''} {beans.length === 1 ? 'coffee' : 'coffees'}
            </span>
          </div>
          <div className="cj-journal-grid">
            {beans.map((bean) => <BeanTile key={bean.id} bean={bean} />)}
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
