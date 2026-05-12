(function () {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const card             = byId('itineraryCard');
  const shareModeBtn     = byId('shareModeBtn');
  const printBtn         = byId('printBtn');
  const printBtn2        = byId('printBtn2');
  const downloadPngBtn   = byId('downloadPngBtn');
  const downloadPngBtn2  = byId('downloadPngBtn2');
  const copyImageBtn     = byId('copyImageBtn');
  const copyImageBtn2    = byId('copyImageBtn2');
  const resetBtn         = byId('resetBtn');
  const collapseInputBtn = byId('collapseInputBtn');
  const expandInputBtn   = byId('expandInputBtn');
  const appLayout        = byId('appLayout');
  const form             = document.querySelector('form');
  const settingsPanel    = document.querySelector('.settings-panel');
  const presetBtns       = document.querySelectorAll('[data-preset]');

  /* ── Share mode ─────────────────────────────────── */
  if (shareModeBtn) {
    shareModeBtn.addEventListener('click', () => toggleShareMode());
  }
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('share-mode')) {
      toggleShareMode(false);
    }
  });

  /* ── Sidebar collapse / expand ──────────────────── */
  const COLLAPSED_KEY = 'pnrc-sidebar-collapsed';

  function setSidebarCollapsed(collapsed) {
    if (!appLayout) return;
    appLayout.classList.toggle('sidebar-collapsed', collapsed);
    try { sessionStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0'); } catch {}
  }

  // Restore collapse state on page load
  try {
    if (sessionStorage.getItem(COLLAPSED_KEY) === '1') setSidebarCollapsed(true);
  } catch {}

  if (collapseInputBtn) {
    collapseInputBtn.addEventListener('click', () => setSidebarCollapsed(true));
  }
  if (expandInputBtn) {
    expandInputBtn.addEventListener('click', () => setSidebarCollapsed(false));
  }

  /* ── Dynamic tabs ───────────────────────────────── */
  const TAB_KEY = 'pnrc-active-tab';
  const tabBtns  = document.querySelectorAll('.opts-tab');
  const tabPanels = document.querySelectorAll('.opts-tab-panel');

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
    try { sessionStorage.setItem(TAB_KEY, name); } catch {}
  }

  if (tabBtns.length) {
    // Restore last active tab
    const saved = (() => { try { return sessionStorage.getItem(TAB_KEY); } catch { return null; } })();
    activateTab(saved || 'layout');

    tabBtns.forEach((btn) => {
      btn.addEventListener('click', () => activateTab(btn.getAttribute('data-tab')));
    });
  }

  /* ── Print ──────────────────────────────────────── */
  [printBtn, printBtn2].forEach((btn) => {
    if (btn) btn.addEventListener('click', () => window.print());
  });

  /* ── Clear — navigate to fresh page ─────────────── */
  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      window.location.href = window.location.pathname;
    });
  }

  /* ── Auto-submit on options change ──────────────── */
  if (form && settingsPanel && card) {
    let timer;
    settingsPanel.addEventListener('change', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }, 120);
    });
  }

  /* ── Preset buttons ─────────────────────────────── */
  if (form && presetBtns.length) {
    updatePresetState();
    presetBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const preset = btn.getAttribute('data-preset');
        applyPreset(preset);
        updatePresetState(preset);
        if (card) {
          if (typeof form.requestSubmit === 'function') form.requestSubmit();
          else form.submit();
        }
      });
    });
  }

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

  function applyPreset(preset) {
    const cb    = (n) => form.querySelector(`input[type="checkbox"][name="${n}"]`);
    const rd    = (n, v) => form.querySelector(`input[type="radio"][name="${n}"][value="${v}"]`);
    const check = (n, v) => { const el = cb(n); if (el) el.checked = v; };
    const radio = (n, v) => { const el = rd(n, v); if (el) el.checked = true; };

    if (preset === 'neutral') {
      check('show_agency_header', false);
      check('show_agency_footer', false);
      check('show_disclaimer',    false);
      radio('result_format', 'detailed');
      return;
    }
    // branded
    check('show_agency_header', true);
    check('show_agency_footer', true);
    check('show_disclaimer',    true);
    radio('result_format', 'detailed');
  }

  function updatePresetState(active) {
    presetBtns.forEach((btn) => {
      const isActive = Boolean(active && btn.getAttribute('data-preset') === active);
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  /* ── PNG render via html2canvas ──────────────────── */
  async function renderCardToPng(element) {
    if (typeof window.html2canvas !== 'function') {
      throw new Error('html2canvas not loaded');
    }
    const canvas = await window.html2canvas(element, {
      backgroundColor: '#ffffff',
      scale: 2,
      useCORS: true,
      allowTaint: false,
      logging: false,
      width:  element.offsetWidth,
      height: element.scrollHeight,
      scrollX: 0,
      scrollY: -window.scrollY,
      windowWidth:  document.documentElement.offsetWidth,
      windowHeight: document.documentElement.offsetHeight,
    });
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 0.95));
  }

})();
