import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { clearDrafts, readDraft, removeDraft, writeDraft } from '../src/lib/drafts'

describe('local drafts', () => {
  beforeEach(() => localStorage.clear())
  afterEach(() => localStorage.clear())

  it('keeps drafts separate per account', () => {
    writeDraft(1, 'bean:new', { title: 'Mine' })
    writeDraft(2, 'bean:new', { title: 'Theirs' })
    expect(readDraft<{ title: string }>(1, 'bean:new')?.values.title).toBe('Mine')
    expect(readDraft<{ title: string }>(2, 'bean:new')?.values.title).toBe('Theirs')
  })

  it('records when a draft was kept locally', () => {
    writeDraft(1, 'bean:new', { title: 'A' })
    const draft = readDraft<{ title: string }>(1, 'bean:new')
    expect(typeof draft?.savedAt).toBe('number')
  })

  it('ignores corrupted draft data instead of crashing a form', () => {
    localStorage.setItem('cj-draft:v1:1:bean:new', '{not json')
    expect(readDraft(1, 'bean:new')).toBeNull()
    localStorage.setItem('cj-draft:v1:1:bean:new', '{"values":{},"savedAt":"nope"}')
    expect(readDraft(1, 'bean:new')).toBeNull()
  })

  it('removes a single draft after a successful save', () => {
    writeDraft(1, 'bean:new', { title: 'A' })
    removeDraft(1, 'bean:new')
    expect(readDraft(1, 'bean:new')).toBeNull()
  })

  it('clears every account draft on logout', async () => {
    writeDraft(1, 'bean:new', { title: 'A' })
    writeDraft(2, 'brew:new', { title: 'B' })
    localStorage.setItem('cj-theme', 'light')
    await clearDrafts()
    expect(readDraft(1, 'bean:new')).toBeNull()
    expect(readDraft(2, 'brew:new')).toBeNull()
    // Unrelated preferences must survive.
    expect(localStorage.getItem('cj-theme')).toBe('light')
  })

  it('reports failure when storage refuses to persist', () => {
    const original = Storage.prototype.setItem
    Storage.prototype.setItem = () => { throw new Error('QuotaExceededError') }
    expect(writeDraft(1, 'bean:new', { title: 'A' })).toBe(false)
    Storage.prototype.setItem = original
  })
})
