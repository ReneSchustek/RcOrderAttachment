import Plugin from 'src/plugin-system/plugin.class';

/**
 * Asynchroner File-Upload auf der Checkout-Confirm-Page.
 *
 * Verantwortung:
 *  - Datei-Auswahl im File-Input abfangen
 *  - Client-seitige Pre-Validierung (Extension/Größe/Anzahl) — Server hat
 *    immer das letzte Wort, der Client-Check spart nur einen Roundtrip
 *  - Multipart-POST mit Progress-Anzeige
 *  - Erfolgreiche Uploads in die DOM-Liste einhängen (createElement, kein
 *    innerHTML-Concat — XSS-Defense-in-Depth)
 *  - Entfernen-Buttons asynchron
 *  - Confirm-Submit-Button bei aktiver Pflicht ohne Uploads sperren
 *
 * Kein CSRF-Token: Shopware hat den CSRF-Layer mit 6.5 entfernt (`sw_csrf`
 * existiert nicht mehr). State-Change-Requests sind über POST + das
 * `SameSite=Lax`-Session-Cookie geschützt — ein Cross-Site-POST bekommt die
 * Session gar nicht erst mitgeschickt.
 *
 * Übersetzungen kommen über data-Attribute aus Twig — Snippet-konform, kein
 * hardcoded Deutsch im JS.
 */
export default class RcOrderAttachmentUploadPlugin extends Plugin {
    init() {
        this._readDataset();
        this._cacheDomReferences();

        if (!this._input) {
            return;
        }

        this._input.addEventListener('change', this._onFilesSelected.bind(this));
        this._bindRemoveButtons();
        this._enforceSubmitGuard();
    }

    _readDataset() {
        const ds = this.el.dataset;

        this._uploadUrl = ds.uploadUrl;
        this._removeUrlTemplate = ds.removeUrlTemplate;
        // Twig erzeugt die Remove-URL mit einem regex-konformen Dummy-Token
        // (32 Hex-Zeichen), weil der Symfony-URL-Generator die `requirements`
        // der Route durchsetzt. Hier wird der Dummy gegen den echten Token getauscht.
        this._removeTokenPlaceholder = ds.removeTokenPlaceholder || '00000000000000000000000000000000';

        this._maxFiles = parseInt(ds.maxFiles, 10) || 0;
        this._maxFileSizeBytes = parseInt(ds.maxFileSizeBytes, 10) || 0;
        this._maxTotalSizeBytes = parseInt(ds.maxTotalSizeBytes, 10) || 0;
        this._allowedExtensions = (ds.allowedExtensions || '')
            .toLowerCase().split(',').map(s => s.trim()).filter(Boolean);
        this._required = ds.required === 'true';

        this._texts = {
            remove: ds.textRemove || 'Remove',
            noFiles: ds.textNoFiles || 'No files uploaded.',
            generic: ds.textGenericError || 'An error occurred.',
            progress: ds.textProgress || 'Uploading…',
        };

        try {
            this._errorMessages = JSON.parse(ds.errorMessages || '{}');
        } catch (e) {
            this._errorMessages = {};
        }
    }

    _cacheDomReferences() {
        this._input = this.el.querySelector('[data-rc-order-attachment-input]');
        this._list = this.el.querySelector('[data-rc-order-attachment-list]');
        this._progress = this.el.querySelector('[data-rc-order-attachment-progress]');
        this._progressBar = this.el.querySelector('[data-rc-order-attachment-progress-bar]');
        this._errorEl = this.el.querySelector('[data-rc-order-attachment-error]');
    }

    _onFilesSelected(event) {
        const files = Array.from(event.target.files || []);
        if (files.length === 0) {
            return;
        }

        const uploadNext = () => {
            const next = files.shift();
            if (!next) {
                this._input.value = '';
                this._hideProgress();
                return;
            }

            if (!this._preValidate(next)) {
                uploadNext();
                return;
            }

            this._uploadSingle(next).finally(uploadNext);
        };

        this._clearError();
        this._showProgress();
        uploadNext();
    }

