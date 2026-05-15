# 🚀 Quick Start — Local Testing

## 30-Second Setup

```bash
cd /Users/shivam.chandan/brewtal
drush cache:rebuild
drush config:import -y
drush cache:rebuild
```

Done! ✓

---

## 3 Things to Check Immediately

### 1. **Homepage Feed (/) **
- Log in → Visit `http://your-local-site.test/`
- **Should see:**
  - Community Feed page
  - Sidebar nav on the left (desktop) or hamburger menu (mobile)
  - Empty state: "No recipes shared yet..."

### 2. **Sidebar Navigation**
- **Desktop:** Fixed left sidebar with Feed, My Coffees, Profile links
- **Mobile:** Hamburger menu (☰) that expands/collapses on click

### 3. **Node Type Exists**
- Visit `/admin/structure/types`
- **Should see:** "Brew Recipe" in the list
- All 7 fields present (title, method, coffee_weight, water_weight, is_public, coffee_bean_ref, community_notes)

---

## Create Test Recipes (5 minutes)

1. Go to `/node/add/brew_recipe`
2. Fill in:
   - **Title:** "Perfect V60"
   - **Coffee Bean:** Select from dropdown (or `/node/add/coffee_bean` first)
   - **Brew Method:** "V60"
   - **Coffee Weight:** 18
   - **Water Weight:** 300
   - **☑ Public (Share to Feed):** CHECKED
3. Save
4. **Repeat 4-5 more times** with different brew methods

Then visit `/` and **you should see your recipes on the feed! ✓**

---

## What to Look For

### ✅ Good Signs
- [ ] Recipes appear in a grid layout
- [ ] Cards show: title, author, method, weights, ratio
- [ ] Cards have nice hover effects (lift up, glow)
- [ ] Sidebar nav links work
- [ ] No red errors in browser console (F12 → Console)

### ⚠️ Issues to Watch For
- [ ] 404 on `/` → Run `drush cache:rebuild` again
- [ ] Blank feed → Create some public recipes first
- [ ] Sidebar broken on desktop → CSS may not have loaded, hard-refresh (Ctrl+Shift+R)
- [ ] "Private" recipe showing up → Privacy issue, check console
- [ ] Hamburger menu not working → JS error in console

---

## Key Files to Understand

| File | What It Does |
|------|-------------|
| `config/default/node.type.brew_recipe.yml` | Defines the brew_recipe content type |
| `docroot/modules/custom/coffee_journal_api/src/Controller/FeedController.php` | Renders the `/` page |
| `docroot/themes/custom/coffee_journal/templates/page/page--front.html.twig` | Feed page template |
| `docroot/themes/custom/coffee_journal/js/app.js` | `cjGlobalFeed` behavior (loads recipes via JSON:API) |
| `docroot/modules/custom/brew_privacy/brew_privacy.module` | Privacy system (node grants) |

---

## Detailed Testing Docs

- **Full Guide:** See `TESTING_GUIDE.md`
- **Checklist:** See `TESTING_CHECKLIST.md`
- **Setup Verification:** Run `./test-feed-setup.sh`

---

## Next After Testing ✨

Once the feed works, the next feature to build:
- **Share/Unshare workflow** on coffee_bean form
- One-click toggle to create/publish brew recipes
- Connect to existing coffee entries

---

**Questions? Check the docs or review the git commit:** `ba36b5edc`
