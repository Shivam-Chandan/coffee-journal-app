import type { ReactNode } from 'react'
import { Icon } from './Icon'

export function State({ title, children, retry }: { title: string; children?: ReactNode; retry?: () => void }) {
  return <div className="cj-wf-empty" role={retry ? 'alert' : 'status'}>
    <span className="cj-wf-empty__icon"><Icon name={retry ? 'alert' : 'coffee'} /></span>
    <p className="cj-wf-empty__title">{title}</p>
    {children && <div className="cj-wf-empty__sub">{children}</div>}
    {retry && <button className="cj-load-more-btn" onClick={retry}>Try again</button>}
  </div>
}
