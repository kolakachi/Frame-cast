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
  gtag('js', new Date());
  gtag('config', ID);

  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://www.googletagmanager.com/gtag/js?id=' + ID;
  document.head.appendChild(s);

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
