# Brewtal Drupal Local Development Setup Guide

This document provides complete instructions for setting up and running the Brewtal Drupal 11 coffee shop application locally. It synchronizes your local environment with the Acquia Cloud platform.

**Last Updated:** May 16, 2026  
**Drupal Version:** 11.3.8  
**PHP Version Required:** 8.3+  
**Theme:** coffee_journal (custom)  
**Cloud Platform:** Acquia Cloud Next

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Initial Setup (First Time Only)](#initial-setup-first-time-only)
3. [Quick Start (Subsequent Times)](#quick-start-subsequent-times)
4. [Accessing the Site](#accessing-the-site)
5. [Syncing with Cloud](#syncing-with-cloud)
6. [Known Issues & Fixes](#known-issues--fixes)
7. [Troubleshooting](#troubleshooting)
8. [Useful Commands](#useful-commands)

---

## Prerequisites

Before you begin, ensure you have the following installed on your system:

### Required Software
- **PHP 8.3+** - Check with: `php -v`
- **MySQL 8.0+** - Check with: `mysql -u root -e "SELECT VERSION();"`
- **Composer** - Check with: `composer -v`
- **Acquia CLI** - Check with: `acli -v`
- **Git** - Check with: `git -v`

### Cloud Access
- Active Acquia Cloud account with access to **Brewtal** application (UUID: `04f18e5e-3e19-4698-ac58-c162df025345`)
- SSH key configured for Acquia Cloud access
- Acquia CLI authenticated: `acli auth:login`

### Installation

If you're missing any prerequisites, install them:

```bash
# macOS (using Homebrew)
brew install php mysql composer git

# Verify Acquia CLI is installed
acli --version

# If not, install: https://docs.acquia.com/acquia-cli/install/
```

---

## Initial Setup (First Time Only)

### Step 1: Clone and Navigate to Project

```bash
git clone <repository-url> brewtal
cd brewtal
```

### Step 2: Install PHP Dependencies

```bash
composer install
```

This installs all Drupal core, modules, and development dependencies. The process may take 3-5 minutes.

### Step 3: Create MySQL Database

```bash
# Create database and user for local development
mysql -u root -e "CREATE DATABASE IF NOT EXISTS drupal; \
  CREATE USER IF NOT EXISTS 'drupal'@'localhost' IDENTIFIED BY 'drupal'; \
  GRANT ALL PRIVILEGES ON drupal.* TO 'drupal'@'localhost'; \
  FLUSH PRIVILEGES;"
```

**Credentials:**
- Database: `drupal`
- Username: `drupal`
- Password: `drupal`
- Host: `localhost`

### Step 4: Verify Database Configuration

The `docroot/sites/default/settings/local.settings.php` file should already contain the database configuration. Verify it exists and is correct:

```bash
cat docroot/sites/default/settings/local.settings.php | grep -A 10 "Database configuration"
```

Expected output should show:
```php
$databases['default']['default'] = [
  'database' => 'drupal',
  'username' => 'drupal',
  'password' => 'drupal',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
];
```

### Step 5: Sync Database from Cloud

Pull the latest database from the Cloud development environment:

```bash
acli pull:database --on-demand
```

If you get import errors, manually download and import:

```bash
# Download backup without importing
acli pull:database --on-demand --no-import

# Find the backup file
BACKUP_FILE=$(ls -t /var/folders/2d/1t_5r8554x1b_bqktwk027740000gq/T/dev-eeschandan1-*.sql.gz | head -1)

# Drop and recreate database
mysql -u drupal -pdrupal -e "DROP DATABASE drupal; CREATE DATABASE drupal;"

# Import the backup
gunzip -c "$BACKUP_FILE" | mysql -u drupal -pdrupal drupal
```

### Step 6: Sync Public Files from Cloud

```bash
acli pull:files
```

This syncs all media files, images, and assets (7-8 MB).

### Step 7: Rebuild Drupal Cache

```bash
cd brewtal  # if not already in project root
./vendor/bin/drush cache:rebuild
```

**Expected output:**
```
 [success] Cache rebuild complete.
```

### Step 8: Start the Development Server

```bash
cd docroot
php -S localhost:8000 &
```

This starts the PHP built-in web server on port 8000.

**Alternative (with output logging):**
```bash
nohup php -S localhost:8000 > /tmp/drupal-server.log 2>&1 &
```

### Step 9: Generate Admin Login Link

```bash
cd ..  # back to project root
./vendor/bin/drush user:login admin --uri=http://localhost:8000
```

Copy the generated URL and open it in your browser to log in.

---

## Quick Start (Subsequent Times)

If you've already completed the initial setup, here's how to get started quickly:

### Step 1: Start the Server

```bash
cd /Users/shivam.chandan/brewtal/docroot
php -S localhost:8000 &
```

Or if the server is already running from a previous session, verify it's up:

```bash
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost:8000
# Should return: HTTP 302 (redirect to login)
```

### Step 2: Get Admin Access

Generate a fresh login link:

```bash
cd /Users/shivam.chandan/brewtal
./vendor/bin/drush user:login admin --uri=http://localhost:8000
```

### Step 3: Open in Browser

Click the generated URL or paste it manually:

```
http://localhost:8000/user/reset/1/...
```

---

## Accessing the Site

### Homepage

```
http://localhost:8000
```

**Note:** The homepage redirects to `/user/login` for authenticated users due to Cloud database security settings.

### Admin Dashboard

```
http://localhost:8000/admin
```

Login using the link from `drush user:login` command above.

### Content Pages

Once logged in, you can access:
- **Coffee Products:** `/admin/content?type=coffee_bean` (8 products synced from Cloud)
- **User Profiles:** `/user/profile`
- **Themes:** `/admin/appearance`
- **Modules:** `/admin/modules`

### Coffee Products

The site includes **8 coffee bean products**:
1. Ethiopia Yirgacheffe Natural
2. Colombia Geisha
3. Kenya Kirinyaga AA
4. (and 5 more varieties)

View them with:
```bash
./vendor/bin/drush sql:query "SELECT nid, title FROM node_field_data WHERE type='coffee_bean';"
```

---

## Syncing with Cloud

### Pull Latest from Cloud Development Environment

**Sync everything (code, database, files):**
```bash
acli pull:all
```

**Sync only database:**
```bash
acli pull:database --on-demand
```

**Sync only files:**
```bash
acli pull:files
```

**Sync only code:**
```bash
acli pull:code
```

### Push Changes to Cloud

**Push local database to Cloud (CAUTION - overwrites Cloud DB):**
```bash
acli push:database
```

**Push local files to Cloud:**
```bash
acli push:files
```

**Deploy code as artifact:**
```bash
acli push:artifact --destination-git-branch=deploy/main
```

---

## Known Issues & Fixes

### Issue 1: Route "coffee_journal_access.profile" Does Not Exist

**Symptom:**
```
Symfony\Component\Routing\Exception\RouteNotFoundException: 
Route "coffee_journal_access.profile" does not exist
```

**Root Cause:** Custom controller classes were redeclaring readonly properties already defined in the parent `ControllerBase` class (PHP 8.3+ compatibility issue).

**Fix Applied:** 
- Modified `docroot/modules/custom/coffee_journal_access/src/Controller/ProfileController.php`
- Modified `docroot/modules/custom/coffee_journal_api/src/Controller/UserStateController.php`

**Status:** ✅ FIXED in current codebase

If you encounter this again, rebuild cache:
```bash
./vendor/bin/drush cache:rebuild
```

### Issue 2: Database Import Fails with SQL Syntax Error

**Symptom:**
```
ERROR 1064 (42000) at line 1: You have an error in SQL syntax
```

**Solution:**
```bash
# Recreate the database
mysql -u drupal -pdrupal -e "DROP DATABASE drupal; CREATE DATABASE drupal;"

# Download backup without importing
acli pull:database --on-demand --no-import

# Find and import manually
BACKUP_FILE=$(ls -t /var/folders/2d/1t_5r8554x1b_bqktwk027740000gq/T/dev-eeschandan1-*.sql.gz | head -1)
gunzip -c "$BACKUP_FILE" | mysql -u drupal -pdrupal drupal
```

### Issue 3: PHP Server Not Responding

**Symptom:**
```
Connection refused at http://localhost:8000
```

**Solution:**
```bash
# Check if server is running
ps aux | grep "php -S" | grep -v grep

# If not running, start it
cd docroot
php -S localhost:8000 &

# Verify it's responding
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost:8000
```

---

## Troubleshooting

### Module Installation Issues

If a module fails to enable:

```bash
# Check module status
./vendor/bin/drush pm:list

# Try enabling with more output
./vendor/bin/drush pm:enable module_name -vvv

# Check error logs
./vendor/bin/drush watchdog:show
```

### Cache/Configuration Issues

```bash
# Clear all caches
./vendor/bin/drush cache:rebuild

# Clear specific cache
./vendor/bin/drush cache:clear render
./vendor/bin/drush cache:clear module
./vendor/bin/drush cache:clear theme
```

### Database Connection Issues

```bash
# Test database connection
mysql -u drupal -pdrupal drupal -e "SELECT 1;"

# Check Drupal database status
./vendor/bin/drush status | grep -A 5 Database

# Verify settings are loaded
./vendor/bin/drush php:eval "print json_encode(\$GLOBALS['databases'], JSON_PRETTY_PRINT);"
```

### Permission Issues

If you get permission errors:

```bash
# Check file permissions
ls -la docroot/sites/default/

# Fix permissions
chmod -R 755 docroot/sites/default/files
chmod -R 755 files-private/
```

### Logs and Debugging

**PHP Server Logs:**
```bash
tail -f /tmp/drupal-server.log
```

**Drupal Logs:**
```bash
# View recent errors
./vendor/bin/drush watchdog:show --limit=10

# Filter by severity
./vendor/bin/drush watchdog:show --severity=error
```

**MySQL Logs:**
```bash
# macOS
tail -f /usr/local/var/mysql/$(hostname).err
```

---

## Useful Commands

### Drush Commands

```bash
# Get admin login link
./vendor/bin/drush user:login admin --uri=http://localhost:8000

# Reset admin password
./vendor/bin/drush user:password admin "NewPassword123"

# Check site status
./vendor/bin/drush status

# List all modules
./vendor/bin/drush pm:list

# Enable a module
./vendor/bin/drush pm:enable module_name -y

# Disable a module
./vendor/bin/drush pm:disable module_name -y

# Run database queries
./vendor/bin/drush sql:query "SELECT COUNT(*) FROM users;"

# View watchdog logs
./vendor/bin/drush watchdog:show --limit=20

# Clear cache
./vendor/bin/drush cache:rebuild

# Sync config
./vendor/bin/drush config:export
./vendor/bin/drush config:import
```

### Acquia CLI Commands

```bash
# List Cloud environments
acli api:applications:environment-list

# Pull from Cloud dev environment
acli pull:all
acli pull:database --on-demand
acli pull:files
acli pull:code

# Push to Cloud
acli push:database
acli push:files
acli push:artifact

# SSH into Cloud environment
acli ssh

# Tail Cloud logs
acli app:log:tail
```

### MySQL Commands

```bash
# Connect to local database
mysql -u drupal -pdrupal drupal

# Count content
mysql -u drupal -pdrupal drupal -e "SELECT COUNT(*) FROM node;"

# View coffee products
mysql -u drupal -pdrupal drupal -e \
  "SELECT nid, title FROM node_field_data WHERE type='coffee_bean';"

# List users
mysql -u drupal -pdrupal drupal -e "SELECT uid, name, mail FROM users;"

# Check theme config
mysql -u drupal -pdrupal drupal -e \
  "SELECT * FROM config WHERE name='system.theme';"
```

### Server Management

```bash
# Start server (foreground)
cd docroot
php -S localhost:8000

# Start server (background)
cd docroot
nohup php -S localhost:8000 > /tmp/drupal-server.log 2>&1 &

# Check if server is running
ps aux | grep "php -S" | grep -v grep

# Stop server
pkill -f "php -S localhost:8000"

# Test server
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost:8000
```

---

## Cloud Application Details

### Brewtal Application

- **Name:** Brewtal
- **UUID:** `04f18e5e-3e19-4698-ac58-c162df025345`
- **Platform:** Acquia Cloud Next
- **Region:** ap-southeast-1 (Singapore)

### Environments

| Environment | Domain | URL |
|---|---|---|
| Development | eeschandan1dev | https://eeschandan1dev.prod.acquia-sites.com |
| Staging | eeschandan1test | https://eeschandan1test.prod.acquia-sites.com |
| Production | eeschandan1 | https://eeschandan1.prod.acquia-sites.com |

### Custom Modules

1. **coffee_journal** - Main theme and branding
2. **coffee_journal_api** - REST API for user state (`GET /api/user/me`)
3. **coffee_journal_access** - User access control and profile management

---

## Development Workflow

### Typical Development Workflow

1. **Start Work**
   ```bash
   cd /Users/shivam.chandan/brewtal
   acli pull:all  # Sync latest from Cloud
   ./docroot && php -S localhost:8000 &
   ```

2. **Get Admin Access**
   ```bash
   cd ..
   ./vendor/bin/drush user:login admin --uri=http://localhost:8000
   ```

3. **Make Changes**
   - Edit code/configuration locally
   - Test in http://localhost:8000
   - Commit to git

4. **Push Changes**
   ```bash
   git add .
   git commit -m "Your changes"
   git push origin main
   acli push:artifact --destination-git-branch=tags/deploy-main
   ```

5. **Stop Working**
   ```bash
   pkill -f "php -S localhost:8000"
   ```

---

## Git Workflow

### Before Pushing

```bash
# Check status
git status

# Add changes
git add .

# Commit with descriptive message
git commit -m "feat: add new coffee product feature"

# Push to origin
git push origin feature-branch
```

### Recommended Branches

- `main` - Production-ready code
- `develop` - Development branch
- `feature/*` - Feature branches (`feature/coffee-products`)
- `bugfix/*` - Bug fix branches (`bugfix/route-error`)
- `hotfix/*` - Emergency production fixes

---

## Performance Tips

### Enable Development Mode Caching (Optional)

Disable some caches during development for faster iteration:

```bash
# In docroot/sites/development.services.yml, modify:
services:
  cache.backend.null:
    class: Drupal\Core\Cache\NullBackend
    
# Then rebuild cache
./vendor/bin/drush cache:rebuild
```

### Monitor Database Queries

```bash
# Enable query logging in settings.local.php
$settings['database_log_queries'] = TRUE;

# View logs
tail -f /tmp/query.log
```

### Check Module Dependencies

```bash
# Before disabling a module
./vendor/bin/drush pm:uninstall module_name --simulate

# Check module info
./vendor/bin/drush pm:info coffee_journal
```

---

## Additional Resources

- **Drupal Documentation:** https://www.drupal.org/docs
- **Drupal 11 API:** https://api.drupal.org/api/drupal/11
- **Acquia CLI Documentation:** https://docs.acquia.com/acquia-cli/
- **Acquia Cloud Next:** https://docs.acquia.com/acquia-cloud/
- **Drush Documentation:** https://www.drush.org/latest/commands/

---

## Contributing

When working on this project:

1. **Always pull from Cloud first** before starting work
2. **Test locally thoroughly** before pushing
3. **Use meaningful commit messages** for git history
4. **Document any custom modules** with inline comments
5. **Update this file** if you add new steps or change setup

---

## Support & Issues

For issues or questions:

1. Check the **Troubleshooting** section above
2. Review **Known Issues & Fixes** section
3. Check **Drupal watchdog logs:** `./vendor/bin/drush watchdog:show`
4. Check **PHP error logs:** `tail -f /tmp/drupal-server.log`
5. Submit issues to the project repository

---

## Checklist: First Time Setup

- [ ] PHP 8.3+ installed
- [ ] MySQL 8.0+ installed
- [ ] Composer installed
- [ ] Acquia CLI installed and authenticated
- [ ] Project cloned: `git clone`
- [ ] Dependencies installed: `composer install`
- [ ] Database created: `mysql create...`
- [ ] Database synced: `acli pull:database --on-demand`
- [ ] Files synced: `acli pull:files`
- [ ] Cache rebuilt: `./vendor/bin/drush cache:rebuild`
- [ ] Server started: `php -S localhost:8000`
- [ ] Login link generated: `./vendor/bin/drush user:login admin`
- [ ] Homepage accessible: `http://localhost:8000`
- [ ] Logged in successfully ✅

---

## Checklist: Starting Fresh Session

- [ ] Navigate to project: `cd /Users/shivam.chandan/brewtal`
- [ ] Start server: `php -S localhost:8000 &` (in docroot)
- [ ] Get login link: `./vendor/bin/drush user:login admin --uri=http://localhost:8000`
- [ ] Open link in browser
- [ ] Begin development ✅

---

**Happy brewing! ☕**
