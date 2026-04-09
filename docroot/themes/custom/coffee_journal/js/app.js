/**
 * Coffee Journal — App JS
 * Handles: dark/light mode toggle, admin toolbar offset
 *
 * Note: The FAB (+) button is now rendered server-side in the view templates
 * and is always present for all authenticated users regardless of JS.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.coffeeJournal = {
    attach: function (context) {

      // ── THEME TOGGLE ──────────────────────────────────────────────────────

      function getTheme() {
        var stored = localStorage.getItem('cj-theme');
        if (stored) return stored;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }

      function applyTheme(theme) {
        var body = document.body;
        if (theme === 'light') {
          body.classList.remove('dark-mode');
          body.classList.add('light-mode');
        } else {
          body.classList.remove('light-mode');
          body.classList.add('dark-mode');
        }
        var iconEl = document.querySelector('#cj-theme-toggle span');
        if (iconEl) iconEl.textContent = theme === 'light' ? '🌙' : '☀️';
        var toggle = document.querySelector('#cj-theme-toggle');
        if (toggle) {
          toggle.setAttribute('aria-label', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
          toggle.setAttribute('title', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
        }
      }

      // Apply theme on first page load
      once('cj-theme-init', 'body', context).forEach(function () {
        applyTheme(getTheme());
      });

      // Wire up the toggle button click
      once('cj-toggle', '#cj-theme-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var current = document.body.classList.contains('light-mode') ? 'light' : 'dark';
          var next = current === 'light' ? 'dark' : 'light';
          localStorage.setItem('cj-theme', next);
          applyTheme(next);
        });
      });

    }
  };

})(Drupal, once);
