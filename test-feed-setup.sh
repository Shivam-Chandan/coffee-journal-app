#!/bin/bash
# Quick diagnostic script to verify global feed setup

echo "🔍 GLOBAL FEED SETUP DIAGNOSTIC"
echo "================================"
echo ""

# 1. Check if Drupal is accessible
echo "1. Checking Drupal installation..."
if [ -f "docroot/core/install.php" ]; then
    echo "   ✓ Drupal found at docroot/"
else
    echo "   ✗ Drupal not found"
    exit 1
fi

# 2. Check if config files exist
echo ""
echo "2. Checking brew_recipe config files..."
CONFIG_DIR="config/default"
EXPECTED_FILES=(
    "node.type.brew_recipe.yml"
    "field.storage.node.field_is_public.yml"
    "field.field.node.brew_recipe.field_is_public.yml"
    "field.field.node.brew_recipe.field_coffee_weight.yml"
    "field.field.node.brew_recipe.field_water_weight.yml"
    "field.field.node.brew_recipe.field_method.yml"
    "field.field.node.brew_recipe.field_coffee_bean_ref.yml"
    "comment.type.brew_recipe_notes.yml"
    "core.entity_form_display.node.brew_recipe.default.yml"
    "core.entity_view_display.node.brew_recipe.default.yml"
    "core.entity_view_display.node.brew_recipe.teaser.yml"
)

MISSING=0
for file in "${EXPECTED_FILES[@]}"; do
    if [ -f "$CONFIG_DIR/$file" ]; then
        echo "   ✓ $file"
    else
        echo "   ✗ $file (MISSING)"
        MISSING=$((MISSING + 1))
    fi
done

if [ $MISSING -eq 0 ]; then
    echo "   → All config files present ✓"
else
    echo "   → $MISSING files missing"
fi

# 3. Check if code changes exist
echo ""
echo "3. Checking code changes..."

if grep -q "brew_recipe" "docroot/modules/custom/brew_privacy/brew_privacy.module"; then
    echo "   ✓ brew_privacy.module updated"
else
    echo "   ✗ brew_privacy.module NOT updated"
fi

if grep -q "global_feed" "docroot/modules/custom/coffee_journal_api/coffee_journal_api.routing.yml"; then
    echo "   ✓ routing.yml has global_feed route"
else
    echo "   ✗ routing.yml missing global_feed route"
fi

if [ -f "docroot/modules/custom/coffee_journal_api/src/Controller/FeedController.php" ]; then
    echo "   ✓ FeedController.php created"
else
    echo "   ✗ FeedController.php NOT found"
fi

if grep -q "cjGlobalFeed" "docroot/themes/custom/coffee_journal/js/app.js"; then
    echo "   ✓ cjGlobalFeed behavior in app.js"
else
    echo "   ✗ cjGlobalFeed NOT in app.js"
fi

if grep -q "cj-sidebar-nav" "docroot/themes/custom/coffee_journal/css/coffee-theme.css"; then
    echo "   ✓ Sidebar CSS added to coffee-theme.css"
else
    echo "   ✗ Sidebar CSS NOT in coffee-theme.css"
fi

# 4. Check templates
echo ""
echo "4. Checking templates..."

TEMPLATES=(
    "docroot/themes/custom/coffee_journal/templates/page/page--front.html.twig"
    "docroot/themes/custom/coffee_journal/templates/coffee-journal-sidebar-nav.html.twig"
    "docroot/themes/custom/coffee_journal/templates/node/node--brew-recipe--teaser.html.twig"
)

for template in "${TEMPLATES[@]}"; do
    if [ -f "$template" ]; then
        echo "   ✓ $(basename $template)"
    else
        echo "   ✗ $(basename $template) (MISSING)"
    fi
done

# 5. Git status
echo ""
echo "5. Git status..."
COMMIT_MSG=$(git log -1 --pretty=%B | head -n1)
if [[ "$COMMIT_MSG" == *"global community feed"* ]] || [[ "$COMMIT_MSG" == *"brew_recipe"* ]]; then
    echo "   ✓ Latest commit: $COMMIT_MSG"
else
    echo "   ⚠ Latest commit doesn't mention feed/brew_recipe"
    git log -1 --oneline
fi

echo ""
echo "================================"
echo "✨ Next steps:"
echo "1. drush cache:rebuild"
echo "2. drush config:import -y"
echo "3. drush cache:rebuild"
echo "4. Log in and visit: http://your-site.local/"
echo "5. Open browser console (F12) to check for JS errors"
echo ""
echo "See TESTING_GUIDE.md for detailed testing instructions"
