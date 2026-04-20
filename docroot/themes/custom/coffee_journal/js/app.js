/**
 * Coffee Journal — App JS
 *
 * Behaviours:
 *   1. Dark/light mode toggle (persisted in localStorage)
 *   2. Gemini Scraper modal (journal list page)
 *      - Open via #cj-scraper-trigger button
 *      - POST to /api/scrape-coffee, show preview, redirect to form via sessionStorage
 *   3. Form autofill from sessionStorage (node add/edit page)
 *      - Reads sessionStorage['cj_prefill'], fills form fields on arrival
 *   4. Inline form scraper (directly on the add/edit form page)
 *      - Matches source app's "Auto-fill details from URL" inline widget
 *      - POSTs to /api/scrape-coffee and fills fields without redirect
 *   5. Rating input enhancement
 *      - 1-10 fields (bitterness, acidity, note clarity): IonRange-style slider
 *      - 1-5 field (overall taste): clickable star widget
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // ── Shared fill helpers (used by behaviours 3 and 4) ─────────────────────

  function _fillText(selector, value) {
    if (!value && value !== 0) return;
    var el = document.querySelector(selector);
    if (el) {
      el.value = value;
      el.dispatchEvent(new Event('change', { bubbles: true }));
      el.dispatchEvent(new Event('input',  { bubbles: true }));
    }
  }

  function _fillSelect(selector, value) {
    if (!value) return;
    var el = document.querySelector(selector);
    if (!el || el.tagName !== 'SELECT') return;
    var lv = value.toLowerCase().replace(/[- ]/g, '_');
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

  function _fillFormFromScraped(data) {
    if (!data) return;
    _fillText('#edit-title-0-value',               data.coffeeName);
    _fillText('#edit-field-brand-roaster-0-value', data.brandName);
    _fillText('#edit-field-estate-0-value',        data.estate);
    _fillText('#edit-field-flavour-notes-0-value', data.notes);
    _fillText('#edit-field-quantity-0-value',      data.quantity);
    _fillSelect('#edit-field-roast-level',         data.roast);
    _fillSelect('#edit-field-quantity-unit',       data.quantityUnit || 'g');

    // Also update range sliders if they've already been rendered by behaviour 5
    var sliderFields = {
      'edit-field-bitterness-rating-0-value':   data.bitternessRating,
      'edit-field-acidity-rating-0-value':      data.acidityRating,
      'edit-field-note-clarity-rating-0-value': data.noteClarityRating,
    };
    Object.keys(sliderFields).forEach(function (id) {
      var val = sliderFields[id];
      if (!val) return;
      var hiddenInput = document.getElementById(id);
      var rangeInput  = document.getElementById('cj-range-' + id);
      if (hiddenInput) {
        hiddenInput.value = val;
        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (rangeInput) {
        rangeInput.value = val;
        rangeInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
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
        document.body.classList.toggle('dark-mode', theme !== 'light');
        var icon = document.querySelector('#cj-theme-toggle span');
        if (icon) icon.textContent = theme === 'light' ? '🌙' : '☀️';
        var btn = document.querySelector('#cj-theme-toggle');
        if (btn) {
          btn.setAttribute('aria-label', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
          btn.setAttribute('title',      theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
        }
      }

      once('cj-theme-init', 'body', context).forEach(function () {
        applyTheme(getTheme());
      });

      once('cj-toggle', '#cj-theme-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var next = document.body.classList.contains('light-mode') ? 'dark' : 'light';
          localStorage.setItem('cj-theme', next);
          applyTheme(next);
        });
      });
    }
  };

  // ── 2. GEMINI SCRAPER MODAL (journal list page) ──────────────────────────

  Drupal.behaviors.cjScraperModal = {
    attach: function (context) {

      var modal = context.querySelector
        ? context.querySelector('#cj-scraper-modal')
        : document.getElementById('cj-scraper-modal');

      if (!modal) return;

      var backdrop  = document.getElementById('cj-scraper-backdrop');
      var closeBtn  = modal.querySelector('.cj-modal__close');
      var urlInput  = modal.querySelector('#cj-scraper-url');
      var submitBtn = modal.querySelector('#cj-scraper-submit');
      var statusEl  = modal.querySelector('#cj-scraper-status');
      var preview   = modal.querySelector('#cj-scraper-preview');
      var useBtn    = modal.querySelector('#cj-scraper-use');

      var previewName        = modal.querySelector('#cj-preview-name');
      var previewBrand       = modal.querySelector('#cj-preview-brand');
      var previewRoast       = modal.querySelector('#cj-preview-roast');
      var previewNotes       = modal.querySelector('#cj-preview-notes');
      var previewQuantity    = modal.querySelector('#cj-preview-quantity');
      var previewBrandRow    = modal.querySelector('#cj-preview-brand-row');
      var previewRoastRow    = modal.querySelector('#cj-preview-roast-row');
      var previewNotesRow    = modal.querySelector('#cj-preview-notes-row');
      var previewQuantityRow = modal.querySelector('#cj-preview-quantity-row');

      var lastScraped = null;

      function openModal() {
        modal.setAttribute('aria-hidden', 'false');
        modal.removeAttribute('inert');
        document.body.classList.add('cj-modal-open');
        setTimeout(function () { if (urlInput) urlInput.focus(); }, 80);
      }

      function closeModal() {
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('inert', '');
        document.body.classList.remove('cj-modal-open');
        if (urlInput)  urlInput.value = '';
        if (statusEl)  { statusEl.textContent = ''; statusEl.className = 'cj-modal__status'; }
        if (preview)   preview.hidden = true;
        lastScraped = null;
      }

      function setStatus(msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className   = 'cj-modal__status cj-modal__status--' + (type || 'info');
      }

      function setLoading(loading) {
        if (!submitBtn) return;
        var label   = submitBtn.querySelector('.cj-scraper-btn-label');
        var spinner = submitBtn.querySelector('.cj-scraper-spinner');
        submitBtn.disabled = loading;
        if (label)   label.textContent = loading ? Drupal.t('Extracting…') : Drupal.t('Extract');
        if (spinner) spinner.hidden = !loading;
      }

      once('cj-scraper-trigger', '#cj-scraper-trigger', context).forEach(function (btn) {
        btn.addEventListener('click', openModal);
      });

      once('cj-modal-close', modal, context).forEach(function () {
        if (closeBtn)  closeBtn.addEventListener('click', closeModal);
        if (backdrop)  backdrop.addEventListener('click', closeModal);
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') closeModal();
        });
      });

      once('cj-scraper-submit', '#cj-scraper-submit', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var url = urlInput ? urlInput.value.trim() : '';
          if (!url) { setStatus(Drupal.t('Please enter a URL.'), 'error'); if (urlInput) urlInput.focus(); return; }

          var endpoint = (drupalSettings.coffeeJournal && drupalSettings.coffeeJournal.scrapeEndpoint)
            ? drupalSettings.coffeeJournal.scrapeEndpoint : '/api/scrape-coffee';

          setLoading(true);
          setStatus(Drupal.t('Analysing page with AI…'), 'info');
          if (preview) preview.hidden = true;
          lastScraped = null;

          fetch(endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ url: url })
          })
          .then(function (resp) {
            if (!resp.ok) return resp.json().then(function (err) { throw new Error(err.error || ('HTTP ' + resp.status)); });
            return resp.json();
          })
          .then(function (data) {
            setLoading(false);
            if (!data || (!data.coffeeName && !data.brandName)) {
              setStatus(Drupal.t('Could not extract coffee details from that page. Try a different URL.'), 'error');
              return;
            }
            lastScraped = data;
            setStatus('', 'info');
            if (previewName) previewName.textContent = data.coffeeName || Drupal.t('(name not found)');
            _setRow(previewBrandRow,    previewBrand,    data.brandName);
            _setRow(previewRoastRow,    previewRoast,    data.roast);
            _setRow(previewNotesRow,    previewNotes,    data.notes);
            var qtyText = (data.quantity && data.quantityUnit) ? data.quantity + ' ' + data.quantityUnit : (data.quantity ? String(data.quantity) : null);
            _setRow(previewQuantityRow, previewQuantity, qtyText);
            if (preview) preview.hidden = false;
            if (useBtn)  useBtn.focus();
          })
          .catch(function (err) { setLoading(false); setStatus(Drupal.t('Error: @msg', { '@msg': err.message }), 'error'); });
        });
      });

      once('cj-scraper-url-enter', '#cj-scraper-url', context).forEach(function (input) {
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (submitBtn) submitBtn.click(); } });
      });

      once('cj-scraper-use', '#cj-scraper-use', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!lastScraped) return;
          try { sessionStorage.setItem('cj_prefill', JSON.stringify(lastScraped)); } catch (e) {}
          var addPath = (drupalSettings.coffeeJournal && drupalSettings.coffeeJournal.addCoffeePath)
            ? drupalSettings.coffeeJournal.addCoffeePath : '/node/add/coffee_bean';
          window.location.href = addPath;
        });
      });

      function _setRow(rowEl, valEl, value) {
        if (!rowEl || !valEl) return;
        if (value) { valEl.textContent = value; rowEl.hidden = false; } else { rowEl.hidden = true; }
      }
    }
  };

  // ── 3. FORM AUTOFILL FROM sessionStorage ─────────────────────────────────

  Drupal.behaviors.cjFormAutofill = {
    attach: function (context) {
      var form = once('cj-form-autofill', '.node-coffee-bean-form, .node-coffee-bean-edit-form', context);
      if (!form.length) return;

      var raw = null;
      try { raw = sessionStorage.getItem('cj_prefill'); } catch (e) {}
      if (!raw) return;

      var data = null;
      try { data = JSON.parse(raw); } catch (e) { return; }
      try { sessionStorage.removeItem('cj_prefill'); } catch (e) {}
      if (!data) return;

      _fillFormFromScraped(data);

      var banner = document.createElement('div');
      banner.className = 'messages messages--status cj-prefill-banner';
      banner.setAttribute('role', 'status');
      banner.textContent = Drupal.t('Fields pre-filled from AI extraction. Review and save.');
      var formEl = document.querySelector('.node-coffee-bean-form, .node-coffee-bean-edit-form');
      if (formEl) formEl.insertBefore(banner, formEl.firstChild);
      setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 6000);
    }
  };

  // ── 4. INLINE FORM SCRAPER (on the add/edit form page itself) ────────────

  Drupal.behaviors.cjFormScraper = {
    attach: function (context) {
      var scraper = once('cj-inline-scraper', '#cj-inline-scraper', context);
      if (!scraper.length) return;

      var urlInput  = document.getElementById('cj-inline-scraper-url');
      var btn       = document.getElementById('cj-inline-scraper-btn');
      var statusEl  = document.getElementById('cj-inline-scraper-status');

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
        if (!url) { setStatus(Drupal.t('Please enter a URL.'), 'error'); if (urlInput) urlInput.focus(); return; }

        var endpoint = (drupalSettings.coffeeJournal && drupalSettings.coffeeJournal.scrapeEndpoint)
          ? drupalSettings.coffeeJournal.scrapeEndpoint : '/api/scrape-coffee';

        setLoading(true);
        setStatus(Drupal.t('Analysing page with AI…'), 'info');

        fetch(endpoint, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ url: url })
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
          _fillFormFromScraped(data);
          setStatus(Drupal.t('Fields filled — review and save.'), 'success');
          if (urlInput) urlInput.value = '';
          // Scroll to first filled field
          var firstField = document.getElementById('edit-title-0-value');
          if (firstField) firstField.focus();
        })
        .catch(function (err) { setLoading(false); setStatus(Drupal.t('Error: @msg', { '@msg': err.message }), 'error'); });
      }

      if (btn) btn.addEventListener('click', doScrape);
      if (urlInput) {
        urlInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doScrape(); } });
      }
    }
  };

  // ── 5. RATING INPUT ENHANCEMENT ─────────────────────────────────────────

  Drupal.behaviors.cjRatingInputs = {
    attach: function (context) {

      // ── 5a. Range sliders for 1-10 fields (matches IonRange in source app) ──
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
          range.type  = 'range';
          range.id    = 'cj-range-' + cfg.id;
          range.min   = '1';
          range.max   = '10';
          range.step  = '1';
          range.value = current;
          range.className = 'cj-range-input';
          range.setAttribute('aria-label', cfg.label + ' rating 1 to 10');

          var valDisplay = document.createElement('span');
          valDisplay.className = 'cj-range-val';
          valDisplay.textContent = current + '/10';

          // Keep hidden number input in sync
          range.addEventListener('input', function () {
            var v = parseInt(this.value, 10);
            input.value = v;
            valDisplay.textContent = v + '/10';
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });

          // Sync range when hidden input changes (e.g. autofill)
          input.addEventListener('change', function () {
            var v = parseInt(this.value, 10) || 1;
            range.value = v;
            valDisplay.textContent = v + '/10';
          });

          wrap.appendChild(range);
          wrap.appendChild(valDisplay);

          // Hide original number input
          input.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none';
          input.setAttribute('tabindex', '-1');
          input.setAttribute('aria-hidden', 'true');

          input.parentNode.insertBefore(wrap, input.nextSibling);
        });
      });

      // ── 5b. Star widget for overall taste (1-5, matches StarRating component) ──
      once('cj-stars-overall', '#edit-field-overall-taste-rating-0-value', context).forEach(function (input) {
        var current = parseInt(input.value, 10) || 0;

        var widget = document.createElement('div');
        widget.className = 'cj-rating-widget';
        widget.setAttribute('role', 'group');
        widget.setAttribute('aria-label', 'Overall taste rating 1 to 5');

        for (var i = 1; i <= 5; i++) {
          (function (val) {
            var star = document.createElement('button');
            star.type = 'button';
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
        display.className = 'cj-rating-display';
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

})(Drupal, drupalSettings, once);

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
        document.body.classList.toggle('dark-mode', theme !== 'light');
        var icon = document.querySelector('#cj-theme-toggle span');
        if (icon) icon.textContent = theme === 'light' ? '🌙' : '☀️';
        var btn = document.querySelector('#cj-theme-toggle');
        if (btn) {
          btn.setAttribute('aria-label', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
          btn.setAttribute('title',      theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
        }
      }

      once('cj-theme-init', 'body', context).forEach(function () {
        applyTheme(getTheme());
      });

      once('cj-toggle', '#cj-theme-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var next = document.body.classList.contains('light-mode') ? 'dark' : 'light';
          localStorage.setItem('cj-theme', next);
          applyTheme(next);
        });
      });
    }
  };

  // ── 2. GEMINI SCRAPER MODAL ──────────────────────────────────────────────

  Drupal.behaviors.cjScraperModal = {
    attach: function (context) {

      var modal    = context.querySelector
        ? context.querySelector('#cj-scraper-modal')
        : document.getElementById('cj-scraper-modal');

      // Modal lives on the journal list page only.
      if (!modal) return;

      var backdrop  = document.getElementById('cj-scraper-backdrop');
      var trigger   = document.getElementById('cj-scraper-trigger');
      var closeBtn  = modal.querySelector('.cj-modal__close');
      var urlInput  = modal.querySelector('#cj-scraper-url');
      var submitBtn = modal.querySelector('#cj-scraper-submit');
      var statusEl  = modal.querySelector('#cj-scraper-status');
      var preview   = modal.querySelector('#cj-scraper-preview');
      var useBtn    = modal.querySelector('#cj-scraper-use');

      // Preview field references
      var previewName     = modal.querySelector('#cj-preview-name');
      var previewBrand    = modal.querySelector('#cj-preview-brand');
      var previewRoast    = modal.querySelector('#cj-preview-roast');
      var previewNotes    = modal.querySelector('#cj-preview-notes');
      var previewQuantity = modal.querySelector('#cj-preview-quantity');

      var previewBrandRow    = modal.querySelector('#cj-preview-brand-row');
      var previewRoastRow    = modal.querySelector('#cj-preview-roast-row');
      var previewNotesRow    = modal.querySelector('#cj-preview-notes-row');
      var previewQuantityRow = modal.querySelector('#cj-preview-quantity-row');

      // Holds the last successful scrape result
      var lastScraped = null;

      function openModal() {
        modal.setAttribute('aria-hidden', 'false');
        modal.removeAttribute('inert');
        document.body.classList.add('cj-modal-open');
        // Focus the URL input after transition
        setTimeout(function () { if (urlInput) urlInput.focus(); }, 80);
      }

      function closeModal() {
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('inert', '');
        document.body.classList.remove('cj-modal-open');
        // Reset state
        if (urlInput)  urlInput.value = '';
        if (statusEl)  { statusEl.textContent = ''; statusEl.className = 'cj-modal__status'; }
        if (preview)   preview.hidden = true;
        lastScraped = null;
      }

      function setStatus(msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className   = 'cj-modal__status cj-modal__status--' + (type || 'info');
      }

      function setLoading(loading) {
        if (!submitBtn) return;
        var label   = submitBtn.querySelector('.cj-scraper-btn-label');
        var spinner = submitBtn.querySelector('.cj-scraper-spinner');
        submitBtn.disabled = loading;
        if (label)   label.textContent = loading ? Drupal.t('Extracting…') : Drupal.t('Extract');
        if (spinner) spinner.hidden = !loading;
      }

      // Open via trigger button
      once('cj-scraper-trigger', '#cj-scraper-trigger', context).forEach(function (btn) {
        btn.addEventListener('click', openModal);
      });

      // Close via ×, backdrop click, or Escape key
      once('cj-modal-close', modal, context).forEach(function () {
        if (closeBtn)  closeBtn.addEventListener('click', closeModal);
        if (backdrop)  backdrop.addEventListener('click', closeModal);
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
            closeModal();
          }
        });
      });

      // Scrape submit
      once('cj-scraper-submit', '#cj-scraper-submit', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var url = urlInput ? urlInput.value.trim() : '';
          if (!url) {
            setStatus(Drupal.t('Please enter a URL.'), 'error');
            if (urlInput) urlInput.focus();
            return;
          }

          var endpoint = (drupalSettings.coffeeJournal && drupalSettings.coffeeJournal.scrapeEndpoint)
            ? drupalSettings.coffeeJournal.scrapeEndpoint
            : '/api/scrape-coffee';

          setLoading(true);
          setStatus(Drupal.t('Analysing page with AI…'), 'info');
          if (preview) preview.hidden = true;
          lastScraped = null;

          fetch(endpoint, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:        JSON.stringify({ url: url })
          })
          .then(function (resp) {
            if (!resp.ok) {
              return resp.json().then(function (err) {
                throw new Error(err.error || ('HTTP ' + resp.status));
              });
            }
            return resp.json();
          })
          .then(function (data) {
            setLoading(false);
            if (!data || (!data.coffeeName && !data.brandName)) {
              setStatus(Drupal.t('Could not extract coffee details from that page. Try a different URL.'), 'error');
              return;
            }

            lastScraped = data;
            setStatus('', 'info');

            // Populate preview panel
            if (previewName)  previewName.textContent  = data.coffeeName || Drupal.t('(name not found)');
            _setPreviewRow(previewBrandRow,    previewBrand,    data.brandName);
            _setPreviewRow(previewRoastRow,    previewRoast,    data.roast);
            _setPreviewRow(previewNotesRow,    previewNotes,    data.notes);

            var qtyText = (data.quantity && data.quantityUnit)
              ? data.quantity + ' ' + data.quantityUnit
              : (data.quantity ? String(data.quantity) : null);
            _setPreviewRow(previewQuantityRow, previewQuantity, qtyText);

            if (preview) preview.hidden = false;
            if (useBtn)  useBtn.focus();
          })
          .catch(function (err) {
            setLoading(false);
            setStatus(Drupal.t('Error: @msg', { '@msg': err.message }), 'error');
          });
        });
      });

      // Allow Enter key in URL input to trigger extract
      once('cj-scraper-url-enter', '#cj-scraper-url', context).forEach(function (input) {
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            if (submitBtn) submitBtn.click();
          }
        });
      });

      // "Use these details" — store in sessionStorage and redirect to add form
      once('cj-scraper-use', '#cj-scraper-use', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!lastScraped) return;
          try {
            sessionStorage.setItem('cj_prefill', JSON.stringify(lastScraped));
          } catch (e) { /* sessionStorage full or unavailable */ }

          var addPath = (drupalSettings.coffeeJournal && drupalSettings.coffeeJournal.addCoffeePath)
            ? drupalSettings.coffeeJournal.addCoffeePath
            : '/node/add/coffee_bean';

          window.location.href = addPath;
        });
      });

      // Helper: show/hide a preview row
      function _setPreviewRow(rowEl, valEl, value) {
        if (!rowEl || !valEl) return;
        if (value) {
          valEl.textContent = value;
          rowEl.hidden = false;
        } else {
          rowEl.hidden = true;
        }
      }
    }
  };

  // ── 3. FORM AUTOFILL FROM sessionStorage ─────────────────────────────────

  Drupal.behaviors.cjFormAutofill = {
    attach: function (context) {

      // Only fire on the coffee_bean add/edit form pages
      var form = once(
        'cj-form-autofill',
        '.node-coffee-bean-form, .node-coffee-bean-edit-form',
        context
      );
      if (!form.length) return;

      var raw = null;
      try { raw = sessionStorage.getItem('cj_prefill'); } catch (e) {}
      if (!raw) return;

      var data = null;
      try { data = JSON.parse(raw); } catch (e) { return; }

      // Clear immediately so navigating away and back doesn't re-fill.
      try { sessionStorage.removeItem('cj_prefill'); } catch (e) {}

      if (!data) return;

      // Helper: set a text/textarea input value
      function fill(selector, value) {
        if (!value && value !== 0) return;
        var el = document.querySelector(selector);
        if (el) {
          el.value = value;
          // Trigger Drupal's native change detection
          el.dispatchEvent(new Event('change', { bubbles: true }));
          el.dispatchEvent(new Event('input',  { bubbles: true }));
        }
      }

      // Helper: set a <select> by value (case-insensitive partial match)
      function fillSelect(selector, value) {
        if (!value) return;
        var el = document.querySelector(selector);
        if (!el || el.tagName !== 'SELECT') return;
        var lv = value.toLowerCase().replace(/[- ]/g, '_');
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

      // Map ScrapedCoffeeOutput fields → Drupal node form field selectors
      // Drupal generates IDs like: edit-title-0-value, edit-field-brand-roaster-0-value, etc.
      fill('#edit-title-0-value',                  data.coffeeName);
      fill('#edit-field-brand-roaster-0-value',    data.brandName);
      fill('#edit-field-estate-0-value',           data.estate);
      fill('#edit-field-flavour-notes-0-value',    data.notes);
      fill('#edit-field-quantity-0-value',         data.quantity);

      fillSelect('#edit-field-roast-level',        data.roast);
      fillSelect('#edit-field-quantity-unit',      data.quantityUnit || 'g');

      // Show a brief banner so the user knows fields were pre-filled.
      var banner = document.createElement('div');
      banner.className = 'messages messages--status cj-prefill-banner';
      banner.setAttribute('role', 'status');
      banner.textContent = Drupal.t('Fields pre-filled from AI extraction. Review and save.');
      var formEl = document.querySelector('.node-coffee-bean-form, .node-coffee-bean-edit-form');
      if (formEl) formEl.insertBefore(banner, formEl.firstChild);
      setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 6000);
    }
  };

  // ── 4. RATING INPUT ENHANCEMENT ─────────────────────────────────────────

  Drupal.behaviors.cjRatingInputs = {
    attach: function (context) {

      /**
       * Replaces a plain <input type="number"> with a row of clickable dots/stars
       * for rating fields. The original input stays in the DOM (hidden) as the
       * actual form value carrier so Drupal's form submission works normally.
       *
       * Config per field:
       *   max    — maximum rating (sets number of dots)
       *   symbol — character to render ('★' for 1-5, '●' for 1-10)
       */
      var ratingConfig = {
        'edit-field-overall-taste-rating-0-value': { max: 5,  symbol: '★', label: 'Overall taste' },
        'edit-field-bitterness-rating-0-value':    { max: 10, symbol: '●', label: 'Bitterness' },
        'edit-field-acidity-rating-0-value':       { max: 10, symbol: '●', label: 'Acidity' },
        'edit-field-note-clarity-rating-0-value':  { max: 10, symbol: '●', label: 'Note clarity' },
      };

      Object.keys(ratingConfig).forEach(function (inputId) {
        var cfg   = ratingConfig[inputId];

        once('cj-rating-' + inputId, '#' + inputId, context).forEach(function (input) {
          var current = parseInt(input.value, 10) || 0;

          // Build the visual widget
          var widget = document.createElement('div');
          widget.className = 'cj-rating-widget';
          widget.setAttribute('role', 'group');
          widget.setAttribute('aria-label', cfg.label);

          for (var i = 1; i <= cfg.max; i++) {
            (function (val) {
              var dot = document.createElement('button');
              dot.type = 'button';
              dot.className = 'cj-rating-dot' + (val <= current ? ' active' : '');
              dot.textContent = cfg.symbol;
              dot.setAttribute('aria-label', val + ' of ' + cfg.max);
              dot.setAttribute('aria-pressed', val <= current ? 'true' : 'false');

              dot.addEventListener('click', function () {
                input.value = val;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                // Update all dots in this widget
                var allDots = widget.querySelectorAll('.cj-rating-dot');
                allDots.forEach(function (d, idx) {
                  var active = idx < val;
                  d.classList.toggle('active', active);
                  d.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
              });

              widget.appendChild(dot);
            })(i);
          }

          // Value display
          var display = document.createElement('span');
          display.className = 'cj-rating-display';
          display.textContent = current ? current + '/' + cfg.max : '';
          widget.appendChild(display);

          // Update display when input changes (e.g. from autofill)
          input.addEventListener('change', function () {
            var v = parseInt(this.value, 10) || 0;
            display.textContent = v ? v + '/' + cfg.max : '';
            var allDots = widget.querySelectorAll('.cj-rating-dot');
            allDots.forEach(function (d, idx) {
              var active = idx < v;
              d.classList.toggle('active', active);
              d.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
          });

          // Hide the number input but keep it for form submission
          input.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none';
          input.setAttribute('tabindex', '-1');
          input.setAttribute('aria-hidden', 'true');

          // Insert widget directly after the input
          input.parentNode.insertBefore(widget, input.nextSibling);
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
