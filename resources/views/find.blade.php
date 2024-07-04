<x-layout>


    <header class="main-header">
        <div class="container">
          <!-- row -->
          <div class="row align-items-center">
    
            <!-- col -->
           
            <div class="col"></div>
    
            <!-- col -->
            <div class="col-xl-5">
              <!-- form-container -->
              <div class="form-container" id="form-container">
                <!-- header-form -->
                <form action="/find" method="POST" class="header-form ml-auto" id="header-form">
                  @csrf

                  

				  @if (session('errorMessage'))
				  	<p style="color: #ffffff; padding: 5px; background: red;">{{ session('errorMessage') }}</p>
                  @endif

                  <!-- form-title -->
                  <h2 class="form-title mb-2">retrieve notes</h2>
    
                  <!-- inputs -->
                  <div class="inputs">
                    <label for="email" class="form-label">
                        <input type="text" name="code" class="glassy" id="email" placeholder="Enter PIN" autocomplete="off" required>
                      </label>
                  </div>
    
                  <!-- submit-button -->
                  <button type="submit" class="btn btn-fill-success shadow-off w-100 font-500 mb-2 mt-1">retrieve</button>
    
                  <!-- hr -->
                  <div class="hr position-relative"><span>Or</span></div>
    
                  <!-- links -->
                  <div class="row small-gutters mt-2">
    
                    <!-- col -->
                    <div class="col-12">
                      <a href="/" class="btn btn-outline-dark level-up shadow-off w-100 font-500"><img data-src="assets/images/write-icon2.png" class="lazy btn-icon img-fluid" alt="Google">Create note</a>
                    </div>
    
                    <!-- col -->
    
                  </div>
    
                  <!-- form-comment -->
                  <div class="form-comment mt-2">Create a quick note and get 4 digit pin</div>
    
                </form>
              </div>
            </div>


            <div class="col"></div>
    
          </div>
        </div>
      </header>


</x-layout>