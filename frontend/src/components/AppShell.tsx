import { Link, Outlet, useRouterState } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useSession } from '../features/auth/useAuth'
import { Icon } from './Icon'

export function AppShell() {
  const { account, data } = useSession()
  const queryClient = useQueryClient()
  const accountId = account?.id ?? null
  const previousAccount = useRef<number | null | undefined>(undefined)
  const path = useRouterState({ select: (state) => state.location.pathname })
  const [light, setLight] = useState(() => document.documentElement.classList.contains('light-mode'))

  useEffect(() => {
    // A late response must never surface under a different account's key.
    if (data && previousAccount.current !== undefined && previousAccount.current !== accountId) {
      void queryClient.cancelQueries({ queryKey: ['private'] }).then(() => {
        queryClient.removeQueries({ queryKey: ['private'] })
      })
    }
    if (data) previousAccount.current = accountId
  }, [accountId, data, queryClient])

  function toggleTheme() {
    const next = !light
    document.documentElement.classList.toggle('light-mode', next)
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', next ? '#f7f8f3' : '#111513')
    setLight(next)
    try {
      localStorage.setItem('cj-theme', next ? 'light' : 'dark')
    } catch {
      /* Storage is optional; the class change still applies. */
    }
  }

  return (
    <>
      <a href="#main-content" className="cj-skip-link">Skip to main content</a>
      <div id="cj-app">
        <header id="cj-topbar">
          <Link to="/feed" className="cj-brand" aria-label="Coffee Journal - Home">
            <span className="cj-brand-icon"><Icon name="bean" /></span>
            <span>Coffee Journal<span className="cj-brand-dot">.</span></span>
          </Link>
          <button
            type="button"
            className="cj-theme-toggle"
            onClick={toggleTheme}
            aria-label={light ? 'Use dark theme' : 'Use light theme'}
          >
            <Icon name={light ? 'moon' : 'sun'} />
          </button>
        </header>
        <main id="cj-main">
          <div className="cj-page-inner" id="main-content" tabIndex={-1}>
            <Outlet />
          </div>
        </main>
      </div>

      <nav id="cj-bottom-nav" className="cj-bottom-nav" aria-label="Main navigation">
        <div className="cj-bottom-nav__group">
          <Link
            to="/feed"
            className="cj-bottom-nav__item"
            activeProps={{ className: 'is-active', 'aria-current': 'page' }}
          >
            <Icon name="feed" /><span>Feed</span>
          </Link>
          <Link
            to="/my-coffees"
            className="cj-bottom-nav__item"
            activeProps={{ className: 'is-active', 'aria-current': 'page' }}
          >
            <Icon name="journal" /><span>Journal</span>
          </Link>
        </div>
        <div className="cj-bottom-nav__fab-slot">
          <Link to="/add" className="cj-fab" aria-label="Add coffee or brew" aria-current={path === '/add' ? 'page' : undefined}>
            <Icon name="plus" />
          </Link>
        </div>
        <div className="cj-bottom-nav__group">
          <Link
            to={account ? '/user/profile' : '/login'}
            search={account ? { tab: 'posts' as const } : { redirect: '/user/profile' }}
            className="cj-bottom-nav__item"
            activeProps={{ className: 'is-active', 'aria-current': 'page' }}
            activeOptions={{ exact: true, includeSearch: true }}
          >
            <Icon name="user" /><span>{account ? 'Profile' : 'Sign in'}</span>
          </Link>
          <Link
            to={account ? '/user/profile' : '/login'}
            search={account ? { tab: 'saved' as const } : { redirect: '/user/profile?tab=saved' }}
            className="cj-bottom-nav__item"
            activeProps={{ className: 'is-active', 'aria-current': 'page' }}
            activeOptions={{ exact: true, includeSearch: true }}
          >
            <Icon name="bookmark" /><span>Saved</span>
          </Link>
        </div>
      </nav>
    </>
  )
}
