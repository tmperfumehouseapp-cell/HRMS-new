/* Add-on payment proof: one small helper shared by Order Allocation and Performance.
 *  - AddonProof.mount('addonProofSlot')  puts the "Payment proof" field in the add-on form
 *  - AddonProof.show(true|false)         shown only while an amount is prepaid
 *  - AddonProof.file()                   the screenshot (pasted with Ctrl+V / long-press paste, dropped or chosen), or null
 *  - AddonProof.reset()                  empty it (when the form opens)
 *  - AddonProof.save(payload)            saves the add-on with its screenshot (multipart: payload + proof)
 *  - AddonProof.view(addonGroup, id)     opens the screenshot in a pop-up (fetched with the login token)
 */
(function () {
  var slot = null, picked = null, objUrl = null, viewUrl = null;

  function el(id) { return document.getElementById(id); }

  function refresh() {
    if (!slot) return;
    var prev = el('adpPreview'), box = el('adpBox');
    if (objUrl) { URL.revokeObjectURL(objUrl); objUrl = null; }
    if (picked) {
      objUrl = URL.createObjectURL(picked);
      prev.innerHTML = '<img src="' + objUrl + '" alt="Payment screenshot" style="max-width:100%;max-height:180px;border-radius:8px;border:1px solid var(--line);display:block;">' +
        '<button type="button" id="adpClear" class="btn secondary" style="width:auto;font-size:12px;padding:4px 10px;margin-top:6px;">Remove screenshot</button>';
      el('adpClear').onclick = function () { picked = null; refresh(); };
      box.style.display = 'none';
    } else {
      prev.innerHTML = '';
      box.style.display = '';
    }
  }

  function setFile(f) {
    if (!f || !/^image\//i.test(f.type || 'image/jpeg')) return;
    picked = f;
    refresh();
  }

  function inModal() {
    var m = slot && slot.closest('.modal-overlay');
    return !!(m && m.classList.contains('open') && slot.style.display !== 'none');
  }

  window.AddonProof = {
    mount: function (id) {
      slot = el(id);
      if (!slot) return;
      slot.style.display = 'none';
      slot.innerHTML =
        '<label for="adpFile">Payment proof <span style="color:#c81e1e;">(required, paid in advance)</span></label>' +
        '<div id="adpBox" tabindex="0" style="border:1.5px dashed var(--line);border-radius:10px;padding:14px;text-align:center;cursor:pointer;font-size:13px;color:var(--muted);">' +
        '<b style="color:var(--ink);">Paste the screenshot here</b> (click this box, then Ctrl+V)<br>or <u>choose a file</u></div>' +
        '<input type="file" id="adpFile" accept="image/*" style="display:none;">' +
        '<div id="adpPreview" style="margin-top:8px;"></div>';
      var box = el('adpBox'), inp = el('adpFile');
      box.addEventListener('click', function () { inp.click(); });
      box.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
      inp.addEventListener('change', function () { if (inp.files && inp.files[0]) setFile(inp.files[0]); inp.value = ''; });
      ['dragover', 'drop'].forEach(function (ev) { box.addEventListener(ev, function (e) { e.preventDefault(); if (ev === 'drop' && e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); }); });
      // Ctrl+V anywhere while the add-on form is open and the proof field is showing
      document.addEventListener('paste', function (e) {
        if (!inModal()) return;
        var items = (e.clipboardData && e.clipboardData.items) || [];
        for (var i = 0; i < items.length; i++) {
          if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
            var f = items[i].getAsFile();
            if (f) { e.preventDefault(); setFile(new File([f], 'payment-proof.png', { type: f.type })); }
            return;
          }
        }
      });
    },
    show: function (on) { if (slot) slot.style.display = on ? '' : 'none'; },
    file: function () { return picked; },
    reset: function () { picked = null; refresh(); },

    save: function (payload) {
      var fd = new FormData();
      fd.append('payload', JSON.stringify(payload));
      if (picked) fd.append('proof', picked, 'payment-proof.jpg');
      return api.upload('addons.save', fd);
    },

    view: async function (group, id) {
      var ov = el('adpViewer');
      if (!ov) {
        ov = document.createElement('div');
        ov.id = 'adpViewer';
        ov.className = 'modal-overlay';
        ov.innerHTML = '<div class="modal"><h3>Payment proof</h3><div id="adpViewBody" style="text-align:center;"></div><div class="actions"><button type="button" class="btn secondary" id="adpViewClose">Close</button></div></div>';
        document.body.appendChild(ov);
        var close = function () { ov.classList.remove('open'); if (viewUrl) { URL.revokeObjectURL(viewUrl); viewUrl = null; } el('adpViewBody').innerHTML = ''; };
        el('adpViewClose').onclick = close;
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
      }
      var body = el('adpViewBody');
      body.innerHTML = '<div class="loading" style="padding:24px 0;">Loading screenshot...</div>';
      ov.classList.add('open');
      try {
        var q = group ? { addon_group: group } : { addon_id: id };
        var res = await fetch(api.buildUrl('addons.proof', q), { headers: { Authorization: 'Bearer ' + api.token() } });
        if (!res.ok) {
          var msg = 'Could not load the screenshot.';
          try { var j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
          body.innerHTML = '<div class="alert error" style="text-align:left;">' + msg + '</div>';
          return;
        }
        var blob = await res.blob();
        if (viewUrl) URL.revokeObjectURL(viewUrl);
        viewUrl = URL.createObjectURL(blob);
        body.innerHTML = '<img src="' + viewUrl + '" alt="Payment screenshot" style="max-width:100%;max-height:70vh;border-radius:8px;">';
      } catch (err) {
        body.innerHTML = '<div class="alert error" style="text-align:left;">' + err.message + '</div>';
      }
    },
  };
})();
