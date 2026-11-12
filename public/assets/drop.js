'use strict';

const sizeLabel = (bytes) => {
  if (bytes < 1000) return `${bytes} B`;
  const exponent = Math.min(3, Math.floor(Math.log(bytes) / Math.log(1000)));
  return `${Number((bytes / 1000 ** exponent).toFixed(1))} ${['B', 'KB', 'MB', 'GB'][exponent]}`;
};

const element = (tag, text, className) => {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
};

async function api(path, data) {
  const response = await fetch(path, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(data), signal: AbortSignal.timeout(30000),
  });
  const result = await response.json();
  if (!response.ok) {
    const error = new Error(result.error?.message || `Request failed (${response.status}). Please try again.`);
    error.status = response.status;
    throw error;
  }
  return result;
}

function putFile(upload, file, progress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', upload.url);
    // Leave five minutes of the one-hour upload window for verification and retries.
    xhr.timeout = 55 * 60 * 1000;
    for (const [key, value] of Object.entries(upload.headers)) xhr.setRequestHeader(key, value);
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) progress(Math.round(event.loaded / event.total * 100));
    });
    xhr.addEventListener('load', () => {
      // A retry can find an object written by the previous attempt: completion checks its size.
      if ((xhr.status >= 200 && xhr.status < 300) || xhr.status === 412) resolve();
      else reject(new Error('Storage refused the upload. Please try again.'));
    });
    xhr.addEventListener('error', () => reject(new Error('Could not reach storage. Check your connection and try again.')));
    xhr.addEventListener('timeout', () => reject(new Error('The upload took too long. Please try again.')));
    xhr.addEventListener('abort', () => reject(new Error('The upload was interrupted. Please try again.')));
    xhr.send(file);
  });
}

async function copyLink(button, link, input, status) {
  try {
    await navigator.clipboard.writeText(link);
    button.textContent = 'Copied';
    if (status) status.textContent = 'Link copied.';
    setTimeout(() => { button.textContent = 'Copy link'; }, 2000);
  } catch {
    if (!input) {
      input = element('input');
      input.readOnly = true;
      input.value = link;
      input.setAttribute('aria-label', 'Sharing link — select and copy');
      button.after(input);
    }
    input.focus();
    input.select();
    if (status) status.textContent = 'Select and copy the link above.';
  }
}

const uploader = document.querySelector('[data-uploader]');
if (uploader) {
  const zone = uploader;
  const picker = uploader.querySelector('input[type="file"]');
  const list = uploader.querySelector('[data-upload-list]');
  const error = uploader.querySelector('[data-upload-error]');
  const maxBytes = Number(uploader.dataset.maxBytes);
  let queue = Promise.resolve();
  let dragDepth = 0;

  function addFiles(files) {
    error.hidden = true;
    if (files.length > 20) {
      error.textContent = 'Choose up to 20 files at a time.';
      error.hidden = false;
      return;
    }
    for (const file of files) {
      uploader.querySelector('[data-upload-results]').hidden = false;
      const row = element('div', undefined, 'upload-item');
      const name = element('p', file.name, 'filename strong');
      const status = element('p', `Queued · ${sizeLabel(file.size)}`, 'muted');
      status.setAttribute('role', 'status');
      const progress = element('progress');
      progress.max = 100;
      progress.value = 0;
      progress.setAttribute('aria-label', `Upload progress for ${file.name}`);
      row.append(name, status, progress);
      list.append(row);
      if (file.size > maxBytes) {
        status.textContent = `Too large. The limit is ${sizeLabel(maxBytes)} per file.`;
        status.className = 'error';
        progress.remove();
        continue;
      }
      let reservation;
      let uploaded = false;
      const retry = element('button', 'Try again');
      retry.type = 'button';
      retry.hidden = true;
      row.append(retry);

      async function run() {
        retry.hidden = true;
        progress.hidden = false;
        status.className = 'muted';
        row.setAttribute('aria-busy', 'true');
        try {
          if (reservation && Date.parse(reservation.upload_until) <= Date.now()) {
            reservation = undefined;
            uploaded = false;
          }
          status.textContent = `Preparing · ${sizeLabel(file.size)}`;
          reservation ??= await api('/api/uploads', { name: file.name, size: file.size });
          if (!uploaded) {
            status.textContent = `Uploading · ${sizeLabel(file.size)}`;
            await putFile(reservation.upload, file, (value) => { progress.value = value; });
            uploaded = true;
          }
          progress.value = 100;
          status.textContent = 'Finishing upload…';
          const shared = await api(`/api/uploads/${reservation.id}/complete`, { token: reservation.token });
          status.textContent = `${sizeLabel(file.size)} · available for `;
          const expires = element('time', '24h');
          expires.dateTime = shared.expires_at;
          expires.dataset.expires = shared.expires_at;
          status.append(expires);
          progress.hidden = true;
          const group = element('div', undefined, 'input-group');
          const input = element('input');
          input.type = 'text';
          input.readOnly = true;
          input.value = shared.url;
          input.setAttribute('aria-label', `Sharing link for ${file.name}`);
          input.addEventListener('focus', () => input.select());
          const copy = element('button', 'Copy link');
          copy.type = 'button';
          copy.addEventListener('click', () => copyLink(copy, shared.url, input));
          group.append(input, copy);
          row.append(group);
          reservation = undefined;
          updateCountdowns();
        } catch (failure) {
          status.textContent = failure.message || 'Upload failed. Please try again.';
          status.className = 'error';
          progress.hidden = true;
          retry.hidden = false;
        } finally {
          row.removeAttribute('aria-busy');
        }
      }
      retry.addEventListener('click', () => {
        retry.hidden = true;
        status.textContent = 'Queued';
        queue = queue.then(run);
      });
      queue = queue.then(run);
    }
  }

  uploader.querySelector('[data-choose]').addEventListener('click', () => picker.click());
  picker.addEventListener('change', () => { addFiles([...picker.files]); picker.value = ''; });
  zone.addEventListener('dragenter', (event) => { event.preventDefault(); dragDepth++; zone.dataset.dragging = ''; });
  zone.addEventListener('dragover', (event) => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; });
  zone.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; delete zone.dataset.dragging; } });
  zone.addEventListener('drop', (event) => {
    event.preventDefault();
    dragDepth = 0;
    delete zone.dataset.dragging;
    addFiles([...event.dataTransfer.files]);
  });
  // Do not navigate away and open a file if it is accidentally dropped outside the target.
  document.addEventListener('dragover', (event) => event.preventDefault());
  document.addEventListener('drop', (event) => event.preventDefault());
}

for (const button of document.querySelectorAll('[data-copy]')) {
  button.addEventListener('click', () => copyLink(button, button.dataset.copy, null, document.querySelector('[data-copy-status]')));
}

function updateCountdowns() {
  for (const time of document.querySelectorAll('[data-expires]')) {
    const remaining = Date.parse(time.dataset.expires) - Date.now();
    if (remaining <= 0) {
      time.textContent = 'Expired';
      const scope = time.closest('tr, .upload-item, .file-details');
      for (const action of scope?.querySelectorAll('button, a') || []) {
        action.setAttribute('aria-disabled', 'true');
        if (action.tagName === 'BUTTON') action.disabled = true;
        if (action.tagName === 'A') action.removeAttribute('href');
      }
    } else {
      const minutes = Math.ceil(remaining / 60000);
      time.textContent = minutes >= 60 ? `${Math.floor(minutes / 60)}h ${minutes % 60}m` : `${minutes}m`;
    }
  }
}
updateCountdowns();
setInterval(updateCountdowns, 15000);
