import { useEffect, useId, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { beanChoicesQuery } from '../../api/queries'
import { ROAST_LABELS } from './labels'

export interface CoffeeChoice { id: string; title: string; brand?: string; roast?: string }

/** An editable coffee name with owner-scoped, keyboard-accessible suggestions. */
export function CoffeePicker({ uid, value, onChange, onSelect, error }: {
  uid: number; value: string; onChange: (value: string) => void
  onSelect: (coffee: CoffeeChoice) => void; error?: string
}) {
  const id = useId()
  const [search, setSearch] = useState(value)
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  useEffect(() => {
    const timer = setTimeout(() => setSearch(value), 200)
    return () => clearTimeout(timer)
  }, [value])
  const query = useQuery(beanChoicesQuery(uid, search))
  const choices = query.data?.data.slice(0, 10) ?? []
  const expanded = open && choices.length > 0

  return (
    <div className="cj-field cj-coffee-picker">
      <label className="cj-field__label" htmlFor={id}>Coffee name</label>
      <input id={id} className="cj-input" role="combobox" aria-autocomplete="list"
        aria-expanded={expanded} aria-controls={`${id}-choices`}
        aria-activedescendant={expanded && active >= 0 && active < choices.length ? `${id}-${active}` : undefined}
        aria-describedby={`${id}-hint`} aria-invalid={Boolean(error)} autoComplete="off"
        value={value} placeholder="Search your coffees or enter a new name" required
        onFocus={() => setOpen(true)} onBlur={() => setOpen(false)}
        onChange={(event) => { onChange(event.target.value); setActive(-1); setOpen(true) }}
        onKeyDown={(event) => {
          if (event.key === 'Escape') { setOpen(false); return }
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()
            setOpen(true)
            setActive((index) => Math.max(0, Math.min(choices.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1))))
          }
          if (event.key === 'Enter' && expanded && active >= 0 && choices[active]) {
            event.preventDefault()
            onSelect(choices[active]); setOpen(false)
          }
        }} />
      <ul id={`${id}-choices`} role="listbox" className="cj-coffee-picker__choices" hidden={!expanded}>
        {choices.map((choice, index) => (
          <li key={choice.id} id={`${id}-${index}`} role="option" aria-selected={index === active}
            className="cj-coffee-picker__choice" onMouseDown={(event) => event.preventDefault()}
            onClick={() => { onSelect(choice); setOpen(false) }}>
            <strong>{choice.title}</strong>
            <span>{[choice.brand, ROAST_LABELS[choice.roast ?? ''] ?? choice.roast].filter(Boolean).join(' · ')}</span>
          </li>
        ))}
      </ul>
      <p id={`${id}-hint`} className="cj-field__hint" role="status">
        {error || (query.isError ? 'Coffee search is unavailable. Retry before adding a repeat purchase.' : query.isFetching ? 'Searching your coffees…' : 'Select a match to add an order, or enter a new coffee name.')}
      </p>
    </div>
  )
}
