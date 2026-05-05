(function () {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const card          = byId('itineraryCard');
  const shareModeBtn  = byId('shareModeBtn');
  const printBtn      = byId('printBtn');
  const downloadPngBtn = byId('downloadPngBtn');
  const copyTextBtn   = byId('copyTextBtn');
  const copyImageBtn  = byId('copyImageBtn');
  const waBtn         = byId('waBtn');
  const resetBtn      = byId('resetBtn');
  const form          = document.querySelector('form');
  const settingsPanel = document.querySelector('.settings-panel');
  const presetBtns    = document.querySelectorAll('[data-preset]');

  if (shareModeBtn) {
    shareModeBtn.addEventListener('click', () => toggleShareMode());
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('share-mode')) {
      toggleShareMode(false);
    }
  });

  if (printBtn) {
    printBtn.addEventListener('click', () => window.print());
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      const ta = byId('pnr_text');
      if (ta) ta.value = '';
    });
  }

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

  if (waBtn) {
    waBtn.addEventListener('click', async () => {
      const ta = byId('waVersion');
      if (!ta) return;
      try {
        await navigator.clipboard.writeText(ta.value);
        temporaryLabel(waBtn, 'Copied!');
      } catch {
        ta.select();
        document.execCommand('copy');
        temporaryLabel(waBtn, 'Copied!');
      }
    });
  }

  if (copyTextBtn) {
    copyTextBtn.addEventListener('click', async () => {
      const ta = byId('textVersion');
      if (!ta) return;
      try {
        await navigator.clipboard.writeText(ta.value);
        temporaryLabel(copyTextBtn, 'Copied!');
      } catch {
        ta.select();
        document.execCommand('copy');
        temporaryLabel(copyTextBtn, 'Copied!');
      }
    });
  }

  if (downloadPngBtn) {
    downloadPngBtn.addEventListener('click', async () => {
      if (!card) return;
      temporaryLabel(downloadPngBtn, 'Rendering...');
      const blob = await renderCardToPng(card);
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url; a.download = 'roaming-nepal-itinerary.png';
      a.click();
      URL.revokeObjectURL(url);
      temporaryLabel(downloadPngBtn, 'Download PNG');
    });
  }

  if (copyImageBtn) {
    if (!navigator.clipboard || !window.ClipboardItem) {
      copyImageBtn.disabled = true;
      copyImageBtn.title = 'Image clipboard not supported by this browser';
    } else {
      copyImageBtn.addEventListener('click', async () => {
        if (!card) return;
        temporaryLabel(copyImageBtn, 'Rendering...');
        const blob = await renderCardToPng(card);
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        temporaryLabel(copyImageBtn, 'Copied!');
      });
    }
  }

  function temporaryLabel(btn, label) {
    const orig = btn.textContent;
    btn.textContent = label;
    setTimeout(() => { btn.textContent = orig; }, 1500);
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

  async function renderCardToPng(element) {
    const clone  = element.cloneNode(true);
    const rect   = element.getBoundingClientRect();
    const width  = Math.ceil(rect.width);
    const height = Math.ceil(rect.height);
    clone.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
    clone.style.width     = width + 'px';
    clone.style.margin    = '0';
    clone.style.boxShadow = 'none';

    const styles = Array.from(document.styleSheets)
      .map((sheet) => {
        try {
          return Array.from(sheet.cssRules).map((r) => r.cssText).join('\n');
        } catch { return ''; }
      }).join('\n');

    const html = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
      <foreignObject width="100%" height="100%">
        <div xmlns="http://www.w3.org/1999/xhtml">
          <style>${styles}</style>
          ${clone.outerHTML}
        </div>
      </foreignObject>
    </svg>`;

    const svgBlob = new Blob([html], { type: 'image/svg+xml;charset=utf-8' });
    const svgUrl  = URL.createObjectURL(svgBlob);
    try {
      const img    = await loadImage(svgUrl);
      const canvas = document.createElement('canvas');
      const scale  = Math.min(2, window.devicePixelRatio || 1);
      canvas.width  = width  * scale;
      canvas.height = height * scale;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.scale(scale, scale);
      ctx.drawImage(img, 0, 0);
      return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 0.95));
    } finally {
      URL.revokeObjectURL(svgUrl);
    }
  }

  function loadImage(url) {
    return new Promise((res, rej) => {
      const img = new Image();
      img.onload  = () => res(img);
      img.onerror = rej;
      img.src = url;
    });
  }
})();
