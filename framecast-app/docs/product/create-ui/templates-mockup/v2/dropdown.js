// Our own dropdown, as UiSelect.vue in the app: a styled trigger and a styled option list, never a native <select>.
// Markup: .dd[data-dd][data-value] > button.dd-trigger (.dd-value inside) + .dd-menu[role=listbox] > button.dd-option[data-value].
// Picking sets data-value on .dd and fires 'dd-change'. One menu open at a time; Escape or a click outside closes it;
// arrow keys move between options.
(function () {
  var openDd = null;
  function close(dd) {
    if (!dd) return;
    dd.querySelector('.dd-menu').hidden = true;
    dd.querySelector('.dd-trigger').setAttribute('aria-expanded', 'false');
    if (openDd === dd) openDd = null;
  }
  function open(dd) {
    if (openDd && openDd !== dd) close(openDd);
    var menu = dd.querySelector('.dd-menu');
    menu.hidden = false;
    dd.querySelector('.dd-trigger').setAttribute('aria-expanded', 'true');
    openDd = dd;
    (menu.querySelector('[aria-selected="true"]') || menu.querySelector('.dd-option')).focus();
  }
  document.querySelectorAll('[data-dd]').forEach(function (dd) {
    var trigger = dd.querySelector('.dd-trigger'), options = dd.querySelectorAll('.dd-option');
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (openDd === dd) close(dd); else open(dd);
    });
    options.forEach(function (o, i) {
      o.addEventListener('click', function (e) {
        e.stopPropagation();
        options.forEach(function (x) { x.setAttribute('aria-selected', String(x === o)); });
        dd.dataset.value = o.dataset.value;
        dd.querySelector('.dd-value').textContent = o.textContent;
        close(dd); trigger.focus();
        dd.dispatchEvent(new CustomEvent('dd-change', { bubbles: true }));
      });
      o.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); (options[i + 1] || options[0]).focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); (options[i - 1] || options[options.length - 1]).focus(); }
      });
    });
  });
  document.addEventListener('click', function () { close(openDd); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && openDd) { var dd = openDd; close(dd); dd.querySelector('.dd-trigger').focus(); }
  });
})();
