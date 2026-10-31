import { readdir, readFile, lstat } from 'node:fs/promises'
import { resolve } from 'node:path'

export async function inspectOutput(directory) {
  const config = JSON.parse(await readFile(resolve(directory, 'config.json'), 'utf8'))
  if (config.version !== 3) throw new Error('Expected Vercel Build Output API version 3.')
  await lstat(resolve(directory, 'static/index.html'))
  const functions = []
  async function walk(path) {
    let entries
    try { entries = await readdir(path, { withFileTypes: true }) }
    catch (error) { if (error.code === 'ENOENT') return; throw error }
    for (const entry of entries) {
      if (entry.isSymbolicLink()) throw new Error('Unexpected symlink in function output.')
      const child = resolve(path, entry.name)
      if (entry.name.endsWith('.func')) functions.push(child)
      else if (entry.isDirectory()) await walk(child)
      else throw new Error(`Unexpected function output: ${child}`)
    }
  }
  await walk(resolve(directory, 'functions'))
  if (functions.length >= 12) throw new Error(`Function ceiling exceeded: ${functions.length}; maximum is 11.`)
  // The approved allowlist is empty. Adding a gateway needs a reviewed explicit change.
  if (functions.length) throw new Error(`Unapproved functions: ${functions.join(', ')}`)
  return functions.length
}

if (process.argv[1] === new URL(import.meta.url).pathname) {
  const count = await inspectOutput(process.argv[2] ?? '.vercel/output')
  console.log(`Vercel function budget passed: ${count} functions (approved: 0; hard ceiling: 11).`)
}
