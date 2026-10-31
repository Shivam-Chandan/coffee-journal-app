import { QueryClient } from '@tanstack/react-query'
import { createRootRoute, createRoute, createRouter, lazyRouteComponent, redirect } from '@tanstack/react-router'
import { AppShell } from '../components/AppShell'
import { State } from '../components/State'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: false },
    mutations: { retry: false },
  },
})

const root = createRootRoute({
  component: AppShell,
  notFoundComponent: () => <State title="Page not found.">Choose Feed or Journal to continue.</State>,
  errorComponent: ({ reset }) => <State title="This screen could not load." retry={reset} />,
})

const index = createRoute({
  getParentRoute: () => root,
  path: '/',
  beforeLoad: () => { throw redirect({ to: '/feed' }) },
})

const feed = createRoute({
  getParentRoute: () => root,
  path: '/feed',
  component: lazyRouteComponent(() => import('../features/feed/FeedPage'), 'FeedPage'),
})

const journal = createRoute({
  getParentRoute: () => root,
  path: '/my-coffees',
  component: lazyRouteComponent(() => import('../features/beans/JournalPage'), 'JournalPage'),
})

const newBean = createRoute({
  getParentRoute: () => root,
  path: '/beans/new',
  component: lazyRouteComponent(() => import('../features/add/NewEntryPages'), 'NewBeanPage'),
})

const beanDetail = createRoute({
  getParentRoute: () => root,
  path: '/beans/$beanId',
  component: lazyRouteComponent(() => import('../features/beans/BeanDetailPage'), 'BeanDetailPage'),
})

const newBrew = createRoute({
  getParentRoute: () => root,
  path: '/brews/new',
  // A missing method shows the picker, so both are optional strings.
  validateSearch: (search: Record<string, unknown>) => ({
    method: typeof search.method === 'string' ? search.method : '',
    bean: typeof search.bean === 'string' && /^[0-9a-fA-F-]{36}$/.test(search.bean) ? search.bean : '',
  }),
  component: lazyRouteComponent(() => import('../features/add/NewEntryPages'), 'NewBrewPage'),
})

const brewDetail = createRoute({
  getParentRoute: () => root,
  path: '/brews/$brewId',
  component: lazyRouteComponent(() => import('../features/brews/BrewDetailPage'), 'BrewDetailPage'),
})

const add = createRoute({
  getParentRoute: () => root,
  path: '/add',
  component: lazyRouteComponent(() => import('../features/add/AddPage'), 'AddPage'),
})

const profile = createRoute({
  getParentRoute: () => root,
  path: '/user/profile',
  validateSearch: (search: Record<string, unknown>) => ({
    tab: search.tab === 'saved' ? ('saved' as const) : ('posts' as const),
  }),
  component: lazyRouteComponent(() => import('../features/profile/ProfilePage'), 'ProfilePage'),
})

const login = createRoute({
  getParentRoute: () => root,
  path: '/login',
  // Only in-app paths are accepted, so a crafted link cannot redirect away.
  validateSearch: (search: Record<string, unknown>) => ({
    redirect: typeof search.redirect === 'string' && /^\/(?!\/)[\w\-/?=&.]*$/.test(search.redirect)
      ? search.redirect
      : undefined,
  }),
  component: lazyRouteComponent(() => import('../features/auth/LoginPage'), 'LoginPage'),
})

export const router = createRouter({
  routeTree: root.addChildren([
    index, feed, journal, newBean, beanDetail, newBrew, brewDetail, add, profile, login,
  ]),
  scrollRestoration: true,
})

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
