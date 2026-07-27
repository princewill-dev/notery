const FILE_LIMITS = {
    image: 20 * 1024 * 1024,
    pdf:   500 * 1024 * 1024,
    video: 500 * 1024 * 1024,
    zip:   500 * 1024 * 1024,
};

const FILE_ACCEPT = {
    image: 'image/*',
    pdf:   'application/pdf',
    video: 'video/*',
    zip:   'application/zip,.zip',
};

class PortalClient {
    constructor() {
        this.code = null;
        this.peerId = null;
        this.expiresAt = null;
        this.pollInterval = 1500;
        this.lastMessageTs = 0;
        this.pollTimer = null;
        this.lastActivity = Date.now();
        this.isClosed = false;

        console.log('[Portal] Initializing...');
        this.cacheDom();
        this.loadConfig();
        console.log('[Portal] Config loaded:', { code: this.code, peerId: this.peerId });
        if (this.code) {
            this.bind();
            this.startPolling();
            this.startTimer();
            console.log('[Portal] Started polling and timer');
        } else {
            console.warn('[Portal] No config found — portal will not work');
            if (this.dom.connectionText) {
                this.dom.connectionText.textContent = 'Error: portal config not loaded';
            }
        }
    }

    cacheDom() {
        this.dom = {
            timer: document.getElementById('portalTimer'),
            connection: document.getElementById('portalConnection'),
            connectionText: document.getElementById('portalConnectionText'),
            feed: document.getElementById('portalFeed'),
            feedEmpty: document.getElementById('portalFeedEmpty'),
            fileInput: document.getElementById('portalFileInput'),
            closeBtn: document.getElementById('portalCloseBtn'),
            actionBtns: document.querySelectorAll('.portal-action-btn'),
            textModal: document.getElementById('portalTextModal'),
            textBackdrop: document.getElementById('portalTextBackdrop'),
            textClose: document.getElementById('closePortalTextModal'),
            textArea: document.getElementById('portalTextArea'),
            shareTextBtn: document.getElementById('portalShareTextBtn'),
            uploadProgress: document.getElementById('portalUploadProgress'),
            progressPercent: document.getElementById('portalProgressPercent'),
            progressFilename: document.getElementById('portalProgressFilename'),
            progressBar: document.getElementById('portalProgressBar'),
            progressSpeed: document.getElementById('portalProgressSpeed'),
            progressError: document.getElementById('portalProgressError'),
        };
    }

    loadConfig() {
        if (window.__portalConfig) {
            this.code = window.__portalConfig.code;
            this.peerId = window.__portalConfig.peerId;
            this.expiresAt = new Date(window.__portalConfig.expiresAt).getTime();
        }
    }

