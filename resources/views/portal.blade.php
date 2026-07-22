<x-layout>

<div class="notery-page">
<div class="portal-container" id="portalRoot">

  <div class="portal-header">
    <div class="portal-header-top">
      <div class="portal-code-block">
        <span class="portal-code-label">Portal</span>
        <span class="portal-code-value" id="portalCode">{{ $code }}</span>
      </div>
      <div class="portal-header-actions">
        <span class="portal-timer" id="portalTimer">--:--</span>
        <button type="button" id="portalCloseBtn" class="notery-btn notery-btn-ghost notery-btn-sm">Close</button>
      </div>
    </div>
    <div class="portal-connection" id="portalConnection">
      <span class="portal-dot"></span>
      <span id="portalConnectionText">Waiting for partner...</span>
    </div>
    <div class="portal-nav">
      <a href="/" class="portal-nav-link">← Back to home</a>
      <a href="/" class="portal-nav-link">Open new portal</a>
    </div>
  </div>

  <div class="portal-feed" id="portalFeed">
    <div class="portal-feed-empty" id="portalFeedEmpty">
      <div class="portal-feed-empty-icon">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </div>
      <p>Share the code <strong>{{ $code }}</strong> with someone to start</p>
    </div>
  </div>

  <div class="portal-action-bar" id="portalActionBar">
    <button type="button" class="portal-action-btn" data-action="text">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
      Text
    </button>
    <button type="button" class="portal-action-btn" data-action="image">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      Image
    </button>
    <button type="button" class="portal-action-btn" data-action="pdf">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      PDF
    </button>
    <button type="button" class="portal-action-btn" data-action="video">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
      Video
    </button>
    <button type="button" class="portal-action-btn" data-action="zip">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
      ZIP
    </button>
  </div>

  <div class="portal-upload-progress" id="portalUploadProgress" style="display:none;">
    <div class="notery-progress-header">
      <span class="notery-progress-title">Uploading...</span>
      <span class="notery-progress-percent" id="portalProgressPercent">0%</span>
    </div>
    <div class="notery-progress-filename" id="portalProgressFilename"></div>
    <div class="notery-progress-bar-track">
      <div class="notery-progress-bar-fill" id="portalProgressBar"></div>
    </div>
    <div class="notery-progress-speed" id="portalProgressSpeed"></div>
    <div class="notery-progress-error" id="portalProgressError" style="display:none;"></div>
  </div>

</div>
</div>

{{-- Text modal --}}
<div id="portalTextModal" class="notery-hidden notery-modal-overlay">
  <div id="portalTextBackdrop" style="position:absolute;inset:0;"></div>
  <div class="notery-modal" style="position:relative;z-index:1;max-width:560px;">
    <div class="notery-modal-header">
      <div class="notery-modal-title">Share text</div>
      <button type="button" id="closePortalTextModal" class="notery-btn notery-btn-ghost notery-btn-sm">Close</button>
    </div>
    <textarea id="portalTextArea" class="portal-textarea-modal" placeholder="Paste or type text to share..." rows="10"></textarea>
    <button type="button" id="portalShareTextBtn" class="notery-btn notery-btn-primary notery-btn-block notery-mt-3">Share text</button>
  </div>
</div>

<script>
window.__portalConfig = {
  code: '{{ $code }}',
  peerId: '{{ $peerId }}',
  expiresAt: '{{ $expiresAt }}',
  portalUrl: '{{ $portalUrl }}',
};
</script>

<input type="file" id="portalFileInput" style="position:fixed;left:-9999px;top:0;" />

</x-layout>
