/**
 * Coffee Journal — App JS
 * Handles: dark/light mode toggle, FAB injection, admin toolbar offset
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.coffeeJournal = {
    attach: function (context) {

      // ── THEME TOGGLE ──────────────────────────────────────────────────────

      // Read stored theme or system preference
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
        var btn = document.querySelector('#cj-theme-toggle span');
        if (btn) btn.textContent = theme === 'light' ? '🌙' : '☀️';
        var toggle = document.querySelector('#cj-theme-toggle');
        if (toggle) toggle.setAttribute('aria-label', theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode');
      }

      // Apply theme on first attach (document context)
      once('cj-theme-init', 'body', context).forEach(function () {
        applyTheme(getTheme());
      });

      // Wire up toggle button
      once('cj-toggle', '#cj-theme-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var current = document.body.classList.contains('light-mode') ? 'light' : 'dark';
          var next = current === 'light' ? 'dark' : 'light';
          localStorage.setItem('cj-theme', next);
          applyTheme(next);
        });
      });

      // ── FLOATING ACTION BUTTON ────────────────────────────────────────────

      once('cj-fab', 'body', context).forEach(function () {
        // Only show FAB on journal / frontpage listing views
        var isListing = document.querySelector('.view-coffee-journal, .view-frontpage');
        var isAddOrEdit = window.location.pathname.indexOf('/node/add') !== -1 ||
                          window.location.pathname.indexOf('/edit') !== -1 ||
                          window.location.pathname.indexOf('/delete') !== -1;

        if (isListing && !isAddOrEdit) {
          var fab = document.createElement('a');
          fab.href = '/node/add/coffee_bean';
          fab.className = 'cj-fab';
          fab.setAttribute('aria-label', Drupal.t('Add a Coffee Bean'));
          fab.innerHTML = '<span aria-hidden="true">+</span>';
          document.body.appendChild(fab);
        }
      });

      // ── EMPTY STATE: update view header text ──────────────────────────────
      // Replace plain "add" link text in view headers with a styled button
      once('cj-view-header', '.cj-view-header a', context).forEach(function (link) {
        if (!link.classList.contains('button')) {
          link.classList.add('button');
        }
      });

    }
  };

})(Drupal, once);
