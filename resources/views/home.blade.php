<x-layout>


    <header class="main-header">
        <div class="container">
            <div class="row">

                <div class="col"></div>

                <div class="col-xl-5 ">
                    <div class="form-container" id="form-container" style="background: #0f1222; border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); padding: 22px; backdrop-filter: blur(6px);">
                        <form action="/save" method="POST" class="header-form mx-auto" id="header-form" enctype="multipart/form-data">
                            @csrf
                            @if ($errors->any())
                            <div style="padding:8px; background:#4b1b1b; border:1px solid rgba(255,255,255,0.12); color:#f2dede; border-radius:6px; margin-bottom:8px;">
                                <div style="font-weight:700; margin-bottom:4px;">There were some problems with your submission:</div>
                                <ul style="margin:0 0 0 16px; padding:0;">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif
                            @if(session('saved') && session('code'))
                            <div
                                style="padding: 8px; background: green; border-radius: 4px; color: #ffffff; margin-bottom: 8px;">
                                Write-up saved successfully. Your code:
                                <strong>{{ session('code') }}</strong>
                            </div>
                            <a href="/{{ session('code') }}"
                                class="btn btn-outline-dark level-up shadow-off w-100 font-500 mb-2">view saved</a>
                            @endif

                            <h1 style="color:#fff; font-size: 26px; font-weight: 700; margin: 0 0 6px 0;">Notery</h1>
                            <p class="para-1 mb-2 mx-xl-0 mx-auto" style="color:#aab3c0; margin-bottom: 16px;">Save and retrieve notes anonymously with a 4‑digit code</p>

                            <div class="inputs">
                                <textarea name="writeup" id="" cols="35" rows="8" class="glassy form-control" required
                                    style="width:100%; box-sizing: border-box; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background-color: rgba(255,255,255,0.04); color:#e6e9ef; padding: 12px;"
                                    placeholder="type something">{{ old('writeup') }}</textarea>
                            </div>
                            <div class="inputs" style="margin-top: 10px;">
                                <label for="images" style="display:block; color:#aab3c0; font-size: 13px; margin-bottom:6px;">Optional images (encrypted)</label>
                                <input type="file" name="images[]" id="images" accept="image/*" class="form-control" multiple
                                    style="width:100%; box-sizing: border-box; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background-color: rgba(255,255,255,0.04); color:#e6e9ef; padding: 8px;" />
                                <div style="color:#8f9bad; font-size: 12px; margin-top: 6px;">Images only. 100MB total for all images. Auto-deleted on viewing.</div>
                            </div>
                            <div class="inputs" style="margin-top: 10px;">
                                <label for="files" style="display:block; color:#aab3c0; font-size: 13px; margin-bottom:6px;">Optional files (not encrypted)</label>
                                <input type="file" name="files[]" id="files" accept=".pdf,.mp4,.zip" class="form-control" multiple
                                    style="width:100%; box-sizing: border-box; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background-color: rgba(255,255,255,0.04); color:#e6e9ef; padding: 8px;" />
                                <div style="color:#8f9bad; font-size: 12px; margin-top: 6px;">PDF (200MB), MP4 (500MB), ZIP (500MB). Deleted 5 minutes after viewing.</div>
                            </div>
                            <button type="submit"
                                class="w-100 font-500 mb-2 mt-2"
                                style="background: linear-gradient(135deg,#6a5af9,#00c2ff); color:#0b0f1a; border:none; border-radius:10px; padding: 12px 16px; font-weight: 700; letter-spacing: .2px; box-shadow: 0 6px 18px rgba(0,194,255,0.25);">Save note</button>
                        </form>
                        <div class="hr position-relative" style="margin: 14px 0; text-align:center;">
                            <span style="display:inline-block; color:#8f9bad; font-size:12px; letter-spacing:.4px; text-transform:uppercase;">Or find a note</span>
                        </div>
                        <form action="/" method="GET" class="d-flex" style="gap: 8px; align-items: center;">
                            <input type="text" name="code" inputmode="numeric" pattern="\d{4}" maxlength="4"
                                class="glassy form-control" placeholder="Enter 4-digit code" required
                                style="flex:1; min-width:0; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; background: rgba(255,255,255,0.04); color:#e6e9ef; padding: 10px 12px;" />
                            <button type="submit" class="font-500"
                                style="white-space:nowrap; background:#1f2537; color:#e6e9ef; border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 14px;">Find</button>
                        </form>
                        <div class="form-comment mt-2" style="color:#8f9bad; font-size: 12px;">Create a quick note and get a 4‑digit pin</div>
                    </div>
                </div>

                <div class="col"></div>

            </div>
        </div>
    </header>





</x-layout>