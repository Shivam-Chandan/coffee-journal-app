# Testing Checklist — Global Community Feed

## 🚀 Setup (Run These Commands First)

```bash
cd /Users/shivam.chandan/brewtal

# 1. Clear caches
drush cache:rebuild

# 2. Import all new config
drush config:import -y

# 3. Clear caches again
drush cache:rebuild
```

**Expected output:** All commands complete without errors. Config import lists ~17 new items.

---

## ✅ Test Suite

### TEST 1: Homepage Redirect (Anonymous vs Authenticated)
- [ ] **ANONYMOUS:** Log out → Visit `/` → Should **redirect to** `/user/login`
- [ ] **AUTHENTICATED:** Log in → Visit `/` → Should **see feed page** (no redirect)
- [ ] **Check in console:** No JavaScript errors

**Success:** Different behavior based on auth state ✓

---

### TEST 2: Brew Recipe Node Type Exists
- [ ] Visit `/admin/structure/types`
- [ ] **See "Brew Recipe"** in the list
- [ ] Click on it
- [ ] **Verify these fields:**
  - [ ] Title
  - [ ] Coffee Bean (entity reference)
  - [ ] Brew Method (text field)
  - [ ] Coffee Weight (decimal: 5,2)
  - [ ] Water Weight (integer)
  - [ ] Public (Share to Feed) (boolean checkbox)
  - [ ] Community Notes (comment field)

**Success:** All fields present with correct types ✓

---

### TEST 3: JSON:API Endpoint Works
- [ ] Open browser DevTools (F12) → Console
- [ ] Paste this code:
```javascript
fetch('/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1&sort=-created', {
  headers: { 'Accept': 'application/vnd.api+json' },
  credentials: 'same-origin'
})
  .then(r => r.json())
  .then(d => {
    console.log('Status: 200 ✓');
    console.log('Recipes found:', d.data.length);
    console.log('Sample data:', d);
  })
  .catch(e => console.error('ERROR:', e))
```
- [ ] **Expected output:** Status 200, empty array `[]` (no recipes yet)

**Success:** JSON:API endpoint responds correctly ✓

---

### TEST 4: Create Test Brew Recipes
- [ ] Go to `/node/add` (Content → Add content)
- [ ] **Create "Brew Recipe #1"**
  - [ ] Title: "V60 - Ethiopia"
  - [ ] Coffee Bean: *Select from list* (or create a coffee_bean first)
  - [ ] Brew Method: "V60"
  - [ ] Coffee Weight: "18"
  - [ ] Water Weight: "300"
  - [ ] **☑ Check: Public (Share to Feed)**
  - [ ] Save
  - [ ] **See message:** "Brew Recipe V60 - Ethiopia has been created."

- [ ] **Create "Brew Recipe #2" (PRIVATE — no public checkbox)**
  - [ ] Title: "Secret Espresso"
  - [ ] Coffee Bean: *Select*
  - [ ] Brew Method: "Espresso"
  - [ ] Coffee Weight: "20"
  - [ ] Water Weight: "50"
  - [ ] **☐ LEAVE UNCHECKED: Public (Share to Feed)**
  - [ ] Save

- [ ] **Create 3-5 more PUBLIC recipes** (repeat with different brew methods)
  - Methods to try: French Press, Moka Pot, AeroPress, Chemex, Pour Over

**Success:** Multiple recipes created, mix of public/private ✓

---

### TEST 5: Feed Page Displays (Desktop)
- [ ] **Resize browser to FULL WIDTH** (>1024px)
- [ ] Navigate to `/`
- [ ] **Check LAYOUT:**
  - [ ] Left sidebar visible (240px wide, fixed)
  - [ ] Sidebar has nav items: "☕ Feed", "📔 My Coffees", "👤 Profile"
  - [ ] Main content area to the right with feed
  - [ ] Top bar (logo, theme toggle) still visible

- [ ] **Check FEED CONTENT:**
  - [ ] Title: "Community Feed"
  - [ ] Recipe cards in a 3-column grid
  - [ ] Each card shows:
    - [ ] Recipe title (e.g., "V60 - Ethiopia")
    - [ ] Author name (e.g., "by admin")
    - [ ] Method: [method name]
    - [ ] Coffee: [number]g
    - [ ] Water: [number]g
    - [ ] Ratio: 1:[calculated ratio]
    - [ ] 💬 Comment count
    - [ ] Blue "View Recipe" button

- [ ] **Check PRIVATE RECIPE:**
  - [ ] "Secret Espresso" (private) should **NOT** appear in feed
  - [ ] Only public recipes visible

**Success:** Feed displays correctly with proper data ✓

---

### TEST 6: Feed Page Displays (Mobile)
- [ ] **Resize browser to SMALL WIDTH** (DevTools: Ctrl+Shift+M or 375px)
- [ ] Navigate to `/` again
- [ ] **Check LAYOUT:**
  - [ ] Hamburger menu (☰) visible in top-left
  - [ ] Sidebar NOT visible initially
  - [ ] Recipe cards in **1 column**
  - [ ] Top bar still visible

- [ ] **Test HAMBURGER MENU:**
  - [ ] Click hamburger (☰)
  - [ ] Menu slides down showing: Feed, My Coffees, Profile
  - [ ] Click on "My Coffees"
  - [ ] Menu closes automatically
  - [ ] Navigates to `/my-coffees`

- [ ] **Test CLICK OUTSIDE TO CLOSE:**
  - [ ] Go back to feed (`/`)
  - [ ] Open menu again
  - [ ] Click outside the menu (on feed area)
  - [ ] Menu closes

