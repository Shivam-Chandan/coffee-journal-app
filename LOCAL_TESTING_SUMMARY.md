# 📋 Local Testing Summary — What to Expect

## Before You Start

✓ All code committed: `ba36b5edc — feat: implement global community feed with brew_recipe content type`
✓ All config files created in `config/default/`
✓ All templates and behaviors added
✓ CSS styling complete

---

## The Setup Commands (Copy & Paste)

```bash
cd /Users/shivam.chandan/brewtal

# 1. Rebuild caches
drush cache:rebuild

# 2. Import new configuration
drush config:import -y

# 3. Rebuild caches again
drush cache:rebuild
```

**Time:** ~30 seconds
**Expected:** No errors, config import lists ~17 new items

---

## Then What? — The Flow

### FLOW 1: Anonymous User
```
Visit http://your-site.test/
    ↓
FrontPageRedirectSubscriber checks: Is user authenticated?
    ↓
NO → Redirect to /user/login
    ↓
See login page
```

### FLOW 2: Authenticated User
```
Log in → Visit http://your-site.test/
    ↓
FrontPageRedirectSubscriber checks: Is user authenticated?
    ↓
YES → Load page--front.html.twig template
    ↓
cjSidebarNav behavior attaches → Hamburger menu (mobile) or fixed sidebar (desktop)
    ↓
cjGlobalFeed behavior fires:
  - Queries /jsonapi/node/brew_recipe?filter[field_is_public]=1&sort=-created
  - Renders recipe cards into #cj-global-feed container
    ↓
Page displays: Feed with recipe cards!
```

---

## 5-Minute Test Workflow

1. **Setup** (30 sec)
   ```bash
   drush cache:rebuild && drush config:import -y && drush cache:rebuild
   ```

2. **Create test recipes** (2 min)
   - Go to `/node/add/brew_recipe`
   - Create "V60 - Ethiopia" with Public: ☑
   - Create "Secret Espresso" with Public: ☐ (PRIVATE)
   - Create 3-5 more PUBLIC recipes

3. **Visit feed** (1 min)
   - Log in
   - Visit `/`
   - See recipe cards in grid
   - Verify "Secret Espresso" NOT visible

4. **Test mobile** (1 min)
   - Resize browser to mobile width (375px)
   - Click hamburger menu
   - Navigate to different pages

5. **Check console** (30 sec)
   - F12 → Console
   - Verify NO red errors

---

## Visual Breakdown — What You'll See

### Desktop (>1024px)
```
┌─────────────────────────────────────────────────┐
│  ☕ Coffee Journal    [🌙 Theme Toggle]      │  ← Top bar
├─────────────────────────────────────────────────┤
│      │ ☕ Feed        │  Community Feed       │
│      │ 📔 My Coffees  │                       │
│ Nav  │ 👤 Profile     │  ┌─────┬─────┬─────┐ │
│      │                │  │Recipe│Recipe│Recipe│
│ 240px│                │  │Card 1│Card 2│Card 3│
│      │                │  ├─────┼─────┼─────┤
│ Sidebar               │  │Recipe│Recipe│Recipe│
│      │                │  │Card 4│Card 5│Card 6│
│      │                │  └─────┴─────┴─────┘
└─────────────────────────────────────────────────┘
```

### Mobile (<768px)
```
┌──────────────────────┐
│ ☰ ☕ Coffee [🌙]    │  ← Click ☰ to see nav
├──────────────────────┤
│                      │
│  Community Feed      │
│                      │
│ ┌──────────────────┐ │
│ │ Recipe Card 1    │ │
│ └──────────────────┘ │
│ ┌──────────────────┐ │
│ │ Recipe Card 2    │ │
│ └──────────────────┘ │
│ ┌──────────────────┐ │
│ │ Recipe Card 3    │ │
│ └──────────────────┘ │
│                      │
│ [  Load More  ]      │
└──────────────────────┘
```

---

## Recipe Card Details

What you'll see on each card:

```
┌─────────────────────────────┐
│★ Perfect V60               │ ← Hover effect: lifts up
│ by admin                    │
├─────────────────────────────┤
│ Method:        V60          │
│ Coffee:        18g          │
│ Water:         300g         │
│ Ratio:         1:16.67      │
│ 💬 0                        │
├─────────────────────────────┤
│ [View Recipe →]             │
└─────────────────────────────┘
```

---

## Checklist — What to Verify

| Item | Desktop | Mobile | Both |
|------|---------|--------|------|
| Sidebar visible | ✓ | Hamburger only | — |
| Recipe cards render | ✓ | ✓ | — |
| Cards in grid (responsive) | 3 cols | 1 col | — |
| Hover effects work | ✓ | N/A | — |
| Nav links work | ✓ | ✓ | — |
| Private recipes hidden | ✓ | ✓ | — |
| Load More button works | ✓ | ✓ | — |
| No console errors | ✓ | ✓ | — |
| Theme toggle works | ✓ | ✓ | — |

---

## If Something Breaks

| Problem | Check This | Fix |
|---------|-----------|-----|
| 404 on `/` | Route loaded? | `drush route:debug \| grep global_feed` |
| No cards show | Recipes created? | Go `/node/add/brew_recipe` and create some |
| Sidebar broken | CSS loaded? | Hard refresh: Ctrl+Shift+R |
| Red console errors | Browser console | Note error text & line number |
| Private recipes visible | Privacy working? | Check node grants in `brew_privacy.module` |
| Hamburger stuck | Menu element exists? | Check DevTools for `#cj-nav-menu` element |

---

## Successful Test Result

✅ You know it works when:

1. Anonymous user tries `/` → Redirects to `/user/login`
2. Authenticated user visits `/` → Sees Community Feed (no redirect)
3. Feed shows 3-5 recipe cards in responsive grid
4. Each card displays: title, author, method, weights, ratio, comment count
5. Private recipe ("Secret Espresso") does NOT appear
6. Sidebar nav visible (desktop) or hamburger (mobile) works
7. "Load More" button loads additional recipes
8. Browser console clean (F12 → no red errors)
9. Theme toggle (🌙/☀️) switches light/dark mode
10. All nav links (Feed, My Coffees, Profile) work

---

## Next Steps After Testing

Once confirmed working:
- ✨ Implement **Share/Unshare workflow** on coffee_bean form
- Allows users to create brew recipes inline
- One-click toggle to publish/unpublish to feed

---

## Documentation References

- **Quick Start:** `QUICK_START.md`
- **Detailed Testing:** `TESTING_GUIDE.md`
- **Test Checklist:** `TESTING_CHECKLIST.md`
- **JSON:API Examples:** `JSONAPI_EXAMPLES.md`
- **Setup Verification:** `./test-feed-setup.sh`

---

## Git Commit

All changes in single commit: `ba36b5edc`

```
feat: implement global community feed with brew_recipe content type

- Add brew_recipe node type with fields: title, method, coffee_weight, water_weight, is_public, community_notes
- Add field_is_public to coffee_bean and brew_recipe for privacy-aware sharing
- Create FeedController with JSON:API integration for querying public recipes
- Add cjGlobalFeed & cjSidebarNav vanilla JS behaviors for dynamic feed rendering
- Create mobile-friendly sidebar navigation with responsive design
- Add page--front.html.twig template with feed layout and containers
- Create node--brew-recipe--teaser.html.twig card template for recipe display
- Update brew_privacy module to enforce access control for brew_recipe nodes
- Modify FrontPageRedirectSubscriber to show authenticated users feed at /
- Add comprehensive CSS styling for feed cards, navigation, and responsive layout
```

---

**Ready to test? Run the setup commands and check the documentation! 🚀**
