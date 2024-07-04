<x-layout>
    <header class="main-header">
        <div class="container">
            <div class="row">
                <div class="col"></div>
                <div class="col-xl-5">
                    <div class="form-container" id="form-container">
                        <form action="/savewriteup" method="POST" class="header-form mx-auto" id="header-form">
                            @csrf
                            <p class="para-1 mb-2 mx-xl-0 mx-auto">here is your note</p>
                            <div>
                                <textarea id="decryptedText" style="padding: 10px; background: #fff;" cols="35" rows="10">{{ $decryptedText }}</textarea>
                            </div>
                            <button type="button" id="copyButton" class="btn btn-primary mt-2">Copy</button>
                            <br>
                            <br>
                            <div class="hr position-relative"><span>Or find a note</span></div>
                            <div class="row small-gutters mt-2">
                                <div class="col-12">
                                    <a href="/" class="btn btn-outline-dark level-up shadow-off w-100 font-500">save another</a>
                                    <a href="/find" class="btn btn-outline-dark level-up shadow-off w-100 font-500">find saved items</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col"></div>
            </div>
        </div>
    </header>

    <script>
        document.getElementById('copyButton').addEventListener('click', function() {
            var decryptedText = document.getElementById('decryptedText');
            decryptedText.select();
            decryptedText.setSelectionRange(0, 99999); // For mobile devices
            document.execCommand('copy');
            alert('Copied to clipboard');
        });
    </script>
</x-layout>
