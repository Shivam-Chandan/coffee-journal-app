import { useState } from 'react'
import { Link, useNavigate, useSearch } from '@tanstack/react-router'
import { ApiError } from '../../api/client'
import { FormError, TextField } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { useLogin, usePasswordReset, useSession } from './useAuth'

export function LoginPage() {
  const { redirect } = useSearch({ from: '/login' })
  const navigate = useNavigate()
  const { account } = useSession()
  const login = useLogin()
  const reset = usePasswordReset()
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [mode, setMode] = useState<'login' | 'reset'>('login')

  if (account) {
    return (
      <div className="cj-auth">
        <h1 className="cj-auth__title">You are signed in</h1>
        <p className="cj-page-description">Signed in as {account.name}.</p>
        <Link to="/my-coffees" className="cj-button cj-button--primary">Go to your journal</Link>
      </div>
    )
  }

  const error = (login.error ?? reset.error) as ApiError | null
  const fields = error?.fields ?? {}
  const generalError = error && !Object.keys(fields).length ? error.message : null

  async function submitLogin(event: React.FormEvent) {
    event.preventDefault()
    const result = await login.mutateAsync({ identifier, password }).catch(() => null)
    if (result) {
      setPassword('')
      await navigate({ to: redirect ?? '/my-coffees' })
    }
  }

  async function submitReset(event: React.FormEvent) {
    event.preventDefault()
    await reset.mutateAsync({ identifier }).catch(() => null)
  }

  return (
    <div className="cj-auth">
      <span className="cj-auth__mark"><Icon name="bean" /></span>
      <h1 className="cj-auth__title">
        {mode === 'login' ? 'Welcome back' : 'Reset your password'}
        <span className="cj-heading-dot">.</span>
      </h1>
      <p className="cj-page-description">
        {mode === 'login'
          ? 'Sign in to your journal with the account you already use.'
          : 'We will email reset instructions if that account exists.'}
      </p>

      {mode === 'login' ? (
        <form className="cj-form" onSubmit={submitLogin} noValidate>
          {generalError && <FormError>{generalError}</FormError>}
          <TextField
            label="Email or username"
            value={identifier}
            onChange={setIdentifier}
            autoComplete="username"
            inputMode="email"
            required
            error={fields.identifier || undefined}
          />
          <TextField
            label="Password"
            type="password"
            value={password}
            onChange={setPassword}
            autoComplete="current-password"
            required
            error={fields.password || undefined}
          />
          <button type="submit" className="cj-button cj-button--primary" disabled={login.isPending}>
            {login.isPending ? 'Signing in…' : 'Sign in'}
          </button>
          <button type="button" className="cj-button cj-button--quiet" onClick={() => setMode('reset')}>
            Forgot your password?
          </button>
        </form>
      ) : (
        <form className="cj-form" onSubmit={submitReset} noValidate>
          {generalError && <FormError>{generalError}</FormError>}
          {reset.isSuccess && (
            <p className="cj-form-success" role="status">{reset.data.data.message}</p>
          )}
          <TextField
            label="Email or username"
            value={identifier}
            onChange={setIdentifier}
            autoComplete="username"
            inputMode="email"
            required
            error={fields.identifier || undefined}
          />
          <button type="submit" className="cj-button cj-button--primary" disabled={reset.isPending}>
            {reset.isPending ? 'Sending…' : 'Email reset instructions'}
          </button>
          <button type="button" className="cj-button cj-button--quiet" onClick={() => setMode('login')}>
            Back to sign in
          </button>
        </form>
      )}

      <p className="cj-auth__note">
        Use your Coffee Journal username or email and password. New account
        registration is currently available on the existing Coffee Journal site.
      </p>
    </div>
  )
}
