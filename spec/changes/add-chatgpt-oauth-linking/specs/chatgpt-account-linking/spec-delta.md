# ChatGPT OAuth account linking

## ADDED Requirements

### Requirement: Explicit account consent
WHEN ChatGPT starts authorization, the system SHALL require an authenticated,
active pilot account and explicit approval of the requested allowed scopes.

#### Scenario: Owner approves
GIVEN the configured pilot account is signed in to Drupal
WHEN it approves the registered client's requested journal scopes
THEN Drupal returns a short-lived code to the exact registered callback
AND no password or bearer token is included in the redirect.

#### Scenario: Wrong account or denial
WHEN a non-pilot account attempts authorization or the owner declines consent
THEN no authorized journal grant is created
AND an appropriate OAuth error is returned only to a validated redirect URI.

### Requirement: Bound authorization-code exchange
WHEN a code is exchanged, the system SHALL enforce single use, expiry, exact
client/redirect/resource binding and PKCE S256 verification.

#### Scenario: Invalid verifier or replay
WHEN an expired or previously redeemed code is submitted, or its verifier fails
THEN token issuance is rejected and no journal access is granted.

### Requirement: Discoverable OAuth linking
WHEN the client discovers the MCP server, the system SHALL publish accurate
resource/issuer metadata, per-tool OAuth scope declarations and supported runtime
authentication challenges without disclosing personal journal data.

#### Scenario: Unlinked client invokes a protected tool
GIVEN the client has no valid OAuth grant
WHEN it discovers or invokes the journal tools
THEN it can obtain only the metadata needed for linking
AND a protected invocation returns the documented OAuth challenge
AND no coffee or profile data is returned.

### Requirement: Validated grant-scoped journal access
WHEN an OAuth-authenticated request reaches the journal, the system SHALL verify
the token, expected issuer/resource, validity, scopes, grant state and active
account, and SHALL apply existing explicit ownership and Drupal permissions.

#### Scenario: Token minted for another resource
WHEN an otherwise valid token has the wrong audience or resource
THEN access is denied and no journal data is returned or changed.

#### Scenario: Read-only grant attempts creation
WHEN a client with only beans:read invokes create_coffee
THEN creation is denied and no node is persisted.

### Requirement: Refresh-safe identity and retries
WHEN access tokens refresh for an existing grant, the system SHALL retain the
connection identity and granted scope limits, rotate refresh credentials and
detect invalid refresh reuse according to the selected provider's policy.

#### Scenario: Retry after refresh
GIVEN a create succeeded but its response was interrupted
WHEN the same grant refreshes and repeats the same request_id and payload within
the existing deduplication window
THEN the existing saved coffee is returned rather than creating a duplicate.

### Requirement: Stable authenticated profile
WHEN get_profile is invoked with valid authorization, the system SHALL return
the current credential's stable Drupal UUID as id with the declared OpenAI
profile-tool metadata and output schema.

#### Scenario: Display metadata changes
WHEN the owner changes name or email and reconnects
THEN the profile ID remains the same
AND another account's credentials cannot select that profile through arguments.

### Requirement: Truthful status and immediate disconnect
WHEN the profile displays connection status, the system SHALL derive it from
server-side OAuth grant state. WHEN the owner disconnects, the system SHALL
revoke the selected grant's access and refresh capability.

#### Scenario: Opening the plugin
WHEN the owner clicks Connect ChatGPT but has not completed OAuth exchange
THEN opening the plugin alone does not mark the account authorized.

#### Scenario: Disconnect from the app
GIVEN an authorized grant and an authenticated owner browser session
WHEN the owner submits the CSRF-protected disconnect operation
THEN subsequent access and refresh requests for that grant fail
AND previously saved coffees and creation provenance remain intact.

### Requirement: Trusted ChatGPT provenance
WHEN the registered ChatGPT OAuth client creates a coffee, the system SHALL
derive provider provenance from trusted client/grant configuration and SHALL
preserve it after edits or revocation.

#### Scenario: Provider spoof
WHEN a request attempts to change ownership or creation provider
THEN it is rejected using the existing integration contract
AND no falsely attributed entry is saved.

### Requirement: Controlled migration
WHEN production OAuth is accepted, the system SHALL revoke the old ChatGPT
personal credential without revoking independent provider grants or deleting
journal data.

#### Scenario: Legacy ChatGPT token after migration
WHEN the old credential is used following the completed migration
THEN it no longer authorizes journal access
AND the OAuth-connected owner can still access their journal.
