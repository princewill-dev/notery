
<x-layout>


    <header class="main-header">
        <div class="container">
          <div class="row">

            {{-- <div class="col-xl-7 text-xl-left text-center">
              <h1 class="title-1 text-uppercase mb-2 mx-xl-0 mx-auto">SAVE & RETRIVE ANONYMOUSLY</h1>
              <p class="para-1 mb-2 mx-xl-0 mx-auto">Check out the services we offer below. We're determined to provide these services at the best quality - customer satisfaction and convenience are of the utmost importance for us.</p>
              <div class="notify glassy mb-4">
                <div class="icon">
                  <img data-src="assets/images/icons/bell.svg" class="lazy img-fluid" alt="Bell">
                </div>
                <div class="text">We don't ask for any personal information</div>
              </div>
              <br>
              <br>
            </div> --}}

            <div class="col"></div>

            <div class="col-xl-5 ">
                <div class="form-container" id="form-container">
                    <form action="/savewriteup" method="POST" class="header-form mx-auto" id="header-form">
                        @csrf
                        <p class="para-1 mb-2 mx-xl-0 mx-auto">here is your note</p>
                        
                        <div class="" >
                            <textarea style="padding: 10px; background: #fff;" cols="35" rows="10">{{ $decryptedText }}</textarea>
                        </div>

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

      



    </x-layout>



 