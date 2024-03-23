<!DOCTYPE html>
<html lang="en">
<head>
  <!-- meta -->
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="msapplication-tap-highlight" content="no">
  <meta name="theme-color" content="#0060fe">
  <meta name="title" content="savex">
  <meta name="description" content="create, save, retrive rapidly">
  <meta name="keywords" content="Buy VPS, Buy VPS Hosting,Buy Cheap VPS, Free VPS, Trial VPS">
  <meta name="language" content="en">
  <link rel="shortcut icon" href="assets/images/theme/favicon.png" type="image/x-icon">
  <!-- link -->
  <link rel="stylesheet" href="/assets/css/bundle.min.css">
  <link rel="stylesheet" href="/assets/css/fonts.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/templates.css">
  <link rel="stylesheet" href="/assets/css/index.css">
    
  <!-- title -->
  <title>Quick save app</title>
</head>
<body class="position-relative index-page dark-theme" id="index-page">

  <!-- Preloader -->
  <div class="preloader" id="preloader">
    <div class="content d-flex align-items-center justify-content-center">
      <div class="spinner"></div>
    </div>
  </div>  



  {{-- @if (session()->has('success')){
      {{session('success')}}
  }
      
  @endif

  @if (session()->has('faliure')){
      {{session('faliure')}}
  }
      
  @endif --}}



  {{-- <center>
    @if (session()->has('success'))
    <span style="padding: 5px; background: green; border-radius: 3px; color: #ffffff;">{{session('success')}}</span>
    @endif

    @if (session()->has('faliure'))
    <span style="padding: 5px; background: red; border-radius: 3px; color: #ffffff;">{{session('faliure')}}</span>
    @endif
  </center> --}}




    {{$slot}}





  <!-- Theme Footer -->
  <footer class="theme-footer">
    
    <!-- footer-bottom -->
    <div class="footer-bottom">
      <div class="container-fluid">
        
        <div class="content d-lg-flex align-items-end flex-wrap">
          <!-- para-3 -->
          <p class="para-3">this site is built to solve a personal problem that I have, feel free to use it fairly<p>
          <!-- sign -->
          <div class="signature ml-auto mt-lg-0 mt-1">
            <p class="para-3">Developed by <a href="https://princewilldev.com/" target="_blank">Princewill dev</a></p>
          </div>
        </div>
      </div>
    </div>
  </footer>

  <!-- jquery-3.3.1.min.js -->
  <script src="/assets/js/jquery-3.3.1.min.js"></script>  
  <!-- jquery.lazy.min.js -->
  <script src="/assets/js/jquery.lazy.min.js"></script> 
  <!-- jQuery.cookies.js -->
  <script src="/assets/js/jQuery.cookies.js"></script>
  <!-- templates.js -->
  <script src="/assets/js/templates.js"></script>
  <!-- script.js -->
  <script src="/assets/js/script.js"></script>  
</body>
</html>
