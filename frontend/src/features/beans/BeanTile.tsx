import { Link } from '@tanstack/react-router'
import type { BeanCard } from '../../api/schemas'
import { Icon } from '../../components/Icon'
import { ROAST_LABELS } from './labels'

/** One coffee in the journal grid. */
export function BeanTile({ bean }: { bean: BeanCard }) {
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
          {bean.finished && <span className="cj-journal-tile__flag">Finished</span>}
        </span>
      </Link>
    </article>
  )
}
