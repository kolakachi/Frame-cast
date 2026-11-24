/* The funnel's buy and "no thanks" links (Launchpad direct checkout links, 2026-10-21). Order bumps are offered on
   Launchpad's checkout, so each product has one link. The visitor's query string (affiliate r/sp and the like) is
   carried onto the checkout, as Launchpad's own embed does. */
(function () {
  'use strict';

  var CHECKOUT = {
    fe: 'https://launchpadjv.com/product/checkout/5945?page=5065&offer=601&offerProduct=6942',
    bundle: 'https://launchpadjv.com/product/checkout/5946?page=5066&offer=601&offerProduct=6944',
    pro: 'https://launchpadjv.com/product/checkout/5947?page=5068&offer=601&offerProduct=6945',
    offeriq: 'https://launchpadjv.com/product/checkout/5948?page=5069&offer=601&offerProduct=6946',
    agency: 'https://launchpadjv.com/product/checkout/5949?page=5070&offer=601&offerProduct=6947'
  };

  // The funnel: FE -> Bundle (no: Pro) -> OfferIQ -> Agency -> thank-you. Launchpad's embedded "no thanks" leads;
  // ours is the fallback, hidden once the embed has drawn its own.
  var DECLINE = { bundle: '/pro', pro: '/offeriq', offeriq: '/agency', agency: '/thank-you' };

  var variantMeta = document.querySelector('meta[name="lp-variant"]');
  var variant = variantMeta ? variantMeta.getAttribute('content') : null;

  function checkoutUrl(key) {
    var base = CHECKOUT[key];
    if (!base) return null;
    try {
      var url = new URL(base);
      new URLSearchParams(location.search).forEach(function (v, k) {
        if (!url.searchParams.has(k)) url.searchParams.set(k, v);
      });
      return url.toString();
    } catch (e) { return base; }
  }

  document.querySelectorAll('[data-buy]').forEach(function (a) {
    var key = a.getAttribute('data-buy');
    var url = checkoutUrl(key);
    if (url) {
      a.href = url;
      a.removeAttribute('aria-disabled');
    } else {
      a.href = '#';
      a.setAttribute('aria-disabled', 'true');
    }
    a.addEventListener('click', function () {
      if (window.posthog && window.posthog.capture) window.posthog.capture('lp_checkout_click', { product: key, lp_variant: variant, page: location.pathname });
    });
  });
  document.querySelectorAll('[data-decline]').forEach(function (a) {
    a.href = DECLINE[a.getAttribute('data-decline')] || '/thank-you';
  });
  if (document.querySelector('.forgeContainer')) {
    var tries = 0, poll = setInterval(function () {
      if (document.querySelector('.forgeContainer .btn-forge-continue')) {
        document.querySelectorAll('[data-decline]').forEach(function (a) { a.style.display = 'none'; });
        clearInterval(poll);
      } else if (++tries > 40) clearInterval(poll);
    }, 250);
  }
  if (variant && window.posthog && window.posthog.register) window.posthog.register({ lp_variant: variant });

  // 1. Theme Management (Cooperative with window.wyvToggleTheme)
  var root = document.documentElement;
  var toggle = document.getElementById('theme-toggle');
  var toggleLabel = document.getElementById('theme-toggle-label');

  if (typeof window.wyvToggleTheme !== 'function') {
    function getTheme() {
      try {
        var stored = localStorage.getItem('wyv-theme') || localStorage.getItem('wyv_theme');
        if (stored) return stored;
      } catch (e) {}
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
      var isDark = theme === 'dark';
      root.classList.toggle('dark', isDark);
      root.setAttribute('data-theme', theme);
      if (toggle) {
        toggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        if (toggleLabel) toggleLabel.textContent = isDark ? 'Dark mode' : 'Light mode';
      }
      var sun = document.getElementById('theme-icon-sun');
      var moon = document.getElementById('theme-icon-moon');
      if (sun) sun.classList.toggle('hidden', !isDark);
      if (moon) moon.classList.toggle('hidden', isDark);
      try {
        localStorage.setItem('wyv-theme', theme);
        localStorage.setItem('wyv_theme', theme);
      } catch (e) {}
    }

    if (toggle) {
      toggle.addEventListener('click', function () {
        var current = root.classList.contains('dark') ? 'dark' : 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    }

    applyTheme(getTheme());
  }

  // 2. CutHeading Splitter & Scroll Observer
  function initCutHeadings() {
    var headings = document.querySelectorAll('[data-cut-heading]');
    headings.forEach(function (heading) {
      var text = heading.getAttribute('data-cut-text') || '';
      var accent = heading.getAttribute('data-cut-accent') || '';
      heading.setAttribute('aria-label', (text + ' ' + accent).trim());

      var wrapper = document.createElement('span');
      wrapper.setAttribute('aria-hidden', 'true');

      function buildWords(str, isAccent, offset) {
        var words = str.split(' ').filter(Boolean);
        words.forEach(function (word, wi) {
          var wordSpan = document.createElement('span');
          wordSpan.className = 'inline-block overflow-hidden pb-[0.12em] align-bottom';

          Array.from(word).forEach(function (char, ci) {
            var charSpan = document.createElement('span');
            charSpan.className = 'cut-char' + (isAccent ? ' font-serif-accent text-primary' : '');
            charSpan.style.animationDelay = ((offset + wi * 3 + ci) * 18) + 'ms';
            charSpan.textContent = char;
            wordSpan.appendChild(charSpan);
          });

          var spaceSpan = document.createElement('span');
          spaceSpan.className = 'inline-block';
          spaceSpan.innerHTML = '&nbsp;';
          wordSpan.appendChild(spaceSpan);

          wrapper.appendChild(wordSpan);
        });
      }

      buildWords(text, false, 0);
      if (accent) {
        buildWords(accent, true, 12);
      }

      heading.innerHTML = '';
      heading.appendChild(wrapper);
    });

    if ('IntersectionObserver' in window) {
      var cutObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('cut-in');
            cutObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.3 });

      headings.forEach(function (h) { cutObserver.observe(h); });
    } else {
      headings.forEach(function (h) { h.classList.add('cut-in'); });
    }
  }

  // 3. Reveal Elements Scroll Observer
  function initReveals() {
    var reveals = document.querySelectorAll('.reveal-ready');
    if ('IntersectionObserver' in window) {
      var revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('reveal-in');
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

      reveals.forEach(function (el) { revealObserver.observe(el); });
    } else {
      reveals.forEach(function (el) { el.classList.add('reveal-in'); });
    }
  }

  // 4. Cost Comparison Bar Growth Trigger
  function initCostComparison() {
    var table = document.getElementById('cost-comparison-table');
    if (!table) return;

    if ('IntersectionObserver' in window) {
      var barObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('bar-in');
            barObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.25 });

      barObserver.observe(table);
    } else {
      table.classList.add('bar-in');
    }
  }

  // 5. Hero Glow Mouse Parallax
  function initHeroGlow() {
    var hero = document.getElementById('top');
    var glow = document.getElementById('hero-glow');
    if (!hero || !glow) return;

    var raf = 0;
    hero.addEventListener('mousemove', function (e) {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(function () {
        var rect = hero.getBoundingClientRect();
        var x = ((e.clientX - rect.left) / rect.width - 0.5) * 60;
        var y = ((e.clientY - rect.top) / rect.height - 0.5) * 40;
        glow.style.transform = 'translate3d(' + x.toFixed(2) + 'px, ' + y.toFixed(2) + 'px, 0)';
      });
    });
  }

  // 6. FAQ Accordion Interaction
  function initFaq() {
    var faqButtons = document.querySelectorAll('[data-faq-btn]');
    faqButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var isOpen = btn.getAttribute('aria-expanded') === 'true';
        var panel = btn.nextElementSibling;
        var icon = btn.querySelector('[data-faq-icon]');

        // Close other panels for clean single accordion feel
        faqButtons.forEach(function (otherBtn) {
          if (otherBtn !== btn) {
            otherBtn.setAttribute('aria-expanded', 'false');
            var otherPanel = otherBtn.nextElementSibling;
            var otherIcon = otherBtn.querySelector('[data-faq-icon]');
            if (otherPanel) otherPanel.classList.remove('faq-panel-open');
            if (otherIcon) otherIcon.classList.remove('rotate-45');
          }
        });

        // Toggle clicked panel
        if (isOpen) {
          btn.setAttribute('aria-expanded', 'false');
          if (panel) panel.classList.remove('faq-panel-open');
          if (icon) icon.classList.remove('rotate-45');
        } else {
          btn.setAttribute('aria-expanded', 'true');
          if (panel) panel.classList.add('faq-panel-open');
          if (icon) icon.classList.add('rotate-45');
        }
      });
    });
  }

  // 7. Cookie Notice Banner
  function initCookieNotice() {
    var notice = document.getElementById('cookie-notice');
    if (!notice) return;

    // The main site's consent record (cookie-notice.js): the same choice holds here, on wyvstudio.com and in the app.
    var KEY = 'wyv_cookie_notice_v2';
    var chosen = false;
    try { chosen = /(?:^|;\s*)wyv_consent=(all|essential)/.test(document.cookie) || !!localStorage.getItem(KEY); } catch (e) {}
    if (!chosen) notice.classList.remove('hidden');

    var essentialBtn = document.getElementById('cookie-essential');
    var allowBtn = document.getElementById('cookie-allow');

    function dismiss(all) {
      try { localStorage.setItem(KEY, JSON.stringify({ ts: Date.now(), choice: all ? 'all' : 'essential' })); } catch (e) {}
      try {
        var domain = location.hostname.slice(-13) === 'wyvstudio.com' ? '; domain=.wyvstudio.com' : '';
        document.cookie = 'wyv_consent=' + (all ? 'all' : 'essential') + '; max-age=' + (180 * 86400) + '; path=/; samesite=lax' + domain + (location.protocol === 'https:' ? '; secure' : '');
      } catch (e) {}
      if (all && window.posthog && window.posthog.set_config) window.posthog.set_config({ persistence: 'localStorage+cookie' });
      notice.classList.add('hidden');
    }

    if (essentialBtn) essentialBtn.addEventListener('click', function () { dismiss(false); });
    if (allowBtn) allowBtn.addEventListener('click', function () { dismiss(true); });
  }

  // 8. Sample Videos Autoplay on Screen
  function initVideoAutoplay() {
    var vids = document.querySelectorAll('video[data-autoplay]');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.play().catch(function () {});
          } else {
            entry.target.pause();
          }
        });
      }, { threshold: 0.35 });

      vids.forEach(function (v) { io.observe(v); });
    }
  }

  // 9. Video Tile Playback — CSS animation handles scrolling; JS only toggles play/pause
  function initVideoTiles() {
    var wrapper = document.querySelector('.infinite-video-wrapper');
    if (!wrapper || wrapper._tilesBooted) return;
    wrapper._tilesBooted = true;

    var container = wrapper.querySelector('.video-scroll-container');
    var tiles = wrapper.querySelectorAll('.video-tile');

    // Pause CSS animation on touch so users feel a natural pause
    if (container) {
      container.addEventListener('touchstart', function () {
        container.classList.add('marquee-paused');
      }, { passive: true });
      container.addEventListener('touchend', function () {
        if (!wrapper.querySelector('.video-tile.is-playing')) {
          container.classList.remove('marquee-paused');
        }
      }, { passive: true });
      container.addEventListener('touchcancel', function () {
        if (!wrapper.querySelector('.video-tile.is-playing')) {
          container.classList.remove('marquee-paused');
        }
      }, { passive: true });
    }

    tiles.forEach(function (tile) {
      var vid = tile.querySelector('video');
      var btn = tile.querySelector('.play-btn');
      var box = tile.querySelector('.vid-box');
      if (!vid) return;

      function togglePlay(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (vid.paused) {
          tiles.forEach(function (t) {
            var tv = t.querySelector('video');
            if (tv && tv !== vid) { tv.pause(); t.classList.remove('is-playing'); }
          });
          vid.muted = false;
          vid.play().then(function () {
            tile.classList.add('is-playing');
            if (container) container.classList.add('marquee-paused');
          }).catch(function () {
            vid.muted = true;
            vid.play().then(function () {
              tile.classList.add('is-playing');
              if (container) container.classList.add('marquee-paused');
            }).catch(function () {});
          });
        } else {
          vid.pause();
          tile.classList.remove('is-playing');
          if (container && !wrapper.querySelector('.video-tile.is-playing')) {
            container.classList.remove('marquee-paused');
          }
        }
      }

      if (btn) btn.addEventListener('click', togglePlay);
      if (box) box.addEventListener('click', togglePlay);
      vid.addEventListener('ended', function () {
        tile.classList.remove('is-playing');
        vid.currentTime = 0;
        if (container && !wrapper.querySelector('.video-tile.is-playing')) {
          container.classList.remove('marquee-paused');
        }
      });
    });
  }

  // Initialize all interactive modules on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initCutHeadings();
      initReveals();
      initCostComparison();
      initHeroGlow();
      initFaq();
      initCookieNotice();
      initVideoAutoplay();
      initVideoTiles();
    });
  } else {
    initCutHeadings();
    initReveals();
    initCostComparison();
    initHeroGlow();
    initFaq();
    initCookieNotice();
    initVideoAutoplay();
    initVideoTiles();
  }

})();

