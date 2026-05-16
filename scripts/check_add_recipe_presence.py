#!/usr/bin/env python3
"""Login as test user and check for Add Recipe button on coffee edit page."""
import re
from urllib.parse import urljoin
import requests

SITE = 'http://localhost:8000'
USERNAME = 'opencode'
PASSWORD = 'opencode123'

def get_token(html, name):
    m = re.search(r'name=["\']%s["\']\s+value=["\']([^"\']*)["\']' % re.escape(name), html)
    if m:
        return m.group(1)
    m = re.search(r'name=["\']%s["\'].*?value=["\']([^"\']*)["\']' % re.escape(name), html, re.S)
    return m.group(1) if m else None

sess = requests.Session()

print('GET login')
r = sess.get(urljoin(SITE, '/user/login'))
open('/tmp/check_login_page.html','w').write(r.text)
fbid = get_token(r.text, 'form_build_id')
fid = get_token(r.text, 'form_id')
print('tokens:', fbid, fid)

print('POST login')
resp = sess.post(urljoin(SITE, '/user/login'), data={'name': USERNAME, 'pass': PASSWORD, 'form_build_id': fbid, 'form_id': fid, 'op': 'Log in'})
open('/tmp/check_login_post.html','w').write(resp.text)
print('login status', resp.status_code)

# Try to find a coffee node owned by user via /my-coffees
print('GET my-coffees')
r = sess.get(urljoin(SITE, '/my-coffees'))
open('/tmp/check_my_coffees.html','w').write(r.text)

# find first coffee edit link
m = re.search(r'/node/(\d+)(?:/edit)?[^\"]*"[^>]*>\s*Edit', r.text)
nid = None
if m:
    nid = m.group(1)
else:
    # fallback to any node link for coffee
    m2 = re.search(r'/node/(\d+)[^\"]*"[^>]*>\s*View', r.text)
    if m2:
        nid = m2.group(1)

if not nid:
    # fallback to a previously-created nid used in tests
    nid = '45'

print('checking nid', nid)

edit_url = urljoin(SITE, f'/node/{nid}/edit')
print('GET', edit_url)
r2 = sess.get(edit_url)
open('/tmp/check_edit_page.html','w').write(r2.text)
txt = r2.text

found = False
if 'data-cj-add-recipe' in txt or 'Add Recipe' in txt:
    found = True

print('Add Recipe present:', found)
if not found:
    # dump snippet around recipes area
    idx = txt.find('Recipes')
    if idx != -1:
        print('snippet around Recipes:')
        print(txt[max(0, idx-200):idx+400])
    else:
        print('Recipes keyword not found in page')
