/* The funnel's buy and "no thanks" links. Launchpad supplies one checkout link per product, and for an upgrade with
   add-ons one per combination it sells (e.g. "bundle+bundle_dfy"); fill CHECKOUT when they arrive. A button whose
   link is not set stays disabled, so no page can take a visitor nowhere. */
(function () {
  var CHECKOUT = {
    fe: '',
    bundle: '', 'bundle+bundle_dfy': '', 'bundle+bundle_length': '', 'bundle+bundle_dfy+bundle_length': '',
    pro: '', 'pro+pro_credits': '', 'pro+pro_seats': '', 'pro+pro_credits+pro_seats': '',
    offeriq: '',
    agency: '', 'agency+agency_clients': '', 'agency+agency_credits': '', 'agency+agency_clients+agency_credits': ''
  };
  // The funnel: FE -> Bundle (no: Pro) -> OfferIQ -> Agency -> thank-you. Launchpad's own accept/decline links may
  // replace these.
  var DECLINE = { bundle: '/pro', pro: '/offeriq', offeriq: '/agency', agency: '/thank-you' };

  function wire(a) {
    var key = a.getAttribute('data-buy');
    var box = a.closest('.lp-cta');
    var picked = box ? Array.prototype.map.call(box.querySelectorAll('[data-addon]:checked'), function (c) { return c.getAttribute('data-addon'); }).sort() : [];
    var url = CHECKOUT[[key].concat(picked).join('+')];
    if (url) { a.href = url; a.removeAttribute('aria-disabled'); a.removeAttribute('title'); }
    else { a.href = '#'; a.setAttribute('aria-disabled', 'true'); a.title = 'Checkout link pending'; }
  }
  document.querySelectorAll('[data-buy]').forEach(wire);
  document.querySelectorAll('[data-addon]').forEach(function (c) {
    c.addEventListener('change', function () { var b = c.closest('.lp-cta').querySelector('[data-buy]'); if (b) wire(b); });
  });
  document.querySelectorAll('[data-decline]').forEach(function (a) { a.href = DECLINE[a.getAttribute('data-decline')] || '/thank-you'; });

  // Sample videos play while on screen.
  var vids = document.querySelectorAll('video[data-autoplay]');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.play().catch(function () {}); } else { e.target.pause(); } }); }, { threshold: .4 });
    vids.forEach(function (v) { io.observe(v); });
  }
})();
