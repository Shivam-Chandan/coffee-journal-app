#!/usr/bin/env bash
set -euo pipefail
SITE="http://localhost:8000"
COOKIEJAR=$(mktemp)
TMPDIR=$(mktemp -d)
echo "Using cookie jar: $COOKIEJAR"

# 1) Fetch login form
echo "Fetching login page..."
curl -s -c "$COOKIEJAR" "$SITE/user/login" -o "$TMPDIR/login.html"

# Extract hidden form fields: form_build_id, form_id
FORM_BUILD_ID=$(perl -ne 'print "$1\n" and exit if /name="form_build_id" value="([^"]+)"/i' "$TMPDIR/login.html" || true)
FORM_ID=$(perl -ne 'print "$1\n" and exit if /name="form_id" value="([^"]+)"/i' "$TMPDIR/login.html" || true)

if [ -z "$FORM_BUILD_ID" ]; then
  echo "Could not find form_build_id in login page; aborting."; exit 1
fi

# 2) Post login
echo "Posting login for opencode..."
resp=$(curl -s -b "$COOKIEJAR" -c "$COOKIEJAR" -X POST \
  -F "name=opencode" \
  -F "pass=opencode123" \
  -F "form_id=$FORM_ID" \
  -F "form_build_id=$FORM_BUILD_ID" \
  -F "op=Log in" \
  "$SITE/user/login")

# Check if login succeeded by requesting the user page and checking for username
homepage=$(curl -s -b "$COOKIEJAR" "$SITE/user")
if echo "$homepage" | grep -q "Log out"; then
  echo "Login appears successful (found Log out link)."
else
  if echo "$homepage" | grep -q "opencode"; then
    echo "Login successful (username found)."
  else
    echo "Login failed. Exiting."; exit 1
  fi
fi

# 3) Create a coffee bean via add form
echo "Fetching coffee add form to extract form tokens..."
curl -s -b "$COOKIEJAR" "$SITE/node/add/coffee_bean" -o "$TMPDIR/coffee_add.html"

ACTION=$(perl -ne 'print "$1\n" and exit if /<form[^>]+action="([^"]+)"/i' "$TMPDIR/coffee_add.html" || true)
FBID=$(perl -ne 'print "$1\n" and exit if /name="form_build_id" value="([^"]+)"/i' "$TMPDIR/coffee_add.html" || true)
FID=$(perl -ne 'print "$1\n" and exit if /name="form_id" value="([^"]+)"/i' "$TMPDIR/coffee_add.html" || true)

if [ -z "$ACTION" ]; then ACTION="/node/add/coffee_bean"; fi

echo "Submitting new coffee bean..."
resp=$(curl -s -b "$COOKIEJAR" -c "$COOKIEJAR" -X POST \
  -F "title[0][value]=Test Coffee from CI" \
  -F "form_build_id=$FBID" \
  -F "form_id=$FID" \
  -F "op=Save" \
  "$SITE$ACTION" )

# Try to determine new node id from response or my coffees list
newnid=$(echo "$resp" | grep -oP '/node/\K[0-9]+' | head -n1 || true)
if [ -z "$newnid" ]; then
  LIST=$(curl -s -b "$COOKIEJAR" "$SITE/my-coffees")
  newnid=$(echo "$LIST" | grep -oP '/node/\K[0-9]+(?=\")' | head -n1 || true)
fi

if [ -z "$newnid" ]; then
  echo "Failed to determine coffee node id. Aborting test."; exit 1
fi

echo "Created coffee node id: $newnid"

# 4) Open brew_recipe add form with prefill param
echo "Fetching brew_recipe add form prefilling coffee nid: $newnid"
curl -s -b "$COOKIEJAR" "$SITE/node/add/brew_recipe?field_coffee_bean_ref_target_id=$newnid" -o "$TMPDIR/recipe_add.html"

if grep -q 'name="title"' "$TMPDIR/recipe_add.html" || grep -q 'field_coffee_bean_ref' "$TMPDIR/recipe_add.html"; then
  echo "Recipe add form loaded successfully with prefill param."
else
  echo "Recipe add form did not render as expected. Showing snippet:";
  sed -n '1,160p' "$TMPDIR/recipe_add.html"; exit 1
fi

RACTION=$(perl -ne 'print "$1\n" and exit if /<form[^>]+action="([^"]+)"/i' "$TMPDIR/recipe_add.html" || true)
RFBID=$(perl -ne 'print "$1\n" and exit if /name="form_build_id" value="([^"]+)"/i' "$TMPDIR/recipe_add.html" || true)
RFID=$(perl -ne 'print "$1\n" and exit if /name="form_id" value="([^"]+)"/i' "$TMPDIR/recipe_add.html" || true)
if [ -z "$RACTION" ]; then RACTION="/node/add/brew_recipe"; fi

echo "Submitting new recipe linked to coffee $newnid..."
recipe_resp=$(curl -s -b "$COOKIEJAR" -X POST \
  -F "title[0][value]=Test Recipe from CI" \
  -F "field_brew_method[0][value]=V60" \
  -F "field_coffee_weight[0][value]=18" \
  -F "field_water_weight[0][value]=300" \
  -F "field_coffee_bean_ref[0][target_id]=$newnid" \
  -F "form_build_id=$RFBID" \
  -F "form_id=$RFID" \
  -F "op=Save" \
  "$SITE$RACTION")

newrecipe_nid=$(echo "$recipe_resp" | grep -oP '/node/\K[0-9]+' | head -n1 || true)
if [ -z "$newrecipe_nid" ]; then
  echo "Could not detect recipe nid in response. Attempting JSON:API search for title..."
  token=$(curl -s -b "$COOKIEJAR" "$SITE/session/token" || true)
  api=$(curl -s -b "$COOKIEJAR" -H "Accept: application/vnd.api+json" "$SITE/jsonapi/node/brew_recipe?filter[title][op]=CONTAINS&filter[title][val]=Test%20Recipe%20from%20CI" || true)
  echo "JSONAPI search result length:"
  echo "$api" | jq '.data | length' || true
fi

if [ -n "$newrecipe_nid" ]; then
  echo "Recipe created with nid: $newrecipe_nid"
else
  echo "Recipe creation may have failed. Response snippet:"; echo "$recipe_resp" | sed -n '1,200p'
fi

# Cleanup
rm -rf "$TMPDIR"
rm -f "$COOKIEJAR"
