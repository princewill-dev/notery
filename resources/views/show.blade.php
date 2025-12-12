<x-layout>
    <header class="main-header">
        <div class="container">
            <center>
                <div class="mx-auto" id="header-form">
                    <h6 style="color:#fff;">Your note</h6>
                    @if(isset($maxViews) && $maxViews !== null)
                        <div style="color:#8f9bad; font-size: 13px; margin: 0 0 10px 0;">Remaining views: <span style="color:#e6e9ef; font-weight:700;">{{ $remainingViews }}</span> / {{ $maxViews }}</div>
                    @endif
                    <textarea id="decryptedText" style="width:100%; box-sizing: border-box; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background-color: rgba(255,255,255,0.04); color:#e6e9ef; padding: 12px; white-space: pre-wrap; word-break: break-word; height: 320px; max-height: 55vh; overflow: auto; resize: none;">{{ $decryptedText }}</textarea>
                    
                </div>
                <br>
                <div>
                    <button type="button" id="copyButton" style="flex:1; background: linear-gradient(135deg,#6a5af9,#00c2ff); color:#0b0f1a; border:none; border-radius:10px; padding: 8px 10px; font-weight: 700; font-size: 13px; letter-spacing: .2px; box-shadow: 0 6px 18px rgba(0,194,255,0.20);">Copy</button>
                    <a href="/" style="flex:1; text-align:center; background:#1f2537; color:#e6e9ef; border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:8px 10px; text-decoration:none; font-weight:600; font-size: 13px;">Save another</a>
                </div>

                <br>

                @if(!empty($attachments))
                    <div style="margin-top:12px;">
                        <div style="color:#aab3c0; font-size: 13px; margin-bottom:6px;">Attachments ({{ count($attachments) }})</div>
                        <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px;">
                            @foreach($attachments as $idx => $att)
                                @php 
                                    $mime = $att['mime'] ?? 'application/octet-stream'; 
                                    $size = $att['size'] ?? null;
                                    $sizeKB = $size ? number_format($size / 1024, 1) . ' KB' : '';
                                @endphp
                                <li style="display:flex; align-items:center; justify-content:space-between; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background-color: rgba(255,255,255,0.02); padding: 10px;">
                                    <div style="color:#e6e9ef; font-size:14px;">
                                        <div style="font-weight:600;">Attachment {{ $idx + 1 }}</div>
                                        <div style="color:#8f9bad; font-size:12px;">{{ $mime }} @if($sizeKB) • {{ $sizeKB }} @endif</div>
                                    </div>
                                    <a href="{{ $att['url'] }}" style="white-space:nowrap; background:#1f2537; color:#e6e9ef; border:1px solid rgba(255,255,255,0.12); border-radius:8px; padding:8px 12px; text-decoration:none; font-weight:600;">Download</a>
                                </li>
                            @endforeach
                        </ul>
                        <div style="color:#8f9bad; font-size: 12px; margin-top: 6px;">Download links expire in 10 minutes. PDFs, MP4s, and ZIPs will be deleted 5 minutes after viewing. This note and images are already deleted.</div>
                    </div>
                @endif
            </center>
        </div>
    </header>

    <script>
        (function() {
            const copyBtn = document.getElementById('copyButton');
            const textEl = document.getElementById('decryptedText');

            copyBtn?.addEventListener('click', async function() {
                const text = textEl?.value || '';
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                    } else {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    }
                    const original = copyBtn.textContent;
                    copyBtn.textContent = 'Copied';
                    copyBtn.disabled = true;
                    setTimeout(() => {
                        copyBtn.textContent = original || 'Copy';
                        copyBtn.disabled = false;
                    }, 1200);
                } catch(e) {
                    const original = copyBtn.textContent;
                    copyBtn.textContent = 'Copy failed';
                    setTimeout(() => {
                        copyBtn.textContent = original || 'Copy';
                    }, 1400);
                }
            });
        })();
    </script>
</x-layout>

