import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { ApiError, mutate } from '../../api/client'
import { formOptionsQuery } from '../../api/queries'
import { useAddOrder, useSaveBean } from '../../api/mutations'
import { scrapeSchema, type BeanDetail, type FieldMeta } from '../../api/schemas'
import { FormError, RatingField, SelectField, TextField, ToggleField } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { State } from '../../components/State'
import { readDraft, removeDraft, writeDraft } from '../../lib/drafts'
import { CoffeePicker, type CoffeeChoice } from './CoffeePicker'

interface Values {
  title: string
  brand: string
  estate: string
  roast: string
  form_factor: string
  quantity: string
  quantity_unit: string
  purchase_date: string
  notes: string
  bitterness: number
  acidity: number
  clarity: number
  rating: number
  finished: boolean
  worth_reordering: boolean
}

const blank: Values = {
  title: '', brand: '', estate: '', roast: '', form_factor: 'whole_bean',
  quantity: '250', quantity_unit: 'g', purchase_date: new Date().toISOString().slice(0, 10),
  notes: '', bitterness: 5, acidity: 5, clarity: 5, rating: 4,
  finished: false, worth_reordering: false,
}

function fromDetail(bean: BeanDetail): Values {
  return {
    title: bean.title,
    brand: bean.brand,
    estate: bean.estate,
    roast: bean.roast,
    form_factor: bean.form_factor,
    quantity: bean.quantity === null ? '' : String(bean.quantity),
    quantity_unit: bean.quantity_unit,
    purchase_date: bean.purchase_date,
    notes: bean.notes,
    bitterness: bean.taste.bitterness,
    acidity: bean.taste.acidity,
    clarity: bean.taste.clarity,
    rating: bean.taste.rating,
    finished: bean.finished,
    worth_reordering: bean.worth_reordering,
  }
}

