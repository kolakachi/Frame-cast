/*
 * Crisp chat loader for the marketing site.
 *
 * Drop in via:
 *   <script src="/crisp-chat.js" defer></script>
 *
 * The Website ID is set as a window variable by nginx (envsubst) or — for
 * the simple case — replaced once at deploy time by sed. If neither is in
 * place yet, the loader no-ops cleanly so we don't break the page.
 */
(function () {
  // Set by nginx envsubst (CRISP_WEBSITE_ID env var) or hardcoded after signup.
  var WEBSITE_ID = window.__CRISP_WEBSITE_ID__ || '';
  if (!WEBSITE_ID || WEBSITE_ID === '__CRISP_WEBSITE_ID__') return;

  if (window.$crisp || document.getElementById('crisp-chat-script')) return;
  window.$crisp = [];
  window.CRISP_WEBSITE_ID = WEBSITE_ID;

  var s = document.createElement('script');
  s.id = 'crisp-chat-script';
  s.src = 'https://client.crisp.chat/l.js';
  s.async = true;
  document.head.appendChild(s);
})();
