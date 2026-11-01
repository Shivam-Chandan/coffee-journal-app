import { z } from 'zod'

const uuid = z.string().regex(/^[0-9a-fA-F-]{36}$/)

export const provenanceSchema = z.object({
  channel: z.enum(['app', 'ai_connector', 'unknown']),
  provider: z.string().nullable(),
  label: z.string().nullable(),
  created_at: z.number().int().nonnegative(),
})

export const engagementSchema = z.object({
  likes: z.number().int().nonnegative(),
  liked: z.boolean(),
  bookmarked: z.boolean(),
})

export const authorSchema = z.object({
  id: z.number().int().nonnegative(),
  name: z.string(),
  initial: z.string(),
  is_me: z.boolean(),
})

export const accountSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string(),
  can_create: z.boolean(),
})

export const sessionSchema = z.object({
  data: z.discriminatedUnion('authenticated', [
    z.object({ authenticated: z.literal(false), user: z.null() }),
    z.object({ authenticated: z.literal(true), user: accountSchema }),
  ]),
})

export const coffeeOrderSchema = z.object({
  id: uuid,
  purchase_date: z.string(),
  quantity: z.number().nullable(),
  quantity_unit: z.string(),
  form_factor: z.string(),
  finished: z.boolean(),
  completed_at: z.number().nullable(),
  completion_reason: z.string(),
})

export const beanCardSchema = z.object({
  id: uuid,
  nid: z.number().int().positive(),
  title: z.string(),
  brand: z.string(),
  roast: z.string(),
  rating: z.number().min(0).max(5),
  finished: z.boolean(),
  order_count: z.number().int().nonnegative().optional(),
  current_order: coffeeOrderSchema.nullable().optional(),
  provenance: provenanceSchema.optional(),
})

export const brewCardSchema = z.object({
  id: uuid,
  nid: z.number().int().positive(),
  title: z.string(),
  method: z.string(),
  ratio: z.string().nullable(),
  caption: z.string(),
  is_public: z.boolean(),
  photo: z.object({
    url: z.string(),
    width: z.number().int().positive().nullable(),
    height: z.number().int().positive().nullable(),
  }).nullable(),
  author: authorSchema,
  created: z.number().int().nonnegative(),
  engagement: engagementSchema,
})

export const beanDetailSchema = beanCardSchema.extend({
  version: z.number().int(),
  can_edit: z.boolean(),
  can_delete: z.boolean(),
  estate: z.string(),
  form_factor: z.string(),
  quantity: z.number().nullable(),
  quantity_unit: z.string(),
  purchase_date: z.string(),
  notes: z.string(),
  worth_reordering: z.boolean(),
  taste: z.object({
    rating: z.number(),
    bitterness: z.number(),
    acidity: z.number(),
    clarity: z.number(),
  }),
  engagement: engagementSchema,
  brews: z.array(brewCardSchema).optional(),
  orders: z.array(coffeeOrderSchema).optional(),
})

export const brewDetailSchema = brewCardSchema.extend({
  version: z.number().int(),
  can_edit: z.boolean(),
  can_delete: z.boolean(),
  bean: z.object({ id: uuid, title: z.string() }).nullable(),
  coffee_weight: z.number().nullable(),
  water_weight: z.number().nullable(),
  water_temp: z.number().nullable(),
  brew_time: z.string(),
  grind: z.string(),
  notes: z.string(),
})

const meta = z.object({ next: z.number().int().positive().nullable() })
export const beansSchema = z.object({ data: z.array(beanCardSchema), meta })
export const feedSchema = z.object({ data: z.array(brewCardSchema), meta })
export const beanChoicesSchema = z.object({
  data: z.array(z.object({ id: uuid, title: z.string(), brand: z.string(), roast: z.string().optional() })),
})
export const savedSchema = z.object({
  data: z.array(z.union([
    beanCardSchema.extend({ kind: z.literal('bean') }),
    brewCardSchema.extend({ kind: z.literal('brew') }),
  ])),
  meta,
})
export const assistantGrantSchema = z.object({
  provider: z.enum(['chatgpt', 'claude']),
  status: z.enum(['not_connected', 'authorized', 'reconnect_required']),
  scopes: z.array(z.string()).optional(),
  authorized_at: z.number().int().optional(),
  expires_at: z.number().int().optional(),
})
export const assistantConnectionsSchema = z.object({
  enabled: z.boolean(),
  providers: z.array(assistantGrantSchema),
  mcp_url: z.string().url().optional(),
})
export const assistantConnectionsResponse = z.object({ data: assistantConnectionsSchema })
export type AssistantGrant = z.infer<typeof assistantGrantSchema>
export type AssistantConnections = z.infer<typeof assistantConnectionsSchema>

export const profileSchema = z.object({
  data: z.object({
    id: z.number().int().positive(),
    name: z.string(),
    email: z.string(),
    initial: z.string(),
    member_since: z.number().int().nonnegative(),
    chatgpt_app_url: z.string().url().nullable().optional(),
    claude_connectors_url: z.string().url().nullable().optional(),
    assistant_connections: assistantConnectionsSchema.nullable().optional(),
    stats: z.object({
      beans: z.number().int().nonnegative(),
      brews: z.number().int().nonnegative(),
      likes: z.number().int().nonnegative(),
    }),
  }),
})

const fieldMeta = z.object({
  label: z.string(),
  required: z.boolean(),
  min: z.number().optional(),
  max: z.number().optional(),
  options: z.array(z.object({ value: z.string(), label: z.string() })).optional(),
})
export const formOptionsSchema = z.object({
  data: z.object({
    coffee_bean: z.record(z.string(), fieldMeta),
    brew_recipe: z.record(z.string(), fieldMeta),
    brew_methods: z.array(z.object({ value: z.string(), label: z.string() })),
  }),
})

export const beanDetailResponse = z.object({ data: beanDetailSchema })
export const brewDetailResponse = z.object({ data: brewDetailSchema })
export const engagementResponse = z.object({ data: z.object({ id: uuid, engagement: engagementSchema }) })
export const deletedResponse = z.object({ data: z.object({ deleted: z.boolean() }) })
export const messageResponse = z.object({ data: z.object({ message: z.string() }) })
export const scrapeSchema = z.object({
  name: z.string().optional(),
  brand: z.string().optional(),
  roastLevel: z.string().optional(),
  formFactor: z.string().optional(),
  estate: z.string().optional(),
  tastingNotes: z.string().optional(),
  flavourNotes: z.string().optional(),
}).passthrough()

export type Account = z.infer<typeof accountSchema>
export type BeanCard = z.infer<typeof beanCardSchema>
export type BrewCard = z.infer<typeof brewCardSchema>
export type BeanDetail = z.infer<typeof beanDetailSchema>
export type BrewDetail = z.infer<typeof brewDetailSchema>
export type FormOptions = z.infer<typeof formOptionsSchema>['data']
export type FieldMeta = z.infer<typeof fieldMeta>
export type SavedItem = z.infer<typeof savedSchema>['data'][number]
