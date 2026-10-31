# Headless app acceptance requirements

Status: proposed requirements for `migrate-headless-frontend`; no implementation completion is implied.

## ADDED Requirements

### Requirement: Existing visual identity

WHEN an existing workflow is rendered in the new frontend, the system SHALL preserve the approved baseline's colors, typography, navigation order, cards, controls, and light/dark appearance, except for documented accessibility/mobile corrections.

#### Scenario: Journal parity

GIVEN fixed journal fixture data and an approved baseline at a specified viewport/theme
WHEN the React journal is rendered with the same data
THEN a visual comparison passes review
AND intentional differences are documented.

### Requirement: Function budget

WHEN a frontend deployment is built, the delivery pipeline SHALL verify actual generated functions against an explicit allowlist and SHALL reject any deployment containing 12 or more functions.

#### Scenario: Static release

GIVEN the initial static architecture with no approved gateway
WHEN Vercel output is built
THEN zero function artifacts are expected
AND any unexpected function fails the deployment check.

#### Scenario: Approved gateway

GIVEN a documented need for one fixed-upstream gateway
WHEN that gateway is added
THEN the allowlist names the approved function
AND additional unapproved functions fail the check.

### Requirement: Drupal identity and authorization

WHEN a user accesses the frontend, the system SHALL retain Drupal as the identity, ownership, validation, and authorization authority.

#### Scenario: Existing user login

GIVEN an existing active Drupal account
WHEN the user signs in through an enabled approved login method
THEN the session represents the existing account
AND existing journal and interaction ownership remains unchanged.

#### Scenario: Private access denied

GIVEN a private bean or brew owned by user A
WHEN user B requests it directly, through a collection, or through a relationship
THEN the backend denies or omits inaccessible data
AND no private derived fields or attachment content are exposed.

### Requirement: Session-safe mutations

WHEN a browser sends an authenticated mutation, the system SHALL validate the session, CSRF protection, entity access, and allowed fields on Drupal.

#### Scenario: Invalid CSRF token

GIVEN a valid session but an invalid CSRF token
WHEN a protected write is submitted
THEN Drupal rejects the write
AND the frontend retains the user's draft and explains recovery.

### Requirement: Durable and conflict-aware saves

WHEN the frontend saves content, the system SHALL apply authoritative validation, detect stale edits, and prevent duplicate creates for the same account-scoped idempotency key.

#### Scenario: Retried create

GIVEN a create succeeded but its response was lost
WHEN the same account retries with the same idempotency key and payload
THEN the backend returns the established result without creating a duplicate.

#### Scenario: Concurrent edit

GIVEN a newer revision exists than the form's version
WHEN the user submits the stale form
THEN the API reports a conflict
AND the frontend preserves input and offers recovery instead of overwriting silently.

### Requirement: Private cache isolation

WHEN private data or session information is served, the system SHALL prevent shared CDN/service-worker caching and isolate client state by account.

#### Scenario: Account switch

GIVEN user A has loaded private records and has an outstanding request
WHEN A logs out and user B logs in
THEN A's private cache/drafts are unavailable to B
AND the late request cannot repopulate B's active state.

### Requirement: Mobile navigation and styling

WHEN a user navigates or edits on a supported phone, the system SHALL preserve usable focus, keyboard visibility, safe areas, browser Back behavior, and scroll restoration.

#### Scenario: Drawer Back action

GIVEN a form drawer opened from a scrolled journal
WHEN the user invokes browser Back
THEN the drawer closes according to navigation history
AND the journal scroll position and appropriate focus are restored.

### Requirement: Upload and share visibility

WHEN a photo is uploaded or brew visibility changes, the system SHALL enforce the documented file policy, validate the upload, and apply access rules to API, image, and share-page representations.

#### Scenario: Invalid photo

GIVEN a photo exceeds the deployed transport limit or is unsupported
WHEN the user uploads it
THEN the frontend presents an actionable error
AND retains the brew draft without falsely reporting upload success.

#### Scenario: Public brew made private

GIVEN a publicly shared brew
WHEN the owner makes it private
THEN subsequent anonymous origin requests cannot obtain its protected share representation or protected attachments
AND the UI does not promise revocation of copies already downloaded by third parties.

### Requirement: Honest offline drafts

WHEN a user edits offline, the system SHALL distinguish a local draft from a server save and SHALL avoid automatic background mutation replay in the first release.

#### Scenario: Offline save attempt

GIVEN an authenticated user's form and an unavailable network
WHEN the user attempts to save
THEN available local storage preserves the account-scoped draft
AND the interface reports that server saving is pending manual retry
AND storage failure is explicitly reported if the draft could not be persisted.

### Requirement: Safe deployment and rollback

WHEN the frontend is released, the system SHALL retain compatible backend APIs and a tested rollback path without discarding legitimate user writes.

#### Scenario: Frontend regression

GIVEN a released frontend with a critical interaction regression
WHEN operators restore the previous frontend or traditional UI routing
THEN existing and newly created records remain available
AND service-worker recovery is performed according to the documented procedure.
