<?php
   require_once __DIR__ . '/../lib/auth.php';
   telerix_session_start();
   if (is_logged_in()) {
      redirect('start.php');
   }
   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

  <!-- Loginkasten begin -->
  <div class="w3-panel w3-card-4 w3-blue" style="border-radius: 15px;">
  <h2 class="w3-center w3-padding-16" style="color: #ffffff">Login</h2>
  <?php
      include(__DIR__ . '/../lib/panels/login.php');
  ?>
  <br />
  </div>
  <!-- Loginkasten end -->

  </div>
</div>
<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
