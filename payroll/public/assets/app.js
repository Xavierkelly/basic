// Plain JavaScript (no libraries). Talks to api.php with fetch + JSON.
(function () {
  'use strict';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  function toast(msg, type) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.className = 'position-fixed bottom-0 start-50 translate-middle-x alert alert-' + (type || 'success') + ' shadow';
    el.textContent = msg;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => el.classList.add('d-none'), 3500);
  }

  async function api(action, data) {
    let res;
    try {
      res = await fetch('api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(data || {})
      });
    } catch (e) {
      throw new Error('No connection. Check your signal and try again.');
    }
    let json = {};
    try { json = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok || !json.ok) throw new Error(json.error || ('Something went wrong (' + res.status + ').'));
    return json;
  }
  window.payrollApi = api;

  const money = (n) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Confirm buttons
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-confirm]');
    if (b && !window.confirm(b.dataset.confirm)) ev.preventDefault();
  });
  // Busy label on long submits
  document.addEventListener('submit', (ev) => {
    const b = ev.target.querySelector('[data-busy]');
    if (b) { b.disabled = true; b.textContent = b.dataset.busy; }
  });
  // Quick client-side table filter
  document.querySelectorAll('[data-filter-table]').forEach((input) => {
    const table = document.querySelector(input.dataset.filterTable);
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach((tr) => { tr.hidden = q !== '' && !tr.textContent.toLowerCase().includes(q); });
    });
  });

  // ---- Time clock -------------------------------------------------------
  const punchBtn = document.getElementById('punchBtn');
  if (punchBtn) {
    const status = document.getElementById('clockStatus');
    const elapsed = document.getElementById('elapsed');
    let since = status.dataset.open === '1' ? new Date(status.dataset.since) : null;
    const tick = () => {
      if (!since) { elapsed.textContent = '0:00:00'; return; }
      const s = Math.max(0, Math.floor((Date.now() - since.getTime()) / 1000));
      elapsed.textContent = Math.floor(s / 3600) + ':' + String(Math.floor(s / 60) % 60).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    };
    tick();
    setInterval(tick, 1000);

    const getLocation = () => new Promise((resolve) => {
      if (!('geolocation' in navigator)) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy }),
        () => resolve(null),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
      );
    });

    punchBtn.addEventListener('click', async () => {
      punchBtn.disabled = true;
      const label = punchBtn.textContent;
      punchBtn.textContent = 'Getting location…';
      const loc = await getLocation();
      punchBtn.textContent = 'Saving…';
      try {
        const r = await api('punch', loc || {});
        const t = new Date(r.at.replace(' ', 'T') + 'Z');
        const hhmm = t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        if (r.state === 'in') {
          since = t;
          status.textContent = 'Clocked in since ' + hhmm;
          punchBtn.textContent = 'Clock out';
          punchBtn.classList.replace('btn-success', 'btn-danger');
          toast('Clocked in at ' + hhmm + (loc ? '' : ' (no location)'), loc ? 'success' : 'warning');
        } else {
          since = null;
          status.textContent = 'You are clocked out';
          punchBtn.textContent = 'Clock in';
          punchBtn.classList.replace('btn-danger', 'btn-success');
          toast('Clocked out at ' + hhmm + (loc ? '' : ' (no location)'), loc ? 'success' : 'warning');
        }
        tick();
        if (navigator.vibrate) navigator.vibrate(60);
      } catch (e) {
        punchBtn.textContent = label;
        toast(e.message, 'danger');
      } finally {
        punchBtn.disabled = false;
      }
    });
  }

  // ---- Timesheets ------------------------------------------------------
  const teModalEl = document.getElementById('teModal');
  if (teModalEl && window.bootstrap) {
    const modal = new bootstrap.Modal(teModalEl);
    const form = document.getElementById('teForm');
    const fill = (d) => {
      form.id.value = d.id || '';
      if (d.employee_id) form.employee_id.value = d.employee_id;
      form.clock_in.value = d.clock_in || '';
      form.clock_out.value = d.clock_out || '';
      form.note.value = d.note || '';
      form.querySelector('[data-te-delete]').hidden = !d.id;
    };
    document.querySelectorAll('[data-te-new]').forEach((b) => b.addEventListener('click', () => {
      const emp = new URLSearchParams(location.search).get('emp');
      fill({ employee_id: emp || '' });
      modal.show();
    }));
    document.querySelectorAll('[data-te-edit]').forEach((b) => b.addEventListener('click', () => {
      fill(JSON.parse(b.closest('tr').dataset.te));
      modal.show();
    }));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await api('te_save', Object.fromEntries(new FormData(form)));
        location.reload();
      } catch (e) { toast(e.message, 'danger'); }
    });
    form.querySelector('[data-te-delete]').addEventListener('click', async () => {
      if (!confirm('Delete this time entry?')) return;
      try { await api('te_delete', { id: form.id.value }); location.reload(); } catch (e) { toast(e.message, 'danger'); }
    });
  }
  const approve = async (ids) => {
    try { await api('te_approve', { ids }); location.reload(); } catch (e) { toast(e.message, 'danger'); }
  };
  document.querySelectorAll('[data-te-approve]').forEach((b) => b.addEventListener('click', () => approve([b.dataset.teApprove])));
  document.querySelectorAll('[data-te-approve-all]').forEach((b) => b.addEventListener('click', () => {
    approve([...document.querySelectorAll('[data-te-approve]')].map((x) => x.dataset.teApprove));
  }));

  // ---- Payroll grid ----------------------------------------------------
  const grid = document.querySelector('.payroll-grid');
  if (grid) {
    const setTotals = (t) => {
      if (!t) return;
      const map = { hours: t.hours, gross: t.gross, taxes: t.taxes, deductions: t.deductions, net: t.net, left_amt: t.left_amt };
      Object.entries(map).forEach(([k, v]) => {
        const el = document.querySelector('[data-total="' + k + '"]');
        if (el) el.textContent = k === 'hours' ? Number(v).toFixed(2) : '$' + money(v);
      });
    };
    const paint = (rows) => (rows || []).forEach((r) => {
      const tr = grid.querySelector('tr[data-pc="' + r.id + '"]');
      if (!tr) return;
      ['gross', 'taxes', 'net', 'left'].forEach((k) => {
        const td = tr.querySelector('[data-v="' + k + '"]');
        if (td) td.textContent = money(r[k]);
      });
      const tax = tr.querySelector('[data-v="taxes"]');
      if (tax) tax.title = 'Federal ' + money(r.federal_tax) + '\nSocial Security ' + money(r.ss_tax) + '\nMedicare ' + money(r.medicare_tax) + '\nAddl. Medicare ' + money(r.addl_medicare_tax) + '\nState ' + money(r.state_tax);
      const calc = tr.querySelector('[data-calc]');
      if (calc) {
        const err = (r.calc_status || '').startsWith('ERROR');
        calc.textContent = err ? 'tax error' : (Number(r.manual_taxes) ? 'manual tax' : (r.calc_status || ''));
        calc.title = r.calc_status || '';
        calc.hidden = r.calc_status === '1099';
        calc.className = 'badge ' + (err ? 'text-bg-danger' : ((r.calc_status || '').includes('missing') ? 'text-bg-warning' : 'text-bg-light'));
      }
      const pay = tr.querySelector('[data-pay]');
      if (pay) pay.dataset.net = r.net;
      let ot = tr.querySelector('[data-ot]');
      if (Number(r.ot_hours) > 0) {
        if (!ot) { ot = document.createElement('div'); ot.className = 'small text-secondary'; ot.dataset.ot = ''; tr.querySelector('[data-f="hours"]').after(ot); }
        ot.textContent = Number(r.ot_hours).toFixed(2) + ' OT';
      } else if (ot) { ot.remove(); }
    });

    grid.addEventListener('change', async (ev) => {
      const el = ev.target.closest('[data-f]');
      if (!el) return;
      const id = el.closest('tr').dataset.pc;
      el.classList.add('saving');
      try {
        const r = await api('pc_update', { id, field: el.dataset.f, value: el.value });
        paint(r.rows);
        setTotals(r.totals);
        el.classList.remove('saving');
        el.classList.add('saved');
        setTimeout(() => el.classList.remove('saved'), 800);
      } catch (e) {
        el.classList.remove('saving');
        toast(e.message, 'danger');
      }
    });
    // Enter moves down the column, like a spreadsheet.
    grid.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
      ev.preventDefault();
      const f = ev.target.dataset.f;
      const next = ev.target.closest('tr').nextElementSibling?.querySelector('[data-f="' + f + '"]');
      ev.target.blur();
      if (next) { next.focus(); next.select(); }
    });

    const payModalEl = document.getElementById('payModal');
    const payModal = payModalEl ? new bootstrap.Modal(payModalEl) : null;
    const payForm = document.getElementById('payForm');
    let payId = null;
    grid.addEventListener('click', async (ev) => {
      const pay = ev.target.closest('[data-pay]');
      const unpay = ev.target.closest('[data-unpay]');
      const remove = ev.target.closest('[data-remove]');
      if (pay) {
        // let any pending edit finish first
        document.activeElement?.blur();
        payId = pay.closest('tr').dataset.pc;
        payForm.sent.value = Number(pay.dataset.net).toFixed(2);
        payForm.method.value = pay.dataset.method || '';
        payForm.querySelector('[data-handle]').textContent = pay.dataset.handle ? 'Payout: ' + pay.dataset.handle : '';
        payModal.show();
      } else if (unpay) {
        if (!confirm('Unlock this paycheck so it can be edited? It will stop counting toward year-to-date wages until marked paid again.')) return;
        try { await api('pc_unpaid', { id: unpay.closest('tr').dataset.pc }); location.reload(); } catch (e) { toast(e.message, 'danger'); }
      } else if (remove) {
        if (!confirm('Remove this line from the pay period?')) return;
        try {
          const tr = remove.closest('tr');
          const r = await api('pc_delete', { id: tr.dataset.pc });
          tr.remove();
          setTotals(r.totals);
        } catch (e) { toast(e.message, 'danger'); }
      }
    });
    if (payForm) payForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await api('pc_paid', { id: payId, sent: payForm.sent.value, method: payForm.method.value });
        location.reload();
      } catch (e) { toast(e.message, 'danger'); }
    });
  }

  // ---- PWA -------------------------------------------------------------
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

  // "Add to home screen" helper: Android/desktop Chrome get a button, iPhone gets instructions.
  const bar = document.getElementById('installBar');
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  let dismissed = false;
  try { dismissed = localStorage.getItem('installDismissed') === '1'; } catch (e) { /* private mode */ }
  if (bar && !standalone && !dismissed) {
    const btn = bar.querySelector('[data-install]');
    const text = bar.querySelector('[data-install-text]');
    bar.querySelector('[data-install-close]').addEventListener('click', () => {
      bar.classList.add('d-none');
      try { localStorage.setItem('installDismissed', '1'); } catch (e) { /* ignore */ }
    });
    let deferred = null;
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferred = e;
      text.textContent = 'Install this app on your device for one-tap clock in.';
      btn.classList.remove('d-none');
      bar.classList.remove('d-none');
    });
    btn.addEventListener('click', async () => {
      if (!deferred) return;
      deferred.prompt();
      await deferred.userChoice;
      deferred = null;
      bar.classList.add('d-none');
    });
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    if (ios) {
      text.textContent = 'Install on your iPhone: tap the Share button, then "Add to Home Screen".';
      bar.classList.remove('d-none');
    }
  }
})();
