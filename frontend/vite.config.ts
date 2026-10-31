import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'
import { backendOrigin, proxyPrefixes } from './scripts/routing.mjs'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = backendOrigin(env.DRUPAL_ORIGIN ?? 'https://brewtal.ddev.site')
  return {
    plugins: [react()],
    server: {
      port: 5173,
      strictPort: true,
      proxy: Object.fromEntries(proxyPrefixes.map((path) => [path, {
        target,
        changeOrigin: true,
        // DDEV's development certificate only. Production rewrites use TLS verification.
        secure: !new URL(target).hostname.endsWith('.ddev.site'),
        cookieDomainRewrite: '',
      }])),
    },
  }
})
