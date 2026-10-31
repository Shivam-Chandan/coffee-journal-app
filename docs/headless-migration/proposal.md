# Proposal: migrate-headless-frontend

Status: implementation started; local foundation verified. See [execution evidence](execution.md). Full migration acceptance remains pending.

Date: 2026-10-30.

## Why

The mobile-oriented Coffee Journal UI currently depends on Drupal-rendered pages and forms. A persistent frontend will support faster repeat navigation, consistent interaction states, and local drafts while retaining the existing backend and visual identity.

## What Changes

- Build a static React/TypeScript/Vite application with TanStack Router and Query.
- Port existing Tailwind 3 styles, design tokens, Twig layouts, and interactions into React.
- Add versioned Drupal application APIs and same-origin session transport through Vercel.
- Preserve existing identities, ownership, content, and enabled authentication methods during rollout.
- Target zero Vercel functions, with one approved gateway fallback and an absolute maximum of 11.
- Add visual/mobile/API/deployment checks, local drafts, a PWA shell, staged rollout, and rollback.

## Impact

Frontend work will introduce a proposed `frontend/` workspace. Backend work affects custom modules and may require exported configuration, runtime settings, and deployment workflow changes. Existing project guidance must be updated before implementation to reflect the headless boundary. No core/contrib modifications are planned.

The existing traditional UI stays available during the pilot. APIs remain backward compatible while both clients operate. This change does not itself retire Google, migrate accounts, redesign the UI, or replace Drupal storage.

## Artifacts

- [Detailed architecture and migration plan](../headless-migration-plan.md)
- [Ordered implementation checklist](tasks.md)
- [Acceptance requirements and scenarios](specs/headless-app/spec-delta.md)

No existing OpenSpec specification tree or CLI was available during planning. These artifacts follow the repository's `docs/` convention and have been structured for implementation handoff without introducing an OpenSpec toolchain dependency.
