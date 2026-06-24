/* ============================================================
   Brilian 2026 — Gradebook per desa (READ-ONLY, card-based)
   Buka modal -> minta kode desa -> tampilkan nilai desa itu.
   ============================================================ */
(function () {
  'use strict';

  var HARI = [
    { key: 'kickoff', label: 'Kick Off', tgl: '7 Mei',  test: false },
    { key: 'day1',    label: 'Day 1',    tgl: '12 Mei', test: true  },
    { key: 'day2',    label: 'Day 2',    tgl: '13 Mei', test: true  },
    { key: 'day3',    label: 'Day 3',    tgl: '19 Mei', test: true  },
    { key: 'day4',    label: 'Day 4',    tgl: '20 Mei', test: true  },
    { key: 'day5',    label: 'Day 5',    tgl: '21 Mei', test: true  },
    { key: 'day6',    label: 'Day 6',    tgl: '25 Mei', test: true  },
    { key: 'day7',    label: 'Day 7',    tgl: '26 Mei', test: true  }
  ];
  var TUGAS = [
    { no: 1, nama: 'Aspek Legal' },
    { no: 2, nama: 'Penyaluran Dana Desa' },
    { no: 3, nama: 'Tugas Tematik' },
    { no: 4, nama: 'Laporan Keuangan BUMDes' }
  ];

  var modal = document.getElementById('modal-gradebook');
  if (!modal) return;

  var step1   = document.getElementById('gb-step-1');
  var step2   = document.getElementById('gb-step-2');
  var mNama   = document.getElementById('gb-m-nama');
  var kodeInp = document.getElementById('gb-kode-input');
  var btnVer  = document.getElementById('gb-btn-verify');
  var msg1    = document.getElementById('gb-step-1-msg');
  var desaName= document.getElementById('gb-desa-name');
  var content = document.getElementById('gb-content');

  function showStep(n) {
    step1.classList.toggle('hidden', n !== 1);
    step2.classList.toggle('hidden', n !== 2);
  }
  function setMsg(text, type) {
    msg1.textContent = text || '';
    msg1.className = 'msg' + (text ? (' ' + (type || 'error')) : '');
  }
  function openModal(nama) {
    mNama.textContent = nama || '';
    kodeInp.value = '';
    setMsg('');
    content.innerHTML = '';
    showStep(1);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { kodeInp.focus(); }, 100);
  }
  function closeModal() {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.btn-gradebook').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.getAttribute('data-nama') || '');
    });
  });
  document.querySelectorAll('[data-close-gb]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
  });
  kodeInp.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
  });
  kodeInp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); btnVer.click(); }
  });

  function card(lbl, sub, val, cls) {
    return '<div class="gb2-card ' + cls + '">' +
      '<div class="lbl">' + lbl + '</div>' +
      (sub ? '<div class="sub">' + sub + '</div>' : '') +
      '<div class="val">' + val + '</div></div>';
  }

  function render(d) {
    var hadir = d.hadir || {}, nilai = d.nilai || {}, tugas = d.tugas || {};
    var html = '';

    // Ringkasan
    var hadirCount = 0;
    HARI.forEach(function (h) { if (hadir[h.key]) hadirCount++; });
    var tugasCount = 0;
    TUGAS.forEach(function (t) { if (tugas[t.no] && tugas[t.no].nilai !== null && tugas[t.no].nilai !== undefined) tugasCount++; });
    html += '<div class="gb2-summary">' +
      '<div class="s"><div class="n">' + hadirCount + '/8</div><div class="l">Sesi Hadir</div></div>' +
      '<div class="s green"><div class="n">' + tugasCount + '/4</div><div class="l">Tugas Dinilai</div></div>' +
      '<div class="s purple"><div class="n">' + (d.keaktifan !== null && d.keaktifan !== undefined ? d.keaktifan : '–') + '</div><div class="l">Keaktifan</div></div>' +
      '</div>';

    // Kehadiran
    html += '<div class="gb2-sec"><div class="gb2-sec-title"><span class="ic">📋</span> Kehadiran <span class="pill">' + hadirCount + '/8</span></div><div class="gb2-grid">';
    HARI.forEach(function (h) {
      var ada = !!hadir[h.key];
      html += card(h.label, h.tgl, ada ? '✓' : '–', ada ? 'hadir' : 'miss');
    });
    html += '</div></div>';

    // Pre-Test
    html += '<div class="gb2-sec"><div class="gb2-sec-title"><span class="ic">📝</span> Nilai Pre-Test</div><div class="gb2-grid">';
    HARI.forEach(function (h) {
      if (!h.test) return;
      var v = nilai[h.key] && nilai[h.key].pretest != null ? nilai[h.key].pretest : null;
      html += card(h.label, h.tgl, v !== null ? v : '–', v !== null ? 'nilai' : 'empty');
    });
    html += '</div></div>';

    // Post-Test
    html += '<div class="gb2-sec"><div class="gb2-sec-title"><span class="ic">✅</span> Nilai Post-Test</div><div class="gb2-grid">';
    HARI.forEach(function (h) {
      if (!h.test) return;
      var v = nilai[h.key] && nilai[h.key].posttest != null ? nilai[h.key].posttest : null;
      html += card(h.label, h.tgl, v !== null ? v : '–', v !== null ? 'nilai' : 'empty');
    });
    html += '</div></div>';

    // 4 Tugas
    html += '<div class="gb2-sec"><div class="gb2-sec-title"><span class="ic">📂</span> Nilai 4 Tugas</div><div class="gb2-grid">';
    TUGAS.forEach(function (t) {
      var tg = tugas[t.no];
      var v = tg && tg.nilai != null ? tg.nilai : (tg && tg.kumpul ? '✓' : null);
      var cls = (tg && tg.nilai != null) ? 'nilai' : (tg && tg.kumpul ? 'hadir' : 'empty');
      html += card('Tugas ' + t.no, t.nama, v !== null ? v : '–', cls);
    });
    html += '</div></div>';

    // Keaktifan
    html += '<div class="gb2-sec"><div class="gb2-sec-title"><span class="ic">⚡</span> Nilai Keaktifan</div>';
    if (d.keaktifan !== null && d.keaktifan !== undefined) {
      html += '<div class="gb2-ka"><div class="big">' + d.keaktifan + '</div>' +
        '<div class="meta"><div class="t">Nilai Keaktifan Akhir</div>' +
        '<div class="d">Penilaian keaktifan selama pelatihan</div></div></div>';
    } else {
      html += '<div class="gb2-ka empty"><div class="big">–</div>' +
        '<div class="meta"><div class="t">Belum ada nilai</div>' +
        '<div class="d">Nilai keaktifan belum diunggah panitia</div></div></div>';
    }
    html += '</div>';

    content.innerHTML = html;
  }

  btnVer.addEventListener('click', function () {
    var kode = kodeInp.value.trim();
    if (kode.length !== 10) {
      setMsg('Kode desa harus 10 digit angka.', 'error');
      kodeInp.focus();
      return;
    }
    btnVer.disabled = true;
    btnVer.textContent = 'Memeriksa...';
    setMsg('');

    var fd = new FormData();
    fd.append('csrf', window.CSRF_TOKEN);
    fd.append('kode', kode);

    fetch('gradebook_status.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btnVer.disabled = false;
        btnVer.textContent = 'Lihat Nilai »';
        if (!j.ok) { setMsg(j.msg || 'Verifikasi gagal.', 'error'); return; }
        desaName.textContent = j.data.nama_desa + ' (' + j.data.kode + ')';
        render(j.data);
        showStep(2);
      })
      .catch(function () {
        btnVer.disabled = false;
        btnVer.textContent = 'Lihat Nilai »';
        setMsg('Koneksi gagal. Coba lagi.', 'error');
      });
  });

})();