export function BeanForm({ uid, bean, orderFor, onDone }: { uid: number; bean?: BeanDetail; orderFor?: CoffeeChoice; onDone: (id: string) => void }) {
  const options = useQuery(formOptionsQuery)
  const save = useSaveBean(uid, bean)
  const addOrder = useAddOrder(uid)
  const [selected, setSelected] = useState<CoffeeChoice | undefined>(orderFor)
  const navigate = useNavigate()
  const draftName = bean ? `bean:${bean.id}` : orderFor ? `order:${orderFor.id}` : 'bean:new'
  const [values, setValues] = useState<Values>(() => {
    const draft = readDraft<Values>(uid, draftName)
    return draft?.values ?? (bean ? fromDetail(bean) : blank)
  })
  const [draftAt, setDraftAt] = useState<number | null>(() => readDraft<Values>(uid, draftName)?.savedAt ?? null)
  const [draftFailed, setDraftFailed] = useState(false)
  const [url, setUrl] = useState('')
  const [autofill, setAutofill] = useState<{ state: 'idle' | 'loading' | 'error' | 'done'; message?: string }>({ state: 'idle' })
  const firstRender = useRef(true)

  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    const timer = setTimeout(() => {
      const stored = writeDraft(uid, draftName, values)
      setDraftFailed(!stored)
      if (stored) setDraftAt(Date.now())
    }, 600)
    return () => clearTimeout(timer)
  }, [values, uid, draftName])

  if (options.isPending) return <State title="Loading the form…" />
  if (options.isError) return <State title={options.error.message} retry={() => void options.refetch()} />

  const meta = options.data.data.coffee_bean
  const field = (key: string): FieldMeta | undefined => meta[key]
  const error = (selected ? addOrder.error : save.error) as ApiError | null
  const saving = save.isPending || addOrder.isPending
  const fields = error?.fields ?? {}
  const conflict = error?.code === 'conflict'
  const generalError = error && !Object.keys(fields).length ? error.message : null
  const set = <K extends keyof Values>(key: K, value: Values[K]) => setValues((current) => ({ ...current, [key]: value }))

  async function runAutofill() {
    if (!url.trim()) return
    setAutofill({ state: 'loading' })
    try {
      const scraped = await mutate('/api/scrape-coffee', scrapeSchema, { method: 'POST', body: { url: url.trim() } })
      setValues((current) => ({
        ...current,
        title: scraped.name?.trim() || current.title,
        brand: scraped.brand?.trim() || current.brand,
        estate: scraped.estate?.trim() || current.estate,
        roast: matchOption(field('roast')?.options, scraped.roastLevel) || current.roast,
        form_factor: matchOption(field('form_factor')?.options, scraped.formFactor) || current.form_factor,
        notes: (scraped.tastingNotes ?? scraped.flavourNotes)?.trim() || current.notes,
      }))
      setAutofill({ state: 'done', message: 'Details filled in. Review before saving.' })
    } catch (caught) {
      const apiError = caught as ApiError
      setAutofill({ state: 'error', message: apiError.message || 'Could not read that page.' })
    }
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    const payload = {
      ...values,
      quantity: values.quantity === '' ? null : Number(values.quantity),
      purchase_date: values.purchase_date || null,
    }
    const result = selected
      ? await addOrder.mutateAsync({ beanId: selected.id, values: {
          quantity: payload.quantity, quantity_unit: values.quantity_unit,
          purchase_date: values.purchase_date, form_factor: values.form_factor, finished: values.finished,
        } }).catch(() => null)
      : await save.mutateAsync(bean ? {
          title: values.title, brand: values.brand, estate: values.estate, roast: values.roast,
          notes: values.notes, rating: values.rating, bitterness: values.bitterness,
          acidity: values.acidity, clarity: values.clarity, worth_reordering: values.worth_reordering,
        } : payload).catch(() => null)
    if (result) {
      removeDraft(uid, draftName)
      onDone(result.data.id)
    }
  }

  return (
    <form className="cj-form" onSubmit={submit} noValidate>
      {generalError && <FormError>{generalError}</FormError>}
      {conflict && (
        <FormError>
          {error?.message}{' '}
          <button type="button" className="cj-inline-button" onClick={() => void navigate({ to: '/my-coffees' })}>
            Reload your journal
          </button>
        </FormError>
      )}
      {draftFailed && (
        <p className="cj-field__hint" role="status">
          This device is not keeping a local copy, so finish before closing.
        </p>
      )}
      {!draftFailed && draftAt && !bean && (
        <p className="cj-field__hint" role="status">
          Kept on this device at {new Date(draftAt).toLocaleTimeString()}. Not saved to your journal yet.
        </p>
      )}

      {!bean && !selected && (
        <div className="cj-autofill">
          <span className="cj-autofill__label"><Icon name="link" />Fill from a product page</span>
          <div className="cj-autofill__row">
            <input
              className="cj-input"
              type="url"
              inputMode="url"
              placeholder="https://roaster.example/coffee"
              aria-label="Product page URL"
              value={url}
              onChange={(event) => setUrl(event.target.value)}
            />
            <button
              type="button"
              className="cj-button cj-button--quiet"
              onClick={() => void runAutofill()}
              disabled={autofill.state === 'loading' || !url.trim()}
            >
              {autofill.state === 'loading' ? 'Reading…' : 'Autofill'}
            </button>
          </div>
          {autofill.message && (
            <p className={autofill.state === 'error' ? 'cj-field__error' : 'cj-field__hint'} role="status">
              {autofill.message}
            </p>
          )}
        </div>
      )}

      {selected && (
        <div className="cj-autofill">
          <strong>{selected.title}</strong>
          <p className="cj-field__hint">Add another order. The coffee’s rating and notes stay the same. A new current order completes the previous bag; older dates are added to history.</p>
          {!orderFor && <button type="button" className="cj-inline-button" onClick={() => { setSelected(undefined); addOrder.reset() }}>Choose a different coffee</button>}
        </div>
      )}
      {!bean && !selected && <CoffeePicker uid={uid} value={values.title} onChange={(value) => set('title', value)} onSelect={(coffee) => { setSelected(coffee); save.reset() }} error={fields.title} />}
      {!selected && <>
      {bean && <TextField label="Coffee name" value={values.title} onChange={(value) => set('title', value)} required error={fields.title} />}
      <TextField label={field('brand')?.label ?? 'Brand / Roaster'} value={values.brand} onChange={(value) => set('brand', value)} placeholder="e.g. Blue Tokai" error={fields.brand} />
      <TextField label={field('estate')?.label ?? 'Estate'} value={values.estate} onChange={(value) => set('estate', value)} error={fields.estate} />
      <SelectField label={field('roast')?.label ?? 'Roast level'} value={values.roast} onChange={(value) => set('roast', value)} options={field('roast')?.options ?? []} error={fields.roast} />
      </>}
      {!bean && <>
      <SelectField label={field('form_factor')?.label ?? 'Form factor'} value={values.form_factor} onChange={(value) => set('form_factor', value)} options={field('form_factor')?.options ?? []} required={field('form_factor')?.required} error={fields.form_factor} />

      <div className="cj-field-row">
        <TextField label={field('quantity')?.label ?? 'Quantity'} value={values.quantity} onChange={(value) => set('quantity', value)} inputMode="decimal" required={field('quantity')?.required} error={fields.quantity} />
        <SelectField label="Unit" value={values.quantity_unit} onChange={(value) => set('quantity_unit', value)} options={field('quantity_unit')?.options ?? []} required={field('quantity_unit')?.required} error={fields.quantity_unit} />
      </div>

      <TextField label={field('purchase_date')?.label ?? 'Order date'} type="date" value={values.purchase_date} onChange={(value) => set('purchase_date', value)} required={field('purchase_date')?.required} error={fields.purchase_date} />
      <ToggleField label="Bag completed" value={values.finished} onChange={(value) => set('finished', value)} />
      </>}
      {!selected && <>
      <TextField label={field('notes')?.label ?? 'Tasting notes'} value={values.notes} onChange={(value) => set('notes', value)} multiline required={field('notes')?.required} error={fields.notes} />

      <fieldset className="cj-fieldset">
        <legend className="cj-fieldset__legend">Taste profile</legend>
        <RatingField label={field('rating')?.label ?? 'Overall'} value={values.rating} onChange={(value) => set('rating', value)} min={field('rating')?.min ?? 1} max={field('rating')?.max ?? 5} error={fields.rating} />
        <RatingField label={field('bitterness')?.label ?? 'Bitterness'} value={values.bitterness} onChange={(value) => set('bitterness', value)} min={field('bitterness')?.min ?? 1} max={field('bitterness')?.max ?? 10} error={fields.bitterness} />
        <RatingField label={field('acidity')?.label ?? 'Acidity'} value={values.acidity} onChange={(value) => set('acidity', value)} min={field('acidity')?.min ?? 1} max={field('acidity')?.max ?? 10} error={fields.acidity} />
        <RatingField label={field('clarity')?.label ?? 'Note clarity'} value={values.clarity} onChange={(value) => set('clarity', value)} min={field('clarity')?.min ?? 1} max={field('clarity')?.max ?? 10} error={fields.clarity} />
      </fieldset>

      <ToggleField label="Worth reordering" value={values.worth_reordering} onChange={(value) => set('worth_reordering', value)} />
      </>}

      <button type="submit" className="cj-button cj-button--primary" disabled={saving}>
        {saving ? 'Saving…' : selected ? 'Add order' : bean ? 'Save coffee details' : 'Add to journal'}
      </button>
    </form>
  )
}

/** Maps a scraped free-text value onto one of Drupal's allowed options. */
function matchOption(options: { value: string; label: string }[] | undefined, candidate?: string): string {
  if (!options || !candidate) return ''
  const needle = candidate.trim().toLowerCase().replace(/[\s-]+/g, '_')
  return options.find((option) =>
    option.value.toLowerCase() === needle || option.label.toLowerCase().replace(/[\s-]+/g, '_') === needle,
  )?.value ?? ''
}
