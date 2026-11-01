# Coffee identity and purchase history

## Model and behavior

- `coffee_bean` is an owner-specific coffee identity. Name, roaster, estate,
  roast, personal rating, taste profile and tasting notes belong here.
- `coffee_order` references a coffee owned by the same account. It records date,
  quantity/unit, form factor, completion state, completion timestamp/reason, and
  optionally the original entry ID used during migration. Orders have no ratings.
- A new coffee includes its first order. An existing coffee can receive more
  orders through the searchable manual-entry picker or its detail page.
- A new current purchase completes the previous current bag. Multiple records
  on the same date are preserved as distinct bags. An older purchase is stored
  as completed history without displacing a newer bag.
- Coffee cards show the purchase count, current bag and expandable order history.
- Drupal forms use the same lifecycle hooks as React and assistant writes.
  Purchase fields retained on `coffee_bean` are a compatibility projection of
  the latest order; the React coffee editor edits only coffee-level properties.
- Exact normalized name + roaster + roast matches are rejected as duplicate new
  cards in browser entry, or recorded as another order in connector create.
  The search dropdown matches the user's coffee names and roasters. Product
  variants remain separate coffees; quantity and form factor do not define identity.
- Node storage transactions and a parent-row database lock serialize order
  lifecycle changes. API order writes additionally bind retry keys to payloads
  and commit the receipt and order together.

## API

- `GET /api/v1/bean-choices?q=...`: owner-scoped existing-coffee search, capped
  at 100 choices per query. Choices include stable UUID, name, roaster and roast.
- `POST /api/v1/beans/{uuid}/orders`: accepts `purchase_date`, `quantity`,
  `quantity_unit`, `form_factor`, `finished`; returns the updated bean detail.
- `POST /api/v1/orders/{uuid}/complete`: empty JSON object; returns updated bean.
- Both writes require the session CSRF header and an `Idempotency-Key` of 8–64
  letters, digits, underscores or hyphens. They recheck ownership and access.
- Bean cards add `order_count` and `current_order`; details add `orders`, newest
  purchase date first, breaking ties by descending order node ID.

## Deployment and backfill

Deploy Drupal and the independent frontend together. The Acquia post-code-deploy
hook runs database updates, config import, cache rebuild and then:

```sh
drush coffee:orders-backfill
```

This repeatable command creates a first order for each published legacy bean
that does not have any history. It does not merge accounts' coffees or infer
unknown completion dates. For manual deployments run those same steps in order.
The `coffee_order` configuration must be imported before backfill or consolidation.

## One-time account consolidation

The reviewed Toffee Coffee plan is in
`docroot/modules/custom/coffee_journal_api/resources/coffee-consolidation.json`.
It recognizes nine coffee identities from the 28 entries inspected in production.
It normalizes the roaster to **Toffee Coffee Roaster**, sets Mullayangiri's estate
to **Mullayangiri Estate**, and preserves both July 30 Mullayangiri bag records.
It refuses unrecognized titles or roasters rather than silently dropping them.

After deploying the backend, preview and apply for the intended account:

```sh
drush coffee:consolidate shivam.chan@gmail.com
drush coffee:consolidate shivam.chan@gmail.com --apply
drush cr
```

The command runs in a transaction. It retains the lowest existing coffee node ID
for each group, moves all purchase and brew references to that coffee, merges
likes/bookmarks without duplicate user-node pairs, and unpublishes the duplicate
coffee nodes. Originals remain available to administrators with their fields
intact; the retained coffee also gets a new revision, preserving its prior values.
Old **API UUIDs** resolve to the canonical coffee, with normal access checks.

The latest purchase's nonempty coffee-level taste values are retained; older
conflicting values remain on archived originals. The latest bag retains its
explicit completion status; older bags become completed with unknown completion
timestamps. Repeating an already completed group does not reset subsequent edits.

Expected result for the inspected account: **9 published coffees, 28 purchase/bag
records**, including **8 Mullayangiri records**. Purchase/bag records are the count
unit; this release does not model a retailer checkout containing several bags.

## Verification

Kernel coverage includes automatic first orders, same-date bags, completion,
backdated entries, account isolation, retry-key conflicts, transaction rollback,
read-only consolidation preview and repeatable consolidation preserving originals.
Run with PHP 8.3:

```sh
SIMPLETEST_DB='sqlite://localhost/:memory:' php vendor/bin/phpunit \
  --no-configuration --bootstrap docroot/core/tests/bootstrap.php \
  docroot/modules/custom/coffee_journal_api/tests/src/Kernel
```

Frontend checks: `npm test` and `npm run build` in `frontend/`.
