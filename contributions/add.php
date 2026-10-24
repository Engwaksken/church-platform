<?php
require_once '../config/config.php';
requireLogin();
$projects = mysqli_query($conn,"SELECT id,name FROM projects WHERE status='Active' ORDER BY name");
$members  = mysqli_query($conn,"SELECT id, full_name,member_no FROM members ORDER BY full_name");
if($_SERVER['REQUEST_METHOD']==='POST'){
    $receipt = genNo('RCP');
    $member_id = intval($_POST['member_id']);
    $project_id = intval($_POST['project_id']);
    $amount = floatval($_POST['amount']);
    $method = san($_POST['payment_method']);
    $date = san($_POST['contributed_on']);
    $notes = san($_POST['notes']);
    mysqli_query($conn,"INSERT INTO contributions (receipt_no,member_id,project_id,amount,payment_method,contributed_on,notes) VALUES ('$receipt',$member_id,$project_id,$amount,'$method','$date','$notes')");
    setFlash("Contribution recorded. Receipt: <strong>$receipt</strong>",'success');
    redirect('index.php');
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>
<div class="page-header"><h2><i class="fas fa-plus"></i> Record Contribution</h2><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a></div>
<div class="card"><div class="card-body">
<form method="POST">
<div class="form-row">
  <div class="form-group col-md-6">
    <label>Member *</label>
    <select name="member_id" class="form-control" required>
      <option value="">-- Select Member --</option>
      <?php while($m=mysqli_fetch_assoc($members)): ?>
      <option value="<?=$m['id']?>"><?=san($m['full_name'])?> (<?=san($m['member_no'])?>)</option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="form-group col-md-6">
    <label>Project *</label>
    <select name="project_id" class="form-control" required>
      <option value="">-- Select Project --</option>
      <?php while($pr=mysqli_fetch_assoc($projects)): ?>
      <option value="<?=$pr['id']?>"><?=san($pr['name'])?></option>
      <?php endwhile; ?>
    </select>
  </div>
</div>
<div class="form-row">
  <div class="form-group col-md-4"><label>Amount (UGX) *</label><input type="number" name="amount" class="form-control" min="0" step="0.01" required></div>
  <div class="form-group col-md-4"><label>Payment Method</label>
    <select name="payment_method" class="form-control">
      <option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option>
    </select>
  </div>
  <div class="form-group col-md-4"><label>Date *</label><input type="date" name="contributed_on" class="form-control" value="<?=date('Y-m-d')?>" required></div>
</div>
<div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Contribution</button>
</form>
</div></div>
</div>
</div>
<?php include '../includes/footer.php'; ?>
