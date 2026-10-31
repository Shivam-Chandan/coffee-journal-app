# AI journal connectors

## ADDED Requirements

### Requirement: Personal scoped connections
WHEN a connector requests journal access, the system SHALL authenticate a valid,
unexpired, unrevoked connection to the enabled pilot account and SHALL enforce
operation scopes, explicit ownership and Drupal permissions.

#### Scenario: Foreign entry request
GIVEN a valid personal connection and a coffee owned by another account
WHEN the connection requests that coffee
THEN the system returns a non-disclosing not-found result and no entry data.

#### Scenario: Revoked connection
GIVEN a revoked connection
WHEN it attempts to read or create a coffee
THEN access is denied and no coffee is created.

### Requirement: Provider-neutral creation
WHEN an authorized connector submits a coffee, the system SHALL validate the
allowlisted fields using shared Drupal rules and SHALL assign the connected
account as owner without accepting a client-specified owner.

#### Scenario: Valid coffee
GIVEN an authorized create-scoped connection
WHEN it submits valid coffee fields and a fresh request ID
THEN one coffee is saved for the connected account
AND the response includes its UUID, application URL and provenance.

#### Scenario: Missing or invalid fields
WHEN a connector submits missing required fields or invalid allowed values
THEN the system returns field-level errors and persists no coffee.

### Requirement: Retry-safe creation
WHEN a connection retries an identical create operation with the same request ID
within the retention window, the system SHALL return the same saved entry,
including under concurrent requests, and SHALL reject ID reuse with different data.

#### Scenario: Concurrent delivery
GIVEN two identical simultaneous requests with the same account, connection and ID
WHEN both are processed
THEN at most one node is created and successful responses identify that node.

#### Scenario: Conflicting reuse
GIVEN a successful create request
WHEN its ID is reused with a different normalized payload
THEN the system returns a conflict and creates no additional entry.

### Requirement: Trusted immutable creation provenance
WHEN a connector creates a coffee, the system SHALL store its creation channel,
registered provider and internal connection reference from authenticated context.
The system SHALL preserve creation provenance through ordinary edits and SHALL
prevent clients from changing it through any supported write path.

#### Scenario: Source spoof attempt
GIVEN a connection registered as ChatGPT
WHEN a request attempts to set its provider to Claude or channel to app
THEN the request is rejected and no falsely attributed coffee is saved.

#### Scenario: Later human edit
GIVEN a coffee created via Claude
WHEN its owner changes its notes in the app
THEN its creation provenance remains Claude.

### Requirement: Visible AI source identifier
WHEN a coffee created through an AI connector is rendered, the system SHALL show
an accessible source badge on cards and an Added via provider label on detail
views in both supported UIs, with a generic AI fallback for unrecognized providers.

#### Scenario: ChatGPT-created coffee
WHEN its owner opens the journal and coffee details after a ChatGPT creation
THEN the card shows AI · ChatGPT and details show Added via ChatGPT
AND the identifier persists after refresh and subsequent edits.

#### Scenario: Existing or manually created coffee
GIVEN an existing coffee without known provenance or a new app-created coffee
WHEN it is rendered
THEN it has no AI-created badge
AND existing records are not retroactively declared manual or AI-generated.

### Requirement: Client-specific adapters
WHEN supported chat clients invoke journal tools, the system SHALL route them
through shared journal services while retaining distinct connection identities.
Provider availability SHALL be verified in the actual target client.

#### Scenario: Private GPT creation
GIVEN the configured private GPT and its personal Action credential
WHEN the user confirms a consequential create action with valid inputs
THEN the coffee is persisted and returned with ChatGPT provenance.

#### Scenario: Claude MCP creation
GIVEN a verified compatible Claude client and authenticated MCP connection
WHEN the user requests and confirms creation through its tool flow
THEN the same journal rules apply and the coffee has Claude provenance.

#### Scenario: Unsupported Gemini client
GIVEN no verified custom-connector support in the selected Gemini client
WHEN integration support is documented
THEN Gemini is marked deferred rather than presented as a working connection.
