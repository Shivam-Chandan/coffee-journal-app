import { Link, useNavigate, useSearch } from '@tanstack/react-router'
import { State } from '../../components/State'
import { useSession } from '../auth/useAuth'
import { BeanForm } from '../beans/BeanForm'
import { BrewForm } from '../brews/BrewForm'
import { BrewMethodPicker } from './AddPage'

function RequireAccount({ redirect, children }: { redirect: string; children: (uid: number) => React.ReactNode }) {
  const { account, isPending, isError, error, refetch } = useSession()
  if (isPending) return <State title="Loading…" />
  if (isError) return <State title={(error as Error).message} retry={() => void refetch()} />
  if (!account) {
    return (
      <State title="Sign in to continue.">
        <Link to="/login" search={{ redirect }} className="cj-button cj-button--primary">Sign in</Link>
      </State>
    )
  }
  return <>{children(account.id)}</>
}

export function NewBeanPage() {
  const navigate = useNavigate()
  return (
    <RequireAccount redirect="/beans/new">
      {(uid) => (
        <div className="cj-form-page">
          <div className="cj-view-heading">
            <p className="cj-eyebrow">NEW COFFEE</p>
            <h1>Log a coffee<span className="cj-heading-dot">.</span></h1>
          </div>
          <BeanForm uid={uid} onDone={(id) => void navigate({ to: '/beans/$beanId', params: { beanId: id } })} />
        </div>
      )}
    </RequireAccount>
  )
}

export function NewBrewPage() {
  const navigate = useNavigate()
  const { method, bean } = useSearch({ from: '/brews/new' })

  // Without a chosen method, show the picker first, exactly like the old flow.
  if (!method) return <BrewMethodPicker />

  return (
    <RequireAccount redirect="/brews/new">
      {(uid) => (
        <div className="cj-form-page">
          <div className="cj-view-heading">
            <p className="cj-eyebrow">NEW BREW</p>
            <h1>{method === 'other' ? 'Log a brew' : method}<span className="cj-heading-dot">.</span></h1>
          </div>
          <BrewForm
            uid={uid}
            initialMethod={method === 'other' ? '' : method}
            initialBean={bean}
            onDone={(id) => void navigate({ to: '/brews/$brewId', params: { brewId: id } })}
          />
        </div>
      )}
    </RequireAccount>
  )
}
