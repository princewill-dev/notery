/**
 * ChunkedUploader — handles chunked file uploads with real-time progress.
 *
 * Auto-binds to #header-form on pages where it exists.
 * Uses axios (already on window.axios from bootstrap.js) for HTTP requests.
 * Chunk size: 5 MB.
 */

const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB
const MAX_RETRIES = 3;
const RETRY_DELAYS = [1000, 2000, 4000]; // exponential backoff

// File size limits per type (in bytes)
const FILE_LIMITS = {
    image: 100 * 1024 * 1024,   // 100 MB
    pdf:   200 * 1024 * 1024,   // 200 MB
    mp4:   500 * 1024 * 1024,   // 500 MB
    zip:   500 * 1024 * 1024,   // 500 MB
};

class ChunkedUploader {
    constructor(form) {
        this.form = form;
        this.uploadId = null;
        this.abortController = null;
        this.bytesUploaded = 0;
        this.totalBytes = 0;
        this.startTime = 0;
        this.cancelled = false;

        // Cache DOM refs
        this.saveButton = document.getElementById('saveButton');
        this.progressContainer = document.getElementById('upload-progress');
        this.progressPercent = document.getElementById('progress-percent');
        this.progressFilename = document.getElementById('progress-filename');
        this.progressBarFill = document.getElementById('progress-bar-fill');
        this.progressSpeed = document.getElementById('progress-speed');
        this.progressError = document.getElementById('progress-error');
        this.cancelButton = document.getElementById('cancel-upload');
        this.attachmentType = document.getElementById('attachment_type');
        this.fileInput = document.getElementById('attachment');
        this.writeupInput = document.getElementById('writeup');
        this.maxViewsInput = document.getElementById('max_views');

        this.bind();
    }

    bind() {
        this._boundStart = (e) => this.start(e);
        this.form.addEventListener('submit', this._boundStart);
        if (this.cancelButton) {
            this.cancelButton.addEventListener('click', () => this.cancel());
        }
    }

    /**
     * Start the upload process.
     */
    async start(e) {
        e.preventDefault();

        // Reset state
        this.cancelled = false;
        this.bytesUploaded = 0;
        this.startTime = 0;

        // Gather form data
        const writeup = this.writeupInput ? this.writeupInput.value.trim() : '';
        const attachmentTypeVal = this.attachmentType ? this.attachmentType.value : '';
        const maxViews = this.maxViewsInput ? (this.maxViewsInput.value || null) : null;
        const files = this.fileInput ? Array.from(this.fileInput.files || []) : [];

        // Client-side validation
        if (!writeup) {
            this.showError('Please enter some text for your note.');
            return;
        }

        if (attachmentTypeVal && files.length === 0) {
            this.showError('Please select at least one file for the chosen attachment type.');
            return;
        }

        if (attachmentTypeVal && FILE_LIMITS[attachmentTypeVal]) {
            const limit = FILE_LIMITS[attachmentTypeVal];
            for (const file of files) {
                if (file.size > limit) {
                    const limitMB = Math.round(limit / 1024 / 1024);
                    this.showError(`File "${file.name}" is too large. Maximum size for ${attachmentTypeVal} is ${limitMB} MB.`);
                    return;
                }
            }
        }

        // For text-only notes (no attachment), use the old synchronous form POST
        // to keep things simple and avoid the chunk infrastructure for no-file saves.
        if (!attachmentTypeVal || files.length === 0) {
            this.submitTextOnly(writeup, maxViews);
            return;
        }

        // Calculate total bytes for progress tracking
        this.totalBytes = files.reduce((sum, f) => sum + f.size, 0);
        this.uploadId = crypto.randomUUID ? crypto.randomUUID() : this.fallbackUUID();
        this.abortController = new AbortController();
        this.startTime = Date.now();

        // Disable form and show progress
        this.setFormEnabled(false);
        this.showProgress();

        try {
            const filesMeta = [];

            for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
                const file = files[fileIndex];
                const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                filesMeta.push({ index: fileIndex, original_name: file.name });

                for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                    if (this.cancelled) {
                        this.resetForm();
                        return;
                    }

                    const start = chunkIndex * CHUNK_SIZE;
                    const end = Math.min(start + CHUNK_SIZE, file.size);
                    const chunk = file.slice(start, end);

                    await this.uploadChunkWithRetry({
                        fileIndex,
                        originalName: file.name,
                        chunkIndex,
                        totalChunks,
                        chunk,
                        attachmentType: attachmentTypeVal,
                    });

                    // Update overall progress (this chunk is done)
                    this.bytesUploaded += chunk.size;
                    this.updateProgress(file.name);
                }
            }

