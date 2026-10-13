/* The funnel's buy and "no thanks" links. Launchpad supplies one checkout link per product and bump; fill CHECKOUT
   when they arrive. A button whose link is not set stays disabled, so no page can take a visitor nowhere. */
(function () {
  var CHECKOUT = {
    fe: '', fe_credits: '', fe_hooks: '',
    bundle: '', bundle_dfy: '', bundle_length: '',
    pro: '', pro_credits: '', pro_seats: '',
    agency: '', agency_clients: '', agency_credits: ''
  };
  // Where "no thanks" goes on each upsell (Launchpad may replace these with its own decline links).
  var DECLINE = { bundle: '/pro', pro: '/agency', agency: '/thank-you' };
  document.querySelectorAll('[data-buy]').forEach(function (a) {
    var url = CHECKOUT[a.getAttribute('data-buy')];
    if (url) { a.href = url; } else { a.setAttribute('aria-disabled', 'true'); a.title = 'Checkout link pending'; }
  });
  document.querySelectorAll('[data-decline]').forEach(function (a) {
    a.href = DECLINE[a.getAttribute('data-decline')] || '/thank-you';
  });
})();
