// Prototype behaviour only. No generation, no charges.
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  const toast = (msg) => {
    const t = $('#agent-toast'); if (!t) return;
    t.textContent = msg; t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 2800);
  };

  const dialog = (title, html, actions = []) => {
    const d = $('#action-dialog'); if (!d) return toast(title);
    $('#dialog-title').textContent = title;
    $('#dialog-body').innerHTML = html + '<div class="actions"></div>';
    const box = $('.actions', d);
    actions.forEach(([label, cls, fn]) => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'btn ' + cls; b.textContent = label;
      b.addEventListener('click', () => { d.close(); fn && fn(); }); box.appendChild(b);
    });
    d.showModal();
  };
  $('#close-dialog')?.addEventListener('click', () => $('#action-dialog').close());

  // ---- details panel ----
  const panel = $('#details-panel'), toggle = $('#toggle-panel');
  if (panel && toggle) {
    const setPanel = (open) => { panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open)); };
    toggle.addEventListener('click', () => setPanel(panel.hidden));
    $('#close-panel').addEventListener('click', () => setPanel(false));
    $$('.detail-tabs button').forEach(b => b.addEventListener('click', () => {
      $$('.detail-tabs button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
      $('#details-pane').hidden = b.dataset.pane !== 'details';
      $('#versions-pane').hidden = b.dataset.pane !== 'versions';
    }));
  }

  // ---- generic pressed-state groups ----
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.seg__btn, .swatch, .optlist button, .role-toggle button');
    if (!b) return;
    $$('[aria-pressed]', b.parentElement).forEach(x => x.setAttribute('aria-pressed', String(x === b)));
  });

  // ---- prompt prefill ----
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-prompt]');
    if (!b) return;
    const p = $('#prompt'); if (!p) return toast('Would prefill: ' + b.dataset.prompt);
    p.value = b.dataset.prompt; p.focus(); p.setSelectionRange(p.value.length, p.value.length);
  });

  // ---- result actions ----
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-action]');
    if (!b) return;
    const result = b.closest('.result');
    const v = result?.dataset.version;
    switch (b.dataset.action) {
      case 'levers': $('.levers', result)?.classList.toggle('is-open'); break;
      case 'apply':
        toast(v === '2' ? 'Would make Version 3 from these changes. Free.' : `Would make a new version from Version ${v}. Version 2 stays current until you say otherwise.`);
        break;
      case 'render':
        dialog('Download this video?', `<p>Version ${v} will be rendered as an MP4 you can download, share and schedule. <b>12 credits</b>, charged only when the file is ready.</p>`,
          [['Not now', 'btn--ghost'], ['Render and download · 12 credits', 'btn--primary', () => toast('Would start the render. You can leave the page.')]]);
        break;
      case 'download': toast('Would download launch-offer-v2.mp4.'); break;
      case 'share': toast('Would create a private share link for this file.'); break;
      case 'schedule': toast('Would open scheduling with this file attached.'); break;
      case 'variants': toast('Would propose up to three variants and their total before starting.'); break;
      default: toast('Prototype only.');
    }
  });

  // ---- player controls ----
  document.addEventListener('click', (e) => {
    const safe = e.target.closest('[data-safe]');
    if (safe) {
      const on = safe.getAttribute('aria-pressed') !== 'true';
      safe.setAttribute('aria-pressed', String(on));
      safe.textContent = on ? 'Show Reels overlay' : 'Reels overlay hidden';
      $$('.safe', safe.closest('.result')).forEach(s => (s.style.display = on ? '' : 'none'));
    }
    const mute = e.target.closest('[data-mute]');
    if (mute) { const m = mute.getAttribute('aria-pressed') === 'true'; mute.setAttribute('aria-pressed', String(!m)); mute.setAttribute('aria-label', m ? 'Mute' : 'Unmute'); }
    const fs = e.target.closest('[data-fullscreen]');
    if (fs) { const f = fs.closest('.result').querySelector('.frame'); (f.requestFullscreen ? f.requestFullscreen() : Promise.reject()).catch(() => toast('Fullscreen preview.')); }
  });

  // ---- approvals, cancel, retry ----
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-approve]');
    if (a) {
      const foot = a.closest('.icard__foot');
      const st = document.createElement('span'); st.className = 'status status--ok';
      st.textContent = a.dataset.approve === 'variants' ? 'STARTED · 36 RESERVED' : 'APPROVED · 40 RESERVED';
      $$('.btn', foot).forEach(x => x.remove()); foot.appendChild(st);
      toast('Approved. Work would start now; you can leave the page.');
    }
    if (e.target.closest('[data-cancel]')) {
      dialog('Cancel this update?', '<p>Work so far is kept as a draft and unused credits are returned. Version 2 is unaffected.</p>',
        [['Keep going', 'btn--ghost'], ['Cancel the update', 'btn--primary', () => {
          const c = $('#working-card'); if (!c) return;
          c.className = 'icard'; c.innerHTML = '<div class="icard__body"><div class="icard__title">Update cancelled<span class="status status--neutral">DRAFT KEPT</span></div><p style="margin:0;font-size:13px;color:var(--text-2)">3 credits used for the work done; 15 returned. Ask again to pick it up from the draft.</p></div>';
          $('#thread-status').textContent = 'VERSION 2 · CURRENT'; $('#thread-status').className = 'status status--ok';
        }]]);
    }
    if (e.target.closest('[data-retry-run]')) toast('Would retry with the same plan and files. No extra charge.');
  });

  // ---- versions ----
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-vaction]');
    if (!b) return;
    const card = b.closest('.version'); const v = card.dataset.version;
    switch (b.dataset.vaction) {
      case 'open': { const t = $('#v' + v); if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'center' }); t.style.outline = '2px solid var(--accent)'; setTimeout(() => (t.style.outline = ''), 1600); } break; }
      case 'compare': toast(`Would show Version ${v} next to Version 2 with the changed lines highlighted.`); break;
      case 'restore':
        dialog(`Restore Version ${v}?`, `<p>This makes a <b>new</b> version identical to Version ${v}. Version 2 and its download stay exactly as they are.</p>`,
          [['Cancel', 'btn--ghost'], ['Restore as a new version', 'btn--primary', () => toast(`Would create Version 4 from Version ${v}. Free.`)]]);
        break;
      case 'download': toast('Would download launch-offer-v2.mp4.'); break;
      case 'share': toast('Would create a private share link.'); break;
    }
  });

  // ---- attachments ----
  const attached = $('#attached'), input = $('#file-input');
  const openPicker = () => input?.click();
  $('#attach-button')?.addEventListener('click', openPicker);
  $('#attach-button-2')?.addEventListener('click', openPicker);
  input?.addEventListener('change', () => {
    [...input.files].forEach(f => {
      const isVideo = f.type.startsWith('video/'), tooBig = f.size > 500 * 1024 * 1024;
      const el = document.createElement('div'); el.className = 'upload' + (tooBig ? ' upload--error' : '');
      const thumb = f.type.startsWith('image/') ? `<img class="upload__thumb" alt="" src="${URL.createObjectURL(f)}">` : `<span class="upload__thumb${isVideo ? ' upload__thumb--video' : ''}"></span>`;
      el.innerHTML = tooBig
        ? `${thumb}<div><b></b><small>Too large (${(f.size / 1e9).toFixed(1)} GB). Limit is 500 MB.</small></div><button type="button" class="btn btn--ghost btn--sm" data-retry>Retry</button><button type="button" class="upload__x" aria-label="Remove">×</button>`
        : `${thumb}<div><b></b><small>uploading · 0%</small><div class="upload__bar"><span style="width:0%"></span></div></div><span class="role-toggle" role="group" aria-label="Use as"><button type="button" aria-pressed="true">REUSE</button><button type="button" aria-pressed="false">REFERENCE</button></span><button type="button" class="upload__x" aria-label="Remove">×</button>`;
      $('b', el).textContent = f.name;
      attached.appendChild(el);
      if (!tooBig) {
        let p = 0; const tick = setInterval(() => {
          p = Math.min(100, p + 8 + Math.random() * 12);
          $('.upload__bar span', el).style.width = p + '%'; $('small', el).textContent = p < 100 ? `uploading · ${Math.round(p)}%` : (isVideo ? 'ready · video' : 'ready');
          if (p >= 100) { clearInterval(tick); $('.upload__bar', el).remove(); }
        }, 180);
      }
    });
    input.value = '';
  });
  document.addEventListener('click', (e) => {
    if (e.target.closest('.upload__x')) e.target.closest('.upload').remove();
    if (e.target.closest('[data-retry]')) toast('Would retry the upload. Files over 500 MB need trimming first.');
  });

  // ---- composer ----
  $('#prompt-form')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const p = $('#prompt'); if (!p.value.trim()) return;
    const m = document.createElement('div'); m.className = 'user-message';
    const files = $$('.upload:not(.upload--error) b', attached).map(b => b.textContent);
    m.innerHTML = (files.length ? `<div class="attachments">${files.map(n => `<span class="chip"><span class="chip__thumb asset__thumb--image"></span><span><b></b></span></span>`).join('')}</div>` : '') + '<p></p>';
    $$('.chip b', m).forEach((b, i) => (b.textContent = files[i]));
    $('p', m).textContent = p.value.trim();
    const empty = $('.empty'); if (empty) empty.remove();
    $('#messages').appendChild(m); p.value = ''; attached.innerHTML = '';
    $('#messages').scrollTop = $('#messages').scrollHeight;
    toast('The assistant would reply with a plan, a free change, a quote, or a question.');
  });
  $('#prompt')?.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#prompt-form').requestSubmit(); } });
  $('#panel-add')?.addEventListener('click', () => toast('Would open the asset library.'));
})();

