/* ============================================================
   Brilian 2026 - Cek Presensi (v4, READ-ONLY)
   Desa hanya bisa LIHAT status, tidak bisa input.
   Presensi dicatat otomatis oleh admin via upload Moodle Attendance.
   ============================================================ */
(function () {
  'use strict';

  // Tanggal sesi (HARUS sinkron dengan $ALLOWED_DATES di server)
  var SESI_DATES = [
    { date: '2026-05-12', day: 12, weekday: 'Selasa' },
    { date: '2026-05-13', day: 13, weekday: 'Rabu'   },
    { date: '2026-05-19', day: 19, weekday: 'Selasa' },
    { date: '2026-05-20', day: 20, weekday: 'Rabu'   },
    { date: '2026-05-21', day: 21, weekday: 'Kamis'  },
    { date: '2026-05-25', day: 25, weekday: 'Senin'  },
    { date: '2026-05-26', day: 26, weekday: 'Selasa' },
  ];

  var modalPre = document.getElementById('modal-presensi');
  if (!modalPre) {
    console.warn('[presensi.js] modal-presensi tidak ditemukan');
    return;
  }

  var preStep1   = document.getElementById('pre-step-1');
  var preStep2   = document.getElementById('pre-step-2');

  var preMNama   = document.getElementById('pre-m-nama');
  var preKodeInp = document.getElementById('pre-kode-input');
  var preBtnVer  = document.getElementById('pre-btn-verify');
  var preMsg1    = document.getElementById('pre-step-1-msg');

  var preDesaName= document.getElementById('pre-desa-name');
  var preDateGrid= document.getElementById('pre-date-grid');
  var preMsg2    = document.getElementById('pre-step-2-msg');

  var preCurrentNama = '';
  var serverToday    = '';

  // ================== Helpers ==================
  function preShowStep(n) {
    preStep1.classList.toggle('hidden', n !== 1);
    preStep2.classList.toggle('hidden', n !== 2);
  }

  function preSetMsg(el, text, type) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'msg' + (text ? (' ' + (type || 'error')) : '');
  }

  function openPreModal(nama) {
    preCurrentNama = nama;
    preMNama.textContent = nama;
    preKodeInp.value = '';
    preSetMsg(preMsg1, '');
    preSetMsg(preMsg2, '');
    preShowStep(1);
    modalPre.classList.add('show');
    modalPre.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { preKodeInp.focus(); }, 100);
  }

  function closePreModal() {
    modalPre.classList.remove('show');
    modalPre.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ================== Bind tombol Cek Presensi ==================
  document.querySelectorAll('.btn-presensi').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openPreModal(btn.getAttribute('data-nama') || '');
    });
  });

  // Close handlers
  document.querySelectorAll('[data-close-pre]').forEach(function (el) {
    el.addEventListener('click', closePreModal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modalPre.classList.contains('show')) closePreModal();
  });

  preKodeInp.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
  });
  preKodeInp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); preBtnVer.click(); }
  });

  // ================== Render grid tanggal (READ-ONLY) ==================
  // State:
  //   - hadir  : sudah presensi (✓ hijau + timestamp)
  //   - today  : tanggal hari ini, belum tercatat hadir
  //              ("Belum tercatat – pastikan login di Moodle")
  //   - locked : tgl belum tiba (gembok abu)
  //   - missed : tgl sudah lewat, tidak hadir ("Tidak hadir")
  function renderDateGrid(presensiMap, today) {
    preDateGrid.innerHTML = '';

    SESI_DATES.forEach(function (sesi) {
      var card = document.createElement('div');
      var done = presensiMap[sesi.date];
      var isToday  = (sesi.date === today);
      var isPast   = (sesi.date < today);
      var isFuture = (sesi.date > today);

      var stateClass = '';
      var badge = '';
      var extraNote = '';

      if (done) {
        stateClass = 'done';
        badge = '<div class="pre-date-check">✓</div>';
        extraNote = '<div class="pre-date-time">Tercatat hadir</div>';
        extraNote += '<div class="pre-date-info">' + done.submitted_at + '</div>';
      } else if (isToday) {
        stateClass = 'today-pending';
        badge = '<div class="pre-date-check today-dot">●</div>';
        extraNote = '<div class="pre-date-info">Hari ini – belum tercatat</div>';
      } else if (isFuture) {
        stateClass = 'locked';
        badge = '<div class="pre-date-check lock">🔒</div>';
        extraNote = '<div class="pre-date-info">Belum berlangsung</div>';
      } else if (isPast) {
        stateClass = 'missed';
        badge = '<div class="pre-date-check">—</div>';
        extraNote = '<div class="pre-date-info">Tidak hadir</div>';
      }

      card.className = 'pre-date-card readonly ' + stateClass;
      card.setAttribute('data-tanggal', sesi.date);

      card.innerHTML =
        badge +
        '<div class="pre-date-day">' + sesi.day + '</div>' +
        '<div class="pre-date-month">Mei 2026</div>' +
        '<div class="pre-date-weekday">' + sesi.weekday + '</div>' +
        extraNote;

      preDateGrid.appendChild(card);
    });

    // Summary banner
    var hadirCount = 0;
    SESI_DATES.forEach(function (s) { if (presensiMap[s.date]) hadirCount++; });
    var bannerTxt = 'Total tercatat hadir: <b>' + hadirCount + ' dari ' + SESI_DATES.length + ' sesi</b>.';
    preMsg2.className = 'msg';
    preMsg2.innerHTML = bannerTxt;
  }

  // ================== Verifikasi kode + load status ==================
  preBtnVer.addEventListener('click', function () {
    var kode = preKodeInp.value.trim();
    if (kode.length !== 10) {
      preSetMsg(preMsg1, 'Kode desa harus 10 digit angka.', 'error');
      preKodeInp.focus();
      return;
    }

    preBtnVer.disabled = true;
    preBtnVer.textContent = 'Memeriksa...';
    preSetMsg(preMsg1, '');

    var fd = new FormData();
    fd.append('csrf', window.CSRF_TOKEN);
    fd.append('kode', kode);

    fetch('presensi_status.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        preBtnVer.disabled = false;
        preBtnVer.textContent = 'Lihat Status »';

        if (!j.ok) {
          preSetMsg(preMsg1, j.msg || 'Verifikasi gagal.', 'error');
          return;
        }

        serverToday = j.data.today || '';
        preCurrentNama = j.data.nama_desa;
        preDesaName.textContent = preCurrentNama + ' (' + j.data.kode + ')';
        renderDateGrid(j.data.presensi || {}, serverToday);
        preShowStep(2);
      })
      .catch(function () {
        preBtnVer.disabled = false;
        preBtnVer.textContent = 'Lihat Status »';
        preSetMsg(preMsg1, 'Koneksi gagal. Coba lagi.', 'error');
      });
  });

})();
