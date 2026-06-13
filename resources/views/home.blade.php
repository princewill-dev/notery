<x-layout>

<div class="notery-page">
<div class="notery-container">

  <div class="notery-brand">
    <h1 class="notery-logo">Notery</h1>
    <p class="notery-subtitle">Save and retrieve notes anonymously with a 4‑digit code</p>
  </div>

  @if(session('saved') && session('code'))
    <div class="notery-alert notery-alert-success notery-mb-3">
      Note saved — your code: <strong>{{ session('code') }}</strong>
    </div>
    <a href="/{{ session('code') }}" class="notery-btn notery-btn-primary notery-btn-block notery-mb-4">View saved note</a>
  @endif

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

      <div class="notery-row">
        <div class="notery-col">
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
        <div class="notery-col" id="attachment-file-wrapper" style="display:none;">
          <label for="attachment" class="notery-label">Choose files</label>
          <input type="file" name="attachment[]" id="attachment" multiple class="notery-input" />
        </div>
      </div>

      <div>
        <label for="max_views" class="notery-label">
          View limit <span class="notery-label-optional">(optional, 1–100)</span>
        </label>
        <input type="number" name="max_views" id="max_views" class="notery-input"
          min="1" max="100" placeholder="e.g. 5" value="{{ old('max_views') }}" />
      </div>

      <button type="submit" id="saveButton" class="notery-btn notery-btn-primary notery-btn-block">Save note</button>
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

  function show() {
    modal.classList.remove('notery-hidden');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(function(){ findInput && findInput.focus(); }, 50);
  }
  function hide() {
    modal.classList.add('notery-hidden');
    modal.setAttribute('aria-hidden', 'true');
  }
  function toggle() {
    fileWrapper.style.display = typeSelect && typeSelect.value ? '' : 'none';
  }

  document.addEventListener('DOMContentLoaded', toggle);
  typeSelect && typeSelect.addEventListener('change', toggle);
  headerForm && saveButton && headerForm.addEventListener('submit', function() {
    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';
  });
  openBtn && openBtn.addEventListener('click', show);
  closeBtn && closeBtn.addEventListener('click', hide);
  backdrop && backdrop.addEventListener('click', hide);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hide();
  });
})();
</script>

</x-layout>