/**
 * Coffee Journal — App JS
 *
 * Behaviours (all inside a single IIFE):
 *   1. cjThemeToggle       — dark/light mode toggle
 *   2. cjAddDrawer         — slide-up drawer for add/edit coffee form
 *                            FAB + "Import from URL" + edit links open this
 *                            Form is fetched via AJAX, submitted via fetch,
 *                            drawer closes on success and list refreshes
 *   3. cjFormAutofill      — reads sessionStorage['cj_prefill'] on form load
 *   4. cjFormScraper       — inline scraper inside the drawer form
 *                            POST /api/scrape-coffee → fills form fields directly
 *   5. cjSort              — sort dropdown (Order Date / Rating)
 *                            navigates with ?sort_by= param; applies finished-last
 *                            secondary sort client-side after page load
 *   6. cjRatingInputs      — 1-10 range sliders + 1-5 star widget
 */
(function (Drupal, drupalSettings) {
  'use strict';

  // ── Shared fill helpers ──────────────────────────────────────────────────

  function _fillText(selector, value, root) {
    if (!value && value !== 0) return;
    var el = (root || document).querySelector(selector);
    if (el) {
      el.value = value;
      el.dispatchEvent(new Event('change', { bubbles: true }));
      el.dispatchEvent(new Event('input',  { bubbles: true }));
    }
  }

  function _fillSelect(selector, value, root) {
    if (!value) return;
    var el = (root || document).querySelector(selector);
    if (!el || el.tagName !== 'SELECT') return;
    var lv = String(value).toLowerCase().replace(/[- ]/g, '_');
    for (var i = 0; i < el.options.length; i++) {
      var ov = el.options[i].value.toLowerCase().replace(/[- ]/g, '_');
      var ot = el.options[i].text.toLowerCase().replace(/[- ]/g, '_');
      if (ov === lv || ot === lv) {
        el.selectedIndex = i;
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
    }
  }

  function _fillFormFromScraped(data, root) {
    if (!data) return;
    root = root || document;
    _fillText('#edit-title-0-value',               data.coffeeName,  root);
    _fillText('#edit-field-brand-roaster-0-value', data.brandName,   root);
    _fillText('#edit-field-estate-0-value',        data.estate,      root);
    _fillText('#edit-field-flavour-notes-0-value', data.notes,       root);
    _fillText('#edit-field-quantity-0-value',      data.quantity,    root);
    _fillSelect('#edit-field-roast-level',         data.roast,       root);
    _fillSelect('#edit-field-quantity-unit',       data.quantityUnit || 'g', root);

    // Sync range sliders if already rendered
    var sliders = {
      'edit-field-bitterness-rating-0-value':   data.bitternessRating,
      'edit-field-acidity-rating-0-value':      data.acidityRating,
      'edit-field-note-clarity-rating-0-value': data.noteClarityRating,
    };
    Object.keys(sliders).forEach(function (id) {
      var val = sliders[id];
      if (!val) return;
      var hidden = root.getElementById ? root.getElementById(id) : document.getElementById(id);
      var range  = root.getElementById ? root.getElementById('cj-range-' + id) : document.getElementById('cj-range-' + id);
      if (hidden) { hidden.value = val; hidden.dispatchEvent(new Event('change', { bubbles: true })); }
      if (range)  { range.value  = val; range.dispatchEvent(new Event('input',  { bubbles: true })); }
    });
  }

  // ── 1. DARK / LIGHT MODE TOGGLE ─────────────────────────────────────────

  Drupal.behaviors.cjThemeToggle = {
    attach: function (context) {
      function getTheme() {
        var stored = localStorage.getItem('cj-theme');
        if (stored) return stored;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      function applyTheme(theme) {
        document.body.classList.toggle('light-mode', theme === 'light');
        document.body.classList.toggle('dark-mode',  theme !== 'light');
        var icon = document.querySelector('#cj-theme-toggle span');
        if (icon) icon.textContent = theme === 'light' ? '🌙' : '☀️';
        var btn = document.querySelector('#cj-theme-toggle');
        if (btn) {
          btn.setAttribute('aria-label', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
          btn.setAttribute('title',      theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
        }
      }
      once('cj-theme-init', 'body', context).forEach(function () { applyTheme(getTheme()); });
      once('cj-toggle', '#cj-theme-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var next = document.body.classList.contains('light-mode') ? 'dark' : 'light';
          localStorage.setItem('cj-theme', next);
          applyTheme(next);
        });
      });
    }
  };

  // ── 2. ADD/EDIT COFFEE DRAWER ────────────────────────────────────────────
  //
  // Matches source app's IonModal slide-up sheet.
  //
  // Opens when:
  //   - FAB (+) is clicked
  //   - "Import from URL" button is clicked (drawer opens, scraper auto-focused)
  //   - Edit pencil icon on a coffee card is clicked
  //   - "Add your first coffee" empty-state link is clicked
  //
  // On open: fetches the add/edit form HTML via fetch(), strips it out of the
  //   full page response, injects into the drawer body, then runs Drupal.attachBehaviors
  //   so sliders/stars/scraper all work inside the drawer.
  //
  // On submit (Option A): intercepts the form's submit event, POSTs via fetch,
  //   detects the redirect to a node page (success), closes the drawer, and
  //   reloads /my-coffees to show the updated list.
  //
  // On close: restores scroll, removes injected form HTML.

  Drupal.behaviors.cjAddDrawer = {
    attach: function (context) {

      var drawer     = document.getElementById('cj-drawer');
      var drawerBody = document.getElementById('cj-drawer-body');
      var drawerTitle= document.getElementById('cj-drawer-title');
      var drawerClose= document.getElementById('cj-drawer-close');
      var drawerBack = document.getElementById('cj-drawer-backdrop');
      var drawerSpinner = document.getElementById('cj-drawer-spinner');

      if (!drawer) return;

      var _currentUrl   = null;  // URL being loaded in the drawer
      var _focusScraper = false; // whether to auto-focus scraper input after load

      // Expose openDrawer for cross-behavior access (e.g. cjAddRecipeFromBean)
      Drupal.behaviors.cjAddDrawer.openDrawer = function (url, title, focusScraper) {
        openDrawer(url, title, focusScraper);
      };

      // ── Open drawer ──────────────────────────────────────────────────────

      function openDrawer(url, title, focusScraper) {
        _currentUrl   = url;
        _focusScraper = !!focusScraper;

        if (drawerTitle) drawerTitle.textContent = title || Drupal.t('Add Coffee');
        if (drawerBody)  drawerBody.innerHTML = '';
        if (drawerSpinner) drawerSpinner.hidden = false;

        drawer.setAttribute('aria-hidden', 'false');
        drawer.removeAttribute('inert');
        document.body.classList.add('cj-drawer-open');

        // Fetch the form page
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.text();
          })
          .then(function (html) {
            if (drawerSpinner) drawerSpinner.hidden = true;

            // Extract just the #cj-main content from the full page HTML
            var parser  = new DOMParser();
            var doc     = parser.parseFromString(html, 'text/html');
            var main    = doc.getElementById('cj-main');
            var content = main ? main.innerHTML : doc.querySelector('.cj-page-inner') ? doc.querySelector('.cj-page-inner').outerHTML : doc.body.innerHTML;

            if (drawerBody) {
              drawerBody.innerHTML = content;

              // Re-attach Drupal behaviours so sliders/stars/scraper work
              Drupal.attachBehaviors(drawerBody, drupalSettings);

              // Wire up form submit interception (coffee bean or brew recipe)
              var form = drawerBody.querySelector(
                'form.node-coffee-bean-form, form.node-coffee-bean-edit-form,' +
                'form.node-brew-recipe-form, form.node-brew-recipe-edit-form'
              );
              if (form) {
                // Remove the Preview button — not needed in the drawer UX
                var previewBtn = form.querySelector('[data-drupal-selector="edit-preview"], input[value="Preview"], button[value="Preview"]');
                if (previewBtn) previewBtn.remove();
                _wireFormSubmit(form);
              }

              // Auto-focus scraper URL input when opened via "Import from URL"
              if (_focusScraper) {
                var scraperInput = drawerBody.querySelector('#cj-inline-scraper-url');
                if (scraperInput) setTimeout(function () { scraperInput.focus(); }, 120);
              } else {
                // Focus first text input (Coffee Name)
                var firstInput = drawerBody.querySelector('#edit-title-0-value');
                if (firstInput) setTimeout(function () { firstInput.focus(); }, 120);
              }
            }
          })
          .catch(function (err) {
            if (drawerSpinner) drawerSpinner.hidden = true;
            if (drawerBody) {
              drawerBody.innerHTML = '<div class="messages messages--error">' +
                Drupal.t('Could not load the form. @err', { '@err': err.message }) +
                '</div>';
            }
          });
      }

      // ── Close drawer ─────────────────────────────────────────────────────

      function closeDrawer() {
        drawer.setAttribute('aria-hidden', 'true');
        drawer.setAttribute('inert', '');
        document.body.classList.remove('cj-drawer-open');
        // Clear injected form so next open starts fresh
        setTimeout(function () {
          if (drawerBody) drawerBody.innerHTML = '';
          _currentUrl = null;
        }, 300); // match CSS transition duration
      }

      // ── Wire form submit interception (Option A) ─────────────────────────
      //
      // Submits the form via fetch instead of a full-page POST.
      // On redirect to a node URL (Drupal's post-save redirect) = success.
      // Closes the drawer and reloads /my-coffees.

      function _wireFormSubmit(form) {
        once('cj-drawer-submit', form).forEach(function (f) {
          f.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = f.querySelector('[data-drupal-selector="edit-submit"]') ||
                            f.querySelector('input[type="submit"], button[type="submit"]');

            // Show loading state on submit button
            var originalText = submitBtn ? (submitBtn.value || submitBtn.textContent) : '';
            if (submitBtn) {
              submitBtn.disabled = true;
              if (submitBtn.tagName === 'INPUT') submitBtn.value = Drupal.t('Saving…');
              else submitBtn.textContent = Drupal.t('Saving…');
            }

            var formData = new FormData(f);

            fetch(f.action || _currentUrl, {
              method:      'POST',
              credentials: 'same-origin',
              body:        formData,
              redirect:    'manual', // Don't follow redirect automatically
            })
            .then(function (resp) {
              // Drupal redirects on successful save (status 302 → opaqueredirect in fetch with redirect:manual)
              // Or it may return 200 with a redirect header or refreshed page
              var location = resp.headers.get('Location') || resp.url || '';
              var isSuccess = (resp.type === 'opaqueredirect') ||
                              (resp.status >= 300 && resp.status < 400) ||
                              (location && location.indexOf('/node/') !== -1 && location.indexOf('/add') === -1 && location.indexOf('/edit') === -1);

              if (isSuccess) {
                closeDrawer();
                // Reload the journal list to show the new/updated coffee
                window.location.reload();
              } else {
                // Form may have validation errors — inject the response back into the drawer
                return resp.text().then(function (html) {
                  if (submitBtn) {
                    submitBtn.disabled = false;
                    if (submitBtn.tagName === 'INPUT') submitBtn.value = originalText;
                    else submitBtn.textContent = originalText;
                  }
                  var parser = new DOMParser();
                  var doc    = parser.parseFromString(html, 'text/html');
                  var main   = doc.getElementById('cj-main');
                  var content = main ? main.innerHTML : doc.body.innerHTML;
                  if (drawerBody) {
                    drawerBody.innerHTML = content;
                    Drupal.attachBehaviors(drawerBody, drupalSettings);
                    var newForm = drawerBody.querySelector(
                      'form.node-coffee-bean-form, form.node-coffee-bean-edit-form,' +
                      'form.node-brew-recipe-form, form.node-brew-recipe-edit-form'
                    );
                    if (newForm) {
                      // Remove the Preview button on re-render after validation errors
                      var previewBtn = newForm.querySelector('[data-drupal-selector="edit-preview"], input[value="Preview"], button[value="Preview"]');
                      if (previewBtn) previewBtn.remove();
                      _wireFormSubmit(newForm);
                    }
                    // Scroll to first error
                    var firstError = drawerBody.querySelector('.form-item--error, .messages--error');
                    if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'start' });
                  }
                });
              }
            })
            .catch(function (err) {
              if (submitBtn) {
                submitBtn.disabled = false;
                if (submitBtn.tagName === 'INPUT') submitBtn.value = originalText;
                else submitBtn.textContent = originalText;
              }
              // On network error, fall back to normal submit
              f.removeEventListener('submit', arguments.callee);
              f.submit();
            });
          });
        });
      }

      // ── Bind close triggers ───────────────────────────────────────────────

      once('cj-drawer-close', drawer).forEach(function () {
        if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
        if (drawerBack)  drawerBack.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && drawer.getAttribute('aria-hidden') === 'false') closeDrawer();
        });
      });

      // ── FAB: open drawer for "Add Coffee" ────────────────────────────────

      once('cj-fab', '.cj-fab', context).forEach(function (fab) {
        fab.addEventListener('click', function (e) {
          e.preventDefault();
          openDrawer('/node/add/coffee_bean', Drupal.t('Add Coffee'), false);
        });
      });

      // ── "Import from URL" button: open drawer with scraper focused ────────
      // Same as FAB but scraper input gets focus

      once('cj-import-url-btn', '#cj-import-url-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          openDrawer('/node/add/coffee_bean', Drupal.t('Add Coffee'), true);
        });
      });

      // ── Edit links on cards: open drawer for edit form ───────────────────
      // Intercepts <a data-drawer-edit="true" href="/node/{nid}/edit"> clicks

      context.querySelectorAll && context.querySelectorAll('a[data-drawer-edit]').forEach(function (link) {
        once('cj-edit-drawer', link).forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();
            openDrawer(a.href, Drupal.t('Edit Coffee'), false);
          });
        });
      });

      // ── Recipe edit links: nested panel when inside coffee drawer, else full drawer ──

      context.querySelectorAll && context.querySelectorAll('a[data-drawer-recipe-edit]').forEach(function (link) {
        once('cj-recipe-edit-drawer', link).forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();

            var coffeeForm = a.closest('form.node-coffee-bean-form, form.node-coffee-bean-edit-form');

            if (drawerBody && drawerBody.contains(a) && coffeeForm) {
              // Inside the coffee edit drawer — load recipe edit form as nested panel
              var nested = drawerBody.querySelector('#cj-drawer-nested');
              if (!nested) {
                nested = document.createElement('div');
                nested.id        = 'cj-drawer-nested';
                nested.className = 'cj-drawer-nested';
                coffeeForm.setAttribute('aria-hidden', 'true');
                coffeeForm.style.display = 'none';
                drawerBody.appendChild(nested);
              }

              nested.innerHTML = '<div class="cj-spinner">' + Drupal.t('Loading…') + '</div>';

              fetch(a.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (resp) { if (!resp.ok) throw new Error('HTTP ' + resp.status); return resp.text(); })
                .then(function (html) {
                  var parser  = new DOMParser();
                  var doc     = parser.parseFromString(html, 'text/html');
                  var main    = doc.getElementById('cj-main');
                  var content = main ? main.innerHTML
                    : doc.querySelector('.cj-page-inner') ? doc.querySelector('.cj-page-inner').outerHTML
                    : doc.body.innerHTML;
                  nested.innerHTML = content;
                  Drupal.attachBehaviors(nested, drupalSettings);

                  var nestedForm = nested.querySelector('form.node-brew-recipe-form, form.node-brew-recipe-edit-form');
                  if (nestedForm) {
                    var preview = nestedForm.querySelector('[data-drupal-selector="edit-preview"], input[value="Preview"], button[value="Preview"]');
                    if (preview) preview.remove();

                    once('cj-nested-recipe-edit-submit', nestedForm).forEach(function (f) {
                      f.addEventListener('submit', function (ev) {
                        ev.preventDefault();
                        var submitBtn = f.querySelector('[data-drupal-selector="edit-submit"]') || f.querySelector('input[type="submit"], button[type="submit"]');
                        var originalText = submitBtn ? (submitBtn.value || submitBtn.textContent) : '';
                        if (submitBtn) {
                          submitBtn.disabled = true;
                          if (submitBtn.tagName === 'INPUT') submitBtn.value = Drupal.t('Saving…');
                          else submitBtn.textContent = Drupal.t('Saving…');
                        }

                        var formData = new FormData(f);
                        fetch(f.action || a.href, { method: 'POST', credentials: 'same-origin', body: formData, redirect: 'manual' })
                          .then(function (resp) {
                            var loc = resp.headers.get('Location') || resp.url || '';
                            var ok  = (resp.type === 'opaqueredirect') ||
                                      (resp.status >= 300 && resp.status < 400) ||
                                      (loc && loc.indexOf('/node/') !== -1 && loc.indexOf('/edit') === -1 && loc.indexOf('/add') === -1);
                            if (ok) {
                              nested.remove();
                              coffeeForm.removeAttribute('aria-hidden');
                              coffeeForm.style.display = '';
                              var banner = document.createElement('div');
                              banner.className   = 'messages messages--status';
                              banner.textContent = Drupal.t('Recipe updated.');
                              coffeeForm.insertBefore(banner, coffeeForm.firstChild);
                              setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 4000);
                            } else {
                              resp.text().then(function (htmlResp) {
                                var p2   = new DOMParser();
                                var doc2 = p2.parseFromString(htmlResp, 'text/html');
                                var m2   = doc2.getElementById('cj-main');
                                nested.innerHTML = m2 ? m2.innerHTML : doc2.body.innerHTML;
                                Drupal.attachBehaviors(nested, drupalSettings);
                              });
                            }
                          })
                          .catch(function (err) {
                            console.error('Nested recipe edit submit failed', err);
                            if (submitBtn) {
                              submitBtn.disabled = false;
                              if (submitBtn.tagName === 'INPUT') submitBtn.value = originalText;
                              else submitBtn.textContent = originalText;
                            }
                          });
                      });
                    });
                  }
                })
                .catch(function (err) {
                  nested.innerHTML = '<div class="messages messages--error">' +
                    Drupal.t('Could not load form. @err', { '@err': err.message }) + '</div>';
                });

            } else {
              // Outside the coffee form drawer — open in the main drawer
              openDrawer(a.href, Drupal.t('Edit Recipe'), false);
            }
          });
        });
      });

      // ── View Recipe links: open recipe detail in drawer ──────────────────
      // Intercepts <a data-drawer-view-recipe="true"> clicks on recipe card title / footer

      context.querySelectorAll && context.querySelectorAll('a[data-drawer-view-recipe]').forEach(function (link) {
        once('cj-view-recipe-drawer', link).forEach(function (a) {
          a.addEventListener('click', function (e) {
            // Only intercept if we are not already inside a nested panel
            if (drawerBody && drawerBody.querySelector('#cj-drawer-nested')) return;
            e.preventDefault();
            var title = (a.closest('.cj-recipe-card') || {}).querySelector && a.closest('.cj-recipe-card').querySelector('.cj-recipe-card__title');
            openDrawer(a.href, (title ? title.textContent.trim() : Drupal.t('Recipe')), false);
          });
        });
      });

      // ── "Add your first coffee" empty-state link ─────────────────────────
      context.querySelectorAll && context.querySelectorAll('.cj-empty a.button, .cj-empty a[href*="coffee_bean"]').forEach(function (link) {
        once('cj-empty-add', link).forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();
            openDrawer('/node/add/coffee_bean', Drupal.t('Add Coffee'), false);
          });
        });
      });

      // ── Any [data-cj-add-coffee] link (View headers, etc.) ───────────────
      context.querySelectorAll && context.querySelectorAll('a[data-cj-add-coffee]').forEach(function (link) {
        once('cj-add-coffee-link', link).forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();
            openDrawer('/node/add/coffee_bean', Drupal.t('Add Coffee'), false);
          });
        });
      });
    }
  };

  // ── 3. FORM AUTOFILL FROM sessionStorage ─────────────────────────────────
  //
  // Reads sessionStorage['cj_prefill'] when the form is loaded into the drawer
  // (or on a standalone form page) and fills all fields.

  Drupal.behaviors.cjFormAutofill = {
    attach: function (context) {
      var forms = once('cj-form-autofill', '.node-coffee-bean-form, .node-coffee-bean-edit-form', context);
      if (!forms.length) return;

      var raw = null;
      try { raw = sessionStorage.getItem('cj_prefill'); } catch (e) {}
      if (!raw) return;

      var data = null;
      try { data = JSON.parse(raw); } catch (e) { return; }
      try { sessionStorage.removeItem('cj_prefill'); } catch (e) {}
      if (!data) return;

      var root = forms[0].closest('#cj-drawer-body') || document;
      _fillFormFromScraped(data, root);

      var banner = document.createElement('div');
      banner.className = 'messages messages--status cj-prefill-banner';
      banner.setAttribute('role', 'status');
      banner.textContent = Drupal.t('Fields pre-filled from AI extraction. Review and save.');
      forms[0].insertBefore(banner, forms[0].firstChild);
      setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 5000);
    }
  };

  // ── 4. INLINE FORM SCRAPER ────────────────────────────────────────────────
  //
  // Handles the "Auto-fill details from URL" widget injected by hook_form_alter
  // at the top of every coffee_bean add/edit form.
  // Works whether the form is inside the drawer or on a standalone page.

  Drupal.behaviors.cjFormScraper = {
    attach: function (context) {
      var scrapers = once('cj-inline-scraper', '#cj-inline-scraper', context);
      if (!scrapers.length) return;

      var root      = scrapers[0].closest('#cj-drawer-body') || document;
      var urlInput  = scrapers[0].querySelector('#cj-inline-scraper-url');
      var btn       = scrapers[0].querySelector('#cj-inline-scraper-btn');
      var statusEl  = scrapers[0].querySelector('#cj-inline-scraper-status');

      function setLoading(loading) {
        if (!btn) return;
        var label   = btn.querySelector('.cj-inline-btn-label');
        var spinner = btn.querySelector('.cj-inline-spinner');
        btn.disabled = loading;
        if (label)   label.textContent = loading ? Drupal.t('Filling…') : Drupal.t('Auto-fill');
        if (spinner) spinner.hidden = !loading;
      }

      function setStatus(msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'cj-scraper-inline__status cj-scraper-inline__status--' + (type || 'info');
      }

      function doScrape() {
        var url = urlInput ? urlInput.value.trim() : '';
        if (!url) {
          setStatus(Drupal.t('Please enter a URL.'), 'error');
          if (urlInput) urlInput.focus();
          return;
        }

        // Always use '/api/scrape-coffee' as fallback — it's a relative URL,
        // not a secret. drupalSettings override still takes precedence if set.
        var endpoint = '/api/scrape-coffee';
        if (drupalSettings && drupalSettings.coffeeJournal && drupalSettings.coffeeJournal.scrapeEndpoint) {
          endpoint = drupalSettings.coffeeJournal.scrapeEndpoint;
        }

        setLoading(true);
        setStatus(Drupal.t('Analysing page with AI…'), 'info');

        fetch(endpoint, {
          method:      'POST',
          credentials: 'same-origin',
          headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body:        JSON.stringify({ url: url })
        })
        .then(function (resp) {
          if (!resp.ok) return resp.json().then(function (err) { throw new Error(err.error || ('HTTP ' + resp.status)); });
          return resp.json();
        })
        .then(function (data) {
          setLoading(false);
          if (!data || (!data.coffeeName && !data.brandName)) {
            setStatus(Drupal.t('Could not extract details from that page. Try a different URL.'), 'error');
            return;
          }
          _fillFormFromScraped(data, root);
          setStatus(Drupal.t('Fields filled — review and save.'), 'success');
          if (urlInput) urlInput.value = '';
          // Move focus to the Coffee Name field
          var nameField = root.querySelector ? root.querySelector('#edit-title-0-value') : document.getElementById('edit-title-0-value');
          if (nameField) nameField.focus();
        })
        .catch(function (err) {
          setLoading(false);
          setStatus(Drupal.t('Error: @msg', { '@msg': err.message }), 'error');
        });
      }

      if (btn) btn.addEventListener('click', doScrape);
      if (urlInput) {
        urlInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); doScrape(); }
        });
      }
    }
  };

  // ── 5. SORT DROPDOWN ─────────────────────────────────────────────────────
  //
  // Drives the "Sort by" control in the journal view.
  //
  // The Drupal Views exposed form (#edit-sort-by) is rendered inside
  // #cj-sort-wrap. This behaviour:
  //   1. Hides the sort_order select (locked DESC) and the submit button
  //   2. Inserts a "Sort by" label before the sort_by select
  //   3. Applies cj-sort-select styling to the Drupal select
  //   4. Auto-submits the form when sort_by changes (no button click needed)
  //   5. Applies finished-last secondary sort on every page load
  //   6. Updates the count label

  Drupal.behaviors.cjSort = {
    attach: function (context) {
      var COUNT_ID = 'cj-coffee-count';
      var GRID_ID  = 'cj-grid';

      // ── Tidy up the Drupal Views exposed form ──────────────────────────
      once('cj-sort-form', '#cj-sort-wrap', context).forEach(function (wrap) {
        // Hide sort_order select (we always want DESC) and submit button
        var orderSel  = wrap.querySelector('#edit-sort-order');
        var submitBtn = wrap.querySelector('[data-drupal-selector="edit-submit-coffee-journal"], input[type="submit"], button[type="submit"]');
        var orderWrap = wrap.querySelector('.form-item-sort-order, .js-form-item-sort-order');
        var submitWrap= wrap.querySelector('.form-actions');

        if (orderSel)   orderSel.value = 'DESC';
        if (orderWrap)  orderWrap.style.display = 'none';
        if (orderSel && !orderWrap) orderSel.style.display = 'none';
        if (submitWrap) submitWrap.style.display = 'none';
        if (submitBtn && !submitWrap) submitBtn.style.display = 'none';

        // Style the sort_by select to match our theme
        var sortBySel = wrap.querySelector('#edit-sort-by');
        if (sortBySel) {
          sortBySel.classList.add('cj-sort-select');

          // Remove any existing Drupal label — inject our own styled one
          var existingLabel = wrap.querySelector('label[for="edit-sort-by"]');
          if (existingLabel) existingLabel.style.display = 'none';

          var label = document.createElement('label');
          label.setAttribute('for', 'edit-sort-by');
          label.className   = 'cj-sort-label';
          label.textContent = Drupal.t('Sort by');
          wrap.insertBefore(label, wrap.firstChild);

          // Auto-submit the form on change — no button click required
          sortBySel.addEventListener('change', function () {
            // Ensure sort_order is DESC before submitting
            if (orderSel) orderSel.value = 'DESC';
            var form = wrap.querySelector('form');
            if (form) form.submit();
          });
        }
      });

      // ── Finished-last secondary sort (client-side) ──────────────────────
      // Finished cards always float to the bottom, mirroring the source app.
      function applyFinishedLast() {
        var grid = document.getElementById(GRID_ID);
        if (!grid) return;
        var cards    = Array.prototype.slice.call(grid.children);
        var active   = cards.filter(function (c) { return !c.querySelector('.cj-badge--finished'); });
        var finished = cards.filter(function (c) { return  c.querySelector('.cj-badge--finished'); });
        finished.forEach(function (c) { c.classList.add('cj-card--finished'); });
        active.concat(finished).forEach(function (c) { grid.appendChild(c); });
      }

      // ── Update count label ──────────────────────────────────────────────
      function updateCount() {
        var countEl = document.getElementById(COUNT_ID);
        if (!countEl) return;
        var grid  = document.getElementById(GRID_ID);
        var count = grid ? grid.querySelectorAll('.cj-card').length : 0;
        countEl.textContent = count + ' ' + (count === 1 ? Drupal.t('coffee') : Drupal.t('coffees'));
      }

      // Run on every page load
      once('cj-sort-init', 'body', context).forEach(function () {
        applyFinishedLast();
        updateCount();
      });
    }
  };

  // ── 6. RATING INPUT ENHANCEMENT ─────────────────────────────────────────

  Drupal.behaviors.cjRatingInputs = {
    attach: function (context) {

      // 6a — Range sliders (1-10)
      var sliderFields = [
        { id: 'edit-field-bitterness-rating-0-value',   label: 'Bitterness' },
        { id: 'edit-field-acidity-rating-0-value',      label: 'Acidity'    },
        { id: 'edit-field-note-clarity-rating-0-value', label: 'Clarity'    },
      ];

      sliderFields.forEach(function (cfg) {
        once('cj-slider-' + cfg.id, '#' + cfg.id, context).forEach(function (input) {
          var current = parseInt(input.value, 10) || 5;

          var wrap = document.createElement('div');
          wrap.className = 'cj-range-wrap';

          var range = document.createElement('input');
          range.type      = 'range';
          range.id        = 'cj-range-' + cfg.id;
          range.min       = '1';
          range.max       = '10';
          range.step      = '1';
          range.value     = current;
          range.className = 'cj-range-input';
          range.setAttribute('aria-label', cfg.label + ' rating 1 to 10');

          var valDisplay = document.createElement('span');
          valDisplay.className   = 'cj-range-val';
          valDisplay.textContent = current + '/10';

          range.addEventListener('input', function () {
            var v = parseInt(this.value, 10);
            input.value = v;
            valDisplay.textContent = v + '/10';
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });

          input.addEventListener('change', function () {
            var v = parseInt(this.value, 10) || 1;
            range.value = v;
            valDisplay.textContent = v + '/10';
          });

          wrap.appendChild(range);
          wrap.appendChild(valDisplay);

          input.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none';
          input.setAttribute('tabindex', '-1');
          input.setAttribute('aria-hidden', 'true');
          input.parentNode.insertBefore(wrap, input.nextSibling);
        });
      });

      // 6b — Star widget (1-5 overall taste)
      once('cj-stars-overall', '#edit-field-overall-taste-rating-0-value', context).forEach(function (input) {
        var current = parseInt(input.value, 10) || 0;

        var widget = document.createElement('div');
        widget.className = 'cj-rating-widget';
        widget.setAttribute('role', 'group');
        widget.setAttribute('aria-label', 'Overall taste rating 1 to 5');

        for (var i = 1; i <= 5; i++) {
          (function (val) {
            var star = document.createElement('button');
            star.type      = 'button';
            star.className = 'cj-rating-dot' + (val <= current ? ' active' : '');
            star.textContent = '★';
            star.setAttribute('aria-label', val + ' of 5');
            star.setAttribute('aria-pressed', val <= current ? 'true' : 'false');

            star.addEventListener('click', function () {
              input.value = val;
              input.dispatchEvent(new Event('change', { bubbles: true }));
              widget.querySelectorAll('.cj-rating-dot').forEach(function (s, idx) {
                var active = idx < val;
                s.classList.toggle('active', active);
                s.setAttribute('aria-pressed', active ? 'true' : 'false');
              });
            });
            widget.appendChild(star);
          })(i);
        }

        var display = document.createElement('span');
        display.className   = 'cj-rating-display';
        display.textContent = current ? current + '/5' : '';
        widget.appendChild(display);

        input.addEventListener('change', function () {
          var v = parseInt(this.value, 10) || 0;
          display.textContent = v ? v + '/5' : '';
          widget.querySelectorAll('.cj-rating-dot').forEach(function (s, idx) {
            var active = idx < v;
            s.classList.toggle('active', active);
            s.setAttribute('aria-pressed', active ? 'true' : 'false');
          });
        });

        input.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none';
        input.setAttribute('tabindex', '-1');
        input.setAttribute('aria-hidden', 'true');
        input.parentNode.insertBefore(widget, input.nextSibling);
      });
    }
  };

  // ── 7. GLOBAL FEED (Community Recipes) ───────────────────────────────────

  Drupal.behaviors.cjGlobalFeed = {
    attach: function (context) {
      // Find feed container manually without once()
      var feedContainer = (context || document).getElementById ? (context || document).getElementById('cj-global-feed') : document.getElementById('cj-global-feed');
      
      if (!feedContainer || feedContainer.getAttribute('data-cj-initialized')) {
        return;
      }
      feedContainer.setAttribute('data-cj-initialized', 'true');
      
      console.log('DEBUG: cjGlobalFeed behavior attached, feedContainer found:', feedContainer.id);
      
      var offset = 0;
      var isLoading = false;
      var hasMore = true;

      /**
       * Fetch recipes from JSON:API with optional filters.
       */
      function fetchRecipes(pageOffset) {
        console.log('DEBUG: fetchRecipes called with offset:', pageOffset, 'isLoading:', isLoading);
        if (isLoading) return Promise.resolve([]);
        isLoading = true;

        var url = '/jsonapi/node/brew_recipe?' +
          'filter[field_is_public]=1' +
          '&sort=-created' +
          '&include=uid,field_coffee_bean_ref' +
          '&page[limit]=20' +
          '&page[offset]=' + pageOffset;

        console.log('DEBUG: Fetching from URL:', url);

        return fetch(url, {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/vnd.api+json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        })
          .then(function (response) {
            console.log('DEBUG: Fetch response status:', response.status, response.ok);
            if (!response.ok) throw new Error('Failed to fetch recipes');
            return response.json();
          })
          .then(function (data) {
            console.log('DEBUG: Recipes data received:', data.data.length, 'recipes');
            isLoading = false;
            return data.data || [];
          })
          .catch(function (error) {
            console.error('ERROR fetching recipes:', error);
            isLoading = false;
            return [];
          });
      }

      /**
       * Render a single recipe card.
       */
      function renderRecipeCard(recipe) {
        var attrs = recipe.attributes || {};
        var nid = attrs.drupal_internal__nid || recipe.id;
        var uid = recipe.relationships && recipe.relationships.uid
          ? recipe.relationships.uid.data
          : null;

        var html = '<article class="cj-recipe-card" data-recipe-id="' + recipe.id + '">' +
          '<div class="cj-recipe-card__header">' +
          '<h3 class="cj-recipe-card__title">' +
          '<a href="/node/' + nid + '">' + 
          (attrs.title || 'Untitled Recipe') +
          '</a></h3>';

        if (uid) {
          html += '<p class="cj-recipe-card__author">by ' + uid.id + '</p>';
        }

        html += '</div>' +
          '<div class="cj-recipe-card__body">' +
          '<div class="cj-recipe-info">';

        if (attrs.field_brew_method) {
          html += '<div class="cj-recipe-field">' +
            '<strong class="cj-recipe-label">Method:</strong> ' +
            '<span class="cj-recipe-value">' + attrs.field_brew_method + '</span>' +
            '</div>';
        }

        if (attrs.field_coffee_weight) {
          html += '<div class="cj-recipe-field">' +
            '<strong class="cj-recipe-label">Coffee:</strong> ' +
            '<span class="cj-recipe-value">' + attrs.field_coffee_weight + 'g</span>' +
            '</div>';
        }

        if (attrs.field_water_weight) {
          html += '<div class="cj-recipe-field">' +
            '<strong class="cj-recipe-label">Water:</strong> ' +
            '<span class="cj-recipe-value">' + attrs.field_water_weight + 'g</span>' +
            '</div>';
        }

        if (attrs.field_coffee_weight && attrs.field_water_weight) {
          var ratio = (attrs.field_water_weight / attrs.field_coffee_weight).toFixed(2);
          html += '<div class="cj-recipe-field">' +
            '<strong class="cj-recipe-label">Ratio:</strong> ' +
            '<span class="cj-recipe-value">1:' + ratio + '</span>' +
            '</div>';
        }

        if (attrs.field_community_notes && attrs.field_community_notes.value) {
          html += '<div class="cj-recipe-field">' +
            '<strong class="cj-recipe-label">Notes:</strong> ' +
            '<span class="cj-recipe-value">' + attrs.field_community_notes.value + '</span>' +
            '</div>';
        }

        html += '</div>' +
          '<div class="cj-recipe-card__meta">' +
          '<p class="cj-recipe-comments">💬 0</p>' +
          '</div>' +
          '</div>' +
          '<div class="cj-recipe-card__footer">' +
          '<a href="/node/' + nid + '" class="cj-recipe-card__link">View Recipe</a>' +
          '</div>' +
          '</article>';

        return html;
      }

      /**
       * Load and render recipes.
       */
      function loadRecipes(pageOffset) {
        console.log('DEBUG: loadRecipes called with offset:', pageOffset, 'hasMore:', hasMore);
        if (!hasMore) {
          console.log('DEBUG: hasMore is false, returning');
          return;
        }

        fetchRecipes(pageOffset).then(function (recipes) {
          console.log('DEBUG: loadRecipes received', recipes.length, 'recipes from fetch');
          if (recipes.length === 0) {
            console.log('DEBUG: No recipes, setting hasMore to false');
            hasMore = false;
            if (offset === 0) {
              feedContainer.innerHTML = '<p class="cj-feed-empty">No recipes shared yet.</p>';
            }
            return;
          }

          console.log('DEBUG: Rendering', recipes.length, 'recipe cards');
          recipes.forEach(function (recipe) {
            var card = document.createElement('div');
            card.innerHTML = renderRecipeCard(recipe);
            feedContainer.appendChild(card.firstChild);
          });

          offset += recipes.length;
        });
      }

      // Initial load - disabled, server-side rendering provides recipes
      // loadRecipes(0);

      // Load more button handler
      var loadMoreBtn = document.getElementById('cj-load-more');
      if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function () {
          loadRecipes(offset);
        });
      }
    }
  };

  // ── 8. ADD RECIPE FROM COFFEE FORM (nested) ─────────────────────────────
  //
  // Handles [data-cj-add-recipe] buttons anywhere on the page.
  // The button carries data-bean-nid set server-side so we never need to
  // inspect the form DOM or drupalSettings to find the parent coffee NID.
  //
  // When the button is inside the drawer (coffee edit form):
  //   - hides the coffee form, shows the recipe form nested in the drawer body
  //   - on recipe save: restores the coffee form and appends the new card
  // When the button is outside the drawer (coffee detail page):
  //   - navigates directly to the recipe add page (full-page navigation)
  Drupal.behaviors.cjAddRecipeFromBean = {
    attach: function (context) {
      once('cj-add-recipe', '[data-cj-add-recipe]', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();

          // NID is set as a data attribute on the button by the server.
          var beanNid = btn.getAttribute('data-bean-nid') || null;

          // Build recipe add URL
          var url = '/node/add/brew_recipe';
          if (beanNid) url += '?field_coffee_bean_ref_target_id=' + encodeURIComponent(beanNid);

          // Detect whether the button is inside the drawer.
          // If so, open the recipe form nested inside the drawer body.
          // Otherwise (e.g. detail page), navigate directly to the recipe form.
          var drawerBody = document.getElementById('cj-drawer-body');
          var coffeeForm = btn.closest('form.node-coffee-bean-form, form.node-coffee-bean-edit-form');
          if (drawerBody && drawerBody.contains(btn) && coffeeForm) {
            // Create nested container
            var nested = drawerBody.querySelector('#cj-drawer-nested');
            if (!nested) {
              nested = document.createElement('div');
              nested.id = 'cj-drawer-nested';
              nested.className = 'cj-drawer-nested';
              // Hide the coffee form visually but keep it in DOM
              coffeeForm.setAttribute('aria-hidden', 'true');
              coffeeForm.style.display = 'none';
              drawerBody.appendChild(nested);
            }

            // Show spinner while loading
            nested.innerHTML = '<div class="cj-spinner">' + Drupal.t('Loading…') + '</div>';

            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
              .then(function (resp) { if (!resp.ok) throw new Error('HTTP ' + resp.status); return resp.text(); })
              .then(function (html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                // Extract the form (main content) — reuse same strategy as drawer
                var main = doc.getElementById('cj-main');
                var content = main ? main.innerHTML : doc.querySelector('.cj-page-inner') ? doc.querySelector('.cj-page-inner').outerHTML : doc.body.innerHTML;
                nested.innerHTML = content;
                Drupal.attachBehaviors(nested, drupalSettings);

                // Wire nested form submit similar to top-level _wireFormSubmit but keep drawer open
                var nestedForm = nested.querySelector('form.node-brew-recipe-form, form.node-brew-recipe-add-form, form.node-form');
                if (nestedForm) {
                  // Remove preview
                  var preview = nestedForm.querySelector('[data-drupal-selector="edit-preview"], input[value="Preview"], button[value="Preview"]');
                  if (preview) preview.remove();

                  once('cj-nested-recipe-submit', nestedForm).forEach(function (f) {
                    f.addEventListener('submit', function (ev) {
                      ev.preventDefault();
                      var submitBtn = f.querySelector('[data-drupal-selector="edit-submit"]') || f.querySelector('input[type="submit"], button[type="submit"]');
                      var originalText = submitBtn ? (submitBtn.value || submitBtn.textContent) : '';
                      if (submitBtn) { submitBtn.disabled = true; if (submitBtn.tagName === 'INPUT') submitBtn.value = Drupal.t('Saving…'); else submitBtn.textContent = Drupal.t('Saving…'); }

                      var formData = new FormData(f);
                      fetch(f.action || url, { method: 'POST', credentials: 'same-origin', body: formData, redirect: 'manual' })
                        .then(function (resp) {
                          var location = resp.headers.get('Location') || resp.url || '';
                          var isSuccess = (resp.type === 'opaqueredirect') || (resp.status >= 300 && resp.status < 400) || (location && location.indexOf('/node/') !== -1 && location.indexOf('/add') === -1 && location.indexOf('/edit') === -1);
                          if (isSuccess) {
                            // Extract node id from location if present
                            var nidMatch = location.match(/\/node\/(\d+)/);
                            var newNid = nidMatch ? nidMatch[1] : null;
                            // Remove nested form and unhide coffee form
                            nested.remove();
                            coffeeForm.removeAttribute('aria-hidden');
                            coffeeForm.style.display = '';

                            // If we have new nid, fetch its teaser/card HTML and append to #cj-bean-recipes
                            if (newNid) {
                              fetch('/node/' + newNid, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                                .then(function (html2) {
                                  var p = new DOMParser();
                                  var d2 = p.parseFromString(html2, 'text/html');
                                  // Try to find a recipe card or teaser in the response
                                  var card = d2.querySelector('.cj-recipe-card, article.cj-recipe-card');
                                  var wrap = document.getElementById('cj-bean-recipes');
                                  if (wrap) {
                                    if (card) {
                                      wrap.insertAdjacentElement('afterbegin', card);
                                    } else {
                                      // Fallback: create a simple link
                                      var a = document.createElement('a');
                                      a.href = '/node/' + newNid;
                                      a.textContent = Drupal.t('View recipe');
                                      var div = document.createElement('div'); div.className = 'cj-recipe-placeholder'; div.appendChild(a);
                                      wrap.insertAdjacentElement('afterbegin', div);
                                    }
                                  }
                                })
                                .catch(function (err2) { console.error('Failed to fetch new recipe teaser', err2); });
                            }
                            // Optionally show a small status banner
                            var banner = document.createElement('div'); banner.className = 'messages messages--status'; banner.textContent = Drupal.t('Recipe saved — added to this coffee.');
                            coffeeForm.insertBefore(banner, coffeeForm.firstChild);
                            setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 4000);
                          } else {
                            // Validation errors — replace nested container contents with response
                            resp.text().then(function (htmlResp) {
                              var parser = new DOMParser();
                              var doc = parser.parseFromString(htmlResp, 'text/html');
                              var main = doc.getElementById('cj-main');
                              var content = main ? main.innerHTML : doc.body.innerHTML;
                              nested.innerHTML = content;
                              Drupal.attachBehaviors(nested, drupalSettings);
                            });
                          }
                        })
                        .catch(function (err) {
                          console.error('Nested recipe submit failed', err);
                          if (submitBtn) {
                            submitBtn.disabled = false; if (submitBtn.tagName === 'INPUT') submitBtn.value = originalText; else submitBtn.textContent = originalText;
                          }
                        });
                    });
                  });
                }
              })
              .catch(function (err) {
                nested.innerHTML = '<div class="messages messages--error">' + Drupal.t('Could not load recipe form. @err', { '@err': err.message }) + '</div>';
              });

          } else {
            // Not inside the drawer (e.g. detail page) — open in the shared drawer.
            var openFn = Drupal.behaviors.cjAddDrawer && Drupal.behaviors.cjAddDrawer.openDrawer;
            if (openFn) {
              openFn(url, Drupal.t('Add Recipe'), false);
            } else {
              window.location.href = url;
            }
          }
        });
      });
    }
  };


  // ── 8. SEGMENTED ROAST CONTROL ──────────────────────────────────────────

  Drupal.behaviors.cjSegmentedRoast = {
    attach: function (context) {
      // Find all segmented roast controls
      once('cj-roast-seg', '[data-cj-roast-control]', context).forEach(function (container) {
        var select = container.querySelector('select');
        if (!select) return;

        var currentValue = select.value;
        var roastLabels = {
          'light':        'Light',
          'light_medium': 'Lt-Med',
          'medium':       'Medium',
          'medium_dark':  'Med-Dark',
          'dark':         'Dark'
        };

        // Create buttons for each roast option
        select.style.display = 'none';
        var segmented = document.createElement('div');
        segmented.className = 'cj-segmented-buttons';

        Array.from(select.options).forEach(function (option) {
          if (!option.value) return; // Skip empty option
          
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'cj-seg-btn';
          btn.dataset.value = option.value;
          btn.textContent = roastLabels[option.value] || option.text;
          
          if (option.value === currentValue) {
            btn.classList.add('active');
          }
          
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            // Update select value
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            
            // Update button states
            segmented.querySelectorAll('.cj-seg-btn').forEach(function (b) {
              b.classList.toggle('active', b.dataset.value === option.value);
            });
          });
          
          segmented.appendChild(btn);
        });

        // Listen to select changes (for programmatic updates)
        select.addEventListener('change', function () {
          segmented.querySelectorAll('.cj-seg-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.value === select.value);
          });
        });

        select.parentNode.insertBefore(segmented, select);
      });
    }
  };

  // ── 8. SEGMENTED ROAST CONTROL ──────────────────────────────────────────

  Drupal.behaviors.cjSegmentedRoast = {
    attach: function (context) {
      // Find all segmented roast controls
      once('cj-roast-seg', '[data-cj-roast-control]', context).forEach(function (container) {
        var select = container.querySelector('select');
        if (!select) return;

        var currentValue = select.value;
        var roastLabels = {
          'light':        'Light',
          'light_medium': 'Lt-Med',
          'medium':       'Medium',
          'medium_dark':  'Med-Dark',
          'dark':         'Dark'
        };

        // Create buttons for each roast option
        select.style.display = 'none';
        var segmented = document.createElement('div');
        segmented.className = 'cj-segmented-buttons';

        Array.from(select.options).forEach(function (option) {
          if (!option.value) return; // Skip empty option
          
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'cj-seg-btn';
          btn.dataset.value = option.value;
          btn.textContent = roastLabels[option.value] || option.text;
          
          if (option.value === currentValue) {
            btn.classList.add('active');
          }
          
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            // Update select value
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            
            // Update button states
            segmented.querySelectorAll('.cj-seg-btn').forEach(function (b) {
              b.classList.toggle('active', b.dataset.value === option.value);
            });
          });
          
          segmented.appendChild(btn);
        });

        // Listen to select changes (for programmatic updates)
        select.addEventListener('change', function () {
          segmented.querySelectorAll('.cj-seg-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.value === select.value);
          });
        });

        select.parentNode.insertBefore(segmented, select);
      });
    }
  };

})(Drupal, drupalSettings);

  // ── 9. BOTTOM NAVIGATION — Active state management ─────────────────────────

  Drupal.behaviors.cjBottomNav = {
    attach: function (context) {
      // Set active nav item based on current route
      var navItems = [
        { selector: '[data-cj-nav-feed]', routes: ['coffee_journal_api.global_feed'] },
        { selector: '[data-cj-nav-my-coffees]', routes: ['view.coffee_journal.page_1'] },
        { selector: '[data-cj-nav-profile]', routes: ['coffee_journal_access.profile'] }
      ];

      function updateActiveNav() {
        // Get current route/path
        var currentPath = window.location.pathname;
        var isHomepage = currentPath === '/' || currentPath === '/feed';
        
        navItems.forEach(function (item) {
          var element = document.querySelector(item.selector);
          if (!element) return;
          
          var href = element.getAttribute('href');
          var isActive = false;
          
          // Check if link href matches current path
          if (href && (currentPath.startsWith(href) || (isHomepage && (href.includes('/feed') || href === '/')))) {
            isActive = true;
          }
          
          element.classList.toggle('active', isActive);
        });
      }

      // Update on initial page load
      once('cj-bottom-nav', '#cj-bottom-nav', context).forEach(function () {
        updateActiveNav();
      });

      // Update when navigation occurs (for SPAs or page transitions)
      window.addEventListener('popstate', updateActiveNav);
    }
  };

