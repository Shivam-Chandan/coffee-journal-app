/**
 * Account-scoped local form drafts.
 *
 * A draft is explicitly not a saved entry: the UI must always distinguish
 * "kept on this device" from "saved to your journal". Storage can be denied or
 * evicted, so every call reports failure instead of assuming success.
 */

const PREFIX = 'cj-draft:v1:'

function key(uid: number, name: string): string {
  return `${PREFIX}${uid}:${name}`
}

export interface Draft<T> {
  values: T
  savedAt: number
}

export function readDraft<T>(uid: number, name: string): Draft<T> | null {
  try {
    const raw = localStorage.getItem(key(uid, name))
    if (!raw) return null
    const parsed: unknown = JSON.parse(raw)
    if (
      typeof parsed !== 'object' || parsed === null ||
      !('values' in parsed) || !('savedAt' in parsed) ||
      typeof (parsed as Draft<T>).savedAt !== 'number'
    ) {
      return null
    }
    return parsed as Draft<T>
  } catch {
    return null
  }
}

/** Returns false when the draft could not be persisted. */
export function writeDraft<T>(uid: number, name: string, values: T): boolean {
  try {
    localStorage.setItem(key(uid, name), JSON.stringify({ values, savedAt: Date.now() }))
    return true
  } catch {
    return false
  }
}

export function removeDraft(uid: number, name: string): void {
  try {
    localStorage.removeItem(key(uid, name))
  } catch {
    /* Nothing to clean up when storage is unavailable. */
  }
}

/** Removes every account's drafts; used on logout. */
export async function clearDrafts(): Promise<void> {
  try {
    for (const storageKey of Object.keys(localStorage)) {
      if (storageKey.startsWith(PREFIX)) localStorage.removeItem(storageKey)
    }
  } catch {
    /* Ignore: storage may be unavailable in private browsing modes. */
  }
}
