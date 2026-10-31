import { z } from 'zod'

/** An error that carries a status and optional per-field messages. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly code = 'error',
    public readonly fields: Record<string, string> = {},
    /** True when the backend returned a structured application error body. */
    public readonly structured = false,
  ) {
    super(message)
  }
}

const errorBody = z.object({
  error: z.object({
    code: z.string(),
    message: z.string(),
    fields: z.record(z.string(), z.string()).optional(),
  }),
})

function assertApiPath(path: string): void {
  if (!path.startsWith('/api/') || path.startsWith('//')) {
    throw new Error('Expected a same-origin API path.')
  }
}

/** Drupal issues one CSRF token per session; it must be refetched after login. */
let csrfToken: Promise<string> | null = null

export function resetCsrfToken(): void {
  csrfToken = null
}

function fetchCsrfToken(): Promise<string> {
  csrfToken ??= fetch('/session/token', { credentials: 'same-origin', cache: 'no-store' })
    .then(async (response) => {
      if (!response.ok) throw new ApiError(response.status, 'Could not start a secure session.')
      const token = (await response.text()).trim()
      if (!token) throw new ApiError(502, 'Could not start a secure session.')
      return token
    })
    .catch((error: unknown) => {
      csrfToken = null
      throw error
    })
  return csrfToken
}

async function toError(response: Response): Promise<ApiError> {
  if (response.headers.get('content-type')?.includes('application/json')) {
    const parsed = errorBody.safeParse(await response.json().catch(() => null))
    if (parsed.success) {
      const { code, message, fields } = parsed.data.error
      return new ApiError(response.status, message, code, fields ?? {}, true)
    }
  }
  if (response.status === 401) return new ApiError(401, 'Please sign in to continue.', 'authentication_required')
  if (response.status === 403) return new ApiError(403, 'You do not have access to this.', 'forbidden')
  if (response.status === 413) return new ApiError(413, 'That file is too large.', 'too_large')
  return new ApiError(response.status, 'Something went wrong. Please try again.', 'error')
}

async function parse<T>(response: Response, schema: z.ZodType<T>): Promise<T> {
  if (!response.ok) throw await toError(response)
  if (!response.headers.get('content-type')?.includes('application/json')) {
    throw new ApiError(502, 'The backend returned an unexpected response.', 'unexpected_response')
  }
  const parsed = schema.safeParse(await response.json())
  if (!parsed.success) {
    throw new ApiError(502, 'The backend response does not match this app version.', 'contract_mismatch')
  }
  return parsed.data
}

export async function getJson<T>(path: string, schema: z.ZodType<T>, signal?: AbortSignal): Promise<T> {
  assertApiPath(path)
  const response = await fetch(path, {
    credentials: 'same-origin',
    cache: 'no-store',
    signal,
    headers: { Accept: 'application/json' },
  })
  return parse(response, schema)
}

export interface MutateOptions {
  method: 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  /** Makes an interrupted create safe to retry without duplicating records. */
  idempotencyKey?: string
  signal?: AbortSignal
}

export async function mutate<T>(path: string, schema: z.ZodType<T>, options: MutateOptions): Promise<T> {
  assertApiPath(path)
  const send = async (token: string) => {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-CSRF-Token': token }
    if (options.body !== undefined) headers['Content-Type'] = 'application/json'
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey
    return fetch(path, {
      method: options.method,
      credentials: 'same-origin',
      cache: 'no-store',
      signal: options.signal,
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    })
  }

  let response = await send(await fetchCsrfToken())
  // Drupal rejects a stale CSRF token with an unstructured 403, while genuine
  // access denials return a structured application error. Only the former is
  // safe to retry, and only once.
  if (response.status === 403) {
    const error = await toError(response.clone())
    if (!error.structured) {
      resetCsrfToken()
      response = await send(await fetchCsrfToken())
    }
  }
  return parse(response, schema)
}

/** Generates an idempotency key for a single logical create attempt. */
export function newIdempotencyKey(): string {
  return crypto.randomUUID().replaceAll('-', '')
}
