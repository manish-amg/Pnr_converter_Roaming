(function () {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const card           = byId('itineraryCard');
  const shareModeBtn   = byId('shareModeBtn');
  const printBtn       = byId('printBtn');
  const downloadPngBtn = byId('downloadPngBtn');
  const copyTextBtn    = byId('copyTextBtn');
  const copyImageBtn   = byId('copyImageBtn');
  const waBtn          = byId('waBtn');
  const resetBtn       = byId('resetBtn');
  const form           = document.querySelector('form');
  const settingsPanel  = document.querySelector('.settings-panel');
  const presetBtns     = document.querySelectorAll('[data-preset]');

  /* ── Share mode ─────────────────────────────────── */
  if (shareModeBtn) {
    shareModeBtn.addEventListener('click', () => toggleShareMode());
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('share-mode')) {
      toggleShareMode(false);
    }
  });

  /* ── Print ──────────────────────────────────────── */
  if (printBtn) {
    printBtn.addEventListener('click', () => window.print());
  }

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

  /* ── WhatsApp copy ──────────────────────────────── */
  if (waBtn) {
    waBtn.addEventListener('click', async () => {
      const ta = byId('waVersion');
      if (!ta) return;
      try {
        await navigator.clipboard.writeText(ta.value);
        temporaryLabel(waBtn, '✓ Copied!');
      } catch {
        ta.select(); document.execCommand('copy');
        temporaryLabel(waBtn, '✓ Copied!');
      }
    });
  }

  /* ── Copy text ──────────────────────────────────── */
  if (copyTextBtn) {
    copyTextBtn.addEventListener('click', async () => {
      const ta = byId('textVersion');
      if (!ta) return;
      try {
        await navigator.clipboard.writeText(ta.value);
        temporaryLabel(copyTextBtn, '✓ Copied!');
      } catch {
        ta.select(); document.execCommand('copy');
        temporaryLabel(copyTextBtn, '✓ Copied!');
      }
    });
  }

  /* ── Save PNG ───────────────────────────────────── */
  if (downloadPngBtn) {
    downloadPngBtn.addEventListener('click', async () => {
      if (!card) return;
      temporaryLabel(downloadPngBtn, '⏳ Rendering…');
      try {
        const blob = await renderCardToPng(card);
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = 'itinerary-roamingnepal.png';
        document.body.appendChild(a); a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        temporaryLabel(downloadPngBtn, '💾 Save PNG');
      } catch (err) {
        console.error('PNG render error:', err);
        temporaryLabel(downloadPngBtn, '❌ Failed');
      }
    });
  }

  /* ── Copy image ─────────────────────────────────── */
  if (copyImageBtn) {
    if (!navigator.clipboard || !window.ClipboardItem) {
      copyImageBtn.disabled = true;
      copyImageBtn.title = 'Image clipboard not supported in this browser';
    } else {
      copyImageBtn.addEventListener('click', async () => {
        if (!card) return;
        temporaryLabel(copyImageBtn, '⏳ Rendering…');
        try {
          const blob = await renderCardToPng(card);
          await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
          temporaryLabel(copyImageBtn, '✓ Copied!');
        } catch (err) {
          console.error('Copy image error:', err);
          temporaryLabel(copyImageBtn, '❌ Failed');
        }
      });
    }
  }

  /* ── Helpers ─────────────────────────────────────── */
  function temporaryLabel(btn, label) {
    const orig = btn.textContent;
    btn.textContent = label;
    setTimeout(() => { btn.textContent = orig; }, 1800);
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
    if (preset === 'whatsapp') {
      check('show_agency_header', false);
      check('show_agency_footer', false);
      check('show_disclaimer',    true);
      radio('result_format', 'whatsapp');
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
