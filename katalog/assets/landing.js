/* =====================================================
   KATALOG - landing modal verifikasi kode desa
   ===================================================== */
(function () {
  'use strict';

  var input = document.getElementById('kode-input');
  var btn   = document.getElementById('btn-verify');
  var msg   = document.getElementById('msg');

  if (!input || !btn) return;

  function setMsg(text, type) {
    msg.textContent = text || '';
    msg.className = 'msg ' + (type || '');
  }

  // Numeric-only input filter
  input.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
    if (msg.textContent) setMsg('');
  });

  // Enter key submits
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      btn.click();
    }
  });

  btn.addEventListener('click', function () {
    var kode = (input.value || '').trim();
    if (kode.length !== 10) {
      setMsg('Kode desa harus 10 digit angka.', 'error');
      input.focus();
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Memverifikasi...';
    setMsg('');

    var fd = new FormData();
    fd.append('action', 'verify_kode');
    fd.append('csrf', window.KATALOG_CSRF);
    fd.append('kode', kode);

    fetch('api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        if (resp.ok) {
          setMsg('✓ Berhasil! Mengarahkan ke editor...', 'ok');
          window.location.href = 'editor.php';
        } else {
          btn.disabled = false;
          btn.textContent = 'Lanjutkan »';
          setMsg(resp.msg || 'Verifikasi gagal.', 'error');
          input.focus();
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Lanjutkan »';
        setMsg('Koneksi gagal. Coba lagi.', 'error');
      });
  });

  // Auto-focus input
  setTimeout(function () { input.focus(); }, 100);
})();
