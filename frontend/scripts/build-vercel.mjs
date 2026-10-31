import { cp, mkdir, rm, writeFile } from 'node:fs/promises'
import { loadEnv } from 'vite'
import { buildRoutes } from './routing.mjs'

const env = { ...loadEnv('production', process.cwd(), ''), ...process.env }
// Validate before changing generated output. No runtime server or function is emitted.
const routes = buildRoutes(env.DRUPAL_ORIGIN)
await rm('.vercel/output', { recursive: true, force: true })
await mkdir('.vercel/output', { recursive: true })
await cp('dist', '.vercel/output/static', { recursive: true })
await writeFile('.vercel/output/config.json', JSON.stringify({ version: 3, routes }, null, 2) + '\n')
console.log('Generated static Vercel Build Output with explicit Drupal rewrites.')
