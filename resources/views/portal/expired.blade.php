<x-layout>

<div class="notery-page">
<div class="notery-container notery-text-center">

  <div class="notery-brand">
    <h1 class="notery-logo">
      @if(isset($errorMessage) && $errorMessage)
        {{ $errorMessage }}
      @else
        Portal unavailable
      @endif
    </h1>
  </div>

  <div class="notery-mt-4 notery-btn-group">
    <a href="/" class="notery-btn notery-btn-primary">Open new portal</a>
    <a href="/" class="notery-btn notery-btn-secondary">Return home</a>
  </div>

</div>
</div>

</x-layout>
