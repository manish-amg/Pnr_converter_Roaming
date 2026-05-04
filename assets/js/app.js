(function () {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const card = byId('itineraryCard');
  const shareModeBtn = byId('shareModeBtn');
  const printBtn = byId('printBtn');
  const downloadPngBtn = byId('downloadPngBtn');
  const copyTextBtn = byId('copyTextBtn');
  const copyImageBtn = byId('copyImageBtn');
  const resetBtn = byId('resetBtn');
  const form = document.querySelector('form');
  const settingsPanel = document.querySelector('.settings-panel');

  if (shareModeBtn) {
    shareModeBtn.addEventListener('click', () => {
      toggleShareMode();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('share-mode')) {
      toggleShareMode(false);
    }
  });

  if (printBtn) {
    printBtn.addEventListener('click', () => window.print());
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      const textarea = byId('pnr_text');
      if (textarea) textarea.value = '';
    });
  }

  if (form && settingsPanel && card) {
    let refreshTimer;
    settingsPanel.addEventListener('change', () => {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(() => {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }, 120);
    });
  }

  if (copyTextBtn) {
    copyTextBtn.addEventListener('click', async () => {
      const textVersion = byId('textVersion');
      if (!textVersion) return;
      await navigator.clipboard.writeText(textVersion.value);
      temporaryLabel(copyTextBtn, 'Copied');
    });
  }

  if (downloadPngBtn) {
    downloadPngBtn.addEventListener('click', async () => {
      if (!card) return;
      const blob = await renderCardToPng(card);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'roaming-nepal-itinerary.png';
      link.click();
      URL.revokeObjectURL(url);
    });
  }

  if (copyImageBtn) {
    if (!navigator.clipboard || !window.ClipboardItem) {
      copyImageBtn.disabled = true;
      copyImageBtn.title = 'Image clipboard is not supported by this browser';
    } else {
      copyImageBtn.addEventListener('click', async () => {
        if (!card) return;
        const blob = await renderCardToPng(card);
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        temporaryLabel(copyImageBtn, 'Copied');
      });
    }
  }

  function temporaryLabel(button, label) {
    const old = button.textContent;
    button.textContent = label;
    setTimeout(() => {
      button.textContent = old;
    }, 1400);
  }

  function toggleShareMode(force) {
    const shouldEnable = typeof force === 'boolean' ? force : !document.body.classList.contains('share-mode');
    document.body.classList.toggle('share-mode', shouldEnable);
    if (shareModeBtn) {
      shareModeBtn.textContent = shouldEnable ? 'Exit Share View' : 'Clean Share View';
    }
  }

  async function renderCardToPng(element) {
    const clone = element.cloneNode(true);
    const rect = element.getBoundingClientRect();
    const width = Math.ceil(rect.width);
    const height = Math.ceil(rect.height);
    clone.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
    clone.style.width = width + 'px';
    clone.style.margin = '0';
    clone.style.boxShadow = 'none';

    const styles = Array.from(document.styleSheets)
      .map((sheet) => {
        try {
          return Array.from(sheet.cssRules).map((rule) => rule.cssText).join('\n');
        } catch (error) {
          return '';
        }
      })
      .join('\n');

    const html = `
      <svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
        <foreignObject width="100%" height="100%">
          <div xmlns="http://www.w3.org/1999/xhtml">
            <style>${styles}</style>
            ${clone.outerHTML}
          </div>
        </foreignObject>
      </svg>`;

    const svgBlob = new Blob([html], { type: 'image/svg+xml;charset=utf-8' });
    const svgUrl = URL.createObjectURL(svgBlob);
    try {
      const image = await loadImage(svgUrl);
      const canvas = document.createElement('canvas');
      const scale = Math.min(2, window.devicePixelRatio || 1);
      canvas.width = width * scale;
      canvas.height = height * scale;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.scale(scale, scale);
      ctx.drawImage(image, 0, 0);
      return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 0.95));
    } finally {
      URL.revokeObjectURL(svgUrl);
    }
  }

  function loadImage(url) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = url;
    });
  }
})();