    _preValidate(file) {
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        if (this._allowedExtensions.length > 0 && !this._allowedExtensions.includes(extension)) {
            this._showError(this._errorMessages.extensionNotAllowed || this._texts.generic);
            return false;
        }
        if (this._maxFileSizeBytes > 0 && file.size > this._maxFileSizeBytes) {
            this._showError(this._errorMessages.fileSizeExceeded || this._texts.generic);
            return false;
        }
        if (this._maxFiles > 0 && this._currentItemCount() >= this._maxFiles) {
            this._showError(this._errorMessages.fileCountExceeded || this._texts.generic);
            return false;
        }
        if (this._maxTotalSizeBytes > 0
            && (this._currentTotalSize() + file.size) > this._maxTotalSizeBytes) {
            this._showError(this._errorMessages.totalSizeExceeded || this._texts.generic);
            return false;
        }
        return true;
    }

    _uploadSingle(file) {
        return new Promise((resolve) => {
            const form = new FormData();
            form.append('file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this._uploadUrl, true);
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable && this._progressBar) {
                    const percent = Math.round((event.loaded / event.total) * 100);
                    this._progressBar.style.width = percent + '%';
                    this._progressBar.setAttribute('aria-valuenow', String(percent));
                }
            });

            xhr.onload = () => {
                this._handleUploadResponse(xhr);
                resolve();
            };

            xhr.onerror = () => {
                this._showError(this._texts.generic);
                resolve();
            };

            // Ohne Timeout blockiert eine hängende Verbindung die (sequenzielle)
            // Upload-Queue dauerhaft — der Nutzer sieht endlos "wird hochgeladen".
            xhr.timeout = 120000;
            xhr.ontimeout = () => {
                this._showError(this._texts.generic);
                resolve();
            };

            xhr.send(form);
        });
    }

    _handleUploadResponse(xhr) {
        let body = {};
        try {
            body = JSON.parse(xhr.responseText);
        } catch (e) {
            this._showError(this._texts.generic);
            return;
        }

        if (xhr.status >= 200 && xhr.status < 300 && body && body.success) {
            this._appendUploadItem(body.upload);
            this._clearError();
        } else {
            const codes = (body && body.errors) || [];
            this._showError(this._formatErrorCodes(codes));
        }

        this._enforceSubmitGuard();
    }

    _formatErrorCodes(codes) {
        if (!codes || codes.length === 0) {
            return this._texts.generic;
        }
        return codes.map(c => this._errorMessages[c] || c).join(' • ');
    }

    _appendUploadItem(upload) {
        if (!this._list || !upload || !upload.token) {
            return;
        }
        const empty = this._list.querySelector('[data-rc-order-attachment-empty]');
        if (empty) empty.remove();

        const item = document.createElement('li');
        item.className = 'list-group-item d-flex justify-content-between align-items-center';
        item.setAttribute('data-rc-order-attachment-item', upload.token);
        item.setAttribute('data-rc-order-attachment-item-size', String(upload.sizeBytes || 0));

        const left = document.createElement('span');
        left.className = 'd-inline-flex align-items-center gap-2';

        const name = document.createElement('span');
        name.className = 'rc-order-attachment-item-name';
        name.textContent = upload.name;
        left.appendChild(name);

        const sizeLabel = document.createElement('small');
        sizeLabel.className = 'text-muted';
        sizeLabel.textContent = ' (' + this._formatFileSize(upload.sizeBytes || 0) + ')';
        left.appendChild(sizeLabel);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-link btn-sm rc-order-attachment-remove';
        removeBtn.setAttribute('data-rc-order-attachment-remove', upload.token);
        removeBtn.textContent = this._texts.remove;

        item.appendChild(left);
        item.appendChild(removeBtn);
        this._list.appendChild(item);
        this._bindRemoveButtons();
    }

    _bindRemoveButtons() {
        const buttons = this.el.querySelectorAll('[data-rc-order-attachment-remove]');
        buttons.forEach((btn) => {
            if (btn.dataset.bound === 'true') {
                return;
            }
            btn.dataset.bound = 'true';
            btn.addEventListener('click', this._onRemoveClicked.bind(this));
        });
    }

    _onRemoveClicked(event) {
        event.preventDefault();
        const btn = event.currentTarget;
        const token = btn.getAttribute('data-rc-order-attachment-remove');
        if (!token) return;

        btn.disabled = true;
        const url = this._removeUrlTemplate.replace(this._removeTokenPlaceholder, token);

        // POST statt DELETE: Shopware routet Storefront-State-Changes über POST,
        // und `SameSite=Lax` schickt das Session-Cookie bei Cross-Site-POST nicht mit.
        fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then((response) => {
            // fetch() rejectet NICHT bei HTTP 4xx/5xx — ohne diese Prüfung würde
            // das DOM-Item auch dann entfernt, wenn der Server die Datei behielt
            // (Fail-open, DOM/Server-Divergenz). Nur bei 2xx wirklich entfernen.
            if (!response.ok) {
                btn.disabled = false;
                this._showError(this._texts.generic);
                return;
            }
            const item = this.el.querySelector('[data-rc-order-attachment-item="' + token + '"]');
            if (item) item.remove();
            if (this._currentItemCount() === 0) this._renderEmpty();
            this._enforceSubmitGuard();
            // WCAG 2.4.3: der Fokus lag auf dem entfernten Button und ginge sonst an
            // den Seitenanfang. Zurück auf das Datei-Input als logischen Ankerpunkt.
            if (this._input) this._input.focus();
        }).catch(() => {
            btn.disabled = false;
            this._showError(this._texts.generic);
        });
    }

    _renderEmpty() {
        if (!this._list) return;
        if (this._list.querySelector('[data-rc-order-attachment-empty]')) return;

        const empty = document.createElement('li');
        empty.className = 'list-group-item text-muted small';
        empty.setAttribute('data-rc-order-attachment-empty', 'true');
        empty.textContent = this._texts.noFiles;
        this._list.appendChild(empty);
    }

    _enforceSubmitGuard() {
        if (!this._required) return;
        const submit = document.querySelector('#confirmFormSubmit, button[form="confirmOrderForm"][type="submit"]');
        if (!submit) return;
        submit.disabled = this._currentItemCount() === 0;
    }

    _currentItemCount() {
        return this.el.querySelectorAll('[data-rc-order-attachment-item]').length;
    }

    /**
     * Liest die Bytes-Größe direkt aus data-Attributen — robust gegen
     * Locale-Formatierung im Text und stabil bei UI-Refresh.
     */
    _currentTotalSize() {
        let sum = 0;
        this.el.querySelectorAll('[data-rc-order-attachment-item-size]').forEach((el) => {
            const bytes = parseInt(el.dataset.rcOrderAttachmentItemSize, 10);
            if (!isNaN(bytes) && bytes > 0) {
                sum += bytes;
            }
        });
        return sum;
    }

    _formatFileSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    _showProgress() {
        if (!this._progress) return;
        this._progress.classList.remove('d-none');
        if (this._progressBar) {
            this._progressBar.style.width = '0%';
            this._progressBar.setAttribute('aria-valuenow', '0');
        }
    }

    _hideProgress() {
        if (!this._progress) return;
        this._progress.classList.add('d-none');
        if (this._progressBar) {
            this._progressBar.style.width = '0%';
            this._progressBar.setAttribute('aria-valuenow', '0');
        }
    }

    _showError(message) {
        if (!this._errorEl) return;
        this._errorEl.textContent = message;
        this._errorEl.classList.remove('d-none');
    }

    _clearError() {
        if (!this._errorEl) return;
        this._errorEl.textContent = '';
        this._errorEl.classList.add('d-none');
    }
}
