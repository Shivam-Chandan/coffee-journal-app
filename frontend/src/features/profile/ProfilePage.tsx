import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useSearch } from '@tanstack/react-router'
import { myBrewsOptions, profileQuery, savedQuery } from '../../api/queries'
import { Icon } from '../../components/Icon'
import { State } from '../../components/State'
import { BeanTile } from '../beans/BeanTile'
import { BrewCardItem } from '../brews/BrewCardItem'
import { useLogout, useSession } from '../auth/useAuth'

const TABS = [
  { id: 'posts', label: 'Brews' },
  { id: 'saved', label: 'Saved' },
] as const

export function ProfilePage() {
  const { tab } = useSearch({ from: '/user/profile' })
  const navigate = useNavigate()
  const { account, isPending, isError, error, refetch } = useSession()
  const logout = useLogout()

  if (isPending) return <State title="Loading your profile…" />
  if (isError) return <State title={(error as Error).message} retry={() => void refetch()} />
  if (!account) {
    return (
      <State title="Sign in to see your profile.">
        <Link to="/login" search={{ redirect: '/user/profile' }} className="cj-button cj-button--primary">Sign in</Link>
      </State>
    )
  }

  return (
    <div>
      <ProfileHeader uid={account.id} name={account.name} email={account.email} />

      <div className="cj-tabs" role="tablist" aria-label="Profile sections">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`panel-${item.id}`}
            className="cj-tab"
            onClick={() => void navigate({ to: '/user/profile', search: { tab: item.id } })}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        {tab === 'saved' ? <SavedTab uid={account.id} /> : <BrewsTab uid={account.id} />}
      </div>

      <div className="cj-profile__footer">
        <button
          type="button"
          className="cj-button cj-button--quiet"
          disabled={logout.isPending}
          onClick={() => logout.mutate()}
        >
          <Icon name="logout" />{logout.isPending ? 'Signing out…' : 'Sign out'}
        </button>
        {logout.isError && <p className="cj-field__error" role="alert">Could not sign out. Please try again.</p>}
      </div>
    </div>
  )
}

function ProfileHeader({ uid, name, email }: { uid: number; name: string; email: string }) {
  const query = useQuery(profileQuery(uid))
  const stats = query.data?.data.stats
  return (
    <header className="cj-profile">
      <span className="cj-profile__avatar" aria-hidden="true">{name.slice(0, 1).toUpperCase()}</span>
      <h1 className="cj-profile__name">{name}</h1>
      <p className="cj-profile__meta">{email}</p>
      <dl className="cj-stat-row">
        <Stat label="Coffees" value={stats?.beans} />
        <Stat label="Brews" value={stats?.brews} />
        <Stat label="Likes" value={stats?.likes} />
      </dl>
    </header>
  )
}

function Stat({ label, value }: { label: string; value?: number }) {
  return (
    <div className="cj-stat">
      <dt className="cj-stat__label">{label}</dt>
      <dd className="cj-stat__value">{value ?? '—'}</dd>
    </div>
  )
}

function BrewsTab({ uid }: { uid: number }) {
  const query = useInfiniteQuery(myBrewsOptions(uid))
  if (query.isPending) return <State title="Loading your brews…" />
  if (query.isError) return <State title={query.error.message} retry={() => void query.refetch()} />
  const brews = query.data.pages.flatMap((page) => page.data)
  if (!brews.length) {
    return (
      <State title="No brews yet.">
        <Link to="/brews/new" search={{ method: '', bean: '' }} className="cj-button cj-button--primary">Log a brew</Link>
      </State>
    )
  }
  return (
    <>
      <div className="cj-feed-stack">
        {brews.map((brew) => <BrewCardItem key={brew.id} brew={brew} uid={uid} canEngage />)}
      </div>
      {query.hasNextPage && (
        <button className="cj-load-more-btn" disabled={query.isFetchingNextPage} onClick={() => void query.fetchNextPage()}>
          {query.isFetchingNextPage ? 'Loading…' : 'Load more'}
        </button>
      )}
    </>
  )
}

function SavedTab({ uid }: { uid: number }) {
  const query = useQuery(savedQuery(uid))
  if (query.isPending) return <State title="Loading saved items…" />
  if (query.isError) return <State title={query.error.message} retry={() => void query.refetch()} />
  const items = query.data.data
  if (!items.length) {
    return <State title="Nothing saved yet.">Tap the bookmark on any brew to keep it here.</State>
  }
  const beans = items.filter((item) => item.kind === 'bean')
  const brews = items.filter((item) => item.kind === 'brew')
  return (
    <>
      {brews.length > 0 && (
        <div className="cj-feed-stack">
          {brews.map((brew) => <BrewCardItem key={brew.id} brew={brew} uid={uid} canEngage />)}
        </div>
      )}
      {beans.length > 0 && (
        <div className="cj-journal-grid cj-journal-grid--spaced">
          {beans.map((bean) => <BeanTile key={bean.id} bean={bean} />)}
        </div>
      )}
    </>
  )
}
