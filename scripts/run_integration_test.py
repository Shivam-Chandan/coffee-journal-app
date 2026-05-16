#!/usr/bin/env python3
import re
import sys
from urllib.parse import urljoin

SITE = 'http://localhost:8000'
USERNAME = 'opencode'
PASSWORD = 'opencode123'

try:
    import requests
except ImportError:
    print('The requests library is required. Please install with: pip install requests')
    sys.exit(1)

session = requests.Session()

def get_form_fields(html, field_names):
    out = {}
    for name in field_names:
        m = re.search(r'name=["\']%s["\']\s+value=["\']([^"\']*)["\']' % re.escape(name), html)
        out[name] = m.group(1) if m else None
    return out

def main():
    print('Fetching login page...')
    r = session.get(urljoin(SITE, '/user/login'))
    if r.status_code != 200:
        print('Failed to GET login page', r.status_code)
        sys.exit(1)

    fields = get_form_fields(r.text, ['form_build_id', 'form_id'])
    if not fields.get('form_build_id'):
        print('Could not find login form tokens')
        # show snippet
        print(r.text[:1000])
        sys.exit(1)

    print('Posting login...')
    post = {
        'name': USERNAME,
        'pass': PASSWORD,
        'form_build_id': fields['form_build_id'],
        'form_id': fields['form_id'],
        'op': 'Log in'
    }
    r2 = session.post(urljoin(SITE, '/user/login'), data=post)
    if r2.status_code >= 500:
        print('Server error on login POST:', r2.status_code)
        print(r2.text[:2000])
        sys.exit(1)

    # Check login success
    r3 = session.get(urljoin(SITE, '/user'))
    if USERNAME in r3.text or 'Log out' in r3.text:
        print('Login successful')
    else:
        print('Login failed; user page snippet:')
        print(r3.text[:1000])
        sys.exit(1)

    # Create coffee bean
    print('Fetching coffee add form...')
    r = session.get(urljoin(SITE, '/node/add/coffee_bean'))
    fields = get_form_fields(r.text, ['form_build_id', 'form_id'])
    if not fields.get('form_build_id'):
        print('Could not find coffee form tokens'); sys.exit(1)
    post = {
        'title[0][value]': 'Test Coffee from CI',
        'form_build_id': fields['form_build_id'],
        'form_id': fields['form_id'],
        'op': 'Save'
    }
    print('Submitting coffee form...')
    r = session.post(urljoin(SITE, '/node/add/coffee_bean'), data=post, allow_redirects=True)
    # try to find created node id
    m = re.search(r'/node/(\d+)', r.text)
    nid = None
    if m:
        nid = m.group(1)
    else:
        # try my-coffees view
        r2 = session.get(urljoin(SITE, '/my-coffees'))
        m2 = re.search(r'/node/(\d+)', r2.text)
        if m2:
            nid = m2.group(1)

    if not nid:
        print('Could not determine new coffee nid. Aborting.'); sys.exit(1)

    print('Created coffee nid:', nid)

    # Open recipe add form with prefill
    print('Fetching recipe add form with prefill...')
    r = session.get(urljoin(SITE, f'/node/add/brew_recipe?field_coffee_bean_ref_target_id={nid}'))
    if 'field_coffee_bean_ref' not in r.text and 'name="title"' not in r.text:
        print('Recipe add form did not render as expected.')
        print(r.text[:1000])
        sys.exit(1)
    print('Recipe add form loaded.')

    # Extract tokens
    fields = get_form_fields(r.text, ['form_build_id', 'form_id'])
    post = {
        'title[0][value]': 'Test Recipe from CI',
        'field_brew_method[0][value]': 'V60',
        'field_coffee_weight[0][value]': '18',
        'field_water_weight[0][value]': '300',
        'field_coffee_bean_ref[0][target_id]': nid,
        'form_build_id': fields['form_build_id'],
        'form_id': fields['form_id'],
        'op': 'Save'
    }
    print('Submitting recipe form...')
    r = session.post(urljoin(SITE, '/node/add/brew_recipe'), data=post, allow_redirects=True)
    m = re.search(r'/node/(\d+)', r.text)
    recipe_nid = m.group(1) if m else None
    if recipe_nid:
        print('Recipe created with nid:', recipe_nid)
    else:
        print('Recipe creation may have failed. Response snippet:')
        print(r.text[:2000])
        sys.exit(1)

    # Verify recipe references coffee via JSON:API
    print('Verifying via JSON:API...')
    r = session.get(urljoin(SITE, f'/jsonapi/node/brew_recipe/{recipe_nid}'), headers={'Accept': 'application/vnd.api+json'})
    if r.status_code == 200 and nid in r.text:
        print('Verification success: recipe references coffee nid present in JSONAPI response')
    else:
        print('Verification failed: status', r.status_code)
        print(r.text[:1000])
        sys.exit(1)

    print('Integration test completed successfully.')

if __name__ == '__main__':
    main()
