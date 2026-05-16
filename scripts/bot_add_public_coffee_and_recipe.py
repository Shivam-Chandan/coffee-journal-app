#!/usr/bin/env python3
"""Create a coffee, make it public, and add a brew recipe using test account."""
import re
from urllib.parse import urljoin
import requests
from datetime import date

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
open('/tmp/bot_login_page.html','w').write(r.text)
fb = get_token(r.text, 'form_build_id')
fid = get_token(r.text, 'form_id')
print('tokens:', fb, fid)

print('POST login')
resp = sess.post(urljoin(SITE, '/user/login'), data={'name': USERNAME, 'pass': PASSWORD, 'form_build_id': fb, 'form_id': fid, 'op': 'Log in'})
open('/tmp/bot_login_post.html','w').write(resp.text)
print('login status', resp.status_code)

print('GET coffee add form')
r = sess.get(urljoin(SITE, '/node/add/coffee_bean'))
open('/tmp/bot_coffee_add.html','w').write(r.text)
fb3 = get_token(r.text, 'form_build_id')
fi3 = get_token(r.text, 'form_id')
form_token = get_token(r.text, 'form_token')
print('form tokens', fb3, fi3, form_token)

payload = {
    'title[0][value]':'Bot Coffee',
    'field_form_factor':'whole_bean',
    'field_purchase_date[0][value][date]': date.today().isoformat(),
    'field_quantity[0][value]':'250',
    'field_quantity_unit':'g',
    'field_bitterness_rating[0][value]':'5',
    'field_acidity_rating[0][value]':'5',
    'field_note_clarity_rating[0][value]':'5',
    'field_overall_taste_rating[0][value]':'4',
    'field_flavour_notes[0][value]':'Bot created coffee',
    'form_build_id': fb3,
    'form_token': form_token,
    'form_id': fi3,
    'op': 'Save'
}

resp = sess.post(urljoin(SITE, '/node/add/coffee_bean'), data=payload, allow_redirects=True)
open('/tmp/bot_coffee_post.html','w').write(resp.text)
print('coffee post status', resp.status_code)
m = re.search(r'/node/(\d+)', resp.text)
if not m:
    print('Could not find created coffee nid in response')
    raise SystemExit(1)
nid = m.group(1)
print('created coffee nid', nid)

print('GET coffee edit form to set public')
r = sess.get(urljoin(SITE, f'/node/{nid}/edit'))
open('/tmp/bot_coffee_edit.html','w').write(r.text)
fb4 = get_token(r.text, 'form_build_id')
fi4 = get_token(r.text, 'form_id')
tok4 = get_token(r.text, 'form_token')
print('edit tokens', fb4, fi4, tok4)

payload2 = {
    'field_is_public[0][value]': '1',
    'form_build_id': fb4,
    'form_token': tok4,
    'form_id': fi4,
    'op': 'Save'
}
resp2 = sess.post(urljoin(SITE, f'/node/{nid}/edit'), data=payload2, allow_redirects=True)
open('/tmp/bot_edit_post.html','w').write(resp2.text)
print('made public status', resp2.status_code)

print('GET brew_recipe add with prefill')
r5 = sess.get(urljoin(SITE, f'/node/add/brew_recipe?field_coffee_bean_ref_target_id={nid}'))
open('/tmp/bot_recipe_add_get.html','w').write(r5.text)
print('/node/add/brew_recipe status', r5.status_code)
if r5.status_code != 200 or 'Access denied' in r5.text:
    print('Cannot open brew_recipe add form; access denied or non-200 response')
    raise SystemExit(1)

fb5 = get_token(r5.text, 'form_build_id')
fi5 = get_token(r5.text, 'form_id')
tok5 = get_token(r5.text, 'form_token')
print('recipe form tokens', fb5, fi5, tok5)

post = {
    'title[0][value]':'Bot Recipe',
    'field_brew_method[0][value]':'V60',
    'field_coffee_weight[0][value]':'18',
    'field_water_weight[0][value]':'300',
    'field_coffee_bean_ref[0][target_id]': nid,
    'form_build_id': fb5,
    'form_token': tok5,
    'form_id': fi5,
    'op': 'Save'
}

resp6 = sess.post(urljoin(SITE, '/node/add/brew_recipe'), data=post, allow_redirects=True)
open('/tmp/bot_recipe_post.html','w').write(resp6.text)
print('recipe post status', resp6.status_code)
mm = re.search(r'/node/(\d+)', resp6.text)
if mm:
    print('created recipe nid', mm.group(1))
else:
    print('could not find recipe nid in response; saved HTML for inspection')
