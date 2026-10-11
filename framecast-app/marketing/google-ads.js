/*
 * Google Ads tag (AW-18506583015), owner 2026-10-11, with Google Consent Mode v2.
 *
 * Load this first in <head>, before anything else that may call gtag. Until the visitor presses "Allow" in the cookie
 * notice, every consent signal is "denied": Google sets no cookies and gets only cookieless, anonymous pings. The
 * choice is kept by the cookie notice (cookie-notice.js) and applied here on every later page view.
 */
(function () {
  var ID = 'AW-18506583015';
  var choice = null;
  try { var m = document.cookie.match(/(?:^|;\s*)wyv_consent=(all|essential)/); choice = m ? m[1] : null; } catch (e) {}
  if (!choice) {
    try { var rec = JSON.parse(localStorage.getItem('wyv_cookie_notice_v2') || 'null'); choice = rec && rec.choice; } catch (e) {}
  }
  var state = choice === 'all' ? 'granted' : 'denied';

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = window.gtag || gtag;

  gtag('consent', 'default', {
    ad_storage: state, ad_user_data: state, ad_personalization: state, analytics_storage: state,
    wait_for_update: 500,
  });
  // Without consent, ad click identifiers are not stored and are redacted from what is sent.
  gtag('set', 'ads_data_redaction', state === 'denied');
  // Without cookies, the ad's click id rides on our links into app.wyvstudio.com instead, so a sign-up or purchase
  // there still links to the ad (owner, 2026-10-11).
  gtag('set', 'url_passthrough', true);
  gtag('js', new Date());
  gtag('config', ID);

  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://www.googletagmanager.com/gtag/js?id=' + ID;
  document.head.appendChild(s);

  // Conversions sent from this site; a kind without a label sends nothing (labels come from the Google Ads side).
  var LABELS = { brief_started: '' };
  window.wyvConversion = function (kind) {
    var label = LABELS[kind];
    if (label) gtag('event', 'conversion', { send_to: ID + '/' + label });
  };

  // Called by the cookie notice when the visitor chooses.
  window.wyvConsent = function (all) {
    var v = all ? 'granted' : 'denied';
    gtag('consent', 'update', { ad_storage: v, ad_user_data: v, ad_personalization: v, analytics_storage: v });
    gtag('set', 'ads_data_redaction', !all);
    if (window.posthog && window.posthog.set_config) {
      window.posthog.set_config({ persistence: all ? 'localStorage+cookie' : 'memory' });
    }
  };
})();

/*
 * Where a visitor first came from (owner, 2026-10-11): the Google click id, UTM tags, referring site and landing page of
 * their first visit, sent with the sign-up in the app so each account and each sale has a channel. Kept for the visit
 * in sessionStorage; after "Allow" also in a cookie the app shares, for 90 days. Without that cookie the visit rides on
 * our links into app.wyvstudio.com (?src=…). Same rules as web/src/services/attribution.js.
 */
(function () {
  var KEYS = ['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var CLICKS = ['gclid', 'gbraid', 'wbraid'];
  var NAME = 'wyv_src';
  var PASSING = /(^|\.)(wyvstudio\.com|kelviq\.com|stripe\.com|paypal\.com|appsumo\.com)$|^accounts\.google\.com$|^localhost$/;

  function consented() { try { return /(?:^|;\s*)wyv_consent=all/.test(document.cookie); } catch (e) { return false; } }
  function cookie() { try { return /(?:^|;\s*)wyv_src=/.test(document.cookie); } catch (e) { return false; } }
  function read() {
    try { var m = document.cookie.match(/(?:^|;\s*)wyv_src=([^;]+)/); if (m) return JSON.parse(decodeURIComponent(m[1])); } catch (e) {}
    try { return JSON.parse(sessionStorage.getItem(NAME) || 'null'); } catch (e) { return null; }
  }
  function store(t) {
    var v = JSON.stringify(t);
    try { sessionStorage.setItem(NAME, v); } catch (e) {}
    if (!consented()) return;
    var domain = /(^|\.)wyvstudio\.com$/.test(location.hostname) ? '; domain=.wyvstudio.com' : '';
    try { document.cookie = NAME + '=' + encodeURIComponent(v) + '; max-age=' + (90 * 86400) + '; path=/; samesite=lax' + domain + (location.protocol === 'https:' ? '; secure' : ''); } catch (e) {}
  }

  try {
    var q = new URLSearchParams(location.search), fresh = {};
    KEYS.forEach(function (k) { var v = q.get(k); if (v) fresh[k] = v.slice(0, 200); });
    try {
      var ref = document.referrer ? new URL(document.referrer).hostname.replace(/^www\./, '').toLowerCase() : '';
      if (ref && !PASSING.test(ref)) fresh.ref_host = ref;
    } catch (e) {}
    var t = read();
    if (!t || typeof t !== 'object') {
      t = fresh; t.landing = location.pathname.slice(0, 200); t.at = new Date().toISOString();
    } else if (!CLICKS.some(function (k) { return t[k]; })) {
      CLICKS.forEach(function (k) { if (fresh[k]) t[k] = fresh[k]; });
    }
    store(t);
  } catch (e) {}

  // Links into the app carry the visit when the shared cookie cannot.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || cookie()) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.hostname !== 'app.wyvstudio.com') return;
    var t = read(); if (!t) return;
    u.searchParams.set('src', JSON.stringify(t));
    a.href = u.toString();
  }, true);
})();
