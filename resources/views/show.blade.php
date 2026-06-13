<x-layout>

<div class="notery-page">
<div class="notery-container">

  <div class="notery-brand">
    <h1 class="notery-logo">Your note</h1>
    @if(isset($maxViews) && $maxViews !== null)
      <p class="notery-views notery-mt-2">
        Remaining views: <strong>{{ $remainingViews }}</strong> / {{ $maxViews }}
      </p>
    @endif
  </div>

  <div class="notery-card notery-mb-3">
    <textarea id="decryptedText" class="notery-textarea" readonly>{{ $decryptedText }}</textarea>
  </div>

  <div class="notery-btn-group notery-mb-4">
    <button type="button" id="copyButton" class="notery-btn notery-btn-primary">Copy</button>
    <a href="/" class="notery-btn notery-btn-secondary">Save another</a>
  </div>

  @if(!empty($attachments))
    <div class="notery-card">
      <h3 style="font-size:14px; font-weight:600; margin-bottom:12px; color:var(--text-secondary);">
        Attachments ({{ count($attachments) }})
      </h3>
      <div class="notery-form">
        @foreach($attachments as $idx => $att)
          @php
            $mime   = $att['mime'] ?? 'application/octet-stream';
            $size   = $att['size'] ?? null;
            $sizeKB = $size ? number_format($size / 1024, 1) . ' KB' : '';
          @endphp
          <div class="notery-attachment">
            <div class="notery-attachment-info">
              <div class="notery-attachment-name">Attachment {{ $idx + 1 }}</div>
              <div class="notery-attachment-meta">{{ $mime }}@if($sizeKB) &middot; {{ $sizeKB }}@endif</div>
            </div>
            <a href="{{ $att['url'] }}" class="notery-btn notery-btn-secondary notery-btn-sm">Download</a>
          </div>
        @endforeach
      </div>
      <p class="notery-hint notery-mt-3">
        Download links expire in 10 minutes.
      </p>
    </div>
  @endif

</div>
</div>

<script>
(function() {
  var btn  = document.getElementById('copyButton');
  var el   = document.getElementById('decryptedText');

  btn && btn.addEventListener('click', function() {
    var text = el ? el.value : '';
    try {
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      var orig = btn.textContent;
      btn.textContent = 'Copied';
      btn.disabled = true;
      setTimeout(function() {
        btn.textContent = orig;
        btn.disabled = false;
      }, 1200);
    } catch(e) {
      var orig2 = btn.textContent;
      btn.textContent = 'Copy failed';
      setTimeout(function() {
        btn.textContent = orig2;
      }, 1400);
    }
  });
})();
</script>

</x-layout>

