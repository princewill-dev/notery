<x-layout>

<div class="notery-page">
<div class="notery-container">

  <div class="notery-brand">
    <h1 class="notery-logo">Notery</h1>
    <p class="notery-subtitle">Save and retrieve notes anonymously with a 4‑digit code</p>
  </div>

  @if ($errors->any())
    <div class="notery-alert notery-alert-error notery-mb-4">
      <div class="notery-alert-title">There were some problems:</div>
      <ul>
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="notery-card">
    <form action="/save" method="POST" id="header-form" class="notery-form" enctype="multipart/form-data">
      @csrf

      <textarea name="writeup" id="writeup" class="notery-textarea"
        placeholder="Start typing your note..."
        required autofocus>{{ old('writeup') }}</textarea>

      <div>
        <label for="attachment_type" class="notery-label">Attachment type</label>
        <div class="notery-select-wrap">
          <select name="attachment_type" id="attachment_type">
            <option value="" {{ old('attachment_type')===''?'selected':'' }}>None</option>
            <option value="image" {{ old('attachment_type')==='image'?'selected':'' }}>Image (max 100 MB)</option>
            <option value="pdf" {{ old('attachment_type')==='pdf'?'selected':'' }}>PDF (max 200 MB)</option>
            <option value="mp4" {{ old('attachment_type')==='mp4'?'selected':'' }}>MP4 (max 500 MB)</option>
            <option value="zip" {{ old('attachment_type')==='zip'?'selected':'' }}>ZIP (max 500 MB)</option>
          </select>
        </div>
      </div>
      <div id="attachment-file-wrapper" style="display:none;">
        <label for="attachment" class="notery-label">Choose files</label>
        <input type="file" name="attachment[]" id="attachment" multiple class="notery-input" />
      </div>

      <div>
        <label for="max_views" class="notery-label">
          View limit <span class="notery-label-optional">(optional, 1–100)</span>
        </label>
        <input type="number" name="max_views" id="max_views" class="notery-input"
          min="1" max="100" placeholder="e.g. 5" value="{{ old('max_views') }}" />
      </div>

      <button type="submit" id="saveButton" class="notery-btn notery-btn-primary notery-btn-block">Save note</button>

      {{-- Upload progress (hidden until file upload starts) --}}
      <div id="upload-progress" class="notery-upload-progress" style="display:none;">
        <div class="notery-progress-header">
          <span class="notery-progress-title">Uploading...</span>
          <span class="notery-progress-percent" id="progress-percent">0%</span>
        </div>
        <div class="notery-progress-filename" id="progress-filename"></div>
        <div class="notery-progress-bar-track">
          <div class="notery-progress-bar-fill" id="progress-bar-fill"></div>
        </div>
        <div class="notery-progress-speed" id="progress-speed"></div>
        <div class="notery-progress-error" id="progress-error" style="display:none;"></div>
        <div class="notery-progress-cancel">
          <button type="button" id="cancel-upload" class="notery-btn notery-btn-ghost notery-btn-sm">Cancel</button>
        </div>
      </div>
    </form>
  </div>

  <div class="notery-mt-4">
    <div class="notery-divider notery-mb-3">or</div>
    <button type="button" id="openFindModal" class="notery-btn notery-btn-ghost notery-btn-block">Find a note by code</button>
  </div>

</div>
</div>

{{-- Find modal --}}
<div id="findNoteModal" aria-hidden="true" class="notery-hidden notery-modal-overlay">
  <div id="findNoteModalBackdrop" style="position:absolute;inset:0;"></div>
  <div class="notery-modal" style="position:relative;z-index:1;">
    <div class="notery-modal-header">
      <div class="notery-modal-title">Find a note</div>
      <button type="button" id="closeFindModal" class="notery-btn notery-btn-ghost notery-btn-sm">Close</button>
    </div>
    <form action="/" method="GET" class="notery-form">
      <input type="tel" name="code" id="find_code" class="notery-input"
        inputmode="numeric" pattern="\d{4}" maxlength="4"
        placeholder="Enter 4-digit code" required />
      <button type="submit" class="notery-btn notery-btn-primary notery-btn-block">Find note</button>
    </form>
  </div>
