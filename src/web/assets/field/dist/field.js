/* SEO by ROOGUL — field UI: live search preview and character counts. */
(function(window) {
  'use strict';

  var TITLE_MAX = 60;
  var DESC_MAX = 160;

  function truncate(text, max) {
    return text.length > max ? text.slice(0, max - 1).replace(/\s+\S*$/, '') + '…' : text;
  }

  function prettyUrl(url) {
    try {
      var u = new URL(url);
      var parts = u.pathname.split('/').filter(Boolean);
      return [u.host].concat(parts).join(' › ');
    } catch (e) {
      return url;
    }
  }

  function count(el, length, max) {
    if (!el) return;
    el.textContent = length ? length + ' / ' + max : '';
    el.classList.toggle('seobr-count--over', length > max);
  }

  window.SeoByRoogulField = {
    init: function(id) {
      var root = document.getElementById(id);
      if (!root || root.dataset.seobrInit) return;
      root.dataset.seobrInit = '1';

      var cfg = JSON.parse(root.dataset.config || '{}');
      var titleInput = root.querySelector('[data-seobr-title]');
      var descInput = root.querySelector('[data-seobr-desc]');
      var rawInput = root.querySelector('input[type="hidden"][name$="[rawTitle]"]');
      var urlEl = root.querySelector('[data-seobr-url]');
      var titleEl = root.querySelector('[data-seobr-preview-title]');
      var descEl = root.querySelector('[data-seobr-preview-desc]');
      var titleCount = root.querySelector('[data-seobr-title-count]');
      var descCount = root.querySelector('[data-seobr-desc-count]');

      function fullTitle() {
        var own = titleInput ? titleInput.value.trim() : '';
        var raw = rawInput && rawInput.value === '1';

        // Homepage with no SEO title: just the site name
        if (cfg.isHomepage && !own) {
          return cfg.siteName;
        }

        var title = own || cfg.fallbackTitle || cfg.siteName;

        if (raw || !title || title === cfg.siteName || cfg.titleTemplate.indexOf('{title}') === -1) {
          return title;
        }

        return cfg.titleTemplate.replace('{title}', title).replace('{siteName}', cfg.siteName);
      }

      function update() {
        var title = fullTitle();
        var desc = (descInput ? descInput.value.trim() : '') || cfg.fallbackDescription || '';

        if (urlEl) urlEl.textContent = prettyUrl(cfg.url || '');
        if (titleEl) titleEl.textContent = truncate(title, TITLE_MAX);
        if (descEl) {
          descEl.textContent = desc ? truncate(desc, DESC_MAX) : 'No description: search engines will pick some text from the page.';
          descEl.classList.toggle('seobr-preview__desc--empty', !desc);
        }

        count(titleCount, title.length, TITLE_MAX);
        count(descCount, descInput ? descInput.value.trim().length : 0, DESC_MAX);
      }

      // Craft's lightswitch triggers jQuery events, so listen with jQuery when it's there.
      if (window.jQuery) {
        window.jQuery(root).on('input change', update);
      } else {
        root.addEventListener('input', update);
        root.addEventListener('change', update);
      }

      update();
    },
  };
})(window);
