/*
 * WyvStudio cookie notice — minimal GDPR-friendly banner.
 *
 * Shown until the visitor chooses. Essential cookies (login, Kelviq billing,
 * anonymized Sentry errors) are always on. "Allow" also lets Google Ads and
 * PostHog set cookies to measure our ads and visits; "Essential only" keeps
 * them cookieless (google-ads.js applies the choice through Consent Mode).
 * The choice is kept in localStorage for 180 days. v2 (2026-10-11): the
 * notice became a choice when the Google Ads tag was added, so everyone is
 * asked again.
 *
 * Drop in via <script src="/cookie-notice.js" defer></script>.
 */
(function () {
  var KEY = 'wyv_cookie_notice_v2';
  var TTL_DAYS = 180;

  function dismissed() {
    try {
      var raw = localStorage.getItem(KEY);
      if (!raw) return false;
      var rec = JSON.parse(raw);
      if (!rec || !rec.ts) return false;
      var age = (Date.now() - rec.ts) / (1000 * 60 * 60 * 24);
      return age < TTL_DAYS;
    } catch (e) { return false; }
  }

  function dismiss(all) {
    try { localStorage.setItem(KEY, JSON.stringify({ ts: Date.now(), choice: all ? 'all' : 'essential' })); } catch (e) {}
    if (window.wyvConsent) window.wyvConsent(all);
    var el = document.getElementById('wyv-cookie-notice');
    if (el) el.remove();
  }

  function render() {
    if (dismissed()) return;
    if (document.getElementById('wyv-cookie-notice')) return;

    var host = document.createElement('div');
    host.id = 'wyv-cookie-notice';
    host.setAttribute('role', 'dialog');
    host.setAttribute('aria-label', 'Cookie notice');
    host.innerHTML =
      '<div class="wcn-inner">' +
        '<div class="wcn-text">' +
          'We use essential cookies for login and billing, plus anonymized error tracking via Sentry. ' +
          'With your OK, Google Ads and PostHog also use cookies to measure our ads and site visits. See our <a href="/privacy">Privacy Policy</a>.' +
        '</div>' +
        '<div class="wcn-btns">' +
          '<button type="button" class="wcn-no">Essential only</button>' +
          '<button type="button" class="wcn-ok">Allow</button>' +
        '</div>' +
      '</div>';

    var style = document.createElement('style');
    style.textContent =
      '#wyv-cookie-notice{position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;font-family:"DM Sans",-apple-system,system-ui,sans-serif;}' +
      '#wyv-cookie-notice .wcn-inner{max-width:780px;margin:0 auto;display:flex;align-items:center;gap:14px;padding:14px 18px;background:#14141c;color:#ececf3;border:1px solid #2a2a31;border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,0.55);}' +
      '#wyv-cookie-notice .wcn-text{font-size:13px;line-height:1.55;flex:1;color:#cdcdd4;}' +
      '#wyv-cookie-notice .wcn-text a{color:#ff8055;text-decoration:underline;}' +
      '#wyv-cookie-notice .wcn-ok{background:#ff6b35;border:1px solid #ff6b35;color:#0a0a0f;font:600 13px "DM Sans",sans-serif;padding:9px 18px;border-radius:8px;cursor:pointer;flex-shrink:0;transition:.15s;}' +
      '#wyv-cookie-notice .wcn-ok:hover{background:#ff8055;border-color:#ff8055;}' +
      '#wyv-cookie-notice .wcn-btns{display:flex;gap:8px;flex-shrink:0;}' +
      '#wyv-cookie-notice .wcn-no{background:transparent;border:1px solid #3a3a44;color:#ececf3;font:600 13px "DM Sans",sans-serif;padding:9px 14px;border-radius:8px;cursor:pointer;}' +
      '#wyv-cookie-notice .wcn-no:hover{border-color:#ff8055;}' +
      '@media (max-width:640px){#wyv-cookie-notice .wcn-inner{flex-direction:column;align-items:stretch;}#wyv-cookie-notice .wcn-btns{align-self:flex-end;}}';
    document.head.appendChild(style);

    host.querySelector('.wcn-ok').addEventListener('click', function () { dismiss(true); });
    host.querySelector('.wcn-no').addEventListener('click', function () { dismiss(false); });
    document.body.appendChild(host);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
