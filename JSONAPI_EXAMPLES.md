# JSON:API Testing Examples

Use these `curl` commands to test the JSON:API endpoints locally.

## Prerequisites

```bash
# Make sure you're authenticated (session cookie will be included with -b and -c flags)
# First, get a CSRF token from a Drupal session
```

---

## 1. Get All Public Brew Recipes

```bash
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1&sort=-created&page[limit]=20"
```

**Expected response:**
```json
{
  "data": [
    {
      "type": "node--brew_recipe",
      "id": "00000000-0000-0000-0000-000000000001",
      "attributes": {
        "drupal_internal__nid": 1,
        "drupal_internal__vid": 1,
        "langcode": "en",
        "title": "Perfect V60",
        "created": "2026-05-16T12:00:00+00:00",
        "changed": "2026-05-16T12:00:00+00:00",
        "field_method": "V60",
        "field_coffee_weight": "18.00",
        "field_water_weight": 300,
        "field_is_public": true
      },
      "relationships": {
        "uid": {
          "data": {
            "type": "user--user",
            "id": "00000000-0000-0000-0000-000000000001"
          }
        },
        "field_coffee_bean_ref": {
          "data": {
            "type": "node--coffee_bean",
            "id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
          }
        }
      }
    }
  ],
  "included": [
    {
      "type": "user--user",
      "id": "00000000-0000-0000-0000-000000000001",
      "attributes": {
        "name": "admin",
        "mail": "admin@example.com"
      }
    }
  ],
  "meta": {
    "count": 1
  }
}
```

---

## 2. Get Single Brew Recipe by UUID

```bash
# Replace UUID with actual recipe ID
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe/00000000-0000-0000-0000-000000000001"
```

---

## 3. Include Related Resources

```bash
# Get recipes + their related user + coffee bean + comments
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?include=uid,field_coffee_bean_ref,field_community_notes&filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1"
```

---

## 4. Pagination

```bash
# First 20 (default)
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?page[limit]=20&page[offset]=0"

# Next 20
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?page[limit]=20&page[offset]=20"
```

---

## 5. Create a New Brew Recipe (POST)

First, get a CSRF token:

```bash
# In Drupal, POST endpoints require a CSRF token
# Get it from the session/token endpoint
curl -c cookies.txt \
  "http://your-local-site.test/session/token"
```

Then create the recipe:

```bash
curl -X POST \
  -H "Accept: application/vnd.api+json" \
  -H "Content-Type: application/vnd.api+json" \
  -H "X-CSRF-Token: [CSRF_TOKEN_HERE]" \
  -b cookies.txt \
  -d '{
    "data": {
      "type": "node--brew_recipe",
      "attributes": {
        "title": "My New Recipe",
        "field_method": "Moka Pot",
        "field_coffee_weight": "25.50",
        "field_water_weight": 350,
        "field_is_public": true
      },
      "relationships": {
        "field_coffee_bean_ref": {
          "data": {
            "type": "node--coffee_bean",
            "id": "[COFFEE_BEAN_UUID]"
          }
        }
      }
    }
  }' \
  "http://your-local-site.test/jsonapi/node/brew_recipe"
```

**Note:** Requires authenticated user with permission to create brew_recipe nodes.

---

## 6. Update a Recipe (PATCH)

```bash
curl -X PATCH \
  -H "Accept: application/vnd.api+json" \
  -H "Content-Type: application/vnd.api+json" \
  -H "X-CSRF-Token: [CSRF_TOKEN_HERE]" \
  -b cookies.txt \
  -d '{
    "data": {
      "type": "node--brew_recipe",
      "id": "00000000-0000-0000-0000-000000000001",
      "attributes": {
        "field_is_public": false
      }
    }
  }' \
  "http://your-local-site.test/jsonapi/node/brew_recipe/00000000-0000-0000-0000-000000000001"
```

---

## 7. Test Privacy (Anonymous User)

```bash
# Without authentication — should only see public recipes
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1"
```

**Expected:** Only recipes with `field_is_public: true`

```bash
# Try to get private recipe (without authentication)
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe/[PRIVATE_RECIPE_UUID]"
```

**Expected:** 403 Forbidden (Access Denied)

---

## 8. Using jq to Parse JSON

```bash
# Pretty-print and filter results
curl -s -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1" \
  | jq '.data[] | {title: .attributes.title, method: .attributes.field_method, ratio: (.attributes.field_water_weight / .attributes.field_coffee_weight)}'
```

**Output example:**
```json
{
  "title": "Perfect V60",
  "method": "V60",
  "ratio": 16.666666666666668
}
```

---

## 9. Troubleshooting

### 403 Forbidden
```
Error: Access Denied
```
**Solution:** Ensure you're authenticated. The user needs permission to view the node type. Check node grants in `brew_privacy.module`.

### 404 Not Found
```
Error: The resource /node/brew_recipe/[uuid] does not exist.
```
**Solution:** Verify the UUID is correct. Check the actual recipe exists: `drush node:list`

### Invalid JSON
```
Error: The request body is not valid JSON.
```
**Solution:** Ensure POST/PATCH payloads are valid JSON. Check quotes and braces match.

---

## Quick Test Script (Bash)

Save as `test-jsonapi.sh`:

```bash
#!/bin/bash
SITE="http://your-local-site.test"
ACCEPT="Accept: application/vnd.api+json"

echo "📊 JSON:API Diagnostic"
echo "====================="
echo ""

# Test 1: Public recipes
echo "1. Fetching public recipes..."
curl -s -H "$ACCEPT" "$SITE/jsonapi/node/brew_recipe?filter[visibility][condition][path]=field_is_public&filter[visibility][condition][value]=1" | jq '.data | length' && echo "   ✓ Found recipes" || echo "   ✗ Error"

# Test 2: Total recipes (should be more than public)
echo "2. Fetching total recipes..."
curl -s -H "$ACCEPT" "$SITE/jsonapi/node/brew_recipe" | jq '.data | length' && echo "   ✓ Query successful" || echo "   ✗ Error"

# Test 3: Check included relationships
echo "3. Checking relationships (uid, coffee_bean_ref)..."
curl -s -H "$ACCEPT" "$SITE/jsonapi/node/brew_recipe?include=uid,field_coffee_bean_ref&page[limit]=1" | jq '.included | length' && echo "   ✓ Relationships loaded" || echo "   ✗ Error"

echo ""
echo "Done! ✓"
```

---

## Common Query Patterns

### Pattern 1: Get recipes by specific author
```bash
# Requires filtering by uid relationship — this requires custom filter or Views JSON:API
# For now, fetch all and filter client-side
```

### Pattern 2: Get recipes sorted by newest first
```bash
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?sort=-created&page[limit]=20"
```

### Pattern 3: Get recipes with all relationships included
```bash
curl -H "Accept: application/vnd.api+json" \
  "http://your-local-site.test/jsonapi/node/brew_recipe?include=uid,field_coffee_bean_ref,field_community_notes"
```

---

## Notes

- All timestamps are in ISO 8601 format (UTC)
- UUIDs are in RFC 4122 format
- `field_is_public` is a boolean (true/false)
- Numeric fields (`field_coffee_weight`) are strings in JSON:API
- Authentication uses Drupal sessions (cookies)
- CSRF tokens required for POST/PATCH/DELETE
