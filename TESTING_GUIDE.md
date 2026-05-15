# Local Testing Guide — Global Community Feed

## Prerequisites
- Local Drupal 11 environment running
- Admin access to `/admin`
- Curl or Postman for JSON:API testing (optional)
- Browser DevTools open (F12) to check console for errors

---

## Step 1: Import Configuration & Clear Caches

```bash
# Navigate to your project root
cd /Users/shivam.chandan/brewtal

# Import configuration (registers new node type, fields, comment type)
drush config:import -y

# Clear all caches
drush cache:rebuild
```

**Expected output:**
- Drush should list all imported config files (brew_recipe type, field storage, displays, etc.)
- Cache rebuild completes without errors

---

## Step 2: Verify brew_recipe Node Type in Admin UI

1. Log in to Drupal admin: `https://your-local-site/admin`
2. Navigate to **Structure → Content types** (`/admin/structure/types`)
3. **Look for:** "Brew Recipe" in the list
4. **Click on it** to verify fields:
   - Title
   - Coffee Bean (entity reference)
   - Brew Method (string)
   - Coffee Weight (decimal)
   - Water Weight (integer)
   - Public (Share to Feed) — boolean checkbox
   - Community Notes — comment field

**✓ Success:** All fields visible in the form display

---

## Step 3: Test JSON:API Endpoint

### Option A: Using Curl
```bash
# Test JSON:API endpoint for brew_recipe
curl -H "Accept: application/vnd.api+json" \
  "https://your-local-site/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1&sort=-created&page[limit]=20"
```

### Option B: Browser
1. Open browser DevTools (F12)
2. Go to **Console** tab
3. Paste this code to test:
```javascript
fetch('/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1&sort=-created&page[limit]=20', {
  headers: { 'Accept': 'application/vnd.api+json' },
  credentials: 'same-origin'
})
  .then(r => r.json())
  .then(d => console.log('Recipes found:', d.data.length, d))
  .catch(e => console.error('Error:', e))
```

**Expected output:**
- Empty array `[]` initially (no recipes created yet)
- Status 200 OK
- Proper JSON:API structure with `data`, `included`, `meta` fields

**⚠️ If you get 403 Forbidden:**
- Verify you're logged in as an authenticated user
- Check node grants: user should have `brew_privacy_author` or `brew_privacy_public` grants

---

## Step 4: Navigate to the Feed Homepage

1. **Log in** as an authenticated user
2. Go to: `https://your-local-site/`
3. **Verify redirect doesn't happen** (you should see the feed, not redirect to `/user/login`)

**Look for:**
- ✓ Page title: "Community Feed" or "Global Feed"
- ✓ Sidebar navigation with hamburger menu on mobile (<768px width)
- ✓ Empty state message: "No recipes shared yet..."
- ✓ "Load More" button present
- ✓ Coffee Journal logo and theme toggle in top bar

**If you see 404:**
- Make sure routes are loaded: `drush route:debug | grep global_feed`
- Verify FeedController.php is readable in the module
- Clear caches again: `drush cache:rebuild`

**If redirect to /user/login still happens:**
- Check FrontPageRedirectSubscriber.php was updated correctly
- Run: `drush cache:rebuild`
- Verify user is actually authenticated (check user icon in top bar)

---

## Step 5: Create Test Brew Recipe Nodes

### Option A: Via Admin UI
1. Log in as admin
2. Go to **Content → Add content** (`/node/add`)
3. Click **"Brew Recipe"**
4. Fill in:
   - **Title:** "Perfect V60"
   - **Coffee Bean:** Search and select a coffee_bean node (or create one first if needed)
   - **Brew Method:** "V60"
   - **Coffee Weight:** 18
   - **Water Weight:** 300
   - **Public (Share to Feed):** ✓ Check this box
   - Click **Save**

5. **Repeat** to create 3-5 test recipes with `Public` checkbox CHECKED

### Option B: Via Drush SQL
```bash
# If you want to quickly create test data:
# (Optional — for advanced users)
drush php < create_test_recipes.php
```

**Expected result:**
- Node created successfully
- Message appears: "Brew Recipe Perfect V60 has been created."

---

## Step 6: Verify Feed Displays Recipe Cards

1. Go back to: `https://your-local-site/`
2. **Refresh the page** (Ctrl+R or Cmd+R)

**Look for:**
- ✓ Recipe cards appear in a grid
- ✓ Card shows:
  - Recipe title (e.g., "Perfect V60")
  - "by [Author Name]"
  - Method: V60
  - Coffee: 18g
  - Water: 300g
  - Ratio: 1:16.67
  - Comment count (💬 0 if no comments yet)
  - Blue "View Recipe" button
- ✓ Cards have hover effect (slight lift/glow)
- ✓ Grid is responsive:
  - **Mobile (<640px):** 1 column
  - **Tablet (640-1024px):** 2 columns
  - **Desktop (>1024px):** 3 columns

**If cards don't appear:**
1. Open **Browser DevTools → Console**
2. Look for JavaScript errors (red text)
3. Check **Network tab:** Is `/jsonapi/node/brew_recipe?...` request successful (200 OK)?
4. Check the response data: Does it have `data` array with items?

