import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { ApiError } from '../../api/client'
import { beanChoicesQuery, formOptionsQuery } from '../../api/queries'
import { useSaveBrew } from '../../api/mutations'
import type { BrewDetail, FieldMeta } from '../../api/schemas'
import { FormError, SelectField, TextField, ToggleField } from '../../components/Field'
import { State } from '../../components/State'
import { readDraft, removeDraft, writeDraft } from '../../lib/drafts'

interface Values {
  title: string
  method: string
  bean: string
  coffee_weight: string
  water_weight: string
  water_temp: string
  brew_time: string
  grind: string
  notes: string
  is_public: boolean
  caption: string
}

function blank(method: string, bean: string): Values {
  return {
    title: method ? `${method} brew` : '', method, bean,
    coffee_weight: '15', water_weight: '250', water_temp: '94',
    brew_time: '', grind: '', notes: '', is_public: false, caption: '',
  }
}

function fromDetail(brew: BrewDetail): Values {
  return {
    title: brew.title,
    method: brew.method,
    bean: brew.bean?.id ?? '',
    coffee_weight: brew.coffee_weight === null ? '' : String(brew.coffee_weight),
    water_weight: brew.water_weight === null ? '' : String(brew.water_weight),
    water_temp: brew.water_temp === null ? '' : String(brew.water_temp),
    brew_time: brew.brew_time,
    grind: brew.grind,
    notes: brew.notes,
    is_public: brew.is_public,
    caption: brew.caption,
  }
}

export function BrewForm({
  uid,
  brew,
  initialMethod = '',
  initialBean = '',
  onDone,
}: {
  uid: number
  brew?: BrewDetail
  initialMethod?: string
  initialBean?: string
  onDone: (id: string) => void
}) {
  const options = useQuery(formOptionsQuery)
  const beans = useQuery(beanChoicesQuery(uid))
  const save = useSaveBrew(uid, brew)
  const navigate = useNavigate()
  const draftName = brew ? `brew:${brew.id}` : `brew:new:${initialMethod || 'any'}`
  const [values, setValues] = useState<Values>(() => {
    const draft = readDraft<Values>(uid, draftName)
    return draft?.values ?? (brew ? fromDetail(brew) : blank(initialMethod, initialBean))
  })
  const [draftAt, setDraftAt] = useState<number | null>(() => readDraft<Values>(uid, draftName)?.savedAt ?? null)
  const [draftFailed, setDraftFailed] = useState(false)
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

  if (options.isPending || beans.isPending) return <State title="Loading the form…" />
  if (options.isError) return <State title={options.error.message} retry={() => void options.refetch()} />
  if (beans.isError) return <State title={beans.error.message} retry={() => void beans.refetch()} />

  const meta = options.data.data.brew_recipe
  const field = (key: string): FieldMeta | undefined => meta[key]
  const error = save.error as ApiError | null
  const fields = error?.fields ?? {}
  const conflict = error?.code === 'conflict'
  const generalError = error && !Object.keys(fields).length ? error.message : null
  const set = <K extends keyof Values>(key: K, value: Values[K]) => setValues((current) => ({ ...current, [key]: value }))

  const coffee = Number(values.coffee_weight)
  const water = Number(values.water_weight)
  const ratio = coffee > 0 && water > 0 ? (water / coffee).toFixed(1) : null

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    const payload = {
      title: values.title,
      method: values.method,
      bean: values.bean || null,
      coffee_weight: values.coffee_weight === '' ? null : Number(values.coffee_weight),
      water_weight: values.water_weight === '' ? null : Number(values.water_weight),
      water_temp: values.water_temp === '' ? null : Number(values.water_temp),
      brew_time: values.brew_time,
      grind: values.grind,
      notes: values.notes,
      is_public: values.is_public,
      caption: values.caption,
    }
    const result = await save.mutateAsync(payload).catch(() => null)
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
        <p className="cj-field__hint" role="status">This device is not keeping a local copy, so finish before closing.</p>
      )}
      {!draftFailed && draftAt && !brew && (
        <p className="cj-field__hint" role="status">
          Kept on this device at {new Date(draftAt).toLocaleTimeString()}. Not saved to your journal yet.
        </p>
      )}

      <TextField label="Brew name" value={values.title} onChange={(value) => set('title', value)} placeholder="e.g. Sunday V60" required error={fields.title} />
      <SelectField label={field('method')?.label ?? 'Brew method'} value={values.method} onChange={(value) => set('method', value)} options={options.data.data.brew_methods} error={fields.method} />
      <SelectField
        label="Coffee used"
        value={values.bean}
        onChange={(value) => set('bean', value)}
        options={beans.data.data.map((item) => ({ value: item.id, label: item.brand ? `${item.title} · ${item.brand}` : item.title }))}
        placeholder={beans.data.data.length ? 'Choose a coffee…' : 'Add a coffee first'}
        hint={beans.data.data.length ? undefined : 'Your journal has no coffees yet, so this can stay empty.'}
        error={fields.bean}
      />

      <div className="cj-field-row">
        <TextField label="Coffee (g)" value={values.coffee_weight} onChange={(value) => set('coffee_weight', value)} inputMode="decimal" error={fields.coffee_weight} />
        <TextField label="Water (g)" value={values.water_weight} onChange={(value) => set('water_weight', value)} inputMode="decimal" error={fields.water_weight} />
      </div>
      {ratio && <p className="cj-ratio-preview">Ratio 1:{ratio}</p>}

      <div className="cj-field-row">
        <TextField
          label="Water temp (°C)"
          value={values.water_temp}
          onChange={(value) => set('water_temp', value)}
          inputMode="decimal"
          hint={field('water_temp')?.min !== undefined ? `${field('water_temp')?.min}–${field('water_temp')?.max}` : undefined}
          error={fields.water_temp}
        />
        <TextField label="Brew time" value={values.brew_time} onChange={(value) => set('brew_time', value)} placeholder="2:45" error={fields.brew_time} />
      </div>

      <TextField label={field('grind')?.label ?? 'Grind size'} value={values.grind} onChange={(value) => set('grind', value)} placeholder="Medium-fine" error={fields.grind} />
      <TextField label={field('notes')?.label ?? 'Notes'} value={values.notes} onChange={(value) => set('notes', value)} multiline error={fields.notes} />

      <ToggleField
        label="Share to the community feed"
        description="Public brews are visible to everyone. Your coffee details stay private."
        value={values.is_public}
        onChange={(value) => set('is_public', value)}
      />
      {values.is_public && (
        <TextField label="Caption" value={values.caption} onChange={(value) => set('caption', value)} multiline placeholder="What made this cup work?" error={fields.caption} />
      )}

      <button type="submit" className="cj-button cj-button--primary" disabled={save.isPending}>
        {save.isPending ? 'Saving…' : brew ? 'Save changes' : 'Save brew'}
      </button>
    </form>
  )
}
