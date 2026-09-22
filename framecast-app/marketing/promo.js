/* Founders campaign — the only switch.
 *
 * Nothing about the offer is visible unless this file adds `promo-on` to
 * <body>, and it only does that while the window is open. When CAMPAIGN_ENDS
 * passes, the bar, badges, strikethroughs, savings lines and codes all revert
 * to hidden by CSS and the page shows $89 / $199 / $399 again — with no deploy
 * and no chance of an expired countdown being left running, which is the
 * clearest fake-urgency signal there is.
 *
 * To change or extend the campaign, edit CAMPAIGN_ENDS. To kill it early, set
 * it to a past date. That is the whole control surface.
 */
(function () {
  var CAMPAIGN_ENDS = new Date('2026-10-06T23:59:59Z');
  var END_LABEL = '6 October';

  var left = CAMPAIGN_ENDS - Date.now();
  if (!(left > 0)) return;              // expired, or an unparseable date → stay off

  document.body.classList.add('promo-on');

  Array.prototype.forEach.call(
    document.querySelectorAll('.promo-end-date'),
    function (el) { el.textContent = END_LABEL; }
  );

  var bar = document.getElementById('promo-cd-bar');
  var offer = document.getElementById('promo-cd-offer');

  function pad(n) { return String(n).padStart(2, '0'); }

  function seg(n, u) { return '<span class="n">' + n + '</span><span class="u">' + u + '</span>'; }

  function tick() {
    var ms = CAMPAIGN_ENDS - Date.now();

    if (ms <= 0) {                      // ended while the tab was open
      document.body.classList.remove('promo-on');
      clearInterval(timer);
      return;
    }

    var s = Math.floor(ms / 1000);
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;

    // The bar is on screen the whole visit, so it shows days and hours only —
    // a seconds counter ticking in the corner is distracting while reading.
    if (bar) bar.innerHTML = seg(d, 'd') + seg(pad(h), 'h');

    // Seconds belong in the pricing section, where the decision happens.
    if (offer) offer.innerHTML = seg(d, 'd') + seg(pad(h), 'h') + seg(pad(m), 'm') + seg(pad(sec), 's');
  }

  tick();
  var timer = setInterval(tick, 1000);

  // Dismissing hides the bar for this browser session only — the offer box and
  // the card pricing stay. A bar that cannot be closed reads as pressure.
  var dismiss = document.getElementById('promo-dismiss');
  if (dismiss) {
    try {
      if (sessionStorage.getItem('promo-dismissed') === '1') {
        document.querySelector('.promo-bar').style.display = 'none';
        document.body.classList.add('promo-bar-hidden');
      }
    } catch (e) { /* private mode — just show it */ }

    dismiss.addEventListener('click', function () {
      var el = document.querySelector('.promo-bar');
      if (el) el.style.display = 'none';
      document.body.classList.add('promo-bar-hidden');
      try { sessionStorage.setItem('promo-dismissed', '1'); } catch (e) {}
    });
  }

  // Click to copy. The code is typed three screens later at checkout, so
  // nobody should have to select text on a phone to carry it there.
  document.addEventListener('click', function (e) {
    var chip = e.target.closest ? e.target.closest('.chip') : null;
    if (!chip || !chip.dataset.code) return;

    var label = chip.querySelector('.cp');
    var restore = label ? label.textContent : '';

    function done() {
      chip.classList.add('done');
      if (label) label.textContent = 'copied';
      setTimeout(function () {
        chip.classList.remove('done');
        if (label) label.textContent = restore;
      }, 1600);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(chip.dataset.code).then(done, function () {});
    } else {
      var t = document.createElement('textarea');
      t.value = chip.dataset.code;
      t.style.position = 'fixed';
      t.style.opacity = '0';
      document.body.appendChild(t);
      t.select();
      try { document.execCommand('copy'); done(); } catch (err) {}
      t.remove();
    }
  });
})();