**Success:** Mobile navigation works smoothly ✓

---

### TEST 7: Recipe Card Hover Effects
- [ ] **On DESKTOP (>768px):**
  - [ ] Hover over recipe card
  - [ ] Card should:
    - [ ] Lift up (translateY -4px)
    - [ ] Show shadow
    - [ ] Border becomes orange (primary color)
    - [ ] Top accent line appears

- [ ] **Click recipe title:**
  - [ ] Should navigate to full recipe detail page (`/node/[id]`)

**Success:** Hover effects responsive and clickable ✓

---

### TEST 8: Load More Button
- [ ] **Create 25+ public recipes** (to test pagination)
- [ ] Go to feed
- [ ] Scroll to bottom
- [ ] **Click "Load More" button**
- [ ] More recipe cards append to the grid
- [ ] Visible count increases (should now show recipes 21-40)

**Success:** Pagination works correctly ✓

---

### TEST 9: Privacy Enforcement (JSON:API)
- [ ] In browser console, create a "private" recipe with:
```javascript
// Create a private recipe
const privateRecipe = {
  data: {
    type: 'node--brew_recipe',
    attributes: {
      title: 'Private Brew',
      field_method: 'Test',
      field_coffee_weight: 20,
      field_water_weight: 200,
      field_is_public: false  // PRIVATE
    }
  }
};

// Try to view ALL recipes (public + private)
fetch('/jsonapi/node/brew_recipe', {
  headers: { 'Accept': 'application/vnd.api+json' },
  credentials: 'same-origin'
})
  .then(r => r.json())
  .then(d => {
    console.log('All recipes (should only see public):', d.data.length);
    d.data.forEach(r => console.log('- ', r.attributes.title, 'Public:', r.attributes.field_is_public));
  })
```

- [ ] **Verify output:** Only recipes with `field_is_public: true` appear

**Success:** Node grants filtering works ✓

---

### TEST 10: Browser Console Check
- [ ] Open DevTools (F12) → Console tab
- [ ] **Look for:**
  - [ ] **NO RED ERROR MESSAGES** (errors show in red)
  - [ ] No "Cannot read property" errors
  - [ ] No "fetch is not defined" errors
  - [ ] No "undefined is not a function" errors

- [ ] If you see errors:
  - [ ] Note the error text
  - [ ] Note the line number in app.js
  - [ ] Check that HTML elements match JS selectors (e.g., `#cj-global-feed`)

**Success:** No console errors ✓

---

### TEST 11: Sidebar Navigation Links
- [ ] **Click "Global Feed"** in sidebar
  - [ ] Should navigate to `/` and show feed
  - [ ] Nav item highlighted as "active"

- [ ] **Click "My Coffees"**
  - [ ] Should navigate to `/my-coffees`
  - [ ] Shows your personal coffee journal (existing view)
  - [ ] Nav item highlighted as "active"

- [ ] **Click "Profile"**
  - [ ] Should navigate to `/user/profile` (or your profile page)

**Success:** All nav links work and highlight correctly ✓

---

### TEST 12: Dark/Light Mode Works
- [ ] **Feed page:**
  - [ ] Click theme toggle (🌙 icon in top-right)
  - [ ] Page switches to light mode
  - [ ] Icon changes to ☀️
  - [ ] Recipe cards, sidebar, text all readable in light mode
  - [ ] Click again → dark mode returns
  - [ ] Preference saved in localStorage

**Success:** Theme toggle works across feed ✓

---

## 📊 Summary Checklist

| Test | Status | Notes |
|------|--------|-------|
| Anonymous redirect | ☐ | Should see /user/login |
| Authenticated feed | ☐ | Should see / without redirect |
| brew_recipe type exists | ☐ | 7 fields present |
| JSON:API works | ☐ | Returns 200 with data |
| Public recipes display | ☐ | All public recipes visible |
| Private recipes hidden | ☐ | Private recipes NOT visible |
| Feed layout (desktop) | ☐ | Sidebar + 3-column grid |
| Feed layout (mobile) | ☐ | 1 column + hamburger menu |
| Card hover effects | ☐ | Lift + shadow + border |
| Pagination works | ☐ | Load More button loads more |
| No console errors | ☐ | DevTools shows clean console |
| Nav links work | ☐ | Feed, My Coffees, Profile |
| Dark/light mode | ☐ | Theme toggle works |

---

## 🐛 Debugging Tips

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| 404 on `/` | Route not loaded | `drush cache:rebuild` |
| Feed shows empty | No public recipes | Create test recipes with public checkbox |
| Sidebar broken | CSS not loaded | Check `coffee-theme.css` has ~400 new lines |
| Cards don't render | JS error | Check console for errors, verify `#cj-global-feed` exists in HTML |
| Private recipes visible | Privacy not working | Check `brew_privacy.module` handles `brew_recipe` nodes |
| Hamburger menu stuck | Event listener issue | Check console for `.addEventListener` errors |
| Redirect still happening | FrontPageRedirectSubscriber not updated | Verify code was modified, clear cache |

---

## ✨ What Success Looks Like

When everything works:
1. ✓ Anonymous users redirected to login
2. ✓ Authenticated users see beautiful feed at `/`
3. ✓ Recipe cards display in responsive grid
4. ✓ Sidebar nav available on mobile and desktop
5. ✓ Public recipes appear, private recipes hidden
6. ✓ No JavaScript console errors
7. ✓ Smooth hover effects and interactions
8. ✓ Theme toggle works
9. ✓ "Load More" pagination works
10. ✓ All links navigate correctly

---

**Need help? Check the TESTING_GUIDE.md for more detailed instructions.**
