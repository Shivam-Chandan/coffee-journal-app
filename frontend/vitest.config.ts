import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    // Draft tests need localStorage; API tests only need fetch.
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
  },
})
