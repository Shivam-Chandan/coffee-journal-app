import { Link } from '@tanstack/react-router'
import type { BeanCard } from '../../api/schemas'
import { Icon } from '../../components/Icon'
import { ROAST_LABELS } from './labels'
import { ProvenanceBadge } from '../../components/ProvenanceBadge'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { beanQuery } from '../../api/queries'
import { useSession } from '../auth/useAuth'
import { OrderHistory } from './OrderHistory'

/** One coffee in the journal grid. */
export function BeanTile({ bean }: { bean: BeanCard }) {
  const [expanded, setExpanded] = useState(false)
  const { account } = useSession()
  const history = useQuery({ ...beanQuery(account?.id ?? 0, bean.id), enabled: expanded && Boolean(account) })
  return (
    <article className="cj-journal-tile">
      <Link to="/beans/$beanId" params={{ beanId: bean.id }} className="cj-journal-tile__link">
        <span className="cj-journal-tile__image" data-roast={bean.roast} aria-hidden="true">
          <Icon name="bean" />
          <span className="cj-bean-art-ring" />
        </span>
        <span className="cj-journal-tile__info">
          <span className="cj-journal-tile__name">{bean.title}</span>
          {bean.brand && <span className="cj-journal-tile__brand">{bean.brand}</span>}
          <span className="cj-journal-tile__meta">
            {bean.roast && <span className="cj-journal-tile__roast">{ROAST_LABELS[bean.roast] ?? bean.roast}</span>}
            <span className="cj-journal-tile__rating">
              <span className="sr-only">Rating: {bean.rating} out of 5</span>
              <Icon name="star" />
              <span aria-hidden="true">{bean.rating.toFixed(1)}</span>
            </span>
          </span>
          {bean.order_count !== undefined && <span className="cj-journal-tile__brand">Ordered {bean.order_count} {bean.order_count === 1 ? 'time' : 'times'}</span>}
          {bean.current_order && <span className="cj-journal-tile__brand">Current: {bean.current_order.quantity}{bean.current_order.quantity_unit}</span>}
          {bean.finished && <span className="cj-journal-tile__flag">No current bag</span>}
          <ProvenanceBadge provenance={bean.provenance} />
        </span>
      </Link>
      <details className="cj-card-orders" onToggle={(event) => setExpanded(event.currentTarget.open)}>
        <summary>Order history</summary>
        {expanded && (history.isPending ? <p role="status">Loading orders…</p> : history.isError ? <p role="alert">Could not load orders. <button type="button" className="cj-inline-button" onClick={() => void history.refetch()}>Retry</button></p> : <OrderHistory orders={history.data.data.orders ?? []} />)}
      </details>
    </article>
  )
}
