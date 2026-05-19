# Coffee Journal App — Project Context

This file is automatically loaded by Claude Code. Agents should not need to re-explore the codebase for basic context.

## Project Identity

- **Name**: Coffee Journal App
- **Template**: `acquia/drupal-cms-project` (Acquia Drupal CMS)
- **Drupal**: 11.1.1
- **Hosting**: Acquia Cloud
- **Web root**: `docroot/`
- **PHP**: 8.3

## Architecture

### Custom Modules (`docroot/modules/custom/`)

| Module | Purpose |
|---|---|
| `coffee_journal_api` | REST API — `/api/scrape-coffee` (Gemini AI), `/api/places/search` (Google Places), `/api/user/me` |
| `coffee_journal_access` | Node access control — users can only view/edit/delete their own coffee bean entries |

### Custom Theme (`docroot/themes/custom/coffee_journal`)

- Standalone (no base theme)
- 3 regions: `topbar`, `content`, `status_messages`
- Tailwind CSS build → compiled to `coffee-theme.css`
- `app.js` for frontend interactivity

### Custom Install Profile (`docroot/profiles/custom/acquia_drupal_cms_installer`)

Recipe-based guided installer UI. Optional content types: Blog, Case Studies, Events, News, Person Profiles, Projects.

## Key Directories

```
docroot/
  modules/custom/   coffee_journal_api, coffee_journal_access
  themes/custom/    coffee_journal (Tailwind)
  profiles/custom/  acquia_drupal_cms_installer
config/             Drupal config export (drush cex target)
recipes/            30 Drupal CMS recipes
vendor/             Composer-managed PHP dependencies
```

## CI/CD & Environments

**Pipeline**: Acquia Pipelines (`.acquia-pipelines.yml`) — PHP 8.3

Build steps: `setup` → `composer-install` → `tailwind-build` → `artifact-prep`

Post-deploy hooks (run on every environment after deploy):
```
drush config:import && drush cr
```

| Environment | URL |
|---|---|
| dev | eeschandan1dev.prod.acquia-sites.com |
| stage | eeschandan1test.prod.acquia-sites.com |
| prod | eeschandan1.prod.acquia-sites.com |

## Required Environment Variables

These must be set in Acquia Cloud environment variables and locally for full functionality:

```
GOOGLE_CLIENT_ID          # Google OAuth (social_auth)
GOOGLE_CLIENT_SECRET      # Google OAuth (social_auth)
GEMINI_API_KEY            # AI coffee data scraping
GOOGLE_PLACES_API_KEY     # Coffee shop search
```

## Common Commands

```bash
# Install PHP dependencies
composer install

# Build Tailwind CSS for the coffee_journal theme
cd docroot/themes/custom/coffee_journal && npm install && npm run build

# Export/import config
drush cex && drush cim

# Clear caches
drush cr

# SSH into an Acquia environment via Acquia CLI
acli ssh

# Run Drush on a remote environment
acli drush @<env> <command>
```

## Notable Contrib Modules

Beyond standard Drupal CMS defaults:

- `social_auth` / `social_auth_google` — Google OAuth login
- `ai`, `ai_provider_anthropic`, `ai_provider_openai`, `ai_agents` — AI integrations
- `webform` — Forms
- `search_api` — Search infrastructure
- `gin` — Admin theme (webpack build in `docroot/themes/contrib/gin/`)
- `klaro` — Cookie consent management
- `memcache` — Acquia Memcache integration
- `captcha`, `honeypot`, `friendlycaptcha` — Anti-spam

## Drupal Development Rules

This project uses **traditional (non-headless) Drupal**. All code must follow these rules.

### Hard Rules

- **No raw HTML in PHP.** Never `echo` or `print` HTML inside hooks or controllers. Always return Render Arrays.
- **No core/contrib modifications.** Only touch files under `docroot/modules/custom/` and `docroot/themes/custom/`.
- **Strict module/theme separation:**
  - Business logic, Form API, database queries → custom modules
  - Layout markup, CSS classes, template preprocessing → custom theme

### Render Arrays & Theming

- Build UI with nested Render Arrays using `#type` properties (`container`, `table`, `item`, `link`) — not raw tags.
- Pass data to Twig via `#theme` hooks or `#suggestions` only.
- Keep `.html.twig` templates logic-less — HTML scaffolding and variable printing only.
- All string manipulation, entity loading, and data formatting belongs in `template_preprocess_HOOK()` inside the `.theme` file.
- Attach assets per-template: `{{ attach_library('coffee_journal/my_library') }}`

### Form API (FAPI)

- All forms extend `\Drupal\Core\Form\FormBase`.
- Use proper `#type` values (`textfield`, `select`, `number`) with `#states` for dynamic visibility.
- Never hardcode `action` attributes or CSRF tokens — let Drupal's form builder handle them.

### Workflow

1. **Config first.** If a field, view, or block can be created via the UI, do it there and export with `drush cex` rather than writing code.
2. **Cache clears.** Run `drush cr` after modifying any `.yml`, `.module`, `.theme`, or `.twig` file.
3. **Debug safely.** Use `{{ dump(variable) }}` or `{{ kint(variable) }}` (if Devel is active) in Twig for debugging — never commit them.

---

## What NOT to re-explore

- `vendor/` and `docroot/modules/contrib/` — managed by Composer, check `composer.json` instead
- Git history for architectural decisions — use `git log --oneline` or `git show <hash>`
- Passwords / real API key values are never stored in the repo
