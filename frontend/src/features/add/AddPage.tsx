import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useSearch } from '@tanstack/react-router'
import { formOptionsQuery } from '../../api/queries'
import { Icon } from '../../components/Icon'
import { Sheet } from '../../components/Sheet'
import { State } from '../../components/State'
import { useSession } from '../auth/useAuth'

/** Step one of adding: choose a coffee or a brew. Mirrors the old add sheet. */
export function AddPage() {
  const navigate = useNavigate()
  const { account, isPending, isAnonymous } = useSession()
  const close = () => void navigate({ to: '/my-coffees' })

  if (isPending) return <State title="Loading…" />
  if (isAnonymous || !account) {
    return (
      <State title="Sign in to add an entry.">
        <Link to="/login" search={{ redirect: '/add' }} className="cj-button cj-button--primary">Sign in</Link>
      </State>
    )
  }

  return (
    <Sheet title="What are you logging?" onClose={close}>
      <div className="cj-choice-list">
        <Link to="/beans/new" className="cj-choice" data-autofocus>
          <span className="cj-choice__art" aria-hidden="true"><Icon name="bean" /></span>
          <span className="cj-choice__text">
            <span className="cj-choice__title">A new coffee</span>
            <span className="cj-choice__sub">Log a bag you are drinking</span>
          </span>
          <Icon name="arrow" />
        </Link>
        <Link to="/brews/new" search={{ method: '', bean: '' }} className="cj-choice">
          <span className="cj-choice__art" aria-hidden="true"><Icon name="coffee" /></span>
          <span className="cj-choice__text">
            <span className="cj-choice__title">A brew recipe</span>
            <span className="cj-choice__sub">Record how you brewed it</span>
          </span>
          <Icon name="arrow" />
        </Link>
      </div>
    </Sheet>
  )
}

/** Step two for brews: pick a method, matching the old method picker grid. */
export function BrewMethodPicker() {
  const navigate = useNavigate()
  const { bean } = useSearch({ from: '/brews/new' })
  const options = useQuery(formOptionsQuery)

  if (options.isPending) return <State title="Loading brew methods…" />
  if (options.isError) return <State title={options.error.message} retry={() => void options.refetch()} />

  return (
    <div className="cj-method-picker">
      <div className="cj-view-heading">
        <p className="cj-eyebrow">NEW BREW</p>
        <h1>How did you brew it<span className="cj-heading-dot">?</span></h1>
        <p className="cj-page-description">Pick a method to start, or skip straight to the details.</p>
      </div>
      <div className="cj-method-grid">
        {options.data.data.brew_methods.map((method) => (
          <button
            key={method.value}
            type="button"
            className="cj-method-tile"
            onClick={() => void navigate({ to: '/brews/new', search: { method: method.value, bean } })}
          >
            <Icon name="coffee" />
            <span>{method.label}</span>
          </button>
        ))}
      </div>
      <button
        type="button"
        className="cj-button cj-button--quiet"
        onClick={() => void navigate({ to: '/brews/new', search: { method: 'other', bean } })}
      >
        Skip and fill in details
      </button>
    </div>
  )
}
