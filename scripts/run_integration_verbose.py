#!/usr/bin/env python3
import re
from urllib.parse import urljoin
import requests

SITE = 'http://localhost:8000'
USERNAME = 'opencode'
PASSWORD = 'opencode123'

def get_field(html, name):
    # match input/select/textarea with name="name" and extract value if present
    m = re.search(r'name=["\']%s["\']\s+value=["\']([^"\']*)["\']' % re.escape(name), html)
    if m:
        return m.group(1)
    # try alternate pattern: data-drupal-selector or other ordering
    m = re.search(r'name=["\']%s["\'].*?>.*?value=["\']([^"\']*)["\']' % re.escape(name), html, re.S)
    return m.group(1) if m else None

sess = requests.Session()
print('GET login')
r = sess.get(urljoin(SITE, '/user/login'))
open('/tmp/login_page.html','w').write(r.text)
fbid = get_field(r.text, 'form_build_id')
fid = get_field(r.text, 'form_id')
print('tokens:', fbid, fid)
print('POST login')
resp = sess.post(urljoin(SITE, '/user/login'), data={'name': USERNAME, 'pass': PASSWORD, 'form_build_id': fbid, 'form_id': fid, 'op': 'Log in'})
open('/tmp/login_post.html','w').write(resp.text)
print('login POST status', resp.status_code)
rp = sess.get(urljoin(SITE, '/user'))
open('/tmp/user_page.html','w').write(rp.text)
print('user page length', len(rp.text))
if USERNAME in rp.text:
    print('login success')
else:
    print('login possibly failed')

print('GET coffee add form')
r = sess.get(urljoin(SITE, '/node/add/coffee_bean'))
open('/tmp/coffee_add.html','w').write(r.text)
fbid = get_field(r.text, 'form_build_id')
fid = get_field(r.text, 'form_id')
print('tokens for coffee:', fbid, fid)
print('POST coffee add')
from datetime import date
today = date.today().isoformat()

coffee_payload = {
    'title[0][value]': 'Test Coffee from CI',
    'field_form_factor': 'whole_bean',
    'field_purchase_date[0][value][date]': today,
    'field_quantity[0][value]': '250',
    'field_quantity_unit': 'g',
    'field_bitterness_rating[0][value]': '5',
    'field_acidity_rating[0][value]': '5',
    'field_note_clarity_rating[0][value]': '5',
    'field_overall_taste_rating[0][value]': '4',
    'field_flavour_notes[0][value]': 'Integration test coffee',
    'form_build_id': fbid,
    'form_token': get_field(r.text, 'form_token') or '',
    'form_id': fid,
    'changed': get_field(r.text, 'changed') or '',
    'op': 'Save'
}
resp = sess.post(urljoin(SITE, '/node/add/coffee_bean'), data=coffee_payload, allow_redirects=True)
open('/tmp/coffee_post.html','w').write(resp.text)
print('coffee post URL:', resp.url)
print('coffee post status:', resp.status_code)
match = re.search(r'/node/(\d+)', resp.text)
if match:
    nid = match.group(1)
    print('Extracted nid from response text:', nid)
else:
    print('No nid in response text; trying /my-coffees')
    r2 = sess.get(urljoin(SITE, '/my-coffees'))
    open('/tmp/my_coffees.html','w').write(r2.text)
    m2 = re.search(r'/node/(\d+)', r2.text)
    if m2:
        nid = m2.group(1)
        print('Found nid in my-coffees:', nid)
    else:
        print('Could not find nid; aborting')
        raise SystemExit(1)

print('Created coffee nid:', nid)

print('GET brew_recipe add with prefill')
r = sess.get(urljoin(SITE, f'/node/add/brew_recipe?field_coffee_bean_ref_target_id={nid}'))
open('/tmp/recipe_add.html','w').write(r.text)
if 'field_coffee_bean_ref' not in r.text and 'name="title"' not in r.text:
    print('Recipe add form missing expected fields; aborting')
    raise SystemExit(1)
print('Recipe form loaded')

fbid = get_field(r.text, 'form_build_id')
fid = get_field(r.text, 'form_id')
print('Recipe tokens:', fbid, fid)
resp = sess.post(urljoin(SITE, '/node/add/brew_recipe'), data={
    'title[0][value]':'Test Recipe from CI',
    'field_brew_method[0][value]':'V60',
    'field_coffee_weight[0][value]':'18',
    'field_water_weight[0][value]':'300',
    'field_coffee_bean_ref[0][target_id]':nid,
    'form_build_id':fbid,
    'form_id':fid,
    'op':'Save'
}, allow_redirects=True)
open('/tmp/recipe_post.html','w').write(resp.text)
print('recipe post URL', resp.url)
match = re.search(r'/node/(\d+)', resp.text)
if match:
    print('Recipe created nid', match.group(1))
else:
    print('Recipe nid not found in response; saved outputs for inspection')
    raise SystemExit(1)
