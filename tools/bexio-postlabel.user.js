// ==UserScript==
// @name         bexio Postlabel
// @namespace    armatum.ch
// @version      1.0
// @description  Knopf «Postlabel drucken» im bexio-Lieferschein: erstellt im Shop das Post-Label zur Bestellung und öffnet es zum Drucken.
// @match        https://office.bexio.com/*
// @grant        GM_getValue
// @grant        GM_setValue
// @run-at       document-idle
// ==/UserScript==

(function () {
  'use strict';

  // ---- Einstellungen ----
  const SHOP = 'https://armatum.ch';      // Adresse des Shops
  const BTN_TEXT = 'Postlabel drucken';

  const log = (...a) => console.log('[bexio-Postlabel]', ...a);
  const uniq = a => [...new Set(a)];

  // bexio-ID des Lieferscheins aus der Adresse (z.B. /index.php/kb_delivery/show/id/1234)
  function deliveryId() {
    const u = location.href;
    const m = u.match(/kb_delivery\/(?:show|edit)\/id\/(\d+)/i)
      || u.match(/kb_delivery[^0-9]{0,20}(\d+)/i)
      || u.match(/deliver(?:y|ies)[^0-9]{0,20}(\d+)/i)
      || u.match(/lieferschein[^0-9]{0,20}(\d+)/i);
    return m ? m[1] : '';
  }

  function isDeliveryPage() {
    if (/kb_delivery|deliver|lieferschein/i.test(location.href)) return true;
    const h = document.querySelector('h1, h2, .page-title, [class*="title"]');
    return !!(h && /Lieferschein/i.test(h.textContent || ''));
  }

  // Hinweise für die Zuordnung: woocommerce-ID, bexio-Auftragsnummer AU-…, Bestellnummer #…
  function hints() {
    const text = (document.title || '') + ' ' + (document.body ? document.body.innerText : '');
    const found = [
      ...(text.match(/woocommerce-\d+/gi) || []),
      ...(text.match(/\bAU-\d{3,}\b/gi) || []),
      ...(text.match(/#\s?\d{3,7}\b/g) || []),
    ];
    return uniq(found.map(s => s.replace(/\s/g, ''))).slice(0, 12).join(' ');
  }

  function getKey(force) {
    let key = force ? '' : GM_getValue('apl_key', '');
    if (!key) {
      key = (prompt('Schlüssel aus dem Shop eingeben\n(WordPress > Einstellungen > Armatum Post Label > «Postlabel aus bexio»):') || '').trim();
      if (key) GM_setValue('apl_key', key);
    }
    return key;
  }

  function printLabel(e) {
    const key = getKey(e && e.shiftKey);            // Umschalt+Klick = Schlüssel neu eingeben
    if (!key) return;
    const id = deliveryId();
    let h = hints();
    if (!id && !h || (e && e.altKey)) {             // Alt+Klick = Bestellnummer von Hand
      const nr = (prompt('Bestellnummer im Shop (z.B. 36895):') || '').trim();
      if (!nr) return;
      h = nr;
    }
    const url = SHOP + '/wp-admin/admin-post.php?action=apl_bexio_label'
      + '&key=' + encodeURIComponent(key)
      + '&delivery=' + encodeURIComponent(id)
      + '&hints=' + encodeURIComponent(h);
    log('öffne', { id, hints: h });
    window.open(url, 'apl_postlabel');
  }

  let btn = null;
  function ensureButton() {
    const show = isDeliveryPage();
    if (show && !btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = '🏷 ' + BTN_TEXT;
      btn.title = 'Klick: Label erstellen/drucken · Umschalt+Klick: Schlüssel neu · Alt+Klick: Bestellnummer von Hand';
      Object.assign(btn.style, {
        position: 'fixed', right: '20px', bottom: '20px', zIndex: 99999,
        padding: '10px 16px', font: '600 14px/1.2 Arial, sans-serif',
        background: '#ffcc00', color: '#000', border: '1px solid #b38f00',
        borderRadius: '6px', boxShadow: '0 2px 8px rgba(0,0,0,.25)', cursor: 'pointer',
      });
      btn.addEventListener('click', printLabel);
      document.body.appendChild(btn);
    } else if (!show && btn) {
      btn.remove();
      btn = null;
    }
  }

  // bexio wechselt Seiten teils ohne Neuladen -> regelmässig prüfen
  ensureButton();
  setInterval(ensureButton, 1000);
  log('geladen');
})();
