export const proxyPrefixes = ['/api', '/session/token']

export function backendOrigin(value) {
  if (!value) throw new Error('DRUPAL_ORIGIN is required for a Vercel build.')
  const url = new URL(value)
  if (url.protocol !== 'https:' || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
    throw new Error('DRUPAL_ORIGIN must be an HTTPS origin without credentials, path, query or fragment.')
  }
  return url.origin
}

export function buildRoutes(origin) {
  const upstream = backendOrigin(origin)
  return [
    { src: '/assets/(.*)', headers: { 'Cache-Control': 'public, max-age=31536000, immutable' }, continue: true },
    { src: '/api(?:/(.*))?', dest: `${upstream}/api/$1`, headers: {
      'Cache-Control': 'private, no-store',
      'CDN-Cache-Control': 'no-store',
      'Vercel-CDN-Cache-Control': 'no-store',
      'x-vercel-enable-rewrite-caching': '0',
    } },
    { src: '/session/token', dest: `${upstream}/session/token`, headers: {
      'Cache-Control': 'private, no-store', 'CDN-Cache-Control': 'no-store',
      'Vercel-CDN-Cache-Control': 'no-store', 'x-vercel-enable-rewrite-caching': '0',
    } },
    { handle: 'filesystem' },
    { src: '/.*', dest: '/index.html', headers: { 'Cache-Control': 'no-cache' } },
  ]
}
