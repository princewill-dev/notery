
<x-layout>


    <header class="main-header">
        <div class="container">
          <div class="row">

            <div class="col"></div>
            
            <div class="col-xl-5 ">
                <div class="form-container" id="form-container">
                    <form action="/" method="POST" class="header-form mx-auto" id="header-form">
                        @csrf
                        
                        <p class="para-1 mb-2 mx-xl-0 mx-auto">save and retrieve notes anonymously <br> with a 4 digit code</p>
                        
                        <div class="inputs">
                        <textarea name="writeup" id="" cols="35" rows="10" class="glassy form-control" required style="box-sizing: border-box; border: 2px solid #ccc; border-radius: 4px; background-color: #f8f8f8;" placeholder="type something"></textarea>   
                        </div>
                        <button type="submit" class="btn btn-fill-success shadow-off w-100 font-500 mb-2 mt-1">save</button>
                        <div class="hr position-relative"><span>Or find a note</span></div>
                        <div class="row small-gutters mt-2">
                        <div class="col-12">
                            <a href="/find" class="btn btn-outline-dark level-up shadow-off w-100 font-500">Find note</a>
                        </div>
                        </div>
                        <div class="form-comment mt-2">Create a quick note and get 4 digit pin</div>
                    </form>
                </div>
            </div>

            <div class="col"></div>
    
          </div>
        </div>
      </header>

      



    </x-layout>



 