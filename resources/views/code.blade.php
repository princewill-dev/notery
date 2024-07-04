
<x-layout>


    <header class="main-header">
        <div class="container">
          <div class="row">

            <div class="col"></div>
            
            <div class="col-xl-5 ">
                    <div class="form-container" id="form-container">
                    <form action="#" method="POST" class="header-form mx-auto" id="header-form">
                        @csrf
                        <span style="padding: 5px; background: green; border-radius: 3px; color: #ffffff;">Write-up saved successfully</span>
                        <br>
                        <br>
                        <div class="inputs">
                            <p style="color: #fff; font-size: 14px;">Here is your code:</p>
                            <br>
                            <span style="padding: 5px; border: 2px dashed #ffffff; color: #fff; text-align: center;">{{ $code }}</span>
                            <br>
                            <br>
                        </div>
                        <br>
                        <a href="/find/{{ session('code') }}" class="btn btn-outline-dark level-up shadow-off w-100 font-500">view saved</a>
                        <br>
                        <br>
                        <div class="hr position-relative"><span>Or you can</span></div>
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

      



    </x-layout>



 