// ---- recent conversations drawer ----
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const drawer = $('#recent-drawer'), btn = $('#recent-button');
  if (!drawer || !btn) return;
  const open = () => { drawer.setAttribute('open', ''); $('#recent-search').focus(); };
  const close = () => { drawer.removeAttribute('open'); btn.focus(); };
  btn.addEventListener('click', open);
  $$('[data-close-drawer]', drawer).forEach(x => x.addEventListener('click', close));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drawer.hasAttribute('open')) close(); });
  const apply = () => {
    const q = $('#recent-search').value.trim().toLowerCase();
    const f = $('.drawer__filters [aria-pressed="true"]').dataset.filter;
    $$('.session', drawer).forEach(s => {
      const hit = (!q || (s.dataset.text + ' ' + s.textContent).toLowerCase().includes(q)) && (f === 'all' || s.dataset.state === f);
      s.hidden = !hit;
    });
    $$('.drawer__group', drawer).forEach(g => {
      let n = g.nextElementSibling, any = false;
      while (n && !n.classList.contains('drawer__group')) { if (!n.hidden) any = true; n = n.nextElementSibling; }
      g.hidden = !any;
    });
  };
  $('#recent-search').addEventListener('input', apply);
  $$('.drawer__filters button').forEach(b => b.addEventListener('click', () => {
    $$('.drawer__filters button').forEach(x => x.setAttribute('aria-pressed', String(x === b))); apply();
  }));
})();

