/* Brilian 2026 - Konfirmasi Data Desa (v2) */
(function () {
  'use strict';

  var modal     = document.getElementById('modal');
  var step1     = document.getElementById('step-1');
  var step2     = document.getElementById('step-2');
  var step3     = document.getElementById('step-3');
  var mNama     = document.getElementById('m-nama');
  var kodeInput = document.getElementById('kode-input');
  var btnVerify = document.getElementById('btn-verify');
  var step1Msg  = document.getElementById('step-1-msg');
  var btnSubmit = document.getElementById('btn-submit');
  var step2Msg  = document.getElementById('step-2-msg');
  var chkConfirm = document.getElementById('chk-confirm');

  var sNama       = document.getElementById('s-nama');
  var sEdited     = document.getElementById('s-edited');
  var sEditCount  = document.getElementById('s-edit-count');
  var sEditList   = document.getElementById('s-edit-list');

  // Field editable: target -> {valEl, inputEl, hintEl}
  var fields = ['nama_desa', 'kec', 'kab', 'prov', 'nama_kades', 'hp', 'email'];
  var fieldElems = {};
  fields.forEach(function (f) {
    fieldElems[f] = {
      val:   document.getElementById('v-' + f),
      input: document.getElementById('i-' + f),
      hint:  document.getElementById('h-' + f) // optional
    };
  });
  // (Read-only BRI fields dihapus dari UI — tidak perlu reference)

  var currentKode = '';

  // ================== Modal control ==================
  function openModal(nama) {
    mNama.textContent = nama;
    showStep(1);
    kodeInput.value = '';
    setMsg(step1Msg, '');
    setMsg(step2Msg, '');
    chkConfirm.checked = false;
    btnSubmit.disabled = true;
    // reset semua field ke read-only
    fields.forEach(function (f) { resetFieldToView(f); });
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { kodeInput.focus(); }, 100);
  }

  function closeModal() {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (!step3.classList.contains('hidden')) {
      window.location.reload();
    }
  }

  function showStep(n) {
    step1.classList.toggle('hidden', n !== 1);
    step2.classList.toggle('hidden', n !== 2);
    step3.classList.toggle('hidden', n !== 3);
  }

  function setMsg(el, text, type) {
    el.textContent = text || '';
    el.className = 'msg' + (text ? (' ' + (type || 'error')) : '');
  }

  // ================== Field edit logic ==================
  function getFieldItem(name) {
    return document.querySelector('.data-item[data-field="' + name + '"]');
  }

  function showInput(name) {
    var item = getFieldItem(name);
    if (!item) return;
    item.classList.add('editing');
    var inp = fieldElems[name].input;
    var val = fieldElems[name].val.textContent;
    if (val && val !== '-') inp.value = val;
    inp.classList.remove('hidden');
    if (fieldElems[name].hint) fieldElems[name].hint.classList.remove('hidden');
    setTimeout(function () { inp.focus(); inp.select(); }, 50);
  }

  function resetFieldToView(name) {
    var item = getFieldItem(name);
    if (!item) return;
    item.classList.remove('editing');
    fieldElems[name].input.classList.add('hidden');
    if (fieldElems[name].hint) fieldElems[name].hint.classList.add('hidden');
  }

  // Bind tombol "Perbaiki"
  document.querySelectorAll('.btn-fix').forEach(function (btn) {
    btn.addEventListener('click', function () {
      showInput(btn.getAttribute('data-target'));
    });
  });

  // Hanya angka untuk HP (kecuali +)
  fieldElems.hp.input.addEventListener('input', function () {
    var v = this.value;
    var first = v.charAt(0);
    var rest  = v.slice(1).replace(/\D/g, '');
    this.value = (first === '+' ? '+' : first.replace(/\D/g, '')) + rest;
  });

  // ================== Bind tombol Konfirmasi di tabel ==================
  document.querySelectorAll('.btn-confirm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.getAttribute('data-nama'));
    });
  });

  // Close handlers
  document.querySelectorAll('[data-close]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
  });

  // Hanya angka untuk kode desa
  kodeInput.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
  });
  kodeInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); btnVerify.click(); }
  });

  // Checkbox konfirmasi → enable/disable submit
  chkConfirm.addEventListener('change', function () {
    btnSubmit.disabled = !this.checked;
  });

  // ================== Step 1: Verifikasi kode ==================
  btnVerify.addEventListener('click', function () {
    var kode = kodeInput.value.trim();
    if (kode.length !== 10) {
      setMsg(step1Msg, 'Kode desa harus 10 digit angka.', 'error');
      kodeInput.focus();
      return;
    }

    btnVerify.disabled = true;
    btnVerify.textContent = 'Memeriksa...';
    setMsg(step1Msg, '');

    var fd = new FormData();
    fd.append('csrf', window.CSRF_TOKEN);
    fd.append('kode', kode);

    fetch('verify.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnVerify.disabled = false;
        btnVerify.textContent = 'Lanjutkan »';

        if (!j.ok) {
          setMsg(step1Msg, j.msg || 'Verifikasi gagal.', 'error');
          return;
        }

        currentKode = j.data.kode;
        var d = j.data;

        // Isi semua field editable
        document.getElementById('v-nama_desa').textContent  = d.nama       || '-';
        document.getElementById('v-kec').textContent        = d.kec        || '-';
        document.getElementById('v-kab').textContent        = d.kab        || '-';
        document.getElementById('v-prov').textContent       = d.prov       || '-';
        document.getElementById('v-nama_kades').textContent = d.nama_kades || '(belum ada, mohon perbaiki)';
        document.getElementById('v-hp').textContent         = d.hp         || '(belum ada, mohon perbaiki)';
        document.getElementById('v-email').textContent      = d.email      || '(belum ada, mohon perbaiki)';

        // BRI fields removed dari UI (tetap ada di server-side data, tidak ditampilkan)

        // Banner revisi
        var banner = document.getElementById('revisi-banner');
        if (d.sudah_konfirmasi && d.total_edits > 0) {
          banner.classList.remove('hidden');
          document.getElementById('revisi-count').textContent = d.total_edits;
        } else {
          banner.classList.add('hidden');
        }

        // Auto-show input untuk field yang kosong (memandu user)
        ['nama_kades', 'hp', 'email'].forEach(function (f) {
          var v = d[f === 'nama_kades' ? 'nama_kades' : f];
          if (!v) showInput(f);
        });

        showStep(2);
      })
      .catch(function () {
        btnVerify.disabled = false;
        btnVerify.textContent = 'Lanjutkan »';
        setMsg(step1Msg, 'Koneksi gagal. Cek internet Anda dan coba lagi.', 'error');
      });
  });

  // ================== Step 2: Submit ==================
  function getFieldValue(name) {
    var item = getFieldItem(name);
    if (item && item.classList.contains('editing')) {
      return fieldElems[name].input.value.trim();
    }
    var v = fieldElems[name].val.textContent;
    if (v === '-' || v.indexOf('(belum ada') === 0) return '';
    return v.trim();
  }

  btnSubmit.addEventListener('click', function () {
    if (!chkConfirm.checked) {
      setMsg(step2Msg, 'Anda harus mencentang konfirmasi terlebih dahulu.', 'error');
      return;
    }

    var data = {
      nama_desa:  getFieldValue('nama_desa'),
      kec:        getFieldValue('kec'),
      kab:        getFieldValue('kab'),
      prov:       getFieldValue('prov'),
      nama_kades: getFieldValue('nama_kades'),
      hp:         getFieldValue('hp'),
      email:      getFieldValue('email'),
    };

    // Validasi client
    var required = {
      'nama_desa':  'Nama Desa',
      'kec':        'Kecamatan',
      'kab':        'Kabupaten',
      'prov':       'Provinsi',
      'nama_kades': 'Nama Kepala Desa',
      'hp':         'Nomor HP',
      'email':      'Email',
    };
    for (var f in required) {
      if (!data[f]) {
        setMsg(step2Msg, required[f] + ' wajib diisi. Klik "Perbaiki" untuk mengedit.', 'error');
        return;
      }
    }
    var hpDigits = data.hp.replace(/\D/g, '');
    if (hpDigits.length < 10 || hpDigits.length > 15) {
      setMsg(step2Msg, 'Nomor HP tidak valid. Format 08xxxxxxxxxx (minimal 11 digit).', 'error');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
      setMsg(step2Msg, 'Format email tidak valid. Contoh: nama@gmail.com', 'error');
      return;
    }

    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Menyimpan...';
    setMsg(step2Msg, '');

    var fd = new FormData();
    fd.append('csrf', window.CSRF_TOKEN);
    fd.append('kode', currentKode);
    fd.append('confirmed', '1');
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });

    fetch('submit.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnSubmit.disabled = false;
        btnSubmit.textContent = '✓ Submit Konfirmasi';

        if (!j.ok) {
          setMsg(step2Msg, j.msg || 'Gagal menyimpan.', 'error');
          return;
        }

        sNama.textContent = j.data.nama || '-';
        var edited = j.data.edited || [];
        if (edited.length > 0) {
          sEdited.classList.remove('hidden');
          sEditCount.textContent = edited.length;
          var labels = {
            'nama_desa': 'Nama Desa', 'kecamatan': 'Kecamatan',
            'kabupaten': 'Kabupaten', 'provinsi': 'Provinsi',
            'nama_kades': 'Nama Kades', 'hp_kades': 'HP', 'email': 'Email'
          };
          sEditList.textContent = edited.map(function (e) { return labels[e] || e; }).join(', ');
        } else {
          sEdited.classList.add('hidden');
        }
        showStep(3);
      })
      .catch(function () {
        btnSubmit.disabled = false;
        btnSubmit.textContent = '✓ Submit Konfirmasi';
        setMsg(step2Msg, 'Koneksi gagal. Coba lagi.', 'error');
      });
  });

  // ====================================================================
  // ============= INFO LMS (BARU - terpisah dari logic atas) ==========
  // ====================================================================

  var modalLms      = document.getElementById('modal-lms');
  var lmsStep1      = document.getElementById('lms-step-1');
  var lmsStep2      = document.getElementById('lms-step-2');
  var lmsStep3      = document.getElementById('lms-step-3');
  var lmsMNama      = document.getElementById('lms-m-nama');
  var lmsKodeInput  = document.getElementById('lms-kode-input');
  var lmsBtnVerify  = document.getElementById('lms-btn-verify');
  var lmsStep1Msg   = document.getElementById('lms-step-1-msg');
  var lmsDesaName   = document.getElementById('lms-desa-name');
  var lmsCredDesa   = document.getElementById('lms-cred-desa');
  var lmsCredUser   = document.getElementById('lms-cred-username');
  var lmsCredPass   = document.getElementById('lms-cred-password');
  var lmsChoiceCred = document.getElementById('lms-choice-cred');
  var lmsChoiceEdit = document.getElementById('lms-choice-edit');

  var lmsCurrentKode = '';
  var lmsCurrentNama = '';

  function lmsShowStep(n) {
    lmsStep1.classList.toggle('hidden', n !== 1);
    lmsStep2.classList.toggle('hidden', n !== 2);
    lmsStep3.classList.toggle('hidden', n !== 3);
  }

  function openLmsModal(nama) {
    lmsCurrentNama = nama;
    lmsMNama.textContent = nama;
    lmsKodeInput.value = '';
    setMsg(lmsStep1Msg, '');
    lmsShowStep(1);
    modalLms.classList.add('show');
    modalLms.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { lmsKodeInput.focus(); }, 100);
  }

  function closeLmsModal() {
    modalLms.classList.remove('show');
    modalLms.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // Bind tombol Info LMS
  document.querySelectorAll('.btn-info-lms').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openLmsModal(btn.getAttribute('data-nama'));
    });
  });

  // Close handlers untuk modal LMS
  document.querySelectorAll('[data-close-lms]').forEach(function (el) {
    el.addEventListener('click', closeLmsModal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modalLms.classList.contains('show')) closeLmsModal();
  });

  // Hanya angka di input kode
  lmsKodeInput.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
  });
  lmsKodeInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); lmsBtnVerify.click(); }
  });

  // Verifikasi kode → ambil kredensial
  lmsBtnVerify.addEventListener('click', function () {
    var kode = lmsKodeInput.value.trim();
    if (kode.length !== 10) {
      setMsg(lmsStep1Msg, 'Kode desa harus 10 digit angka.', 'error');
      lmsKodeInput.focus();
      return;
    }

    lmsBtnVerify.disabled = true;
    lmsBtnVerify.textContent = 'Memeriksa...';
    setMsg(lmsStep1Msg, '');

    var fd = new FormData();
    fd.append('csrf', window.CSRF_TOKEN);
    fd.append('kode', kode);

    fetch('lms_info.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        lmsBtnVerify.disabled = false;
        lmsBtnVerify.textContent = 'Lanjutkan »';

        if (!j.ok) {
          setMsg(lmsStep1Msg, j.msg || 'Verifikasi gagal.', 'error');
          return;
        }

        lmsCurrentKode = j.data.kode;
        lmsDesaName.textContent = j.data.nama_desa + ' (' + j.data.kode + ')';
        lmsCredDesa.textContent = j.data.nama_desa + ' (' + j.data.kode + ')';
        lmsCredUser.textContent = j.data.username;
        lmsCredPass.textContent = j.data.password;
        lmsShowStep(2);
      })
      .catch(function () {
        lmsBtnVerify.disabled = false;
        lmsBtnVerify.textContent = 'Lanjutkan »';
        setMsg(lmsStep1Msg, 'Koneksi gagal. Coba lagi.', 'error');
      });
  });

  // Pilihan: Lihat kredensial
  lmsChoiceCred.addEventListener('click', function () {
    lmsShowStep(3);
  });

  // Pilihan: Edit Data → tutup modal LMS, buka modal konfirmasi existing
  lmsChoiceEdit.addEventListener('click', function () {
    closeLmsModal();
    // Buka modal konfirmasi (yang sudah ada), pakai nama yang sama
    // Modal konfirmasi akan minta verifikasi kode lagi (sesuai flow asli)
    openModal(lmsCurrentNama);
  });

  // Tombol copy
  document.querySelectorAll('.lms-btn-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-copy');
      var text = document.getElementById(targetId).textContent;
      var orig = btn.textContent;

      var done = function () {
        btn.textContent = '✓ Copied';
        btn.classList.add('copied');
        setTimeout(function () {
          btn.textContent = orig;
          btn.classList.remove('copied');
        }, 1500);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
          // fallback
          var ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        });
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
      }
    });
  });

})();
