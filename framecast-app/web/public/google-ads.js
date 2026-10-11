/*
 * Google Ads tag (AW-18506583015) for app.wyvstudio.com, owner 2026-10-11, with Google Consent Mode v2.
 *
 * Loaded first in index.html. Until the visitor allows it, every consent signal is "denied": Google sets no cookies
 * and gets only cookieless, anonymous pings. The choice is shared with wyvstudio.com through the wyv_consent cookie
 * (domain .wyvstudio.com), so allowing on the landing page carries over; the app's own cookie notice sets it too.
 * Conversions (sign-up, purchase) are sent from src/services/googleAds.js.
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
  gtag('consent', 'default', { ad_storage: state, ad_user_data: state, ad_personalization: state, analytics_storage: state, wait_for_update: 500 });
  gtag('set', 'ads_data_redaction', state === 'denied');
  gtag('js', new Date());
  gtag('config', ID);

  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://www.googletagmanager.com/gtag/js?id=' + ID;
  document.head.appendChild(s);

  window.wyvConsent = function (all) {
    var v = all ? 'granted' : 'denied';
    gtag('consent', 'update', { ad_storage: v, ad_user_data: v, ad_personalization: v, analytics_storage: v });
    gtag('set', 'ads_data_redaction', !all);
  };
})();
