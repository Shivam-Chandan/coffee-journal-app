import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { backendOrigin, buildRoutes } from '../scripts/routing.mjs'
import { inspectOutput } from '../scripts/check-functions.mjs'

const directories: string[] = []
afterEach(async () => { await Promise.all(directories.splice(0).map((path) => rm(path, { recursive: true, force: true }))) })

describe('deployment boundaries', () => {
  it('requires a fixed HTTPS upstream without credentials or path overrides', () => {
    for (const origin of ['', 'http://example.com', 'https://user:pass@example.com', 'https://example.com/private', 'https://example.com?token=secret']) {
      expect(() => backendOrigin(origin)).toThrow()
    }
    expect(backendOrigin('https://example.com/')).toBe('https://example.com')
  })

  it('routes API/token requests before the SPA and disables shared caching', () => {
    const routes = buildRoutes('https://drupal.example.com')
    for (const path of ['/api/v1/feed', '/api/not-a-real-endpoint', '/api', '/session/token']) {
      const match = routes.find((route) => 'src' in route && new RegExp(`^${route.src}$`).test(path))!
      expect(match.dest).toMatch(/^https:\/\/drupal\.example\.com\//)
      expect(match.headers?.['Cache-Control']).toBe('private, no-store')
    }
    expect(routes.at(-1)?.dest).toBe('/index.html')
  })

  async function output() {
    const directory = await mkdtemp(join(tmpdir(), 'coffee-output-'))
    directories.push(directory)
    await mkdir(join(directory, 'static'))
    await writeFile(join(directory, 'config.json'), JSON.stringify({ version: 3 }))
    await writeFile(join(directory, 'static/index.html'), '<!doctype html>')
    return directory
  }

  it('accepts static output but rejects an unexpected nested function', async () => {
    const directory = await output()
    await expect(inspectOutput(directory)).resolves.toBe(0)
    await mkdir(join(directory, 'functions/api/hidden.func'), { recursive: true })
    await expect(inspectOutput(directory)).rejects.toThrow('Unapproved functions')
  })

  it('enforces the absolute ceiling independently of the empty allowlist', async () => {
    const directory = await output()
    await Promise.all(Array.from({ length: 12 }, (_, index) => mkdir(join(directory, `functions/${index}.func`), { recursive: true })))
    await expect(inspectOutput(directory)).rejects.toThrow('maximum is 11')
  })
})
