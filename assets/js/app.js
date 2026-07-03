(function () {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const card              = byId('itineraryCard');
  const shareModeBtn      = byId('shareModeBtn');
  const printBtn          = byId('printBtn');
  const printBtn2         = byId('printBtn2');
  const printBtnDock      = byId('printBtnDock');
  const downloadPngBtn    = byId('downloadPngBtn');
  const downloadPngBtn2   = byId('downloadPngBtn2');
  const downloadPngBtnDock = byId('downloadPngBtnDock');
  const copyImageBtn      = byId('copyImageBtn');
  const copyImageBtn2     = byId('copyImageBtn2');
  const copyImageBtnDock  = byId('copyImageBtnDock');
  const copyTextBtn       = byId('copyTextBtn');
  const resetBtn          = byId('resetBtn');
  const collapseInputBtn  = byId('collapseInputBtn');
  const expandInputBtn    = byId('expandInputBtn');
  const appLayout         = byId('appLayout');
  const form              = document.querySelector('form');
  const settingsPanels    = document.querySelectorAll('.settings-panel');
  const presetBtns        = document.querySelectorAll('[data-preset]');
  const textarea          = byId('pnr_text');
  const gdsChip           = byId('gdsDetectChip');
  const gdsLabel          = byId('gdsDetectLabel');
  const historyPanel      = byId('historyPanel');
  const copyToast         = byId('copyToast');

  /* ── Share mode ─────────────────────────────────── */
  if (shareModeBtn) {
    shareModeBtn.addEventListener('click', () => toggleShareMode());
  }
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('share-mode')) {
      toggleShareMode(false);
    }
  });

  /* ══ Settings persistence (localStorage) ════════════ */
  const SETTINGS_KEY  = 'pnrc-settings-v2';
  const SETTINGS_SKIP = new Set(['pnr_text']);

  function saveSettings() {
    if (!form) return;
    const data = {};
    const els  = form.querySelectorAll('input[type="checkbox"], input[type="radio"]');
    els.forEach((el) => {
      if (SETTINGS_SKIP.has(el.name)) return;
      if (el.type === 'radio') {
        if (el.checked) data[el.name] = el.value;
      } else {
        if (el.checked) data[el.name] = '1';
      }
    });
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(data)); } catch {}
  }

  function restoreSettings() {
    if (!form) return;
    let saved;
    try { saved = JSON.parse(localStorage.getItem(SETTINGS_KEY) || 'null'); } catch { saved = null; }
    if (!saved || typeof saved !== 'object') return;

    Object.entries(saved).forEach(([name, value]) => {
      if (SETTINGS_SKIP.has(name)) return;
      const radios = form.querySelectorAll(`input[type="radio"][name="${CSS.escape(name)}"]`);
      if (radios.length) {
        radios.forEach((r) => { r.checked = r.value === value; });
        return;
      }
      const cb = form.querySelector(`input[type="checkbox"][name="${CSS.escape(name)}"][value="1"]`);
      if (cb) cb.checked = value === '1';
    });
  }

  if (form && !card) {
    restoreSettings();
  }

  if (card) {
    setTimeout(() => {
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 120);
  }

  if (form && settingsPanels.length) {
    settingsPanels.forEach((panel) => panel.addEventListener('change', () => saveSettings()));
  }

  /* ── Sidebar collapse / expand ──────────────────── */
  const COLLAPSED_KEY = 'pnrc-sidebar-collapsed';

  function setSidebarCollapsed(collapsed) {
    if (!appLayout) return;
    appLayout.classList.toggle('sidebar-collapsed', collapsed);
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    try { localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0'); } catch {}
  }

  try {
    if (localStorage.getItem(COLLAPSED_KEY) === '1') setSidebarCollapsed(true);
  } catch {}

  if (collapseInputBtn) {
    collapseInputBtn.addEventListener('click', () => setSidebarCollapsed(true));
  }
  if (expandInputBtn) {
    expandInputBtn.addEventListener('click', () => setSidebarCollapsed(false));
  }

  /* ── Ctrl-bar hamburger toggle ──────────────────── */
  const sidebarToggleBtn = byId('sidebarToggleBtn');
  if (sidebarToggleBtn && appLayout) {
    sidebarToggleBtn.addEventListener('click', () => {
      const isCollapsed = appLayout.classList.contains('sidebar-collapsed');
      setSidebarCollapsed(!isCollapsed);
    });
  }

  /* ── Options popover toggle ─────────────────────── */
  const optsToggle  = byId('cbOptsToggle');
  const optsDrw     = byId('cbOptsDrw');
  const optsClose   = byId('cbOptsClose');
  const OPTS_OPEN_KEY = 'pnrc-opts-open';

  function setOptsOpen(open) {
    if (!optsDrw || !optsToggle) return;
    optsDrw.hidden = !open;
    optsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    try { localStorage.setItem(OPTS_OPEN_KEY, open ? '1' : '0'); } catch {}
  }

  if (optsToggle && optsDrw) {
    optsToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      setOptsOpen(optsDrw.hidden);
    });
  }
  if (optsClose) {
    optsClose.addEventListener('click', () => setOptsOpen(false));
  }

  /* Close opts popover when clicking outside */
  document.addEventListener('click', (e) => {
    if (!optsDrw || optsDrw.hidden) return;
    if (optsDrw.contains(e.target) || (optsToggle && optsToggle.contains(e.target))) return;
    setOptsOpen(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && optsDrw && !optsDrw.hidden) setOptsOpen(false);
  });

  /* ── Dynamic tabs ──────────────────────────────── */
  const TAB_KEY   = 'pnrc-active-tab';
  const tabBtns   = document.querySelectorAll('.ctrl-tab, .opts-tab');
  const tabPanels = document.querySelectorAll('.ctrl-panel-body, .opts-tab-panel');

  function activateTab(name) {
    tabBtns.forEach((btn) => {
      const active = btn.getAttribute('data-tab') === name;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    tabPanels.forEach((panel) => {
      const active = panel.getAttribute('data-panel') === name;
      panel.hidden = !active;
    });
    try { localStorage.setItem(TAB_KEY, name); } catch {}
  }

  if (tabBtns.length) {
    const saved = (() => { try { return localStorage.getItem(TAB_KEY); } catch { return null; } })();
    activateTab(saved || 'layout');
    tabBtns.forEach((btn) => {
      btn.addEventListener('click', () => activateTab(btn.getAttribute('data-tab')));
    });
  }

  /* ── Cabin mode segmented control ──────────────── */
  const cabinModeRadios = document.querySelectorAll('input[name="_cabin_mode"]');
  const hidShowCabin = byId('hidShowCabin');
  const hidShowClass = byId('hidShowClass');

  function applyCabinMode(val) {
    if (hidShowCabin) hidShowCabin.value = val === 'cabin' ? '1' : '0';
    if (hidShowClass) hidShowClass.value = val === 'class'  ? '1' : '0';
  }

  cabinModeRadios.forEach((r) => {
    r.addEventListener('change', () => {
      if (r.checked) { applyCabinMode(r.value); saveSettings(); }
    });
  });
  cabinModeRadios.forEach((r) => { if (r.checked) applyCabinMode(r.value); });

  /* ── Print ──────────────────────────────────────── */
  [printBtn, printBtn2, printBtnDock].forEach((btn) => {
    if (btn) btn.addEventListener('click', () => window.print());
  });

  /* ── Clear ───────────────────────────────────────── */
  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      window.location.href = window.location.pathname;
    });
  }

  /* ── Auto-submit on options change ──────────────── */
  if (form && settingsPanels.length && card) {
    let timer;
    const resubmit = () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }, 120);
    };
    settingsPanels.forEach((panel) => panel.addEventListener('change', resubmit));
  }

  /* ── Preset buttons ─────────────────────────────── */
  if (form && presetBtns.length) {
    updatePresetState();
    presetBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const preset = btn.getAttribute('data-preset');
        applyPreset(preset);
        updatePresetState(preset);
        saveSettings();
        if (card) {
          if (typeof form.requestSubmit === 'function') form.requestSubmit();
          else form.submit();
        }
      });
    });
  }

  if (card) saveSettings();

  /* ── Save PNG ───────────────────────────────────── */
  function wireSavePng(btn) {
    if (!btn) return;
    btn.addEventListener('click', async () => {
      if (!card) return;
      temporaryLabel(btn, '⏳ Rendering…');
      try {
        const blob = await renderCardToPng(card);
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = 'itinerary-roamingnepal.png';
        document.body.appendChild(a); a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        temporaryLabel(btn, '✓ Saved!');
      } catch (err) {
        console.error('PNG render error:', err);
        temporaryLabel(btn, '❌ Failed');
      }
    });
  }
  wireSavePng(downloadPngBtn);
  wireSavePng(downloadPngBtn2);
  wireSavePng(downloadPngBtnDock);

  /* ── Copy image ─────────────────────────────────── */
  function wireCopyImage(btn) {
    if (!btn) return;
    if (!navigator.clipboard || !window.ClipboardItem) {
      btn.disabled = true;
      btn.title = 'Image clipboard not supported in this browser';
      return;
    }
    btn.addEventListener('click', async () => {
      if (!card) return;
      temporaryLabel(btn, '⏳ Rendering…');
      try {
        const blob = await renderCardToPng(card);
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        temporaryLabel(btn, '✓ Copied!');
      } catch (err) {
        console.error('Copy image error:', err);
        temporaryLabel(btn, '❌ Failed');
      }
    });
  }
  wireCopyImage(copyImageBtn);
  wireCopyImage(copyImageBtn2);
  wireCopyImage(copyImageBtnDock);

  /* ── Copy as text ───────────────────────────────── */
  if (copyTextBtn) {
    const pnrTextEl = byId('pnrPlainText');
    if (!pnrTextEl || !navigator.clipboard) {
      copyTextBtn.disabled = true;
    } else {
      copyTextBtn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(pnrTextEl.textContent || '');
          temporaryLabel(copyTextBtn, '✓ Copied!');
          showToast('Itinerary text copied — paste into WhatsApp or email');
        } catch {
          temporaryLabel(copyTextBtn, '❌ Failed');
        }
      });
    }
  }

  /* ── Keyboard shortcut: Ctrl/Cmd+Enter to submit ── */
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      if (form) {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
    }
  });

  /* ── Live GDS format detector ───────────────────── */
  const GDS_PATTERNS = [
    { name: 'Amadeus',   re: /^RP\//m },
    { name: 'Amadeus',   re: /^\s*\d+\s+[A-Z0-9]{2}\s+\d{1,4}\s+[A-Z]\s+\d{1,2}[A-Z]{3}\s+[A-Z]{3}[A-Z]{3}\s+HK/m },
    { name: 'Sabre',     re: /\b(?:SABRE|1S)\b/i },
    { name: 'Sabre',     re: /^\s*\d+\s+[A-Z0-9]{2}\s*\d{1,4}[A-Z]\s+\d{1,2}[A-Z]{3}\s+\d\s+[A-Z]{6}/m },
    { name: 'Galileo',   re: /\b(?:GALILEO|TRAVELPORT|1G|1V)\b/i },
    { name: 'Worldspan', re: /\b(?:WORLDSPAN|1P)\b/i },
    { name: 'GDS',       re: /\b(?:HK1|HK\d|SS1|DK1)\b/ },
  ];

  let detectTimer;
  if (textarea && gdsChip && gdsLabel) {
    function runDetect() {
      const val = textarea.value.trim();
      if (val.length < 20) { gdsChip.style.display = 'none'; return; }
      let detected = null;
      for (const p of GDS_PATTERNS) {
        if (p.re.test(val)) { detected = p.name; break; }
      }
      if (detected) {
        gdsLabel.textContent = detected + ' detected';
        gdsChip.style.display = 'inline-flex';
        gdsChip.classList.add('detected');
      } else {
        gdsChip.style.display = 'none';
        gdsChip.classList.remove('detected');
      }
    }
    textarea.addEventListener('input', () => {
      clearTimeout(detectTimer);
      detectTimer = setTimeout(runDetect, 280);
    });
    // Run on load if textarea already has content (result page)
    if (textarea.value.trim().length > 20) runDetect();
  }

  /* ══ PNR History (last 5 conversions) ════════════ */
  const HISTORY_KEY = 'pnrc-history-v1';
  const MAX_HISTORY = 5;

  function loadHistory() {
    try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch { return []; }
  }

  function saveHistoryStore(entries) {
    try { localStorage.setItem(HISTORY_KEY, JSON.stringify(entries)); } catch {}
  }

  function pushHistory(route, pnrText, flights) {
    const entries = loadHistory().filter((e) => e.pnr !== pnrText);
    entries.unshift({ route, pnr: pnrText, flights, ts: Date.now() });
    saveHistoryStore(entries.slice(0, MAX_HISTORY));
  }

  function renderHistoryPanel() {
    if (!historyPanel) return;
    const entries = loadHistory();
    if (!entries.length) { historyPanel.style.display = 'none'; return; }

    historyPanel.style.display = 'block';
    historyPanel.innerHTML = `
      <div class="history-panel">
        <div class="history-hd">
          <span class="history-hd-title">⏱ Recent PNRs</span>
          <button class="history-clear-btn" id="historyClearBtn" type="button">Clear all</button>
        </div>
        <ul class="history-list" role="list">
          ${entries.map((e, i) => `
            <li class="history-item" data-idx="${i}" role="button" tabindex="0" title="Reload this PNR">
              <span class="history-route">${escHtml(e.route)}</span>
              <span class="history-meta">${e.flights} flight${e.flights !== 1 ? 's' : ''}</span>
            </li>`).join('')}
        </ul>
      </div>`;

    byId('historyClearBtn').addEventListener('click', (ev) => {
      ev.stopPropagation();
      saveHistoryStore([]);
      historyPanel.style.display = 'none';
      showToast('History cleared');
    });

    historyPanel.querySelectorAll('.history-item').forEach((item) => {
      const load = () => {
        const idx = parseInt(item.getAttribute('data-idx'), 10);
        const entry = loadHistory()[idx];
        if (!entry || !textarea) return;
        textarea.value = entry.pnr;
        if (form) {
          if (typeof form.requestSubmit === 'function') form.requestSubmit();
          else form.submit();
        }
      };
      item.addEventListener('click', load);
      item.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') load(); });
    });
  }

  // Save current conversion to history (only on result pages)
  if (card && textarea) {
    const route   = card.dataset.route   || '';
    const flights = parseInt(card.dataset.flights || '0', 10);
    const pnrText = textarea.value.trim();
    if (route && pnrText) pushHistory(route, pnrText, flights);
  }
  renderHistoryPanel();

  /* ── Helpers ─────────────────────────────────────── */
  function temporaryLabel(btn, label) {
    const origHtml = btn.innerHTML;
    btn.textContent = label;
    setTimeout(() => { btn.innerHTML = origHtml; }, 1800);
  }

  function toggleShareMode(force) {
    const on = typeof force === 'boolean' ? force : !document.body.classList.contains('share-mode');
    document.body.classList.toggle('share-mode', on);
    if (shareModeBtn) shareModeBtn.textContent = on ? 'Exit share view' : 'Share view';
  }

  function showToast(msg) {
    if (!copyToast) return;
    copyToast.textContent = msg;
    copyToast.classList.add('show');
    setTimeout(() => copyToast.classList.remove('show'), 2600);
  }

  function escHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function applyPreset(preset) {
    const cb    = (n) => form.querySelector(`input[type="checkbox"][name="${n}"]`);
    const check = (n, v) => { const el = cb(n); if (el) el.checked = v; };

    if (preset === 'neutral') {
      check('show_agency_header', false);
      check('show_agency_footer', false);
      check('show_disclaimer',    false);
      return;
    }
    // roaming (was 'branded') — enable header + footer
    check('show_agency_header', true);
    check('show_agency_footer', true);
    check('show_disclaimer',    true);
  }

  function updatePresetState(active) {
    const normalized = active === 'branded' ? 'roaming' : active;
    presetBtns.forEach((btn) => {
      const isActive = Boolean(normalized && btn.getAttribute('data-preset') === normalized);
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  /* ── PNG render via html2canvas ──────────────────── */
  async function renderCardToPng(element) {
    if (typeof window.html2canvas !== 'function') {
      throw new Error('html2canvas not loaded');
    }
    // Temporarily expand table scroll wrappers so full width is captured
    const scrollEls = element.querySelectorAll('.ref-table-scroll');
    const origStyles = [];
    scrollEls.forEach((el) => {
      origStyles.push({ el, overflow: el.style.overflow, width: el.style.width });
      el.style.overflow = 'visible';
      el.style.width = el.scrollWidth + 'px';
    });

    const fullW = Math.max(element.offsetWidth, element.scrollWidth);
    const canvas = await window.html2canvas(element, {
      backgroundColor: '#ffffff',
      scale: 2,
      useCORS: true,
      allowTaint: false,
      logging: false,
      width:  fullW,
      height: element.scrollHeight,
      scrollX: 0,
      scrollY: -window.scrollY,
      windowWidth:  document.documentElement.offsetWidth,
      windowHeight: document.documentElement.offsetHeight,
    });

    origStyles.forEach(({ el, overflow, width }) => {
      el.style.overflow = overflow;
      el.style.width    = width;
    });

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 0.95));
  }

})();
