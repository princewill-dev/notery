<x-layout>
    <header class="main-header">
        <div class="container">
            <div class="row">
                <div class="col"></div>
                <div class="col-xl-5">
                    <div class="form-container" id="form-container" style="background: #0f1222; border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); padding: 22px; backdrop-filter: blur(6px);">
                        <div class="header-form mx-auto" id="header-form">
                            <h1 style="color:#fff; font-size: 22px; font-weight: 700; margin: 0 0 10px 0;">Your note</h1>
                            <p style="color:#8f9bad; margin: 0 0 12px 0;">Large notes are shown compactly. Use expand to view all.</p>
                            <div id="noteWrapper" style="position: relative;">
                                <div id="decryptedText" style="width:100%; box-sizing: border-box; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background-color: rgba(255,255,255,0.04); color:#e6e9ef; padding: 12px; white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow: auto;">
{{ $decryptedText }}
                                </div>
                                <button type="button" id="toggleExpand" style="margin-top: 8px; background:#1f2537; color:#e6e9ef; border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:8px 12px; font-size: 13px;">Expand</button>
                            </div>
                            <div style="display:flex; gap:10px; margin-top: 12px;">
                                <button type="button" id="copyButton" style="flex:1; background: linear-gradient(135deg,#6a5af9,#00c2ff); color:#0b0f1a; border:none; border-radius:10px; padding: 10px 14px; font-weight: 700; letter-spacing: .2px; box-shadow: 0 6px 18px rgba(0,194,255,0.25);">Copy</button>
                                <a href="/" style="flex:1; text-align:center; background:#1f2537; color:#e6e9ef; border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 14px; text-decoration:none; font-weight:600;">Save another</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col"></div>
            </div>
        </div>
    </header>

    <script>
        (function() {
            const copyBtn = document.getElementById('copyButton');
            const textEl = document.getElementById('decryptedText');
            const toggleBtn = document.getElementById('toggleExpand');
            let expanded = false;

            copyBtn?.addEventListener('click', async function() {
                const text = textEl?.innerText || '';
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
                    alert('Copied to clipboard');
                } catch(e) {
                    alert('Copy failed');
                }
            });

            toggleBtn?.addEventListener('click', function() {
                expanded = !expanded;
                if (expanded) {
                    textEl.style.maxHeight = 'none';
                    toggleBtn.textContent = 'Collapse';
                } else {
                    textEl.style.maxHeight = '360px';
                    toggleBtn.textContent = 'Expand';
                }
            });
        })();
    </script>
</x-layout>

