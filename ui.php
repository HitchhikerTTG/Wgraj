
<!doctype html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>File Uploader</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .drop { border:2px dashed var(--bs-border-color); border-radius:.75rem; padding:2rem; text-align:center; }
  .drop.dragover { background: var(--bs-tertiary-bg); }
  .file-rel { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .debug-panel { max-height: 400px; overflow-y: auto; }
</style>
</head>
<body class="py-4">
<div class="container" style="max-width: 900px;">
<?php
$meta = null;
if ($token) {
  $p = tok_path($token);
  if (is_file($p)) $meta = json_decode(file_get_contents($p), true);
}

if ($token):
  if (!$meta): ?>
    <div class="alert alert-danger">
      <h5>Nieprawidłowy link</h5>
      <p class="mb-0">Link nie istnieje lub został usunięty.</p>
    </div>
  <?php elseif (!empty($meta['used'])): ?>
    <div class="alert alert-warning">
      <h5>Link został już użyty</h5>
      <p class="mb-0">Ten link do uploadu był już wykorzystany w dniu <?= date('Y-m-d H:i', $meta['used_at'] ?? time()) ?>.</p>
    </div>
  <?php elseif (now() > ($meta['expires']??0)): ?>
    <div class="alert alert-warning">
      <h5>Link wygasł</h5>
      <p class="mb-0">Link wygasł <?= date('Y-m-d H:i', $meta['expires']) ?>.</p>
    </div>
  <?php else: ?>
    <h1 class="h4 mb-3">Prześlij pliki <?= $meta['label'] ? '— '.h($meta['label']) : '' ?></h1>
    
    <?php if (DEBUG_UPLOAD): ?>
    <div class="alert alert-info">
      <h6>Tryb debugowania włączony</h6>
      <p class="mb-0">Dodatkowe informacje o błędach będą dostępne w przypadku problemów z uploadem.</p>
    </div>
    <?php endif; ?>
    
    <div class="card">
      <div class="card-body">
        <p class="text-body-secondary mb-3">
          <strong>Katalog docelowy:</strong> <code><?=h($meta['remote_dir'])?></code><br>
          <strong>Wygasa:</strong> <?= date('Y-m-d H:i', $meta['expires']) ?>
        </p>

        <div id="drop" class="drop mb-3">
          <i class="bi bi-cloud-upload"></i>
          <h6>Upuść pliki lub folder tutaj</h6>
          <p class="text-muted mb-3">lub wybierz poniżej</p>
          <input type="file" id="pickFiles" multiple class="form-control mb-2" />
          <input type="file" id="pickDir" webkitdirectory directory mozdirectory class="form-control" />
          <small class="text-muted">Pierwszy input: pojedyncze pliki, drugi: cały folder</small>
        </div>

        <div class="list-group mb-3" id="list"></div>

        <div class="d-flex align-items-center gap-3">
          <div class="progress flex-grow-1" role="progressbar">
            <div id="totalbar" class="progress-bar" style="width:0%"></div>
          </div>
          <div class="text-body-secondary" id="percent" style="min-width:3rem; text-align:right;">0%</div>
          <button id="send" class="btn btn-primary">Wyślij pliki</button>
        </div>

        <div id="debugOutput" class="mt-3" style="display: none;">
          <h6>Informacje diagnostyczne</h6>
          <div class="debug-panel">
            <pre id="debugContent" class="small bg-light p-2 rounded"></pre>
          </div>
        </div>

        <p class="text-body-secondary mt-3 mb-0">
          <strong>Dozwolone rozszerzenia:</strong> <?=implode(', ', ALLOW_EXT)?><br>
          <strong>Maksymalny rozmiar pliku:</strong> <?=number_format(MAX_BYTES/1024/1024)?> MB<br>
          <strong>Ważność linku:</strong> <?=TOKEN_TTL_H?> godzin
        </p>
      </div>
    </div>

    <script>
      const drop     = document.getElementById('drop');
      const pickFiles= document.getElementById('pickFiles');
      const pickDir  = document.getElementById('pickDir');
      const list     = document.getElementById('list');
      const sendBtn  = document.getElementById('send');
      const totalbar = document.getElementById('totalbar');
      const percent  = document.getElementById('percent');
      const debugOutput = document.getElementById('debugOutput');
      const debugContent = document.getElementById('debugContent');

      let queue = [];
      let debugInfo = [];

      function fmtBytes(b){
        const u=['B','KB','MB','GB'];
        let i=0;
        while(b>1024&&i<u.length-1){ b/=1024;i++; }
        return b.toFixed(i?1:0)+' '+u[i];
      }

      function addRow(f, rel){
        const row = document.createElement('div');
        row.className = 'list-group-item';
        row.innerHTML = `
          <div class="d-flex align-items-center gap-3">
            <div class="file-rel flex-grow-1" title="${rel}">${rel}</div>
            <div class="text-body-secondary" style="min-width:7rem">${fmtBytes(f.size)}</div>
            <button class="btn btn-sm btn-outline-danger remove-btn" type="button">×</button>
          </div>
          <div class="progress mt-2" role="progressbar">
            <div class="progress-bar" style="width:0%"></div>
          </div>
        `;
        list.appendChild(row);
        
        const pbar = row.querySelector('.progress-bar');
        const removeBtn = row.querySelector('.remove-btn');
        const item = {file:f, rel:rel, row:row, pbar:pbar};
        
        removeBtn.addEventListener('click', () => {
          const index = queue.indexOf(item);
          if (index > -1) {
            queue.splice(index, 1);
            row.remove();
          }
        });
        
        queue.push(item);
      }

      pickFiles.addEventListener('change', e=>{
        for (const f of e.target.files) addRow(f, f.name);
      });
      
      pickDir.addEventListener('change', e=>{
        for (const f of e.target.files){
          const rel = f.webkitRelativePath || f.name;
          addRow(f, rel);
        }
      });

      ['dragenter','dragover'].forEach(ev=>{
        drop.addEventListener(ev, e=>{
          e.preventDefault();
          e.stopPropagation();
          drop.classList.add('dragover');
        });
      });
      
      ['dragleave','drop'].forEach(ev=>{
        drop.addEventListener(ev, e=>{
          e.preventDefault();
          e.stopPropagation();
          drop.classList.remove('dragover');
        });
      });
      
      drop.addEventListener('drop', e=>{
        const items = e.dataTransfer.items;
        if (items && items.length && 'webkitGetAsEntry' in items[0]) {
          for (const it of items) {
            const entry = it.webkitGetAsEntry ? it.webkitGetAsEntry() : null;
            if (entry) traverseEntry(entry, '');
          }
        } else {
          const files = e.dataTransfer.files;
          for (const f of files) addRow(f, f.name);
        }
      });
      
      function traverseEntry(entry, path){
        if (entry.isFile) {
          entry.file(f=> addRow(f, path + entry.name));
        } else if (entry.isDirectory) {
          const dr = entry.createReader();
          dr.readEntries(ents=>{
            for (const en of ents) traverseEntry(en, path + entry.name + '/');
          });
        }
      }

      async function retryUpload(item) {
        // Reset progress bar
        item.pbar.classList.remove('bg-danger', 'bg-warning');
        item.pbar.classList.add('bg-info');
        item.pbar.style.width = '0%';
        item.pbar.textContent = 'Ponawiam...';
        
        // Remove retry button if exists
        const retryBtn = item.row.querySelector('.btn-outline-warning');
        if (retryBtn) retryBtn.remove();
        
        return new Promise((resolve) => {
          const fd = new FormData();
          fd.append('file', item.file, item.file.name);
          fd.append('relpath', item.rel);
          
          const xhr = new XMLHttpRequest();
          xhr.open('POST', '?action=retry<?php echo $token ? '&t='.rawurlencode($token).'&debug=1' : '';?>');
          
          xhr.upload.onprogress = (e) => {
            if(e.lengthComputable){
              item.pbar.style.width = Math.round(e.loaded/e.total*100)+'%';
            }
          };
          
          xhr.onreadystatechange = () => {
            if (xhr.readyState === 4) {
              try {
                const res = JSON.parse(xhr.responseText);
                
                if (res.debug) {
                  debugInfo.push({
                    file: item.rel,
                    success: res.ok,
                    debug: res.debug,
                    response: res,
                    retry: true
                  });
                }
                
                if (!res.ok) {
                  item.pbar.classList.remove('bg-info');
                  item.pbar.classList.add('bg-danger');
                  item.pbar.style.width = '100%';
                  item.pbar.textContent = res.msg || 'Błąd ponownego przesłania';
                } else {
                  item.pbar.classList.remove('bg-info');
                  item.pbar.classList.add('bg-success');
                  item.pbar.style.width = '100%';
                  
                  if (res.debug && res.debug.integrity_check) {
                    const integrityOk = res.debug.integrity_check.verified;
                    if (integrityOk) {
                      item.pbar.textContent = 'OK ✓ (ponowione)';
                    } else {
                      item.pbar.classList.remove('bg-success');
                      item.pbar.classList.add('bg-warning');
                      item.pbar.textContent = 'OK (błąd integralności)';
                    }
                  } else {
                    item.pbar.textContent = 'OK (ponowione)';
                  }
                }
              } catch(e) {
                item.pbar.classList.remove('bg-info');
                item.pbar.classList.add('bg-danger');
                item.pbar.style.width = '100%';
                item.pbar.textContent = 'Błąd parsowania odpowiedzi';
              }
              resolve();
            }
          };
          
          xhr.send(fd);
        });
      }

      async function uploadOne(item){
        return new Promise((resolve)=>{
          const fd = new FormData();
          fd.append('file', item.file, item.file.name);
          fd.append('relpath', item.rel);
          
          const xhr = new XMLHttpRequest();
          xhr.open('POST', '?action=upload<?php echo $token ? '&t='.rawurlencode($token).'&debug=1' : '';?>');
          
          xhr.upload.onprogress = (e)=>{
            if(e.lengthComputable){
              item.pbar.style.width = Math.round(e.loaded/e.total*100)+'%';
            }
          };
          
          xhr.onreadystatechange = ()=>{
            if (xhr.readyState===4){
              try{
                const res = JSON.parse(xhr.responseText);
                
                if (res.debug) {
                  debugInfo.push({
                    file: item.rel,
                    success: res.ok,
                    debug: res.debug,
                    response: res
                  });
                }
                
                if (!res.ok) {
                  item.pbar.classList.add('bg-danger');
                  item.pbar.style.width = '100%';
                  item.pbar.textContent = res.msg || 'Błąd';
                  
                  // Add retry button
                  const retryBtn = document.createElement('button');
                  retryBtn.className = 'btn btn-sm btn-outline-warning mt-2';
                  retryBtn.textContent = 'Ponów przesłanie';
                  retryBtn.onclick = () => retryUpload(item);
                  item.row.appendChild(retryBtn);
                  
                } else {
                  item.pbar.classList.add('bg-success');
                  item.pbar.style.width = '100%';
                  
                  // Check integrity if debug info is available
                  if (res.debug && res.debug.integrity_check) {
                    const integrityOk = res.debug.integrity_check.verified;
                    if (integrityOk) {
                      item.pbar.textContent = 'OK ✓';
                    } else {
                      item.pbar.classList.remove('bg-success');
                      item.pbar.classList.add('bg-warning');
                      item.pbar.textContent = 'OK (błąd integralności)';
                    }
                  } else {
                    item.pbar.textContent = 'OK';
                  }
                }
              } catch(e){
                item.pbar.classList.add('bg-danger');
                item.pbar.style.width = '100%';
                item.pbar.textContent = 'Błąd parsowania';
              }
              resolve();
            }
          };
          
          xhr.send(fd);
        });
      }

      sendBtn.addEventListener('click', async ()=>{
        if (!queue.length){
          alert('Dodaj pliki lub folder.');
          return;
        }
        
        sendBtn.disabled = true;
        sendBtn.textContent = 'Wysyłanie...';
        debugInfo = [];

        let done = 0;
        const total = queue.length;

        for (const it of queue){
          await uploadOne(it);
          done++;
          const p = Math.round(done/total*100);
          totalbar.style.width = p+'%';
          percent.textContent = p+'%';
        }

        // Show debug info if any
        if (debugInfo.length > 0) {
          debugContent.textContent = JSON.stringify(debugInfo, null, 2);
          debugOutput.style.display = 'block';
        }

        // Finalize
        try {
          const finalizeRes = await fetch('?action=finalize<?php echo $token ? '&t='.rawurlencode($token) : '';?>',{method:'POST'});
          const finalizeData = await finalizeRes.json();
          
          if (finalizeData.ok) {
            sendBtn.textContent = `Zakończono (${finalizeData.count} plików)`;
            sendBtn.classList.remove('btn-primary');
            sendBtn.classList.add('btn-success');
          } else {
            sendBtn.textContent = 'Błąd finalizacji';
            sendBtn.classList.add('btn-warning');
          }
        } catch(e) {
          sendBtn.textContent = 'Zakończono (błąd powiadomienia)';
          sendBtn.classList.add('btn-warning');
        }
      });
    </script>
  <?php endif; ?>
<?php else: ?>
  <!-- ADMIN PANEL -->
  <h1 class="h4 mb-3">Generator jednorazowych linków do uploadu</h1>
  
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0">Utwórz nowy link</h5>
    </div>
    <div class="card-body">
      <form id="gen" class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label">Klucz admina</label>
          <input type="password" id="key" class="form-control" required>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Etykieta (będzie częścią URL)</label>
          <input type="text" id="label" class="form-control" placeholder="np. raport-asia-2024" required>
          <div class="form-text">Litery, cyfry, myślniki. Maksymalnie 64 znaki.</div>
        </div>
        <div class="col-12 col-md-2 d-grid align-self-end">
          <button type="submit" class="btn btn-primary">Utwórz link</button>
        </div>
      </form>
      <div id="out" class="mt-3"></div>
      
      <hr class="my-4">
      
      <div class="row text-muted small">
        <div class="col-md-6">
          <strong>Konfiguracja:</strong><br>
          Limit pliku: <?= number_format(MAX_BYTES/1024/1024) ?> MB<br>
          Ważność: <?= TOKEN_TTL_H ?> godzin<br>
          FTP: <?= FTP_MODE ?> (<?= FTP_HOST ?>:<?= FTP_PORT ?>)
        </div>
        <div class="col-md-6">
          <strong>Dozwolone rozszerzenia:</strong><br>
          <?= implode(', ', ALLOW_EXT) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h6 class="mb-0">Test połączenia FTP</h6>
    </div>
    <div class="card-body">
      <form id="autotest" class="row g-2">
        <div class="col-12 col-md-4">
          <label class="form-label">Klucz admina</label>
          <input type="password" id="akey" class="form-control" required>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Etykieta do testowania</label>
          <input type="text" id="alabel" class="form-control" placeholder="np. test-connection" required>
          <div class="form-text">Musisz najpierw utworzyć link z tą etykietą.</div>
        </div>
        <div class="col-12 col-md-2 d-grid align-self-end">
          <button class="btn btn-outline-secondary" type="submit">Testuj</button>
        </div>
      </form>
      <pre id="atestout" class="mt-3 small text-body-secondary" style="white-space:pre-wrap; max-height: 300px; overflow-y: auto;"></pre>
    </div>
  </div>

  <script>
    // Link generator
    const form = document.getElementById('gen');
    const out  = document.getElementById('out');
    
    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const key   = document.getElementById('key').value.trim();
      const label = document.getElementById('label').value.trim();
      
      const fd = new FormData();
      fd.append('key', key);
      fd.append('label', label);
      
      try {
        const res = await fetch('?action=new', {method:'POST', body:fd});
        const json = await res.json();
        
        if (json.ok){
          out.innerHTML = `
            <div class="alert alert-success">
              <h6>Link utworzony pomyślnie!</h6>
              <p><strong>URL:</strong> <a href="${json.url}" target="_blank">${json.url}</a></p>
              <p><strong>Katalog FTP:</strong> <code>${json.remote_dir}</code></p>
              <button class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('${json.url}')">Kopiuj URL</button>
            </div>
          `;
        } else {
          out.innerHTML = `<div class="alert alert-danger">Błąd: ${json.error || 'nieznany'}</div>`;
        }
      } catch(e) {
        out.innerHTML = `<div class="alert alert-danger">Błąd połączenia: ${e.message}</div>`;
      }
    });

    // Connection test
    const atform = document.getElementById('autotest');
    const atout  = document.getElementById('atestout');
    
    atform.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd = new FormData();
      fd.append('key', document.getElementById('akey').value.trim());
      fd.append('label', document.getElementById('alabel').value.trim());
      
      atout.textContent = 'Testowanie...';
      
      try {
        const res = await fetch('?action=autotest', {method:'POST', body:fd});
        const js = await res.json();
        atout.textContent = JSON.stringify(js, null, 2);
      } catch(e) {
        atout.textContent = `Błąd: ${e.message}`;
      }
    });
  </script>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
