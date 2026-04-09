/**
 * Coffee Journal — Theme JS
 * Handles: dark/light mode toggle, FAB injection
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.coffeeJournalTheme = {
    attach: function (context, settings) {

      // ── 1. THEME (dark/light) TOGGLE ──────────────────────────────────────

      // Determine initial theme
      function getStoredTheme() {
        return localStorage.getItem('cj-theme');
      }
      function getSystemTheme() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      function applyTheme(theme) {
        if (theme === 'light') {
          document.body.classList.remove('dark-mode');
          document.body.classList.add('light-mode');
          var btn = document.querySelector('.cj-theme-toggle');
          if (btn) btn.title = 'Switch to dark mode';
          if (btn) btn.textContent = '☀️';
        } else {
          document.body.classList.remove('light-mode');
          document.body.classList.add('dark-mode');
          var btn = document.querySelector('.cj-theme-toggle');
          if (btn) btn.title = 'Switch to light mode';
          if (btn) btn.textContent = '🌙';
        }
      }

      // Apply on load (once)
      if (context === document) {
        var stored = getStoredTheme();
        applyTheme(stored || getSystemTheme());
      }

      // Inject toggle button once
      once('cj-toggle', 'body', context).forEach(function () {
        var btn = document.createElement('button');
        btn.className = 'cj-theme-toggle';
        var currentTheme = getStoredTheme() || getSystemTheme();
        btn.textContent = currentTheme === 'light' ? '☀️' : '🌙';
        btn.title = currentTheme === 'light' ? 'Switch to dark mode' : 'Switch to light mode';
        btn.setAttribute('aria-label', 'Toggle colour theme');
        btn.addEventListener('click', function () {
          var isLight = document.body.classList.contains('light-mode');
          var newTheme = isLight ? 'dark' : 'light';
          localStorage.setItem('cj-theme', newTheme);
          applyTheme(newTheme);
        });
        document.body.appendChild(btn);
      });

      // ── 2. FAB — FLOATING ADD BUTTON ──────────────────────────────────────

      // Only inject on journal and frontpage views
      once('cj-fab', 'body', context).forEach(function () {
        var isJournal  = document.querySelector('.view-coffee-journal');
        var isFront    = document.querySelector('.view-frontpage');
        var isAddPage  = window.location.pathname.indexOf('/node/add') !== -1;
        var isEditPage = window.location.pathname.indexOf('/edit') !== -1;

        if ((isJournal || isFront) && !isAddPage && !isEditPage) {
          var fab = document.createElement('a');
          fab.href = '/node/add/coffee_bean';
          fab.className = 'cj-fab';
          fab.setAttribute('data-label', 'Add a Coffee Bean');
          fab.setAttribute('aria-label', 'Add a new coffee bean');
          fab.innerHTML = '<span aria-hidden="true">+</span>';
          document.body.appendChild(fab);
        }
      });

    }
  };

})(Drupal);
