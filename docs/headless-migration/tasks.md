# Headless migration implementation checklist

Status: implementation in progress. All primary screens now exist in the React frontend against real Drupal APIs, verified locally. Deployment, photo upload, public share HTML, PWA/offline work and production cutover remain outstanding. See the [execution record](execution.md) and [detailed plan](../headless-migration-plan.md). Check items only after their complete evidence is recorded.

### Verified subtasks

- [x] Inspect local runtime, enabled auth/privacy modules, source UI and deployment isolation.
- [x] Create and build the independent React/Vite/TanStack/Tailwind 3 workspace.
- [x] Generate static Vercel output and verify zero functions with negative budget tests.
- [x] Add and kernel-test session, journal, feed, detail, profile and saved read APIs.
- [x] Add and kernel-test create/update/delete, visibility and desired-state engagement APIs.
- [x] Implement the approved frontend visual system and apply it across every screen.
- [x] Implement email/username login, password-reset request and logout against Drupal sessions.
- [x] Implement bean/brew detail, create and edit screens, add sheet, method picker, profile and saved tabs.
- [x] Fix the `brew_privacy` bean-save failure found while probing the write path.
- [x] Commit/push the release to GitHub and promote Drupal through Dev, Test and Prod.
- [x] Create the Coffee-Journal Vercel project and verify production username login, private save/reload and logout through external rewrites.
- [ ] Capture/approve stable visual baselines and finish remaining Phase 0 decisions.
- [ ] Complete separate preview-origin acceptance and the camera-upload probe.
- [ ] Implement photo upload, public share HTML, legacy link resolution and PWA/offline behavior.

### Full migration tasks

1. [ ] **Establish the baseline and decisions (Phase 0).** Inspect latest working-tree/deployed state; record versions, routes, fields, enabled modules, authentication policy, UI screenshots, and existing tests. Reconcile email/Google plans. Decide domains, private-photo policy, delete semantics, and workspace scope. Deliver a feature/parity matrix and decision log.

2. [ ] **Prepare workspace and isolated delivery (Phase 1).** Update project guidance for headless boundaries; create the approved React/TypeScript/Vite workspace, lock dependencies, and configure independent Vercel/Acquia build roots. Add CI type/lint/build checks and actual Vercel output function inspection. Acceptance: static preview deploys with zero functions and no backend secrets.

3. [ ] **Prove session transport and one vertical slice (Phase 1).** Configure explicit rewrites, session bootstrap, approved login, CSRF-protected private read/create, logout, and a photo probe. Verify Google if retained, cookie/redirect/proxy headers, throttles, and stable staging callbacks on phones. Acceptance: deployed flow works; document whether a single gateway is needed and recheck function/upload budgets.

4. [ ] **Specify the API contract and shared validation (Phase 2).** Publish versioned OpenAPI schemas, errors, metadata/units, UUID mappings, pagination, stale-edit policy, idempotency, and compatibility strategy. Extract form-only rules into services/constraints. Generate frontend types. Acceptance: fixtures and contract checks cover happy paths and invalid input.

5. [ ] **Implement bean and brew resource APIs (Phase 2).** Add authorized lists/details/create/edit/delete, owner/field/reference checks, agreed deletion semantics, version preconditions, and idempotent creation. Acceptance: anonymous/owner/other-user tests pass and old forms remain functional.

6. [ ] **Implement screen/interaction APIs (Phase 2).** Add feed, profile/stats, saved items, desired-state likes/bookmarks, visibility, and required account-flow adapters. Reuse provider services and retain old endpoints. Acceptance: pagination is stable, requests are batched, and private relationships/fields remain protected.

7. [ ] **Port styling and shared UI (Phase 3).** Migrate authored CSS/tokens/fonts and Tailwind 3 scanning; implement shell, current navigation order, light/dark preference, drawer, controls, errors, and loading states. Replace Drupal-only selectors deliberately. Acceptance: fixture screenshot review passes at specified sizes; focus/keyboard/safe areas work.

8. [ ] **Deliver journal and brew frontend slices (Phase 4).** Implement lists, sorting, details, forms, ratings, method picker, from-bean flow, autofill, and Places. Replace HTML form fetching with typed mutations. Acceptance: current workflows pass, validation retains values, and stale/duplicate submissions are handled.

9. [ ] **Deliver community and account frontend slices (Phase 4).** Implement feed, optimistic engagement with rollback, saved/profile tabs, statistics, and approved login/registration/recovery/settings flows. Acceptance: account switching clears private state, deep links work, and browser Back/scroll behavior matches the plan.

10. [ ] **Complete photos, visibility, and public links (Phase 4).** Implement validated uploads, HEIC policy, responsive derivatives, private attachment access, existing-file handling, share HTML/metadata, and legacy link resolution. Acceptance: real camera upload works; visibility changes protect all app representations; API routes never return SPA fallback HTML.

11. [ ] **Add PWA shell and account-scoped drafts (Phase 5).** Implement manifest/icons, static asset caching, offline UI, IndexedDB drafts, quota/error handling, logout cleanup, and safe update prompts. Acceptance: no auth/private API response caching or background mutation replay; drafts survive intended interruptions without crossing accounts.

12. [ ] **Run release verification and optimize (Phase 5).** Run backend access/contract regressions, frontend/browser tests, screenshot comparisons, accessibility checks, and real-phone journeys. Measure useful-content performance and bundle sizes. Verify actual deployed function inventory, usage, headers, and two-user cache isolation. Record evidence and resolve launch blockers.

13. [ ] **Pilot, cut over, and rehearse rollback (Phase 6).** Deploy additive backend first, validate stable staging, pilot against the same data store, compare ownership/content invariants, and rehearse routing/service-worker rollback. Release a compatible frontend/backend pair, run production smoke tests, and monitor. Acceptance: no data restore required for frontend rollback and existing UI retained for the agreed window.

14. [ ] **Finalize handoff and post-pilot cleanup plan.** Update setup/deployment/operations docs, record decisions and remaining limitations, assign monitoring ownership, and mark requirements against evidence. Schedule removal of legacy UI paths and any auth retirement as separately reviewed follow-up work.

## Evidence record template

For each completed item record: date, implementation revision(s), environment/build URL, commands/checks, observed results, visual deviations, unresolved issues, and links to relevant artifacts. Do not record credentials, personal data, or reset links.
