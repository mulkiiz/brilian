/* =====================================================
   KATALOG EDITOR - Quill + drag-drop upload + AJAX
   ===================================================== */
(function () {
  'use strict';
  if (!window.KATALOG) return;

  var K = window.KATALOG;
  var editors = {};

  // ---------- Init Quill HANYA untuk section narasi ----------
  var toolbarOptions = [
    ['bold', 'italic', 'underline'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ header: [2, 3, false] }],
    ['link'],
    ['clean']
  ];

  (K.narasiKeys || []).forEach(function (key) {
    var el = document.getElementById('q-' + key);
    if (!el) return;
    var quill = new Quill(el, {
      theme: 'snow',
      modules: { toolbar: toolbarOptions },
      placeholder: 'Tulis narasi di sini...'
    });
    editors[key] = quill;
  });

  function setStatus(key, text, type) {
    var el = document.getElementById('status-' + key);
    if (!el) return;
    el.textContent = text;
    el.className = 'save-status ' + (type || '');
    if (type === 'ok') {
      setTimeout(function () {
        if (el.textContent === text) { el.textContent = ''; el.className = 'save-status'; }
      }, 3500);
    }
  }

  function totalSections() {
    return (K.narasiKeys || []).length + (K.fotoKeys || []).length;
  }

  function updateProgress(filled) {
    var total = totalSections();
    var pct = Math.round((filled / total) * 100);
    var label = document.querySelector('.progress-label');
    var fill  = document.querySelector('.progress-fill');
    if (label) label.innerHTML = 'Progres pengisian: <b>' + filled + ' dari ' + total + ' bagian</b> (' + pct + '%)';
    if (fill)  fill.style.width = pct + '%';
  }

  function updateSectionFilledBadge(key, isFilled) {
    var card = document.querySelector('.section-card[data-section="' + key + '"]');
    if (!card) return;
    var badge = card.querySelector('.sec-status .badge');
    if (!badge) return;
    if (isFilled) { badge.className = 'badge ok'; badge.textContent = '✓ Terisi'; }
    else          { badge.className = 'badge warn'; badge.textContent = 'Belum diisi'; }
  }

  function updatePhotoCount(key) {
    var card = document.querySelector('.section-card[data-section="' + key + '"]');
    if (!card) return;
    var grid = document.getElementById('photos-' + key);
    var lbl  = card.querySelector('.sec-photo-count');
    if (grid && lbl) {
      var n = grid.querySelectorAll('.photo-item').length;
      lbl.textContent = n + '/' + K.maxPhotos + ' foto';
    }
  }

  function postForm(data, onResp, onErr) {
    data.append('csrf', K.csrf);
    fetch('api.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        if (resp.redirect) { window.location.href = resp.redirect; return; }
        onResp(resp);
      })
      .catch(onErr || function (e) { console.error(e); });
  }

  // ---------- Save narasi ----------
  document.querySelectorAll('.btn-save-narasi').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.dataset.key;
      var quill = editors[key];
      if (!quill) return;
      var html = quill.root.innerHTML;

      setStatus(key, 'Menyimpan...', '');
      btn.disabled = true;

      var fd = new FormData();
      fd.append('action', 'save_narasi');
      fd.append('section', key);
      fd.append('narasi', html);

      postForm(fd, function (resp) {
        btn.disabled = false;
        if (resp.ok) {
          setStatus(key, '✓ Tersimpan', 'ok');
          var text = quill.getText().trim();
          updateSectionFilledBadge(key, text.length > 0);
          if (typeof resp.filled === 'number') updateProgress(resp.filled);
        } else {
          setStatus(key, '✗ ' + (resp.msg || 'Gagal simpan'), 'err');
        }
      }, function () {
        btn.disabled = false;
        setStatus(key, '✗ Koneksi gagal', 'err');
      });
    });
  });

  // Helper: hitung urutan (1..maxPhotos) foto di section ini
  function getPhotoFigNo(key, photoId) {
    var grid = document.getElementById('photos-' + key);
    if (!grid) return 1;
    var items = grid.querySelectorAll('.photo-item');
    for (var i = 0; i < items.length; i++) {
      if (String(items[i].dataset.id) === String(photoId)) return i + 1;
    }
    return items.length;
  }

  // ---------- Render photo card ----------
  function renderPhotoCard(key, photo) {
    var grid = document.getElementById('photos-' + key);
    if (!grid) return null;
    var capRaw = photo.caption || '';
    var hasCaption = capRaw.trim() !== '';

    var div = document.createElement('div');
    div.className = 'photo-item' + (hasCaption ? '' : ' caption-missing');
    div.dataset.id = photo.id;

    var capHtml = hasCaption
      ? '<span class="cap-text">' + escapeHtml(capRaw) + '</span>' +
        '<button type="button" class="btn-edit-caption" data-id="' + photo.id + '" title="Edit caption">✏</button>'
      : '<span class="cap-warn">⚠ Caption belum diisi</span>' +
        '<button type="button" class="btn-edit-caption" data-id="' + photo.id + '">Isi caption</button>';

    div.innerHTML =
      '<div class="photo-thumb"><img src="' + photo.url + '" alt=""></div>' +
      '<button type="button" class="photo-del" data-id="' + photo.id + '" title="Hapus foto">×</button>' +
      '<div class="photo-caption-display" data-id="' + photo.id + '">' + capHtml + '</div>';
    grid.appendChild(div);
    bindPhotoEvents(div);
    updatePhotoCount(key);
    updateSectionFilledBadge(key, true);
    return div;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // ---------- Loading state pada dropzone ----------
  function setDzLoading(dz, isLoading, current, total, filename) {
    if (!dz) return;
    if (isLoading) {
      dz.classList.add('is-uploading');
      var pt = dz.querySelector('.dz-progress-text');
      var pd = dz.querySelector('.dz-progress-detail');
      if (pt) pt.textContent = 'Mengunggah foto ' + current + ' dari ' + total + '...';
      if (pd) pd.textContent = filename ? ('📄 ' + filename) : 'Mohon tunggu, jangan tutup halaman';
    } else {
      dz.classList.remove('is-uploading');
    }
  }

  // ---------- Upload satu file (return Promise<{photo, key}|null>) ----------
  function uploadFile(key, file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
        alert('Format harus JPG / PNG / WebP. File "' + file.name + '" dilewati.');
        return resolve(null);
      }
      if (file.size > K.maxBytes) {
        alert('Foto "' + file.name + '" terlalu besar (' + Math.round(file.size/1024) +
              ' KB). Maksimal ' + Math.round(K.maxBytes/1024) + ' KB. Tolong dikompres dulu.');
        return resolve(null);
      }

      var fd = new FormData();
      fd.append('action', 'upload_photo');
      fd.append('section', key);
      fd.append('photo', file);

      postForm(fd, function (resp) {
        if (resp.ok) {
          renderPhotoCard(key, resp.photo);
          if (typeof resp.filled === 'number') updateProgress(resp.filled);
          resolve({ key: key, photo: resp.photo });
        } else {
          alert(resp.msg || 'Upload gagal: ' + file.name);
          resolve(null);
        }
      }, function () {
        alert('Koneksi gagal saat upload: ' + file.name);
        resolve(null);
      });
    });
  }

  // ---------- Handle multiple files (sequential) + buka modal caption antrian ----------
  function handleFiles(key, fileList) {
    var grid = document.getElementById('photos-' + key);
    var existing = grid ? grid.querySelectorAll('.photo-item').length : 0;
    var slots = K.maxPhotos - existing;

    if (slots <= 0) {
      alert('Sudah mencapai batas ' + K.maxPhotos + ' foto. Hapus salah satu dulu.');
      return;
    }
    var files = Array.from(fileList).slice(0, slots);
    if (fileList.length > slots) alert('Hanya ' + slots + ' foto pertama yang akan diunggah (sisa slot).');
    if (files.length === 0) return;

    var dz = document.querySelector('.dropzone[data-key="' + key + '"]');
    var total = files.length;
    var uploaded = []; // {key, photo}

    files.reduce(function (chain, file, i) {
      return chain.then(function () {
        setDzLoading(dz, true, i + 1, total, file.name);
        return uploadFile(key, file).then(function (res) {
          if (res) uploaded.push(res);
        });
      });
    }, Promise.resolve()).then(function () {
      setDzLoading(dz, false);
      // Buka modal caption secara antrian untuk semua foto yang baru diupload
      if (uploaded.length > 0) openCaptionQueue(uploaded);
    }).catch(function () {
      setDzLoading(dz, false);
    });
  }

  // ---------- Bind dropzone ----------
  document.querySelectorAll('.dropzone').forEach(function (dz) {
    var key = dz.dataset.key;
    var input = dz.querySelector('.dz-input');

    input.addEventListener('change', function (e) {
      if (e.target.files && e.target.files.length) {
        handleFiles(key, e.target.files);
        e.target.value = '';
      }
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        dz.classList.add('is-drag');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        dz.classList.remove('is-drag');
      });
    });
    dz.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) handleFiles(key, files);
    });
  });

  // ---------- Bind photo card events ----------
  function bindPhotoEvents(item) {
    var delBtn  = item.querySelector('.photo-del');
    var editBtn = item.querySelector('.btn-edit-caption');

    if (delBtn) {
      delBtn.addEventListener('click', function () {
        if (!confirm('Hapus foto ini?')) return;
        var fd = new FormData();
        fd.append('action', 'delete_photo');
        fd.append('photo_id', delBtn.dataset.id);
        postForm(fd, function (resp) {
          if (resp.ok) {
            var card = item.closest('.section-card');
            var key = card ? card.dataset.section : null;
            var type = card ? card.dataset.type : null;
            item.remove();
            if (key) {
              updatePhotoCount(key);
              if (type === 'foto') {
                var grid = document.getElementById('photos-' + key);
                var nLeft = grid ? grid.querySelectorAll('.photo-item').length : 0;
                if (nLeft === 0) updateSectionFilledBadge(key, false);
                // Renumber visual fig no untuk foto sisanya
                renumberFigNos(key);
              }
              if (typeof resp.filled === 'number') updateProgress(resp.filled);
            }
          } else {
            alert(resp.msg || 'Gagal hapus.');
          }
        });
      });
    }

    if (editBtn) {
      editBtn.addEventListener('click', function () {
        var card = item.closest('.section-card');
        var key = card ? card.dataset.section : null;
        var photoId = editBtn.dataset.id;
        var img = item.querySelector('.photo-thumb img');
        var capText = item.querySelector('.cap-text');
        openCaptionModal({
          key: key,
          photoId: photoId,
          photoUrl: img ? img.src : '',
          currentCaption: capText ? capText.textContent : ''
        });
      });
    }
  }

  // Renumber "Gambar N" labels (cosmetic only — actual numbering happens at preview/PDF)
  function renumberFigNos(key) {
    // Not needed in DOM since label is "Edit caption", but kept as placeholder
    // if later we want to show "Gambar N. ..." inline in editor.
  }
  document.querySelectorAll('.photo-item').forEach(bindPhotoEvents);

  // ============================================================
  // MODAL CAPTION
  // ============================================================
  var modal       = document.getElementById('modal-caption');
  var modalImg    = document.getElementById('caption-photo-preview');
  var modalFigNo  = document.getElementById('caption-figno');
  var modalInput  = document.getElementById('caption-input');
  var modalMsg    = document.getElementById('caption-msg');
  var modalSave   = document.getElementById('btn-save-caption');
  var captionQueue = []; // antrian {key, photo} untuk dimintakan caption setelah upload

  function setModalMsg(text, type) {
    if (!modalMsg) return;
    modalMsg.textContent = text || '';
    modalMsg.className = 'msg ' + (type || '');
  }

  function openCaptionModal(opts) {
    // opts: {key, photoId, photoUrl, currentCaption}
    if (!modal) return;
    modal.dataset.key = opts.key || '';
    modal.dataset.photoId = opts.photoId || '';
    modalImg.src = opts.photoUrl || '';
    modalInput.value = opts.currentCaption || '';
    var figNo = getPhotoFigNo(opts.key, opts.photoId);
    modalFigNo.textContent = 'Gambar ' + figNo;
    setModalMsg('');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(function () { modalInput.focus(); }, 80);
  }

  function closeCaptionModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    // Lanjut antrian (jika ada foto lain yang baru diupload)
    if (captionQueue.length > 0) {
      var next = captionQueue.shift();
      setTimeout(function () {
        openCaptionModal({
          key: next.key,
          photoId: next.photo.id,
          photoUrl: next.photo.url,
          currentCaption: next.photo.caption || ''
        });
      }, 200);
    }
  }

  function openCaptionQueue(uploaded) {
    // uploaded: array of {key, photo}
    if (!uploaded.length) return;
    var first = uploaded.shift();
    captionQueue = uploaded;  // sisanya
    openCaptionModal({
      key: first.key,
      photoId: first.photo.id,
      photoUrl: first.photo.url,
      currentCaption: first.photo.caption || ''
    });
  }

  function saveCaption() {
    var text = (modalInput.value || '').trim();
    if (text.length < 3) {
      setModalMsg('Caption wajib diisi minimal 3 karakter.', 'error');
      modalInput.focus();
      return;
    }
    var photoId = modal.dataset.photoId;
    if (!photoId) { closeCaptionModal(); return; }

    modalSave.disabled = true;
    modalSave.textContent = 'Menyimpan...';

    var fd = new FormData();
    fd.append('action', 'update_caption');
    fd.append('photo_id', photoId);
    fd.append('caption', text);

    postForm(fd, function (resp) {
      modalSave.disabled = false;
      modalSave.textContent = '💾 Simpan Caption';
      if (resp.ok) {
        // Update tampilan kartu foto
        updateCardCaptionDisplay(photoId, text);
        closeCaptionModal();
      } else {
        setModalMsg(resp.msg || 'Gagal menyimpan caption.', 'error');
      }
    }, function () {
      modalSave.disabled = false;
      modalSave.textContent = '💾 Simpan Caption';
      setModalMsg('Koneksi gagal. Coba lagi.', 'error');
    });
  }

  function updateCardCaptionDisplay(photoId, captionText) {
    var item = document.querySelector('.photo-item[data-id="' + photoId + '"]');
    if (!item) return;
    item.classList.remove('caption-missing');
    var disp = item.querySelector('.photo-caption-display');
    if (!disp) return;
    disp.innerHTML =
      '<span class="cap-text">' + escapeHtml(captionText) + '</span>' +
      '<button type="button" class="btn-edit-caption" data-id="' + photoId + '" title="Edit caption">✏</button>';
    // Re-bind tombol edit baru
    var newBtn = disp.querySelector('.btn-edit-caption');
    if (newBtn) {
      newBtn.addEventListener('click', function () {
        var card = item.closest('.section-card');
        var key = card ? card.dataset.section : null;
        var img = item.querySelector('.photo-thumb img');
        openCaptionModal({
          key: key,
          photoId: photoId,
          photoUrl: img ? img.src : '',
          currentCaption: captionText
        });
      });
    }
  }

  if (modal) {
    modalSave.addEventListener('click', saveCaption);
    modalInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); saveCaption(); }
    });
    // Tombol "Nanti saja" / overlay click → close + lanjut antrian
    modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
      el.addEventListener('click', closeCaptionModal);
    });
    // ESC
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) closeCaptionModal();
    });
  }

  // ---------- Cek caption belum diisi saat klik tombol Preview ----------
  document.querySelectorAll('a[href="preview.php"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var missing = document.querySelectorAll('.photo-item.caption-missing');
      if (missing.length > 0) {
        e.preventDefault();
        if (confirm('Masih ada ' + missing.length + ' foto yang belum diberi caption.\n\nLanjut isi caption sekarang?')) {
          // Buka modal untuk yang pertama
          var item = missing[0];
          var card = item.closest('.section-card');
          var img = item.querySelector('.photo-thumb img');
          if (card) {
            // Buka dulu section-nya
            card.setAttribute('open', '');
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
          openCaptionModal({
            key: card ? card.dataset.section : null,
            photoId: item.dataset.id,
            photoUrl: img ? img.src : '',
            currentCaption: ''
          });
        }
      }
    });
  });

  // ---------- Logout ----------
  var btnLogout = document.getElementById('btn-logout');
  if (btnLogout) {
    btnLogout.addEventListener('click', function () {
      if (!confirm('Keluar dari editor katalog?')) return;
      var fd = new FormData();
      fd.append('action', 'logout');
      postForm(fd, function (resp) {
        window.location.href = resp.redirect || 'index.php';
      });
    });
  }

})();