// ---- image and audio results ----
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const toast = (m) => { const t = $('#agent-toast'); if (!t) return; t.textContent = m; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 2600); };
  const openChoice = (id, on) => { $$('.ichoice').forEach(c => c.classList.toggle('is-open', on && c.id === id)); if (on) $('#' + id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); };
  document.addEventListener('click', (e) => {
    const pick = e.target.closest('.iresult__img');
    if (pick) { const card = pick.closest('.iresult'); $$('.iresult', card.closest('.igrid') || card.parentElement).forEach(x => x.classList.toggle('is-picked', x === card)); toast('Set as the cover for this video.'); return; }
    const b = e.target.closest('[data-iaction]');
    if (b) {
      switch (b.dataset.iaction) {
        case 'download': toast('Would download this file.'); break;
        case 'edit': openChoice('ichoice-edit', !$('#ichoice-edit').classList.contains('is-open')); break;
        case 'animate': openChoice('ichoice-animate', !$('#ichoice-animate').classList.contains('is-open')); break;
        case 'variations': toast('Would make 3 variations of this image · ~12 credits, runs straight away.'); break;
      }
      return;
    }
    if (e.target.closest('[data-open-presets]')) { const p = $('#presets'); p.hidden = !p.hidden; return; }
    const pr = e.target.closest('.presets button');
    if (pr) { $$('.presets button').forEach(x => x.setAttribute('aria-pressed', String(x === pr))); toast('Would re-lay the thumbnail at ' + pr.querySelector('small').textContent + '. Free.'); }
  });
})();