            // All chunks uploaded — assemble
            await this.assemble(writeup, attachmentTypeVal, maxViews, filesMeta);
        } catch (err) {
            if (this.cancelled) {
                this.resetForm();
                return;
            }
            this.showError(err.message || 'Upload failed. Please check your connection and try again.');
            this.setFormEnabled(true);
        }
    }

    /**
     * Upload a single chunk with retry logic.
     */
    async uploadChunkWithRetry(params) {
        let lastError = null;

        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (this.cancelled) throw new Error('Upload cancelled');

            try {
                const formData = new FormData();
                formData.append('upload_id', this.uploadId);
                formData.append('file_index', params.fileIndex);
                formData.append('original_name', params.originalName);
                formData.append('chunk_index', params.chunkIndex);
                formData.append('total_chunks', params.totalChunks);
                formData.append('attachment_type', params.attachmentType);
                formData.append('chunk', params.chunk, params.originalName);

                // Track how many bytes were uploaded before *this specific chunk attempt*
                // for accurate within-chunk progress.
                const bytesBeforeThisChunk = this.bytesUploaded;

                await window.axios.post('/upload/chunk', formData, {
                    signal: this.abortController.signal,
                    onUploadProgress: (progressEvent) => {
                        if (this.cancelled) return;
                        const currentUploaded = bytesBeforeThisChunk + progressEvent.loaded;
                        const pct = this.totalBytes > 0
                            ? Math.round((currentUploaded / this.totalBytes) * 100)
                            : 0;
                        this.updateProgressUI(pct, this.formatSpeed());
                    },
                });

                return; // success — exit retry loop
            } catch (err) {
                lastError = err;
                if (window.axios.isCancel && window.axios.isCancel(err)) {
                    throw new Error('Upload cancelled');
                }
                // Only retry on network errors or 5xx, not on 4xx
                if (err.response && err.response.status >= 400 && err.response.status < 500) {
                    const msg = err.response.data?.message || `Server error (${err.response.status})`;
                    throw new Error(msg);
                }
                if (attempt < MAX_RETRIES) {
                    await this.sleep(RETRY_DELAYS[attempt] || 4000);
                }
            }
        }

        throw lastError || new Error('Chunk upload failed after retries');
    }

    /**
     * Call the assemble endpoint to finalize the upload.
     */
    async assemble(writeup, attachmentTypeVal, maxViews, filesMeta) {
        const payload = {
            upload_id: this.uploadId,
            writeup: writeup,
            attachment_type: attachmentTypeVal || null,
            max_views: maxViews ? parseInt(maxViews, 10) : null,
            files: filesMeta,
        };

        const response = await window.axios.post('/upload/assemble', payload, {
            signal: this.abortController.signal,
        });

        if (response.data && response.data.status === 'ok') {
            this.showSavedModal(response.data.code);
        } else {
            throw new Error(response.data?.message || 'Assembly failed');
        }
    }

    /**
     * Submit a text-only note via the old synchronous form endpoint.
     * Unbinds this uploader to avoid re-triggering the chunked flow, then
     * submits the form normally.
     */
    submitTextOnly(writeup, maxViews) {
        // Temporarily unbind the uploader's submit handler so form.submit()
        // doesn't loop back into start().
        this.form.removeEventListener('submit', this._boundStart);
        this.form.submit();
    }

    // ─── Progress UI ──────────────────────────────────────────────

    showProgress() {
        if (this.progressContainer) {
            this.progressContainer.style.display = '';
        }
        this.updateProgressUI(0, 'Starting...');
        if (this.progressError) {
            this.progressError.style.display = 'none';
            this.progressError.textContent = '';
        }
    }

    hideProgress() {
        if (this.progressContainer) {
            this.progressContainer.style.display = 'none';
        }
    }

    updateProgress(currentFileName = '') {
        const pct = this.totalBytes > 0
            ? Math.round((this.bytesUploaded / this.totalBytes) * 100)
            : 0;
        const speed = this.formatSpeed();
        this.updateProgressUI(pct, speed);
        if (this.progressFilename) {
            this.progressFilename.textContent = currentFileName || '';
        }
    }

    updateProgressUI(percent, speedText) {
        if (this.progressPercent) {
            this.progressPercent.textContent = percent + '%';
        }
        if (this.progressBarFill) {
            this.progressBarFill.style.width = percent + '%';
        }
        if (this.progressSpeed) {
            this.progressSpeed.textContent = speedText;
        }
    }

    formatSpeed() {
        if (!this.startTime) return '';
        const elapsed = (Date.now() - this.startTime) / 1000; // seconds
        if (elapsed < 0.1 || this.bytesUploaded === 0) return 'Starting...';

        const bytesPerSec = this.bytesUploaded / elapsed;
        const remaining = this.totalBytes - this.bytesUploaded;
        const etaSeconds = bytesPerSec > 0 ? remaining / bytesPerSec : 0;

        let speedStr;
        if (bytesPerSec > 1024 * 1024) {
            speedStr = (bytesPerSec / 1024 / 1024).toFixed(1) + ' MB/s';
        } else if (bytesPerSec > 1024) {
            speedStr = (bytesPerSec / 1024).toFixed(0) + ' KB/s';
        } else {
            speedStr = Math.round(bytesPerSec) + ' B/s';
        }

        if (etaSeconds > 0 && etaSeconds < 3600) {
            const mins = Math.floor(etaSeconds / 60);
            const secs = Math.round(etaSeconds % 60);
            speedStr += ' — ' + (mins > 0 ? mins + 'm ' : '') + secs + 's remaining';
        }

        return speedStr;
    }

    showError(message) {
        if (this.progressError) {
            this.progressError.textContent = message;
            this.progressError.style.display = '';
        }
        if (this.progressContainer) {
            this.progressContainer.style.display = '';
        }
        // Set bar to 0 and make it red
        if (this.progressBarFill) {
            this.progressBarFill.style.width = '0%';
            this.progressBarFill.style.background = 'var(--danger)';
        }
    }

    // ─── Form state management ────────────────────────────────────

    setFormEnabled(enabled) {
        if (this.saveButton) {
            this.saveButton.disabled = !enabled;
            this.saveButton.textContent = enabled ? 'Save note' : 'Preparing...';
        }
        if (this.writeupInput) this.writeupInput.disabled = !enabled;
        if (this.attachmentType) this.attachmentType.disabled = !enabled;
        if (this.fileInput) this.fileInput.disabled = !enabled;
        if (this.maxViewsInput) this.maxViewsInput.disabled = !enabled;
    }

    resetForm() {
        this.hideProgress();
        this.setFormEnabled(true);
        // Reset progress bar color
        if (this.progressBarFill) {
            this.progressBarFill.style.background = '';
            this.progressBarFill.style.width = '0%';
        }
    }

    // ─── Cancel ───────────────────────────────────────────────────

    cancel() {
        this.cancelled = true;
        if (this.abortController) {
            this.abortController.abort();
        }
        this.resetForm();
    }

    // ─── Saved modal (dynamically created, mirrors Blade template) ─

    showSavedModal(code) {
        this.resetForm();

        // Build the modal dynamically
        const overlay = document.createElement('div');
        overlay.id = 'savedModal';
        overlay.className = 'notery-modal-overlay';
        overlay.innerHTML = `
            <div id="savedModalBackdrop" style="position:absolute;inset:0;"></div>
            <div class="notery-modal notery-text-center" style="position:relative;z-index:1;">
                <h2 style="font-size:18px;font-weight:700;margin-bottom:16px;">Note saved</h2>
                <div class="notery-code" id="savedCode">${this.escapeHtml(code)}</div>
                <button type="button" id="copyCodeBtn" class="notery-btn notery-btn-secondary notery-btn-sm notery-mt-2" style="display:inline-flex;align-items:center;gap:6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    Copy
                </button>
                <div class="notery-mt-4 notery-btn-group">
                    <a href="/${this.escapeHtml(code)}" class="notery-btn notery-btn-primary">View note</a>
                    <button type="button" id="closeSavedModal" class="notery-btn notery-btn-secondary">Save another</button>
                </div>
            </div>`;

        document.body.appendChild(overlay);

        // Wire up close
        const closeBtn = overlay.querySelector('#closeSavedModal');
        const backdrop = overlay.querySelector('#savedModalBackdrop');
        const copyBtn = overlay.querySelector('#copyCodeBtn');
        const savedCode = overlay.querySelector('#savedCode');

        const remove = () => {
            if (overlay.parentNode) overlay.remove();
        };

        closeBtn && closeBtn.addEventListener('click', remove);
        backdrop && backdrop.addEventListener('click', remove);

        // Wire up copy
        if (copyBtn && savedCode) {
            copyBtn.addEventListener('click', () => {
                const c = savedCode.textContent.trim();
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(c);
                    } else {
                        const ta = document.createElement('textarea');
                        ta.value = c;
                        ta.style.position = 'fixed';
                        ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    }
                    copyBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied';
                    copyBtn.disabled = true;
                    setTimeout(() => {
                        copyBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy';
                        copyBtn.disabled = false;
                    }, 1500);
                } catch (e) {
                    copyBtn.textContent = 'Copy failed';
                }
            });
        }

        // Escape key
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                remove();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    fallbackUUID() {
        // Fallback for older browsers without crypto.randomUUID()
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
}

// Auto-initialize on pages that have the form
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('header-form');
    if (form) {
        new ChunkedUploader(form);
    }
});

export default ChunkedUploader;