</div>

{{-- Saved modal --}}
@if(session('saved') && session('code'))
<div id="savedModal" class="notery-modal-overlay">
  <div id="savedModalBackdrop" style="position:absolute;inset:0;"></div>
  <div class="notery-modal notery-text-center" style="position:relative;z-index:1;">
    <h2 style="font-size:18px;font-weight:700;margin-bottom:16px;">Note saved</h2>
    <div class="notery-code" id="savedCode">{{ session('code') }}</div>
    <button type="button" id="copyCodeBtn" class="notery-btn notery-btn-secondary notery-btn-sm notery-mt-2" style="display:inline-flex;align-items:center;gap:6px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      Copy
    </button>
    <div class="notery-mt-4 notery-btn-group">
      <a href="/{{ session('code') }}" class="notery-btn notery-btn-primary">View note</a>
      <button type="button" id="closeSavedModal" class="notery-btn notery-btn-secondary">Save another</button>
    </div>
  </div>
</div>
@endif

<script>
(function () {
  var typeSelect  = document.getElementById('attachment_type');
  var fileWrapper = document.getElementById('attachment-file-wrapper');
  var saveButton  = document.getElementById('saveButton');
  var headerForm  = document.getElementById('header-form');
  var openBtn     = document.getElementById('openFindModal');
  var modal       = document.getElementById('findNoteModal');
  var backdrop    = document.getElementById('findNoteModalBackdrop');
  var closeBtn    = document.getElementById('closeFindModal');
  var findInput   = document.getElementById('find_code');

  // --- Find modal ---
  function show() {
    modal.classList.remove('notery-hidden');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(function(){ findInput && findInput.focus(); }, 50);
  }
  function hide() {
    modal.classList.add('notery-hidden');
    modal.setAttribute('aria-hidden', 'true');
  }

  // --- Saved modal ---
  var savedModal    = document.getElementById('savedModal');
  var savedBackdrop = document.getElementById('savedModalBackdrop');
  var closeSaved    = document.getElementById('closeSavedModal');
  var copyCodeBtn   = document.getElementById('copyCodeBtn');
  var savedCode     = document.getElementById('savedCode');

  function closeSavedModal() {
    if (!savedModal) return;
    savedModal.remove();
  }

  if (copyCodeBtn && savedCode) {
    copyCodeBtn.addEventListener('click', function() {
      var code = savedCode.textContent.trim();
      try {
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(code);
        } else {
          var ta = document.createElement('textarea');
          ta.value = code;
          ta.style.position = 'fixed'; ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.focus(); ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
        }
        copyCodeBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied';
        copyCodeBtn.disabled = true;
        setTimeout(function() {
          copyCodeBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy';
          copyCodeBtn.disabled = false;
        }, 1500);
      } catch(e) {
        copyCodeBtn.textContent = 'Failed';
        setTimeout(function() {
          copyCodeBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy';
        }, 1500);
      }
    });
  }

  closeSaved && closeSaved.addEventListener('click', closeSavedModal);
  savedBackdrop && savedBackdrop.addEventListener('click', closeSavedModal);

  function toggle() {
    fileWrapper.style.display = typeSelect && typeSelect.value ? '' : 'none';
  }

  document.addEventListener('DOMContentLoaded', toggle);
  typeSelect && typeSelect.addEventListener('change', toggle);
  openBtn && openBtn.addEventListener('click', show);
  closeBtn && closeBtn.addEventListener('click', hide);
  backdrop && backdrop.addEventListener('click', hide);

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (savedModal && savedModal.parentNode) closeSavedModal();
      else hide();
    }
  });
})();
</script>

</x-layout>