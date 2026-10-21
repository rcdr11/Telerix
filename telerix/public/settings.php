<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/numbers.php';
   $entries = numbers_all();
   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

 <!-- Optionen Panel Begin-->
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Eingetragene Nummern</h3>
  <table class="w3-table w3-striped w3-light-blue" style="border-radius:5px">
    <thead>
      <tr class="w3-dark-gray">
        <th>Name</th>
        <th>Nummer</th>
      </tr>
    </thead>
<?php if (!$entries): ?>
    <tr><td colspan="2">Keine Nummern eingetragen</td></tr>
<?php else: foreach ($entries as $e): ?>
    <tr>
      <td><?= h($e['name']) ?></td>
      <td><?= h($e['number']) ?></td>
    </tr>
<?php endforeach; endif; ?>
  </table>
  <br />
 </div>
  <!-- Optionen Panel End-->
  <br />

  <form action="deletelist.php" method="POST" class="inline" data-confirm="Die aktuelle Liste unwiderruflich löschen?">
     <?= csrf_field() ?>
     <button type="submit" class="w3-button w3-dark-gray w3-round">Aktuelle Liste l&ouml;schen</button>
  </form>
  <a href="listupload.php" class="w3-button w3-dark-gray w3-round">Neue Liste hochladen</a>

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
