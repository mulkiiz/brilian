/* =====================================================
   KATALOG DESA - frontend logic
   ===================================================== */
(function () {
  'use strict';
  if (!window.KATALOG) return;

  var K = window.KATALOG;
  var editors = {};

  // ---------- Init Quill per section ----------
  var toolbarOptions = [
    ['bold', 'italic', 'underline'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ header: [2, 3, false] }],
    ['link'],
    ['clean']
  ];

  K.sectionKeys.forEach(function (key) {
    var el = document.getElementById('q-' + key);
    if (!el) return;
    var quill = new Quill(el, {
      theme: 'snow',
      modules: { toolbar: toolbarOptions },
      placeholder: 'Tulis narasi di sini...'
    });
    editors[key] = quill;
  });

  // ---------- Helper: status text ----------
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

  // ---------- Update progress bar ----------
  function updateProgress(filled) {
    var total = K.sectionKeys.length;
    var pct = Math.round((filled / total) * 100);
    var label = document.querySelector('.progress-label');
    var fill  = document.querySelector('.progress-fill');
    if (label) label.innerHTML = 'Progres pengisian: <b>' + filled + ' dari ' + total + ' bagian</b> (' + pct + '%)';
    if (fill)  fill.style.width = pct + '%';
  }

  // ---------- Update section status badge ----------
  function updateSectionFilledBadge(key, isFilled) {
    var card = document.querySelector('.section-card[data-section="' + key + '"]');
    if (!card) return;
    var statusWrap = card.querySelector('.sec-status');
    if (!statusWrap) return;
    var badge = statusWrap.querySelector('.badge');
    if (!badge) return;
    if (isFilled) {
      badge.className = 'badge ok';
      badge.textContent = '✓ Terisi';
    } else {
      badge.className = 'badge warn';
      badge.textContent = 'Belum diisi';
    }
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

  // ---------- AJAX helper ----------
  function postForm(data, onResp, onErr) {
    data.append('csrf', K.csrf);
    fetch('katalog_api.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(onResp)
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

  // ---------- Render satu kartu foto baru ----------
  function renderPhotoCard(key, photo) {
    var grid = document.getElementById('photos-' + key);
    if (!grid) return;
    var div = document.createElement('div');
    div.className = 'photo-item';
    div.dataset.id = photo.id;
    div.innerHTML =
      '<img src="' + photo.url + '" alt="">' +
      '<button type="button" class="photo-del" data-id="' + photo.id + '" title="Hapus foto">×</button>' +
      '<input type="text" class="photo-caption" data-id="' + photo.id + '" value="' +
        (photo.caption || '').replace(/"/g, '&quot;') + '" placeholder="Caption (opsional)" maxlength="500">';
    grid.appendChild(div);
    bindPhotoEvents(div);
    updatePhotoCount(key);
  }

  // ---------- Upload satu file ----------
  function uploadFile(key, file) {
    if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
      alert('Format harus JPG / PNG / WebP. File "' + file.name + '" dilewati.');
      return;
    }
    if (file.size > K.maxBytes) {
      alert('Foto "' + file.name + '" terlalu besar (' + Math.round(file.size/1024) +
            ' KB). Maksimal ' + Math.round(K.maxBytes/1024) + ' KB. Tolong dikompres dulu.');
      return;
    }

    var dz = document.querySelector('.dropzone[data-key="' + key + '"]');
    var msg = dz ? dz.querySelector('.dz-msg') : null;
    var oldMsg = msg ? msg.innerHTML : '';
    if (msg) msg.innerHTML = '⏳ Mengunggah ' + file.name + '...';

    var fd = new FormData();
    fd.append('action', 'upload_photo');
    fd.append('section', key);
    fd.append('photo', file);

    postForm(fd, function (resp) {
      if (msg) msg.innerHTML = oldMsg;
      if (resp.ok) {
        renderPhotoCard(key, resp.photo);
      } else {
        alert(resp.msg || 'Upload gagal.');
      }
    }, function () {
      if (msg) msg.innerHTML = oldMsg;
      alert('Koneksi gagal saat upload.');
    });
  }

  function handleFiles(key, fileList) {
    var dz = document.querySelector('.dropzone[data-key="' + key + '"]');
    var grid = document.getElementById('photos-' + key);
    var existing = grid ? grid.querySelectorAll('.photo-item').length : 0;
    var slots = K.maxPhotos - existing;

    if (slots <= 0) {
      alert('Sudah mencapai batas ' + K.maxPhotos + ' foto. Hapus salah satu dulu.');
      return;
    }
    var files = Array.from(fileList).slice(0, slots);
    if (fileList.length > slots) {
      alert('Hanya ' + slots + ' foto pertama yang akan diunggah (sisa slot).');
    }
    files.forEach(function (f) { uploadFile(key, f); });
  }

  // ---------- Bind dropzone untuk semua section ----------
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

  // ---------- Bind events untuk satu kartu foto ----------
  function bindPhotoEvents(item) {
    var delBtn  = item.querySelector('.photo-del');
    var caption = item.querySelector('.photo-caption');

    if (delBtn) {
      delBtn.addEventListener('click', function () {
        if (!confirm('Hapus foto ini?')) return;
        var id = delBtn.dataset.id;
        var fd = new FormData();
        fd.append('action', 'delete_photo');
        fd.append('photo_id', id);
        postForm(fd, function (resp) {
          if (resp.ok) {
            var card = item.closest('.section-card');
            var key = card ? card.dataset.section : null;
            item.remove();
            if (key) updatePhotoCount(key);
          } else {
            alert(resp.msg || 'Gagal hapus.');
          }
        });
      });
    }

    if (caption) {
      var t;
      caption.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
          var fd = new FormData();
          fd.append('action', 'update_caption');
          fd.append('photo_id', caption.dataset.id);
          fd.append('caption', caption.value);
          postForm(fd, function () { /* silent */ });
        }, 600);
      });
    }
  }

  // Bind untuk kartu foto yang sudah ada saat load
  document.querySelectorAll('.photo-item').forEach(bindPhotoEvents);

})();
