<x-layout>

<div class="notery-page">
<div class="notery-container">

  <div class="notery-brand">
    <h1 class="notery-logo">Notery</h1>
  </div>

  <div class="notery-alert notery-alert-error notery-mb-4">
    {{ $errorMessage }}
  </div>

  <div class="notery-card notery-mb-3">
    <form action="/" method="GET" class="notery-form">
      <input type="text" name="code" class="notery-input" inputmode="numeric"
        pattern="\d{4}" maxlength="4" placeholder="Enter 4-digit code" required autofocus />
      <button type="submit" class="notery-btn notery-btn-primary notery-btn-block">Find note</button>
    </form>
  </div>

  <a href="/" class="notery-btn notery-btn-ghost notery-btn-block">Create a new note</a>

</div>
</div>

</x-layout>