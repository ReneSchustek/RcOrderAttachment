import Plugin from 'src/plugin-system/plugin.class';

/**
 * Lädt auf der Bestätigungsseite des Checkouts die gewählten Dateien einzeln im Hintergrund hoch,
 * zeigt den Fortschritt, hängt jede angenommene Datei an die Liste und entfernt sie auf Wunsch
 * wieder. Ist ein Anhang Pflicht, sperrt das Plugin den Bestellknopf, solange die Liste leer ist.
 *
 * Endung, Größe und Anzahl prüft es schon im Browser, um einen vergeblichen Upload zu sparen; das
 * letzte Wort hat der Server. Neue Listeneinträge entstehen über `createElement` und
 * `textContent`, nie über zusammengesetztes `innerHTML`, damit ein Dateiname kein HTML einschleust.
 *
 * Ein CSRF-Token gibt es nicht, weil Shopware die Prüfung mit 6.5 entfernt hat. Ändernde Anfragen
 * schützt das Sitzungscookie mit `SameSite=Lax`, das eine POST-Anfrage von fremder Seite nicht
 * mitbekommt. Alle Texte kommen über data-Attribute aus den Snippets der Vorlage.
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
        // Die Vorlage erzeugt die URL zum Entfernen mit einem Platzhalter aus 32 Hexziffern, weil
        // der URL-Generator von Symfony die `requirements` der Route prüft. Beim Klick wird der
        // Platzhalter gegen den echten Token getauscht.
        this._removeTokenPlaceholder = ds.removeTokenPlaceholder || '00000000000000000000000000000000';

        this._maxFiles = parseInt(ds.maxFiles, 10) || 0;
        this._maxFileSizeBytes = parseInt(ds.maxFileSizeBytes, 10) || 0;
        this._maxTotalSizeBytes = parseInt(ds.maxTotalSizeBytes, 10) || 0;
        this._allowedExtensions = (ds.allowedExtensions || '')
            .toLowerCase().split(',').map(s => s.trim()).filter(Boolean);
        this._required = ds.required === 'true';

        // Englische Rückfalltexte greifen nur, wenn ein data-Attribut fehlt.
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
        this._errors = [];
    }

    _onFilesSelected(event) {
        const files = Array.from(event.target.files || []);
        if (files.length === 0) {
            return;
        }

        // Nacheinander statt parallel, damit die Prüfung von Anzahl und Gesamtgröße die schon
        // angenommenen Dateien mitzählt und der Fortschrittsbalken genau eine Datei zeigt.
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

        // Die Fehler eines Durchgangs bleiben zusammen stehen, je mit dem Namen der Datei. Erst eine
        // neue Auswahl leert sie; sonst löschte der Erfolg der nächsten Datei den Grund der vorigen.
        this._clearError();
        this._showProgress();
        uploadNext();
    }

    _preValidate(file) {
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        if (this._allowedExtensions.length > 0 && !this._allowedExtensions.includes(extension)) {
            this._addError(file, this._errorMessages.extensionNotAllowed || this._texts.generic);
            return false;
        }
        if (this._maxFileSizeBytes > 0 && file.size > this._maxFileSizeBytes) {
            this._addError(file, this._errorMessages.fileSizeExceeded || this._texts.generic);
            return false;
        }
        if (this._maxFiles > 0 && this._currentItemCount() >= this._maxFiles) {
            this._addError(file, this._errorMessages.fileCountExceeded || this._texts.generic);
            return false;
        }
        if (this._maxTotalSizeBytes > 0
            && (this._currentTotalSize() + file.size) > this._maxTotalSizeBytes) {
            this._addError(file, this._errorMessages.totalSizeExceeded || this._texts.generic);
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
                this._handleUploadResponse(xhr, file);
                resolve();
            };

            xhr.onerror = () => {
                this._addError(file, this._texts.generic);
                resolve();
            };

            // Ohne Zeitgrenze hielte eine hängende Verbindung die Warteschlange dauerhaft an, und
            // der Kunde sähe endlos „wird hochgeladen". Zwei Minuten reichen für 10 MB auch bei
            // knapp 1 Mbit/s im Mobilnetz; eine größere Datei bei schwacher Leitung bricht ab.
            xhr.timeout = 120000;
            xhr.ontimeout = () => {
                this._addError(file, this._texts.generic);
                resolve();
            };

            xhr.send(form);
        });
    }

    _handleUploadResponse(xhr, file) {
        let body = {};
        try {
            body = JSON.parse(xhr.responseText);
        } catch (e) {
            this._addError(file, this._texts.generic);
            return;
        }

        if (xhr.status >= 200 && xhr.status < 300 && body && body.success) {
            this._appendUploadItem(body.upload);
        } else {
            const codes = (body && body.errors) || [];
            this._addError(file, this._formatErrorCodes(codes));
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

        // POST statt DELETE, weil die Storefront Zustandsänderungen über POST abwickelt und
        // `SameSite=Lax` das Sitzungscookie bei einem POST von fremder Seite zurückhält.
        fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then((response) => {
            // fetch() schlägt bei 4xx und 5xx nicht fehl. Ohne diese Prüfung verschwände der
            // Eintrag auch dann aus der Liste, wenn der Server die Datei behalten hat.
            if (!response.ok) {
                btn.disabled = false;
                this._showError(this._texts.generic);
                return;
            }
            const item = this.el.querySelector('[data-rc-order-attachment-item="' + token + '"]');
            if (item) item.remove();
            if (this._currentItemCount() === 0) this._renderEmpty();
            this._enforceSubmitGuard();
            // Der Fokus lag auf dem entfernten Knopf und spränge sonst an den Seitenanfang
            // (WCAG 2.4.3). Das Dateifeld ist der naheliegende Ort, an dem es weitergeht.
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
        // Die Sperre im Browser ist nur Bequemlichkeit; durchgesetzt wird die Pflicht im
        // RequiredAttachmentValidator auf dem Server.
        const submit = document.querySelector('#confirmFormSubmit, button[form="confirmOrderForm"][type="submit"]');
        if (!submit) return;
        submit.disabled = this._currentItemCount() === 0;
    }

    _currentItemCount() {
        return this.el.querySelectorAll('[data-rc-order-attachment-item]').length;
    }

    /**
     * Liest die Größe in Bytes aus den data-Attributen statt aus dem angezeigten Text, der je
     * nach Sprache anders formatiert ist.
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

    /**
     * Ein Fehler zu einer Datei eines Durchgangs; die früheren bleiben stehen.
     */
    _addError(file, message) {
        this._errors.push(file && file.name ? `${file.name}: ${message}` : message);
        this._renderErrors();
    }

    /**
     * Ein Fehler, der für sich steht, etwa beim Entfernen.
     */
    _showError(message) {
        this._errors = [message];
        this._renderErrors();
    }

    _clearError() {
        this._errors = [];
        this._renderErrors();
    }

    _renderErrors() {
        if (!this._errorEl) return;
        // Je Fehler eine Zeile, als Text gesetzt: Der Dateiname kommt vom Kunden.
        this._errorEl.replaceChildren(...this._errors.map((text) => {
            const line = document.createElement('div');
            line.textContent = text;
            return line;
        }));
        this._errorEl.classList.toggle('d-none', this._errors.length === 0);
    }
}
