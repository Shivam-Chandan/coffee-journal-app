import { useId, type ReactNode } from 'react'

interface BaseProps {
  label: string
  error?: string
  hint?: string
  required?: boolean
}

function Shell({ label, error, hint, required, id, children }: BaseProps & { id: string; children: ReactNode }) {
  const describedBy = [hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ')
  return (
    <div className="cj-field" data-invalid={error ? 'true' : undefined}>
      <label className="cj-field__label" htmlFor={id}>
        {label}
        {required && <span className="cj-field__required" aria-hidden="true">*</span>}
      </label>
      {hint && <p className="cj-field__hint" id={`${id}-hint`}>{hint}</p>}
      <div data-described-by={describedBy || undefined}>{children}</div>
      {error && <p className="cj-field__error" id={`${id}-error`} role="alert">{error}</p>}
    </div>
  )
}

export function TextField({
  value,
  onChange,
  type = 'text',
  placeholder,
  autoComplete,
  multiline,
  inputMode,
  ...base
}: BaseProps & {
  value: string
  onChange: (value: string) => void
  type?: string
  placeholder?: string
  autoComplete?: string
  multiline?: boolean
  inputMode?: 'text' | 'decimal' | 'numeric' | 'email' | 'url'
}) {
  const id = useId()
  const described = [base.hint ? `${id}-hint` : '', base.error ? `${id}-error` : ''].filter(Boolean).join(' ')
  return (
    <Shell {...base} id={id}>
      {multiline ? (
        <textarea
          id={id}
          className="cj-input"
          rows={4}
          value={value}
          placeholder={placeholder}
          aria-invalid={base.error ? true : undefined}
          aria-describedby={described || undefined}
          aria-required={base.required || undefined}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : (
        <input
          id={id}
          className="cj-input"
          type={type}
          inputMode={inputMode}
          value={value}
          placeholder={placeholder}
          autoComplete={autoComplete}
          aria-invalid={base.error ? true : undefined}
          aria-describedby={described || undefined}
          aria-required={base.required || undefined}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Shell>
  )
}

export function SelectField({
  value,
  onChange,
  options,
  placeholder = 'Choose…',
  ...base
}: BaseProps & {
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
  placeholder?: string
}) {
  const id = useId()
  const described = [base.hint ? `${id}-hint` : '', base.error ? `${id}-error` : ''].filter(Boolean).join(' ')
  return (
    <Shell {...base} id={id}>
      <select
        id={id}
        className="cj-input"
        value={value}
        aria-invalid={base.error ? true : undefined}
        aria-describedby={described || undefined}
        aria-required={base.required || undefined}
        onChange={(event) => onChange(event.target.value)}
      >
        <option value="">{placeholder}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
    </Shell>
  )
}

/** A keyboard-accessible range control; the value is always visible as text. */
export function RatingField({
  value,
  onChange,
  min,
  max,
  ...base
}: BaseProps & { value: number; onChange: (value: number) => void; min: number; max: number }) {
  const id = useId()
  const described = [base.hint ? `${id}-hint` : '', base.error ? `${id}-error` : ''].filter(Boolean).join(' ')
  return (
    <Shell {...base} id={id}>
      <div className="cj-rating-input">
        <input
          id={id}
          className="cj-range"
          type="range"
          min={min}
          max={max}
          step={1}
          value={value}
          aria-describedby={described || undefined}
          aria-valuetext={`${value} of ${max}`}
          onChange={(event) => onChange(Number(event.target.value))}
        />
        <output className="cj-rating-input__value" htmlFor={id}>{value}<span>/{max}</span></output>
      </div>
    </Shell>
  )
}

export function ToggleField({
  value,
  onChange,
  label,
  description,
}: {
  value: boolean
  onChange: (value: boolean) => void
  label: string
  description?: string
}) {
  const id = useId()
  return (
    <div className="cj-toggle">
      <span className="cj-toggle__text">
        <label htmlFor={id} className="cj-toggle__label">{label}</label>
        {description && <span className="cj-toggle__description">{description}</span>}
      </span>
      <button
        type="button"
        id={id}
        role="switch"
        aria-checked={value}
        className="cj-switch"
        onClick={() => onChange(!value)}
      >
        <span className="cj-switch__dot" aria-hidden="true" />
      </button>
    </div>
  )
}

/** Shows a non-field error, such as a conflict or network failure. */
export function FormError({ children }: { children: ReactNode }) {
  return <p className="cj-form-error" role="alert">{children}</p>
}
