/**
 * Coffee Journal — App JS
 *
 * Behaviours:
 *   1. Dark/light mode toggle (persisted in localStorage)
 *   2. Gemini Scraper modal (journal list page)
 *      - Open via #cj-scraper-trigger button or FAB secondary action
 *      - POST to /api/scrape-coffee, show preview, then redirect to
 *        /node/add/coffee_bean with extracted values pre-filled via
 *        sessionStorage (read by behaviour 3)
 *   3. Form autofill from sessionStorage (node add/edit page)
 *      - On /node/add/coffee_bean: reads sessionStorage['cj_prefill'],
 *        fills in title, brand, roast, notes, quantity, quantityUnit,
 *        formFactor, estate fields
 *   4. Rating input enhancement
 *      - Converts the plain number inputs for 1-10 / 1-5 ratings into
 *        visual dot/star selectors
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

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