---

## Step 7: Test Privacy Controls

### Test 1: Create a PRIVATE recipe (should NOT appear on feed)
1. Create another brew_recipe with **"Public (Share to Feed)": UNCHECKED**
2. Go back to feed (`/`)
3. **Verify:** Only your 5 public recipes appear, not this private one

**If private recipe appears on feed:**
- ⚠️ Privacy system not working — check brew_privacy.module hooks

### Test 2: View JSON:API as Anonymous User
1. **Log out** (click user menu → Log out)
2. Run this in console:
```javascript
fetch('/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1', {
  headers: { 'Accept': 'application/vnd.api+json' }
})
  .then(r => r.json())
  .then(d => console.log('Anonymous sees recipes:', d.data.length))
```

**Expected:**
- Anonymous users still see public recipes (status 200)
- Private recipes filtered out by node grants

### Test 3: Redirect for Anonymous Users
1. Logged out, visit `https://your-local-site/`
2. **Should redirect to:** `https://your-local-site/user/login`

**If no redirect:**
- Check FrontPageRedirectSubscriber again
- Verify it was modified correctly (removed authenticated redirect)

---

## Step 8: Test Responsive Sidebar Navigation

### On Desktop (>768px):
1. Open page at full width
2. **Look for:**
   - ✓ Fixed left sidebar (240px wide)
   - ✓ Navigation items always visible (no hamburger)
   - ✓ Feed content shifted right to accommodate sidebar
   - ✓ Main content still centered with max-width

### On Mobile (<768px):
1. **Resize browser to ~375px width** (Chrome DevTools: Ctrl+Shift+M)
2. **Look for:**
   - ✓ Hamburger menu (☰) in top-left
   - ✓ Navigation items hidden initially
   - ✓ Tap hamburger → menu slides down as overlay
   - ✓ Click "Feed" or "My Coffees" → menu closes
   - ✓ Click outside menu → menu closes

**If sidebar layout broken:**
- Open DevTools → Console, check for CSS errors
- Check that `coffee-theme.css` was updated (new sidebar styles appended)
- Verify Tailwind compilation didn't override custom CSS

---

## Step 9: Check Browser Console for JavaScript Errors

1. Open **DevTools (F12) → Console tab**
2. Reload page
3. **Look for:**
   - ✓ No red error messages
   - ✓ Messages should show:
     - "Drupal behaviors attached"
     - Possibly: "Recipes fetched" or similar logs (if you added console.log in cjGlobalFeed)

**Common issues:**
- ❌ `Cannot read property 'addEventListener' of null` → Element ID not found in HTML
- ❌ `fetch is not defined` → Older browser, but shouldn't happen in modern setup
- ❌ JSON:API 403 errors → Privacy/permission issue

**If you see console errors:**
- Note the exact error message
- Check the line number in app.js
- Verify element IDs match between template and JS (e.g., `#cj-global-feed`, `#cj-load-more`)

---

## Step 10: Test "Load More" Button

1. On the feed page, scroll down
2. **Look for:** "Load More" button
3. **Click it**

**Expected:**
- ✓ More recipe cards load and append to the grid
- ✓ Offset increments (second page shows recipes 20-39, etc.)
- ✓ Button disabled or disappears when no more recipes

**If button doesn't work:**
- Check console for JavaScript errors
- Verify JSON:API query in Network tab is being called
- Ensure you have >20 test recipes created to test pagination

---

## Troubleshooting Checklist

| Issue | Solution |
|-------|----------|
| Brew Recipe type not showing | `drush cache:rebuild` + check `/admin/structure/types` |
| Feed shows 404 | Verify route with `drush route:debug \| grep global_feed` |
| No recipe cards render | Check browser console for JS errors; check Network tab for `/jsonapi/...` request |
| Sidebar broken on desktop | Verify CSS appended to `coffee-theme.css` (new ~400 lines) |
| Private recipes appear on feed | Check `brew_privacy.module` — verify hook_node_access_records handles both `coffee_bean` AND `brew_recipe` |
| JSON:API returns 403 | Verify you're authenticated; check user grants with `drush user:list` |
| Hamburger menu not working | Open console, verify `#cj-nav-toggle` and `#cj-nav-menu` elements exist in HTML |

---

## Quick Command Reference

```bash
# Clear all caches (usually fixes most issues)
drush cache:rebuild

# Import config (one-time setup)
drush config:import -y

# Verify routes registered
drush route:debug | grep global_feed

# Check node types
drush node-type:list

# Create a test user (if needed)
drush user:create testuser --mail="test@example.com" --password="password123"

# Verify authentication in browser console
console: console.log('Authenticated:', drupalSettings.user.uid !== 0)
```

---

## Next: Share/Unshare Workflow

After verifying the feed works, the next feature to build is:
- **One-click toggle** on coffee_bean form to create/unpublish brew recipes
- This allows users to quickly share their brew method without entering a separate form

---

**Happy testing! Let me know what you find or if you run into issues.**
