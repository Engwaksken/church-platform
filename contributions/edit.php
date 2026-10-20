<?php
require_once '../config/config.php';
requireLogin();
$id = intval($_GET['id']??0);
$c = fetchOne("SELECT * FROM contributions WHERE id=$id");
if(!$c){ setFlash('Not found.','danger'); redirect('index.php'); }
$projects = mysqli_query($conn,"SELECT id,name FROM projects ORDER BY name");
$members  = mysqli_query($conn,"SELECT id, CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) AS full_name,member_no FROM members ORDER BY first_name");
if($_SERVER['REQUEST_METHOD']==='POST'){
    $member_id  = intval($_POST['member_id']);
    $project_id = intval($_POST['project_id']);
    $amount     = floatval($_POST['amount']);
    $method     = san($_POST['payment_method']);
    $date       = san($_POST['contributed_on']);
    $notes      = san($_POST['notes']);
    mysqli_query($conn,"UPDATE contributions SET member_id=$member_id,project_id=$project_id,amount=$amount,payment_method='$method',contributed_on='$date',notes='$notes' WHERE id=$id");
    setFlash('Contribution updated.','success');
    redirect('index.php');
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>
<div class="page-header"><h2><i class="fas fa-edit"></i> Edit Contribution</h2><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a></div>
<div class="card"><div class="card-body">
<form method="POST">
<div class="form-row">
  <div class="form-group col-md-6"><label>Member</label>
    <select name="member_id" class="form-control">
      <?php while($m=mysqli_fetch_assoc($members)): ?>
      <option value="<?=$m['id']?>" <?=$c['member_id']==$m['id']?'selected':''?>><?=san($m['full_name'])?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="form-group col-md-6"><label>Project</label>
    <select name="project_id" class="form-control">
      <?php while($pr=mysqli_fetch_assoc($projects)): ?>
      <option value="<?=$pr['id']?>" <?=$c['project_id']==$pr['id']?'selected':''?>><?=san($pr['name'])?></option>
      <?php endwhile; ?>
    </select>
  </div>
</div>
<div class="form-row">
  <div class="form-group col-md-4"><label>Amount</label><input type="number" name="amount" class="form-control" value="<?=$c['amount']?>" step="0.01"></div>
  <div class="form-group col-md-4"><label>Payment Method</label>
    <select name="payment_method" class="form-control">
      <?php foreach(['Cash','Mobile Money','Bank Transfer','Cheque'] as $mt): ?>
      <option <?=$c['payment_method']==$mt?'selected':''?>><?=$mt?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group col-md-4"><label>Date</label><input type="date" name="contributed_on" class="form-control" value="<?=$c['contributed_on']?>"></div>
</div>
<div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?=san($c['notes'])?></textarea></div>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
</form>
</div></div>
</div>
</div>
<?php include '../includes/footer.php'; ?>