    bind() {
        this.currentAction = null;

        this.dom.actionBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                if (action === 'text') {
                    this.showTextModal();
                } else {
                    this.pickFile(action);
                }
            });
        });

        this.dom.fileInput.addEventListener('change', () => this.handleFileSelect());
        this.dom.closeBtn.addEventListener('click', () => this.closePortal());
        this.dom.textClose.addEventListener('click', () => this.hideTextModal());
        this.dom.textBackdrop.addEventListener('click', () => this.hideTextModal());
        this.dom.shareTextBtn.addEventListener('click', () => this.shareText());

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.hideTextModal();
            }
        });

        document.addEventListener('paste', (e) => {
            const items = e.clipboardData?.items;
            if (!items) return;
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    e.preventDefault();
                    this.uploadFile(item.getAsFile(), 'image');
                    return;
                }
            }
        });
    }

    showTextModal() {
        this.dom.textModal.classList.remove('notery-hidden');
        this.dom.textModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => this.dom.textArea.focus(), 50);
    }

    hideTextModal() {
        this.dom.textModal.classList.add('notery-hidden');
        this.dom.textModal.setAttribute('aria-hidden', 'true');
        this.dom.textArea.value = '';
    }

    pickFile(action) {
        this.currentAction = action;
        this.dom.fileInput.accept = FILE_ACCEPT[action] || '*';
        this.dom.fileInput.click();
    }

    handleFileSelect() {
        const files = this.dom.fileInput.files;
        if (!files || files.length === 0) return;

        const action = this.currentAction || 'file';

        for (const file of files) {
            if (FILE_LIMITS[action] && file.size > FILE_LIMITS[action]) {
                const limitMB = Math.round(FILE_LIMITS[action] / 1024 / 1024);
                this.showUploadError(`File "${file.name}" is too large. Max ${limitMB} MB for ${action}.`);
                continue;
            }

            if (file.size <= 20 * 1024 * 1024) {
                this.uploadFile(file, action);
            } else {
                this.uploadLargeFile(file, action);
            }
        }

        this.dom.fileInput.value = '';
    }

    async shareText() {
        const content = this.dom.textArea.value.trim();
        if (!content) return;

        this.dom.shareTextBtn.disabled = true;

        try {
            const response = await fetch('/portal/' + this.code + '/message', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                },
                body: JSON.stringify({
                    type: 'text',
                    content: content,
                    peer_id: this.peerId,
                }),
            });

            const data = await response.json();

            if (data.status === 'ok') {
                this.hideTextModal();
                this.lastActivity = Date.now();
                this.poll();
            }
        } catch (err) {
            console.error('Share text error:', err);
        }

        this.dom.shareTextBtn.disabled = false;
    }

    async uploadFile(file, action) {
        this.showUploadProgress(file.name);

        const mimeType = action === 'image' ? 'image' : 'file';
        const formData = new FormData();
        formData.append('type', mimeType);
        formData.append('file', file);
        formData.append('peer_id', this.peerId);

        if (action !== 'image') {
            formData.append('attachment_type', this.mapActionToType(action));
        }

        try {
            const response = await fetch('/portal/' + this.code + '/message', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': this.getCsrfToken() },
                body: formData,
            });

            const data = await response.json();

            if (data.status === 'ok') {
                this.lastActivity = Date.now();
                this.poll();
            } else {
                this.showUploadError(data.message || 'Upload failed');
            }
        } catch (err) {
            this.showUploadError('Upload failed');
        }

        this.hideUploadProgress();
    }

    async uploadLargeFile(file, action) {
        const uploadId = crypto.randomUUID ? crypto.randomUUID() : this.fallbackUUID();
        const CHUNK_SIZE = 5 * 1024 * 1024;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const attType = this.mapActionToType(action);

        this.showUploadProgress(file.name);

        try {
            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                const fd = new FormData();
                fd.append('upload_id', uploadId);
                fd.append('file_index', 0);
                fd.append('chunk_index', i);
                fd.append('total_chunks', totalChunks);
                fd.append('original_name', file.name);
                fd.append('attachment_type', attType);
                fd.append('chunk', chunk, file.name);
                fd.append('peer_id', this.peerId);

                const response = await fetch('/portal/' + this.code + '/upload-chunk', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': this.getCsrfToken() },
                    body: fd,
                });

                if (!response.ok) {
                    const errData = await response.json().catch(() => ({}));
                    throw new Error(errData.message || 'Chunk upload failed');
                }

                const pct = Math.round(((i + 1) / totalChunks) * 100);
                this.updateUploadProgress(pct, file.name);
            }

            const assembleResp = await fetch('/portal/' + this.code + '/upload-assemble', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                },
                body: JSON.stringify({
                    upload_id: uploadId,
                    attachment_type: attType,
                    files: [{ index: 0, original_name: file.name }],
                    peer_id: this.peerId,
                }),
            });

            const assembleData = await assembleResp.json();

            if (assembleData.status === 'ok') {
                this.lastActivity = Date.now();
                this.poll();
            } else {
                this.showUploadError(assembleData.message || 'Assembly failed');
            }
        } catch (err) {
            this.showUploadError(err.message || 'Upload failed');
        }

        this.hideUploadProgress();
    }

    mapActionToType(action) {
        const map = { image: 'image', pdf: 'pdf', video: 'mp4', zip: 'zip' };
        return map[action] || action;
    }

    async poll() {
        if (this.isClosed) return;

        console.log('[Portal] Polling...', { code: this.code, peerId: this.peerId, since: this.lastMessageTs });

        try {
            const response = await fetch(
                '/portal/' + this.code + '/poll?since=' + this.lastMessageTs + '&peer_id=' + this.peerId,
                { credentials: 'same-origin' }
            );
            const data = await response.json();

            console.log('[Portal] Poll response:', { status: data.status, peerCount: data.peer_count, msgCount: (data.messages || []).length });

            if (data.status === 'error' || !response.ok) {
                console.warn('[Portal] Poll error:', data.status, data.message, 'peer_id:', this.peerId);
                if (data.message === 'Not a participant') {
                    this.handlePollError('Connection lost — refresh the page to reconnect.');
                }
                this.scheduleNextPoll();
                return;
            }

            if (data.status === 'closed' || data.status === 'expired') {
                this.handleClose(data.status);
                return;
            }

            if (data.messages && data.messages.length > 0) {
                this.renderCards(data.messages);
            }

            this.updateConnection(data.peer_count);
            this.updateExpiresAt(data.expires_at);
        } catch (err) {
            console.error('Portal poll error:', err);
        }

        this.scheduleNextPoll();
    }

    startPolling() {
        this.lastMessageTs = 0;
        this.lastActivity = Date.now();
        this.poll();
    }

    scheduleNextPoll() {
        if (this.isClosed) return;
        const idle = (Date.now() - this.lastActivity) > 30000;
        const interval = idle ? 3000 : 1500;
        this.pollTimer = setTimeout(() => this.poll(), interval);
    }

    renderCards(messages) {
        if (this.dom.feedEmpty) {
            this.dom.feedEmpty.style.display = 'none';
        }

        const wasAtBottom = this.isScrolledToBottom();

        for (const msg of messages) {
            if (this.lastMessageTs < msg.created_at) {
                this.lastMessageTs = msg.created_at;
            }

            const isMine = msg.peer_id === this.peerId;
            const senderLabel = isMine ? 'You' : 'Guest';

            if (msg.type === 'text') {
                this.dom.feed.appendChild(this.buildTextCard(msg.content, senderLabel));
            } else if (msg.type === 'image' || msg.type === 'file') {
                const isImage = msg.image_mime && msg.image_mime.startsWith('image/');
                if (isImage) {
                    this.dom.feed.appendChild(this.buildImageCard(msg, senderLabel));
                } else {
                    this.dom.feed.appendChild(this.buildFileCard(msg, senderLabel));
                }
            }
        }

        if (wasAtBottom) {
            this.scrollToBottom();
        }
    }

    buildTextCard(content, senderLabel) {
        const card = document.createElement('div');
        card.className = 'portal-card';
        card.innerHTML =
            '<div class="portal-card-header">'
            + '<span class="portal-card-badge portal-card-badge-text">Text</span>'
            + '<span class="portal-card-sender">&middot; shared by ' + this.escapeHtml(senderLabel) + '</span>'
            + '<button type="button" class="portal-card-copy-btn">Copy</button>'
            + '</div>'
            + '<div class="portal-card-body portal-card-text">' + this.escapeHtml(content) + '</div>';

        card.querySelector('.portal-card-copy-btn').addEventListener('click', () => {
            this.copyToClipboard(content);
        });

        return card;
    }

    buildImageCard(msg, senderLabel) {
        const sizeStr = msg.image_size ? this.formatSize(msg.image_size) : '';
        const fileName = msg.file_name || 'image';

        const card = document.createElement('div');
        card.className = 'portal-card';
        card.innerHTML =
            '<div class="portal-card-header">'
            + '<span class="portal-card-badge portal-card-badge-image">Image</span>'
            + '<span class="portal-card-sender">&middot; shared by ' + this.escapeHtml(senderLabel) + '</span>'
            + '</div>'
            + '<div class="portal-card-body">'
            + '<img src="' + this.escapeHtml(msg.view_url) + '" alt="' + this.escapeHtml(fileName) + '" class="portal-card-image" loading="lazy" />'
            + '</div>'
            + '<div class="portal-card-footer">'
            + '<span class="portal-card-meta">' + this.escapeHtml(fileName) + (sizeStr ? ' &middot; ' + sizeStr : '') + '</span>'
            + '<div class="notery-btn-group notery-gap-2" style="flex:none;">'
            + '<a href="' + this.escapeHtml(msg.view_url) + '" target="_blank" class="notery-btn notery-btn-secondary notery-btn-sm">View full</a>'
            + '<a href="' + this.escapeHtml(msg.download_url) + '" class="notery-btn notery-btn-primary notery-btn-sm">Download</a>'
            + '</div>'
            + '</div>';

        return card;
    }

    buildFileCard(msg, senderLabel) {
        const sizeStr = msg.image_size ? this.formatSize(msg.image_size) : '';
        const fileName = msg.file_name || 'file';
        const mimeLabel = msg.image_mime || '';

        const card = document.createElement('div');
        card.className = 'portal-card';
        card.innerHTML =
            '<div class="portal-card-header">'
            + this.getTypeBadge(msg.image_mime, msg.type)
            + '<span class="portal-card-sender">&middot; shared by ' + this.escapeHtml(senderLabel) + '</span>'
            + '</div>'
            + '<div class="portal-card-body portal-card-file">'
            + '<div class="portal-card-file-icon">' + this.getFileIcon(msg.image_mime) + '</div>'
            + '<div class="portal-card-file-info">'
            + '<div class="portal-card-file-name">' + this.escapeHtml(fileName) + '</div>'
            + '<div class="portal-card-file-meta">' + this.escapeHtml(mimeLabel) + (sizeStr ? ' &middot; ' + sizeStr : '') + '</div>'
            + '</div>'
            + '<a href="' + this.escapeHtml(msg.download_url) + '" class="notery-btn notery-btn-primary notery-btn-sm">Download</a>'
            + '</div>';

        return card;
    }

    getTypeBadge(mime, type) {
        let label = 'File';
        let cls = 'portal-card-badge-file';
        if (mime) {
            if (mime.startsWith('video/')) { label = 'Video'; cls = 'portal-card-badge-video'; }
            else if (mime === 'application/pdf') { label = 'PDF'; cls = 'portal-card-badge-pdf'; }
            else if (mime === 'application/zip' || mime.includes('zip')) { label = 'ZIP'; cls = 'portal-card-badge-zip'; }
        } else if (type === 'video') { label = 'Video'; cls = 'portal-card-badge-video'; }
        else if (type === 'pdf') { label = 'PDF'; cls = 'portal-card-badge-pdf'; }
        else if (type === 'zip') { label = 'ZIP'; cls = 'portal-card-badge-zip'; }
        return '<span class="portal-card-badge ' + cls + '">' + label + '</span>';
    }

    copyToClipboard(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text);
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.focus(); ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        } catch (e) {}
    }

    isScrolledToBottom() {
        const feed = this.dom.feed;
        return feed.scrollHeight - feed.scrollTop - feed.clientHeight < 60;
    }

    scrollToBottom() {
        this.dom.feed.scrollTop = this.dom.feed.scrollHeight;
    }

    updateConnection(peerCount) {
        const dot = this.dom.connection?.querySelector('.portal-dot');
        const text = this.dom.connectionText;

        if (peerCount >= 2) {
            this.dom.connection?.classList.add('portal-connected');
            if (text) text.textContent = 'Connected (' + peerCount + ' peers)';
            if (dot) dot.classList.add('portal-dot-active');
        } else {
            this.dom.connection?.classList.remove('portal-connected');
            if (text) text.textContent = 'Waiting for partner... (' + peerCount + ' peer' + (peerCount === 1 ? '' : 's') + ')';
            if (dot) dot.classList.remove('portal-dot-active');
        }
    }

    updateExpiresAt(expiresAt) {
        if (expiresAt) this.expiresAt = expiresAt;
    }

    startTimer() {
        const tick = () => {
            if (this.isClosed) return;
            const remaining = this.expiresAt - Date.now();
            if (remaining <= 0) {
                if (this.dom.timer) this.dom.timer.textContent = 'Expired';
                this.handleClose('expired');
                return;
            }

            const mins = Math.floor(remaining / 60000);
            const secs = Math.floor((remaining % 60000) / 1000);
            const display = mins + ':' + String(secs).padStart(2, '0');

            if (this.dom.timer) {
                this.dom.timer.textContent = display;
                if (remaining < 60000) {
                    this.dom.timer.classList.add('portal-timer-warn');
                }
            }

            this.timerId = setTimeout(tick, 1000);
        };
        tick();
    }

    async closePortal() {
        if (this.isClosed) return;

        try {
            await fetch('/portal/' + this.code + '/close', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                },
                body: JSON.stringify({ peer_id: this.peerId }),
            });
        } catch (err) {
            console.error('Close portal error:', err);
        }

        this.handleClose('closed');
    }

    handlePollError(message) {
        if (this.dom.connectionText) {
            this.dom.connectionText.textContent = message;
        }
        this.dom.connection?.classList.remove('portal-connected');
        const dot = this.dom.connection?.querySelector('.portal-dot');
        if (dot) dot.classList.remove('portal-dot-active');
    }

    handleClose(reason) {
        if (this.isClosed) return;
        this.isClosed = true;

        if (this.pollTimer) clearTimeout(this.pollTimer);
        if (this.timerId) clearTimeout(this.timerId);

        if (reason === 'closed') {
            window.location.href = '/';
            return;
        }

        if (this.dom.timer) this.dom.timer.textContent = 'Expired';

        if (this.dom.connectionText) {
            this.dom.connectionText.textContent = reason === 'closed' ? 'Portal closed' : 'Portal expired';
        }
        this.dom.connection?.classList.remove('portal-connected');
        const dot = this.dom.connection?.querySelector('.portal-dot');
        if (dot) dot.classList.remove('portal-dot-active');

        this.dom.actionBtns.forEach(b => b.disabled = true);

        const notice = document.createElement('div');
        notice.className = 'portal-feed-notice';
        notice.textContent = reason === 'closed'
            ? 'This portal has been closed.'
            : 'This portal has expired.';
        this.dom.feed.appendChild(notice);
        this.scrollToBottom();
    }

    showUploadProgress(filename) {
        if (this.dom.uploadProgress) this.dom.uploadProgress.style.display = '';
        if (this.dom.progressFilename) this.dom.progressFilename.textContent = filename;
        this.updateUploadProgress(0, filename);
        if (this.dom.progressError) this.dom.progressError.style.display = 'none';
    }

    updateUploadProgress(pct, filename) {
        if (this.dom.progressPercent) this.dom.progressPercent.textContent = pct + '%';
        if (this.dom.progressBar) this.dom.progressBar.style.width = pct + '%';
        if (this.dom.progressFilename) this.dom.progressFilename.textContent = filename;
    }

    hideUploadProgress() {
        if (this.dom.uploadProgress) this.dom.uploadProgress.style.display = 'none';
        if (this.dom.progressBar) this.dom.progressBar.style.background = '';
    }

    showUploadError(message) {
        if (this.dom.progressError) {
            this.dom.progressError.textContent = message;
            this.dom.progressError.style.display = '';
        }
        if (this.dom.progressBar) {
            this.dom.progressBar.style.width = '0%';
            this.dom.progressBar.style.background = 'var(--danger)';
        }
        setTimeout(() => {
            if (this.dom.progressError) this.dom.progressError.style.display = 'none';
            if (this.dom.progressBar) this.dom.progressBar.style.background = '';
        }, 4000);
    }

    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const cookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
        if (cookie) return decodeURIComponent(cookie.split('=')[1]);
        return '';
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    formatSize(bytes) {
        if (!bytes) return '';
        if (bytes > 1024 * 1024 * 1024) return (bytes / 1024 / 1024 / 1024).toFixed(1) + ' GB';
        if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        if (bytes > 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    }

    getFileIcon(mime) {
        if (!mime) return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>';
        if (mime.startsWith('video/')) return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
        if (mime === 'application/pdf') return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
        if (mime.includes('zip')) return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>';
    }

    fallbackUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.__portalConfig) {
        new PortalClient();
    }
});

export default PortalClient;
